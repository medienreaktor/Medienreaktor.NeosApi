<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api\Media;

use Medienreaktor\NeosApi\Controller\Api\AbstractApiController;
use Medienreaktor\NeosApi\Domain\Model\AssetUploadTicket;
use Medienreaktor\NeosApi\Domain\Repository\AssetUploadTicketRepository;
use Medienreaktor\NeosApi\Security\Authentication\Token\AssetUploadTicketToken;
use Medienreaktor\NeosApi\Service\LocalAssetUploader;
use Medienreaktor\NeosApi\Service\MediaSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetNotFoundException;
use Neos\Media\Domain\Service\AssetSourceService;
use Neos\Media\TypeConverter\AssetInterfaceConverter;

/**
 * Upload links: a client that can only send JSON (an MCP tool call) creates
 * a ticket and hands its URL to whoever has the file - a shell posts it with
 * curl, a person opens the URL and drops the file on the page. The upload
 * then runs as the account that created the ticket (AssetUploadTicketProvider).
 */
class AssetUploadsController extends AbstractApiController
{
    private const TICKET_LIFETIME = 'PT15M';
    private const LOCAL_ASSET_SOURCE = 'neos';
    private const PAGE_SCRIPT = 'resource://Medienreaktor.NeosApi/Public/AssetUpload/asset-upload.js';
    private const PAGE_STYLE = 'resource://Medienreaktor.NeosApi/Public/AssetUpload/asset-upload.css';

    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\Inject]
    protected AssetUploadTicketRepository $ticketRepository;

    #[Flow\Inject]
    protected LocalAssetUploader $localAssetUploader;

    #[Flow\Inject]
    protected AssetSourceService $assetSourceService;

    #[Flow\Inject]
    protected MediaSerializer $mediaSerializer;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    /**
     * Create an upload link. JSON body: title?, collection?, tags? - applied
     * to every file uploaded with the link.
     *
     * @param array<int, string> $tags
     */
    #[Flow\SkipCsrfProtection]
    public function createAction(?string $title = null, ?string $collection = null, array $tags = []): string
    {
        $this->requireScope('neos.media');
        $account = $this->securityContext->getAccount();
        if ($account === null) {
            $this->throwJsonStatus(401, 'unauthorized', 'No authenticated account present.');
        }

        [$ticket, $secret] = AssetUploadTicket::issue(
            $account->getAccountIdentifier(),
            $title === '' ? null : $title,
            $collection === '' ? null : $collection,
            $tags,
            (new \DateTimeImmutable())->add(new \DateInterval(self::TICKET_LIFETIME))
        );
        $this->ticketRepository->save($ticket);

        $url = $this->uriBuilder->reset()->setCreateAbsoluteUri(true)->setFormat('html')
            ->uriFor('form', ['upload' => $ticket->id, 'secret' => $secret]);

        return $this->json(['upload' => $this->serializeTicket($ticket) + ['url' => $url]], 201);
    }

    /**
     * The state of an upload link of the own account: when it expires and
     * which assets have been uploaded with it so far. An expired link is
     * gone (the ticket cache drops it), so it answers 404.
     */
    public function showAction(string $upload): string
    {
        $this->requireScope('neos.media');
        $ticket = $this->ticketRepository->findById($upload);
        if ($ticket === null || $ticket->accountIdentifier !== $this->securityContext->getAccount()?->getAccountIdentifier()) {
            $this->throwJsonStatus(404, 'upload_not_found', 'The upload link does not exist or has expired.');
        }

        return $this->json(['upload' => $this->serializeTicket($ticket)]);
    }

    /**
     * The page behind an upload link: a file field that posts to the same
     * URL. Public - it shows nothing but whether the link is still valid,
     * the upload itself is authenticated by the link.
     */
    public function formAction(string $upload, string $secret): void
    {
        $this->response->setContentType('text/html');
        $this->response->setHttpHeader('Referrer-Policy', 'no-referrer');

        $ticket = $this->ticketRepository->findById($upload);
        $valid = $ticket !== null && $ticket->matchesSecret($secret) && !$ticket->isExpiredAt(new \DateTimeImmutable());

        $this->view->assign('valid', $valid);
        $this->view->assign('expiresAt', $valid ? $ticket->expiresAt->format(\DateTimeInterface::ATOM) : null);
        $this->view->assign('scriptUri', $this->resourceManager->getPublicPackageResourceUriByPath(self::PAGE_SCRIPT));
        $this->view->assign('styleUri', $this->resourceManager->getPublicPackageResourceUriByPath(self::PAGE_STYLE));
    }

    protected function initializeUploadAction(): void
    {
        // Same mapping as AssetsController::createAction: the file arrives as
        // asset[resource], identical files resolve to the existing asset.
        $configuration = $this->arguments->getArgument('asset')->getPropertyMappingConfiguration();
        $configuration->allowProperties('title', 'resource');
        $configuration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, true);
        $configuration->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_ONE_PER_RESOURCE, true);
    }

    /**
     * Upload a file with an upload link (multipart/form-data, asset[resource]).
     */
    #[Flow\SkipCsrfProtection]
    public function uploadAction(string $upload, string $secret, Asset $asset): string
    {
        $ticket = $this->requireAuthenticatedTicket($upload);

        if ($ticket->title !== null && $asset->getTitle() === '') {
            $asset->setTitle($ticket->title);
        }
        $this->localAssetUploader->store($asset, $ticket->collection, $ticket->tags);

        $assetIdentifier = (string)$this->persistenceManager->getIdentifierByObject($asset);
        // Re-read: another file of the same link may have been stored meanwhile.
        $current = $this->ticketRepository->findById($ticket->id) ?? $ticket;
        $this->ticketRepository->save($current->withAsset($assetIdentifier));

        return $this->json([
            'asset' => $this->mediaSerializer->serializeProxy(
                $this->assetSourceService->getAssetSources()[self::LOCAL_ASSET_SOURCE]->getAssetProxyRepository()->getAssetProxy($assetIdentifier)
            ),
        ], 201);
    }

    /**
     * A bearer token of the same account must not stand in for the link:
     * only a request authenticated by this very ticket may upload with it.
     */
    private function requireAuthenticatedTicket(string $upload): AssetUploadTicket
    {
        foreach ($this->securityContext->getAuthenticationTokensOfType(AssetUploadTicketToken::class) as $token) {
            if ($token->isAuthenticated() && $token->getTicketId() === $upload) {
                $ticket = $this->ticketRepository->findById($upload);
                if ($ticket !== null) {
                    return $ticket;
                }
            }
        }
        $this->throwJsonStatus(401, 'invalid_upload_link', 'The upload link is unknown, wrong or expired. Ask for a new one.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTicket(AssetUploadTicket $ticket): array
    {
        $proxyRepository = $this->assetSourceService->getAssetSources()[self::LOCAL_ASSET_SOURCE]->getAssetProxyRepository();
        $assets = [];
        foreach ($ticket->assetIdentifiers as $assetIdentifier) {
            try {
                $assets[] = $this->mediaSerializer->serializeProxy($proxyRepository->getAssetProxy($assetIdentifier));
            } catch (NeosAssetNotFoundException) {
                // Deleted since the upload - the link's history only lists what still exists.
            }
        }

        return [
            'id' => $ticket->id,
            'expiresAt' => $ticket->expiresAt->format(\DateTimeInterface::ATOM),
            'assets' => $assets,
        ];
    }
}
