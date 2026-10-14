<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\DimensionSpacePointFinder;
use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;

/**
 * The subgraph a request reads nodes from: its contentRepositoryId, workspaceName and dimensionSpacePoint, the
 * workspace live and the point DimensionSpacePointFinder::findDefault() by default, as 400s and 404s where they fail.
 * Used by Nodes, Views and DataSources, so their errors are the same
 */
#[Flow\Scope('singleton')]
final readonly class SubgraphResolver
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentSubgraphs $contentSubgraphs,
        private DimensionSpacePointFinder $dimensionSpacePointFinder,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * The subgraph as the account sees it (see ContentSubgraphs::find()), the workspace live if omitted. A 400 if the
     * dimension space point is invalid, not one of the content repository or can't be defaulted, a 404 if there is
     * no such content repository or workspace the account may read
     *
     * @param string|null $withoutContentRepository the 404's detail if contentRepositoryId is omitted and there is no
     *     default site to take it from, null if it is required
     * @param bool $includeRemoved find soft removed nodes as well, see ContentSubgraphs::find()
     */
    public function resolve(?ContentRepositoryId $contentRepositoryId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled, ?string $withoutContentRepository = null, bool $includeRemoved = false): ContentSubgraphInterface|BadRequest|NotFound
    {
        // a syntax error is a 400 before anything is looked up
        try {
            $point = $dimensionSpacePoint?->toDimensionSpacePoint();
        } catch (\InvalidArgumentException | \RuntimeException | \TypeError $exception) {
            return BadRequest::because(sprintf('dimensionSpacePoint is invalid: %s', $exception->getMessage()));
        }
        $id = $contentRepositoryId?->toContentRepositoryId() ?? $this->siteFinder->findDefault()?->getConfiguration()->contentRepositoryId;
        if ($id === null) {
            return NotFound::because($withoutContentRepository ?? 'There is no default site to take the content repository from, give contentRepositoryId');
        }
        $contentRepository = $this->contentRepositoryFinder->find($id);
        if ($contentRepository === null) {
            return NotFound::contentRepository($id->value);
        }
        $point ??= $this->dimensionSpacePointFinder->findDefault($contentRepository);
        if ($point === null) {
            return BadRequest::because(sprintf('The content repository %s has no site whose default dimension space point applies, give dimensionSpacePoint', $id->value));
        }
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($point)) {
            return BadRequest::because(sprintf('There is no dimension space point %s in the content repository %s, GET /cr/%2$s lists its dimensions and their values', $point->toJson(), $id->value));
        }
        $workspace = $workspaceName?->toWorkspaceName() ?? SharedModel\Workspace\WorkspaceName::forLive();
        return $this->contentSubgraphs->findIn($contentRepository, $workspace, $point, $excludeDisabled, $includeRemoved)
            ?? NotFound::because(sprintf('There is no workspace %s in the content repository %s', $workspace->value, $id->value));
    }

    /**
     * The node in the subgraph of resolve(), see there and resolveNodeIn()
     */
    public function resolveNode(?ContentRepositoryId $contentRepositoryId, SharedModel\Node\NodeAggregateId $nodeAggregateId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint, bool $excludeDisabled, ?string $withoutContentRepository = null, bool $includeRemoved = false): Node|BadRequest|NotFound
    {
        $subgraph = $this->resolve($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $excludeDisabled, $withoutContentRepository, $includeRemoved);
        return $subgraph instanceof ContentSubgraphInterface ? $this->resolveNodeIn($subgraph, $nodeAggregateId) : $subgraph;
    }

    /**
     * The node in the subgraph, a 404 if there is none the account may read
     */
    public function resolveNodeIn(ContentSubgraphInterface $subgraph, SharedModel\Node\NodeAggregateId $nodeAggregateId): Node|NotFound
    {
        return $subgraph->findNodeById($nodeAggregateId)
            ?? NotFound::because(sprintf(
                'There is no node %s in the workspace %s and the dimension space point %s',
                $nodeAggregateId->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
    }
}
