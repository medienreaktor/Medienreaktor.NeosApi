<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\Api\Security\AccountPrivileges;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\OAuth\Security\OAuthContext;
use Neos\Party\Domain\Service\PartyService;

/**
 * The subgraphs the API reads nodes from, with the visibility of the request's access token's account
 *
 * The workspace permissions and visibility constraints come from the token's account roles
 * (AccountPrivileges::rolesOf()) and its Neos user, as for the workspaces, not Flow's security context, which may hold
 * the roles of other tokens as well. The content repository's own check on getContentGraph() (Flow's security
 * context) stays as a fallback
 */
#[Flow\Scope('singleton')]
final readonly class ContentSubgraphs
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private AccountPrivileges $accountPrivileges,
        private OAuthContext $oauthContext,
        private PartyService $partyService,
        private PolicyService $policyService,
    ) {
    }

    /**
     * The subgraph as the account sees it, null if the content repository or workspace doesn't exist or the account
     * may not read it: both are a 404, so workspaces can't be probed
     *
     * @param bool $excludeDisabled leave out disabled nodes even if the account may see them, as the frontend does
     *                              (Neos\Neos\Controller\Frontend\NodeController::showAction()), otherwise it's the
     *                              account's visibility as in the Neos backend and its preview (previewAction())
     * @param bool $includeRemoved find soft removed nodes as well (tagged removed, e.g. for a trash), which Neos never
     *                             shows: the account's node privileges still apply
     */
    public function find(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled, bool $includeRemoved = false): ?ContentSubgraphInterface
    {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId);
        return $contentRepository !== null ? $this->findIn($contentRepository, $workspaceName, $dimensionSpacePoint, $excludeDisabled, $includeRemoved) : null;
    }

    /**
     * As find(), for a content repository found already
     */
    public function findIn(ContentRepository $contentRepository, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled, bool $includeRemoved = false): ?ContentSubgraphInterface
    {
        $roles = $this->roles();
        return $this->readableContentGraph($contentRepository, $workspaceName, $roles)
            ?->getSubgraph($dimensionSpacePoint, $this->visibilityConstraints($contentRepository, $roles, $excludeDisabled, $includeRemoved));
    }

    /**
     * The site node of the site in the live workspace and the site's default dimension space point, the one the
     * frontend renders for its home page. Null if there is none or the account may not read it, see find()
     */
    public function findSiteNode(Model\Site $site, bool $excludeDisabled): ?Node
    {
        $configuration = $site->getConfiguration();
        $subgraph = $this->find($configuration->contentRepositoryId, WorkspaceName::forLive(), $configuration->defaultDimensionSpacePoint, $excludeDisabled);
        return $subgraph !== null ? $this->findSiteNodeIn($subgraph, $site) : null;
    }

    /**
     * The site node of the site in the subgraph, null if there is none or the subgraph is of another content
     * repository
     */
    public function findSiteNodeIn(ContentSubgraphInterface $subgraph, Model\Site $site): ?Node
    {
        if (!$subgraph->getContentRepositoryId()->equals($site->getConfiguration()->contentRepositoryId)) {
            return null;
        }
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
        return $sitesNode !== null ? $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesNode->aggregateId) : null;
    }

    /**
     * The node in the other dimension space points its aggregate occupies (its content variants, not the points that
     * only fall back to it), in the order of the content dimensions, each read in that point with the visibility the
     * node was read with, so a variant the account may not see is left out
     *
     * @return list<Node>
     */
    public function findVariants(Node $node): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        try {
            $contentGraph = $contentRepository->getContentGraph($node->workspaceName);
        } catch (WorkspaceDoesNotExist | AccessDenied) {
            return [];
        }
        return array_values(array_filter(
            $this->variantsIn($contentRepository, $contentGraph, $node->aggregateId, $node->visibilityConstraints),
            static fn (Node $variant) => !$variant->originDimensionSpacePoint->equals($node->originDimensionSpacePoint),
        ));
    }

    /**
     * The aggregate in each dimension space point it occupies, in the order of the content dimensions, each read in
     * that point as the account sees it, so a variant the account may not see is left out. Null if the workspace
     * doesn't exist or the account may not read it, see find()
     *
     * @return list<Node>|null
     */
    public function findAllVariants(ContentRepository $contentRepository, WorkspaceName $workspaceName, NodeAggregateId $nodeAggregateId, bool $excludeDisabled): ?array
    {
        $roles = $this->roles();
        $contentGraph = $this->readableContentGraph($contentRepository, $workspaceName, $roles);
        return $contentGraph !== null
            ? $this->variantsIn($contentRepository, $contentGraph, $nodeAggregateId, $this->visibilityConstraints($contentRepository, $roles, $excludeDisabled))
            : null;
    }

    /**
     * The dimension space points the aggregate covers in the workspace, in the order of the variation graph: the ones
     * it occupies and the ones that fall back to them (e.g. en_UK showing en_US), each only if the account sees the
     * node there, so a point it is removed or hidden from the account in is left out. Null if the workspace doesn't
     * exist or the account may not read it, see find()
     *
     * @return list<DimensionSpacePoint>|null
     */
    public function findCoveredDimensionSpacePoints(ContentRepository $contentRepository, WorkspaceName $workspaceName, NodeAggregateId $nodeAggregateId, bool $excludeDisabled): ?array
    {
        $roles = $this->roles();
        $contentGraph = $this->readableContentGraph($contentRepository, $workspaceName, $roles);
        if ($contentGraph === null) {
            return null;
        }
        $nodeAggregate = $contentGraph->findNodeAggregateById($nodeAggregateId);
        if ($nodeAggregate === null) {
            return [];
        }
        $visibilityConstraints = $this->visibilityConstraints($contentRepository, $roles, $excludeDisabled);
        $covered = [];
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            if ($nodeAggregate->coversDimensionSpacePoint($dimensionSpacePoint) && $contentGraph->getSubgraph($dimensionSpacePoint, $visibilityConstraints)->findNodeById($nodeAggregateId) !== null) {
                $covered[] = $dimensionSpacePoint;
            }
        }
        return $covered;
    }

    /**
     * @return list<Node>
     */
    private function variantsIn(ContentRepository $contentRepository, ContentGraphInterface $contentGraph, NodeAggregateId $nodeAggregateId, VisibilityConstraints $visibilityConstraints): array
    {
        $nodeAggregate = $contentGraph->findNodeAggregateById($nodeAggregateId);
        if ($nodeAggregate === null) {
            return [];
        }
        $variants = [];
        // the occupied points are in no particular order
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            if (!$nodeAggregate->occupiesDimensionSpacePoint(OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint))) {
                continue;
            }
            $variant = $contentGraph->getSubgraph($dimensionSpacePoint, $visibilityConstraints)->findNodeById($nodeAggregateId);
            if ($variant !== null) {
                $variants[] = $variant;
            }
        }
        return $variants;
    }

    /**
     * The content graph of the workspace if the account may read it, null if it doesn't exist or it may not
     *
     * @param array<string, Role> $roles
     */
    private function readableContentGraph(ContentRepository $contentRepository, WorkspaceName $workspaceName, array $roles): ?ContentGraphInterface
    {
        $account = $this->oauthContext->authenticatedGrant()?->account;
        $user = $account !== null ? $this->partyService->getAssignedPartyOfAccount($account) : null;
        $permissions = $this->contentRepositoryAuthorizationService->getWorkspacePermissions($contentRepository->id, $workspaceName, $roles, $user instanceof Model\User ? $user->getId() : null);
        if (!$permissions->read) {
            return null;
        }
        try {
            return $contentRepository->getContentGraph($workspaceName);
        } catch (WorkspaceDoesNotExist | AccessDenied) {
            return null;
        }
    }

    /**
     * @param array<string, Role> $roles
     */
    private function visibilityConstraints(ContentRepository $contentRepository, array $roles, bool $excludeDisabled, bool $includeRemoved = false): VisibilityConstraints
    {
        $visibilityConstraints = $this->contentRepositoryAuthorizationService->getVisibilityConstraints($contentRepository->id, $roles);
        if ($includeRemoved) {
            // Neos always excludes removed, the tags of the account's node privileges stay excluded (unless one of
            // them is removed itself)
            $visibilityConstraints = VisibilityConstraints::excludeSubtreeTags($visibilityConstraints->excludedSubtreeTags->without(NeosSubtreeTag::removed()));
        }
        return $excludeDisabled ? $visibilityConstraints->merge(NeosVisibilityConstraints::excludeDisabled()) : $visibilityConstraints;
    }

    /**
     * The roles of the request's access token's account, only Neos.Flow:Everybody without one
     *
     * @return array<string, Role>
     */
    private function roles(): array
    {
        $account = $this->oauthContext->authenticatedGrant()?->account;
        return $account !== null
            ? $this->accountPrivileges->rolesOf($account)
            : ['Neos.Flow:Everybody' => $this->policyService->getRole('Neos.Flow:Everybody')];
    }
}
