<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\Api\Endpoint\Nodes\Parameter\HierarchyFilter;
use Neos\Api\Endpoint\Nodes\Parameter\HierarchyFilterType;
use Neos\Api\Endpoint\Nodes\Parameter\NodeAggregateIds;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\PropertyCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\ReferenceFilter;
use Neos\Api\Endpoint\Nodes\Parameter\SearchTerm;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;

/**
 * The query of listNodes in the content repository's terms: the filters, search, sort, offset and limit of the request as the
 * filter of the subgraph query its entry point needs, the child nodes or descendant nodes (filterByHierarchy), the back
 * references (filterByReference), descendant nodes without an entry point. filterByNodeAggregateId is a query of its own,
 * the nodes looked up one by one (findByNodeAggregateIds())
 */
final readonly class NodeQuery
{
    /**
     * The sort fields besides properties.<name>, as the node's timestamps
     */
    private const array SORT_TIMESTAMPS = [
        'timestamps.created' => Filter\Ordering\TimestampField::CREATED,
        'timestamps.lastModified' => Filter\Ordering\TimestampField::LAST_MODIFIED,
        'timestamps.originalCreated' => Filter\Ordering\TimestampField::ORIGINAL_CREATED,
        'timestamps.originalLastModified' => Filter\Ordering\TimestampField::ORIGINAL_LAST_MODIFIED,
    ];

    private function __construct(
        private ?HierarchyFilter $hierarchy,
        private ?ReferenceFilter $reference,
        private ?NodeAggregateIds $nodeAggregateIds,
        private ?Filter\NodeType\NodeTypeCriteria $nodeTypes,
        private ?Filter\SearchTerm\SearchTerm $searchTerm,
        private ?Filter\PropertyValue\Criteria\PropertyValueCriteriaInterface $propertyValue,
        private ?Filter\Ordering\Ordering $ordering,
        private Filter\Pagination\Pagination $pagination,
        private Offset $offset,
        private Limit $limit,
    ) {
    }

    /**
     * The query, a 400 if both filterByHierarchy and filterByReference are given, filterByNodeAggregateId with any of
     * them, filterByProperty, search or sort, or the sort, filterByProperty or the syntax of filterByNodeType is
     * invalid. The node types are checked by find(), they are the ones of the entry point's content repository
     */
    public static function create(
        ?HierarchyFilter $hierarchy,
        ?ReferenceFilter $reference,
        ?NodeTypeCriteria $nodeTypes,
        ?NodeAggregateIds $nodeAggregateIds,
        ?PropertyCriteria $properties,
        ?SearchTerm $search,
        ?Sort $sort,
        Offset $offset,
        Limit $limit,
    ): self|BadRequest {
        if ($hierarchy !== null && $reference !== null) {
            return BadRequest::because('filterByHierarchy and filterByReference can\'t be combined');
        }
        if ($nodeAggregateIds !== null) {
            $others = array_keys(array_filter(['filterByHierarchy' => $hierarchy, 'filterByReference' => $reference, 'filterByProperty' => $properties, 'search' => $search, 'sort' => $sort]));
            if ($others !== []) {
                return BadRequest::because(sprintf('filterByNodeAggregateId can\'t be combined with %s', implode(', ', $others)));
            }
        }
        $invalidSort = self::invalidSort($sort);
        if ($invalidSort !== null) {
            return $invalidSort;
        }
        try {
            $propertyValue = $properties !== null ? Filter\PropertyValue\PropertyValueCriteriaParser::parse($properties->value) : null;
        } catch (\InvalidArgumentException $exception) {
            return BadRequest::because(sprintf('filterByProperty is invalid: %s', $exception->getMessage()));
        }
        try {
            $nodeTypeCriteria = $nodeTypes?->toNodeTypeCriteria();
        } catch (\InvalidArgumentException $exception) {
            // e.g. an empty node type name in "!" or "Neos.Neos:Document,"
            return BadRequest::because(sprintf('filterByNodeType is invalid: %s', $exception->getMessage()));
        }
        return new self(
            $hierarchy,
            $reference,
            $nodeAggregateIds,
            $nodeTypeCriteria,
            $search !== null ? Filter\SearchTerm\SearchTerm::fulltext($search->value) : null,
            $propertyValue,
            self::ordering($sort),
            Filter\Pagination\Pagination::fromLimitAndOffset($limit->value, $offset->value),
            $offset,
            $limit,
        );
    }

    /**
     * Whether the nodes are selected by their ids (filterByNodeAggregateId): no entry point, findByNodeAggregateIds()
     * instead of find()
     */
    public function selectsByNodeAggregateId(): bool
    {
        return $this->nodeAggregateIds !== null;
    }

    /**
     * The page of the nodes of filterByNodeAggregateId found in the subgraph, in the order of the ids, and the total;
     * ids of nodes the subgraph doesn't have, or the account may not read, are left out. A 400 if a node type of
     * filterByNodeType doesn't exist
     *
     * @return array{list<ContentGraph\Node>, int}|BadRequest
     */
    public function findByNodeAggregateIds(ContentSubgraphInterface $subgraph, NodeTypeManager $nodeTypeManager): array|BadRequest
    {
        $unknownNodeTypes = $this->nodeTypes !== null ? self::unknownNodeTypes($this->nodeTypes, $nodeTypeManager) : [];
        if ($unknownNodeTypes !== []) {
            return BadRequest::because(sprintf('There is no node type %s', implode(', ', $unknownNodeTypes)));
        }
        $nodeTypes = $this->nodeTypes !== null ? Filter\NodeType\ExpandedNodeTypeCriteria::create($this->nodeTypes, $nodeTypeManager) : null;
        $nodes = [];
        foreach ($this->nodeAggregateIds?->toNodeAggregateIds() ?? [] as $nodeAggregateId) {
            $node = $subgraph->findNodeById($nodeAggregateId);
            if ($node !== null && ($nodeTypes === null || $nodeTypes->matches($node->nodeTypeName))) {
                $nodes[] = $node;
            }
        }
        return [array_slice($nodes, $this->offset->value, $this->limit->value), count($nodes)];
    }

    /**
     * The aggregate id of the node to start from, null for the default site node
     */
    public function entryPoint(): ?NodeAggregateId
    {
        return $this->hierarchy?->nodeAggregateId ?? $this->reference?->nodeAggregateId;
    }

    /**
     * The page of nodes found from the entry point and the total, a 400 if a node type of filterByNodeType doesn't
     * exist
     *
     * @param ContentGraph\Node $entryPoint the node of entryPoint(), else the default site node
     * @param ContentSubgraphInterface $subgraph the one the entry point was found in
     * @return array{list<ContentGraph\Node>, int}|BadRequest
     */
    public function find(ContentGraph\Node $entryPoint, ContentSubgraphInterface $subgraph, NodeTypeManager $nodeTypeManager): array|BadRequest
    {
        $unknownNodeTypes = $this->nodeTypes !== null ? self::unknownNodeTypes($this->nodeTypes, $nodeTypeManager) : [];
        if ($unknownNodeTypes !== []) {
            return BadRequest::because(sprintf('There is no node type %s', implode(', ', $unknownNodeTypes)));
        }
        if ($this->hierarchy?->type === HierarchyFilterType::CHILD_OF) {
            $childNodesFilter = Filter\FindChildNodesFilter::create($this->nodeTypes, $this->searchTerm, $this->propertyValue, $this->ordering, $this->pagination);
            return [
                iterator_to_array($subgraph->findChildNodes($entryPoint->aggregateId, $childNodesFilter), false),
                $subgraph->countChildNodes($entryPoint->aggregateId, Filter\CountChildNodesFilter::fromFindChildNodesFilter($childNodesFilter)),
            ];
        }
        if ($this->reference !== null) {
            $backReferencesFilter = Filter\FindBackReferencesFilter::create(
                nodeTypes: $this->nodeTypes,
                nodeSearchTerm: $this->searchTerm,
                nodePropertyValue: $this->propertyValue,
                referenceName: $this->reference->name,
                ordering: $this->ordering,
                pagination: $this->pagination,
            );
            return [
                array_map(
                    static fn (ContentGraph\Reference $reference) => $reference->node,
                    iterator_to_array($subgraph->findBackReferences($entryPoint->aggregateId, $backReferencesFilter), false),
                ),
                $subgraph->countBackReferences($entryPoint->aggregateId, Filter\CountBackReferencesFilter::fromFindBackReferencesFilter($backReferencesFilter)),
            ];
        }
        // filterByHierarchy with type descendantOf, or the default site node without an entry point
        $descendantNodesFilter = Filter\FindDescendantNodesFilter::create($this->nodeTypes, $this->searchTerm, $this->propertyValue, $this->ordering, $this->pagination);
        return [
            iterator_to_array($subgraph->findDescendantNodes($entryPoint->aggregateId, $descendantNodesFilter), false),
            $subgraph->countDescendantNodes($entryPoint->aggregateId, Filter\CountDescendantNodesFilter::fromFindDescendantNodesFilter($descendantNodesFilter)),
        ];
    }

    /**
     * The node types of the criteria the node type manager doesn't know, e.g. for a 400
     *
     * @return list<string>
     */
    public static function unknownNodeTypes(Filter\NodeType\NodeTypeCriteria $nodeTypes, NodeTypeManager $nodeTypeManager): array
    {
        return array_values(array_filter(
            [...$nodeTypes->explicitlyAllowedNodeTypeNames->toStringArray(), ...$nodeTypes->explicitlyDisallowedNodeTypeNames->toStringArray()],
            static fn (string $nodeTypeName) => !$nodeTypeManager->hasNodeType($nodeTypeName),
        ));
    }

    private static function invalidSort(?Sort $sort): ?BadRequest
    {
        $unknown = array_filter(
            array_column($sort?->fields() ?? [], 'field'),
            static fn (string $field) => !isset(self::SORT_TIMESTAMPS[$field]) && preg_match('/^properties\.[a-zA-Z0-9_]+$/', $field) !== 1,
        );
        return $unknown !== [] ? BadRequest::because(sprintf('Can\'t sort by %s, only by properties.<name> and %s', implode(', ', $unknown), implode(', ', array_keys(self::SORT_TIMESTAMPS)))) : null;
    }

    private static function ordering(?Sort $sort): ?Filter\Ordering\Ordering
    {
        $ordering = null;
        foreach ($sort?->fields() ?? [] as ['field' => $field, 'descending' => $descending]) {
            $direction = $descending ? Filter\Ordering\OrderingDirection::DESCENDING : Filter\Ordering\OrderingDirection::ASCENDING;
            $timestampField = self::SORT_TIMESTAMPS[$field] ?? null;
            $ordering = match (true) {
                $timestampField !== null && $ordering === null => Filter\Ordering\Ordering::byTimestampField($timestampField, $direction),
                $timestampField !== null => $ordering->andByTimestampField($timestampField, $direction),
                $ordering === null => Filter\Ordering\Ordering::byProperty(PropertyName::fromString(substr($field, strlen('properties.'))), $direction),
                default => $ordering->andByProperty(PropertyName::fromString(substr($field, strlen('properties.'))), $direction),
            };
        }
        return $ordering;
    }
}
