<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryList;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryListing;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepository;
use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Exception\InvalidConfigurationException;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Psr\Log\LoggerInterface;

/**
 * The content repositories of the Neos.ContentRepositoryRegistry
 *
 * One that can't be built (misconfigured) is left out of the list and not found, its error is logged: the others
 * stay usable
 */
final readonly class ContentRepositories
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private LabelTranslator $labelTranslator,
        private NodeTypeUi $nodeTypeUi,
        private LoggerInterface $logger,
    ) {
    }

    #[Operation(
        path: '/cr',
        method: 'GET',
        summary: 'List all content repositories',
        description: 'The content repositories configured in the Neos.ContentRepositoryRegistry settings, in the order of the settings, without the ones that can\'t be built (their errors are logged). The labels are translated to the Accept-Language.',
        operationId: 'listContentRepositories',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function list(
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): ContentRepositoryListing {
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        $contentRepositories = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            $contentRepository = $this->build($id);
            if ($contentRepository !== null) {
                $contentRepositories[] = ContentRepository::from($contentRepository, $labels, $this->nodeTypeUi);
            }
        }
        return ContentRepositoryListing::of(new ContentRepositoryList(...$contentRepositories));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}',
        method: 'GET',
        summary: 'Get a content repository',
        description: 'The labels are translated to the Accept-Language.',
        operationId: 'getContentRepository',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CONTENTREPOSITORIES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): ContentRepository|NotFound {
        $id = $contentRepositoryId->toContentRepositoryId();
        $contentRepository = null;
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $configuredId) {
            if ($configuredId->equals($id)) {
                $contentRepository = $this->build($configuredId);
            }
        }
        if ($contentRepository === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        return ContentRepository::from($contentRepository, $this->labelTranslator->forAcceptLanguage($acceptLanguage), $this->nodeTypeUi);
    }

    /**
     * The configured content repository, null if it can't be built
     */
    private function build(SharedModel\ContentRepository\ContentRepositoryId $id): ?Core\ContentRepository
    {
        try {
            return $this->contentRepositoryRegistry->get($id);
        } catch (InvalidConfigurationException $exception) {
            $this->logger->error(sprintf('The content repository %s can\'t be built: %s', $id->value, $exception->getMessage()), ['exception' => $exception]);
            return null;
        }
    }
}
