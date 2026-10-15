<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Nodes\Domain\NodeQuery;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Parameter\ChangeTypes;
use Neos\Api\Endpoint\Workspaces\Schema\Change;
use Neos\Api\Endpoint\Workspaces\Schema\ChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedChangeListing;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType;
use Neos\Neos\PendingChangesProjection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The query of listWorkspaceChanges: filters, include, offset and limit applied to the pending changes
 *
 * The projection reads all changes at once, has no order and can't check permissions or filter, so all of that is done
 * here in PHP: the changes of nodes the account may not read are left out, the others located (ChangeLocator, cached,
 * most changes share a few documents), filtered, sorted by document, node and dimension space point, so the changes
 * of one document are together, and paged. The diff and the included nodes are read for the page only. Only
 * Schema\Change and ChangeDiffer read the projection's changes
 */
final readonly class ChangeQuery
{
    /**
     * What listWorkspaceChanges can include
     */
    private const array INCLUDE_PATHS = ['diff', ...IncludedNodes::PATHS];

    private function __construct(
        private NodeAggregateId|null $document,
        private NodeAggregateId|null $site,
        private ChangeTypes|null $changeTypes,
        private NodeType\NodeTypeCriteria|null $nodeTypes,
        private IncludePaths $include,
        private Offset $offset,
        private Limit $limit,
    ) {
    }

    /**
     * The query, a 400 for an include it can't include or invalid node types
     */
    public static function create(NodeAggregateId|null $filterByDocument, NodeAggregateId|null $filterBySite, ChangeTypes|null $filterByChangeType, NodeTypeCriteria|null $filterByNodeType, IncludePaths $include, Offset $offset, Limit $limit): self|BadRequest
    {
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        try {
            $nodeTypes = $filterByNodeType?->toNodeTypeCriteria();
        } catch (\InvalidArgumentException $exception) {
            return BadRequest::because(sprintf('filterByNodeType is invalid: %s', $exception->getMessage()));
        }
        return new self($filterByDocument, $filterBySite, $filterByChangeType, $nodeTypes, $include, $offset, $limit);
    }

    /**
     * What to include of the nodes, see IncludedNodes
     */
    public function include(): IncludePaths
    {
        return $this->include;
    }

    /**
     * The page, a 400 if a node type of filterByNodeType doesn't exist in the workspace's content repository
     *
     * @param iterable<PendingChangesProjection\Change> $pendingChanges the workspace's
     */
    public function page(ServerRequestInterface $request, iterable $pendingChanges, ReadableWorkspace $workspace, ChangeDiffer $changeDiffer, IncludedNodes $includedNodes): PaginatedChangeListing|BadRequest
    {
        $nodeTypeManager = $workspace->contentRepository->getNodeTypeManager();
        $unknownNodeTypes = $this->nodeTypes !== null ? NodeQuery::unknownNodeTypes($this->nodeTypes, $nodeTypeManager) : [];
        if ($unknownNodeTypes !== []) {
            return BadRequest::because(sprintf('There is no node type %s (filterByNodeType)', implode(', ', $unknownNodeTypes)));
        }
        $nodeTypes = $this->nodeTypes !== null ? NodeType\ExpandedNodeTypeCriteria::create($this->nodeTypes, $nodeTypeManager) : null;
        $locator = new ChangeLocator($workspace);
        $changes = [];
        foreach ($pendingChanges as $pendingChange) {
            if (!$workspace->mayReadNodeAggregate($pendingChange->nodeAggregateId)) {
                continue;
            }
            // located for every change: the filters and the order need the documents
            $change = Change::from($pendingChange, $locator);
            if (
                ($this->changeTypes !== null && !$this->changeTypes->matches($change))
                || ($this->document !== null && $change->documentAggregateId?->value !== $this->document->value)
                || ($this->site !== null && $change->siteAggregateId?->value !== $this->site->value)
                || ($nodeTypes !== null && !self::matches($nodeTypes, $locator->nodeTypeName($pendingChange->nodeAggregateId)))
            ) {
                continue;
            }
            $changes[] = [$change, $pendingChange];
        }
        usort($changes, static fn (array $a, array $b) => self::sortKey($a[0]) <=> self::sortKey($b[0]));
        $withDiff = $this->include->includes('diff');
        $page = [];
        foreach (array_slice($changes, $this->offset->value, $this->limit->value) as [$change, $pendingChange]) {
            $dimensionSpacePoint = $pendingChange->originDimensionSpacePoint?->toDimensionSpacePoint();
            $page[] = $change->withIncluded(
                $withDiff ? $changeDiffer->diff($workspace, $pendingChange) : null,
                $includedNodes->nodeOf($pendingChange->nodeAggregateId, $dimensionSpacePoint),
                $includedNodes->documentById($change->documentAggregateId?->toNodeAggregateId(), $dimensionSpacePoint),
            );
        }
        return new PaginatedChangeListing(
            new ChangeList(...$page),
            new ListingMeta(count($changes)),
            ListingLinks::for($request, $this->offset, $this->limit, count($changes)),
        );
    }

    private static function matches(NodeType\ExpandedNodeTypeCriteria $nodeTypes, NodeTypeName|null $nodeTypeName): bool
    {
        return $nodeTypeName !== null && $nodeTypes->matches($nodeTypeName);
    }

    /**
     * The document, then the node and its dimension space point (aggregate-wide changes first); changes without a
     * document last
     *
     * @return array{bool, string, string, string}
     */
    private static function sortKey(Change $change): array
    {
        return [
            $change->documentAggregateId === null,
            $change->documentAggregateId->value ?? '',
            $change->nodeAggregateId->value,
            $change->originDimensionSpacePoint->value ?? '',
        ];
    }
}
