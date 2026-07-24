<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Security\Authentication\Token\ApiBearerToken;
use Medienreaktor\NeosApi\Service\NodeAddressCodec;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Base class for all protected API endpoints.
 *
 * Security layering (see Policy.yaml for the endpoint level):
 *  - every action here requires an authenticated account (bearer token ->
 *    Flow account -> roles), enforced by the deny-by-default privilege targets
 *  - requireScope() additionally narrows by OAuth token scopes
 *  - all content reads go through ContentRepository::getContentSubgraph(),
 *    which applies the account's visibility constraints structurally
 */
abstract class AbstractApiController extends ActionController
{
    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json'];

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'contentRepository')]
    protected string $contentRepositoryId;

    private ?ContentRepository $contentRepository = null;

    private ?ContentRepositoryId $resolvedContentRepositoryId = null;

    protected function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    protected function getContentRepository(): ContentRepository
    {
        return $this->contentRepository ??= $this->contentRepositoryRegistry->get($this->getContentRepositoryId());
    }

    protected function getContentRepositoryId(): ContentRepositoryId
    {
        return $this->resolvedContentRepositoryId ??= ContentRepositoryId::fromString($this->contentRepositoryId);
    }

    /**
     * Resolve the security-aware subgraph for a node address. With
     * $frontendVisibility, additionally exclude disabled ("hidden") nodes -
     * narrowing beyond the account's constraints is always safe, the reverse
     * direction is not offered.
     */
    protected function getSubgraph(NodeAddress $address, bool $frontendVisibility = false): ContentSubgraphInterface
    {
        $contentRepository = $this->getContentRepository();
        $subgraph = $contentRepository->getContentSubgraph($address->workspaceName, $address->dimensionSpacePoint);
        if ($frontendVisibility) {
            $subgraph = $contentRepository->getContentGraph($address->workspaceName)->getSubgraph(
                $address->dimensionSpacePoint,
                $subgraph->getVisibilityConstraints()->merge(NeosVisibilityConstraints::excludeDisabled())
            );
        }

        return $subgraph;
    }

    protected function decodeNodeAddress(string $encoded): NodeAddress
    {
        try {
            $address = NodeAddressCodec::decode($encoded);
        } catch (\Throwable) {
            $this->throwJsonStatus(400, 'invalid_node_address', 'The given node address could not be parsed.');
        }
        if ($address->contentRepositoryId->value !== $this->contentRepositoryId) {
            $this->throwJsonStatus(400, 'invalid_node_address', sprintf('The node address belongs to content repository "%s", this API serves "%s".', $address->contentRepositoryId->value, $this->contentRepositoryId));
        }

        return $address;
    }

    /**
     * Enforce OAuth scope narrowing. Requests authenticated via bearer token
     * must carry the required scope; the account's roles have already been
     * enforced by the Flow policy layer at this point.
     */
    protected function requireScope(string $scope): void
    {
        foreach ($this->securityContext->getAuthenticationTokensOfType(ApiBearerToken::class) as $token) {
            if ($token->isAuthenticated()) {
                if (!$token->hasScope($scope)) {
                    $this->throwJsonStatus(403, 'insufficient_scope', sprintf('This request requires the "%s" scope.', $scope));
                }

                return;
            }
        }
        $this->throwJsonStatus(401, 'unauthorized', 'No authenticated bearer token present.');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $statusCode = 200): string
    {
        $this->response->setStatusCode($statusCode);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $details extra top-level fields merged into
     *        the error body (e.g. a structured "conflicts" list). Cannot
     *        override "error"/"error_description".
     */
    protected function throwJsonStatus(int $statusCode, string $error, string $description, array $details = []): never
    {
        $this->throwStatus($statusCode, null, json_encode([
            'error' => $error,
            'error_description' => $description,
        ] + $details, JSON_UNESCAPED_SLASHES));
    }
}
