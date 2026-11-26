<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Neos\ContentGraph\DoctrineDbalAdapter\ContentGraphReadModelAdapter;
use Neos\ContentGraph\DoctrineDbalAdapter\ContentGraphTableNames;
use Neos\ContentGraph\DoctrineDbalAdapter\HierarchyRelationSubquery;
use Neos\ContentGraph\DoctrineDbalAdapter\NodeQueryBuilder;
use Neos\ContentGraph\DoctrineDbalAdapter\SqlTableSubqueryFactory;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\ExpandedNodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\Ordering;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingDirection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\TimestampField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\AndCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\OrCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueContains;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueCriteriaInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEndsWith;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEquals;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueStartsWith;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Dbal\DbalSchemaFactory;
use Neos\ContentRepository\Dbal\Query\QueryBuilder;

/**
 * The descendant nodes of a node that match a filter, found without walking the tree below it
 *
 * findDescendantNodes() walks down from the entry point with a recursive query that carries every node's properties
 * along, and filters only at the end: its cost is the size of the whole subtree, about 2s for a site of 25,000 nodes
 * with large properties, however few nodes match. This finds the matching nodes of the subgraph first, then keeps the
 * ones that have the entry point among their ancestors, looked up level by level for the distinct parents of the
 * matches. Its cost is the matches and their ancestors.
 *
 * Same nodes, same total, same order as findDescendantNodes(): the filter is the adapter's own (NodeQueryBuilder), the
 * order the filter's ordering, ranked by the database with the adapter's expressions, then level and position below
 * the entry point, ties broken by node aggregate id. A content repository that doesn't use the Doctrine DBAL adapter is
 * left to findDescendantNodes(), find() is null for it.
 *
 * The adapter's classes are @internal in Neos 9, so they stay in this class (built with FlatDescendantNodesFactory)
 */
final readonly class FlatDescendantNodes implements ContentRepositoryServiceInterface
{
    /**
     * Levels the ancestors are looked up for at most, a cycle in a broken graph ends there instead of never
     */
    private const int MAX_DEPTH = 1000;

    public function __construct(
        private Connection $dbal,
        private ContentGraphReadModelInterface $contentGraphReadModel,
        private NodeTypeManager $nodeTypeManager,
    ) {
    }

    /**
     * The page of the descendants of the entry point that match the filter and their total, as findDescendantNodes()
     * and countDescendantNodes() find them, null if this can't find them (see the class)
     *
     * @return array{list<Node>, int}|null
     */
    public function find(ContentSubgraphInterface $subgraph, NodeAggregateId $entryPoint, FindDescendantNodesFilter $filter): array|null
    {
        if (!$this->contentGraphReadModel instanceof ContentGraphReadModelAdapter) {
            return null;
        }
        $tableNames = ContentGraphTableNames::create($subgraph->getContentRepositoryId());
        $hierarchyRelations = SqlTableSubqueryFactory::for($tableNames)
            ->forHierarchyRelation($this->contentGraphReadModel->getContentGraph($subgraph->getWorkspaceName())->contentStreamLayers)
            ->withDimensionSpacePoint($subgraph->getDimensionSpacePoint());

        $entryPointAnchor = $this->anchorOf($entryPoint, $hierarchyRelations, $tableNames);
        if ($entryPointAnchor === null) {
            return [[], 0];
        }
        $matches = $this->matches($subgraph, $filter, $hierarchyRelations, $tableNames);
        $levels = $this->levelsBelow($entryPointAnchor, array_column($matches, 'parentnodeanchor'), $hierarchyRelations);

        $descendants = [];
        foreach ($matches as $match) {
            $parentLevel = $levels[(int)$match['parentnodeanchor']] ?? null;
            if ($parentLevel !== null) {
                $descendants[] = [(int)($match['sortrank'] ?? 0), $parentLevel + 1, (int)$match['position'], (string)$match['nodeaggregateid']];
            }
        }
        // as findDescendantNodes(): by the ordering, level, then position, the id makes the order of equal ones stable
        // across pages
        usort($descendants, static fn (array $a, array $b) => $a <=> $b);

        $page = $filter->pagination !== null
            ? array_slice($descendants, $filter->pagination->offset, $filter->pagination->limit)
            : $descendants;
        $ids = array_column($page, 3);
        if ($ids === []) {
            return [[], count($descendants)];
        }
        $nodesById = [];
        foreach ($subgraph->findNodesByIds(NodeAggregateIds::fromArray($ids)) as $node) {
            $nodesById[$node->aggregateId->value] = $node;
        }
        $nodes = array_values(array_filter(array_map(static fn (string $id) => $nodesById[$id] ?? null, $ids)));
        return [$nodes, count($descendants)];
    }

    /**
     * The nodes of the subgraph that match the filter's node types, search term and property values, wherever they are,
     * with their parent, position and, with an ordering, their rank in it
     *
     * @return list<array{parentnodeanchor: int|string, position: int|string|null, nodeaggregateid: string, sortrank?: int|string}>
     */
    private function matches(ContentSubgraphInterface $subgraph, FindDescendantNodesFilter $filter, HierarchyRelationSubquery $hierarchyRelations, ContentGraphTableNames $tableNames): array
    {
        $nodeQueryBuilder = new NodeQueryBuilder($this->dbal, $tableNames);
        $queryBuilder = $nodeQueryBuilder->buildBasicNodeQuery($hierarchyRelations, 'n', 'h.parentnodeanchor, h.position, n.nodeaggregateid');
        // as ContentSubgraph::addSubtreeTagConstraints(): a node's relation carries the tags it inherits as well
        $i = 0;
        foreach ($subgraph->getVisibilityConstraints()->excludedSubtreeTags as $excludedTag) {
            $i++;
            $queryBuilder->andWhere('NOT JSON_CONTAINS_PATH(h.subtreetags, \'one\', :excludedTag' . $i . ')')
                ->setParameter('excludedTag' . $i, '$."' . $excludedTag->value . '"');
        }
        if ($filter->nodeTypes !== null) {
            $nodeQueryBuilder->addNodeTypeCriteria($queryBuilder, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager));
        }
        if ($filter->searchTerm !== null) {
            $nodeQueryBuilder->addSearchTermConstraints($queryBuilder, $filter->searchTerm);
        }
        if ($filter->propertyValue !== null) {
            // before the adapter's constraint, which MariaDB then only evaluates for the nodes that pass this one
            $prefilter = $this->dbal->getDatabasePlatform() instanceof MariaDBPlatform ? $this->prefilter($queryBuilder, $filter->propertyValue) : null;
            if ($prefilter !== null) {
                $queryBuilder->andWhere($prefilter);
            }
            $nodeQueryBuilder->addPropertyValueConstraints($queryBuilder, $filter->propertyValue);
        }
        if ($filter->ordering !== null) {
            $queryBuilder->addSelect('DENSE_RANK() OVER (ORDER BY ' . $this->orderBy($filter->ordering, $nodeQueryBuilder) . ') AS sortrank');
        }
        /** @var list<array{parentnodeanchor: int|string, position: int|string|null, nodeaggregateid: string, sortrank?: int|string}> */
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * The ORDER BY of the ordering, as ContentSubgraph::applyOrdering() has it
     */
    private function orderBy(Ordering $ordering, NodeQueryBuilder $nodeQueryBuilder): string
    {
        $fields = [];
        foreach ($ordering as $orderingField) {
            $expression = $orderingField->field instanceof PropertyName
                ? $nodeQueryBuilder->extractPropertyValue($orderingField->field, 'n')
                : 'n.' . match ($orderingField->field) {
                    TimestampField::CREATED => 'created',
                    TimestampField::ORIGINAL_CREATED => 'originalcreated',
                    TimestampField::LAST_MODIFIED => 'lastmodified',
                    TimestampField::ORIGINAL_LAST_MODIFIED => 'originallastmodified',
                };
            $fields[] = $expression . ' ' . ($orderingField->direction === OrderingDirection::DESCENDING ? 'DESC' : 'ASC');
        }
        return implode(', ', $fields);
    }

    /**
     * A cheaper condition every node passes that matches the property value criteria, null if there is none
     *
     * The adapter compares strings with JSON_SEARCH(), which reads a node's properties up to the end, all of them, for
     * each node. JSON_VALUE() stops at the property. It is null for a value that isn't a scalar, e.g. an array of
     * strings JSON_SEARCH() looks into, so those pass, and it is case-insensitive, so it passes all the matches of a
     * case-sensitive criterion as well. The pattern is the adapter's, wildcards in the value included.
     */
    private function prefilter(QueryBuilder $queryBuilder, PropertyValueCriteriaInterface $criteria): ?string
    {
        if ($criteria instanceof AndCriteria) {
            $prefilters = array_filter([$this->prefilter($queryBuilder, $criteria->criteria1), $this->prefilter($queryBuilder, $criteria->criteria2)]);
            return $prefilters !== [] ? '(' . implode(' AND ', $prefilters) . ')' : null;
        }
        if ($criteria instanceof OrCriteria) {
            $prefilter1 = $this->prefilter($queryBuilder, $criteria->criteria1);
            $prefilter2 = $this->prefilter($queryBuilder, $criteria->criteria2);
            return $prefilter1 !== null && $prefilter2 !== null ? '(' . $prefilter1 . ' OR ' . $prefilter2 . ')' : null;
        }
        [$propertyName, $pattern] = match (true) {
            $criteria instanceof PropertyValueContains => [$criteria->propertyName, '%' . $criteria->value . '%'],
            $criteria instanceof PropertyValueStartsWith => [$criteria->propertyName, $criteria->value . '%'],
            $criteria instanceof PropertyValueEndsWith => [$criteria->propertyName, '%' . $criteria->value],
            $criteria instanceof PropertyValueEquals && is_string($criteria->value) => [$criteria->propertyName, $criteria->value],
            default => [null, null],
        };
        if (!$propertyName instanceof PropertyName || $pattern === null) {
            return null;
        }
        $parameterName = 'prefilter' . bin2hex(random_bytes(6));
        $queryBuilder->setParameter($parameterName, $pattern);
        $value = 'JSON_VALUE(n.properties, ' . $this->dbal->quote('$.' . json_encode($propertyName->value, JSON_THROW_ON_ERROR) . '.value') . ')';
        return '(' . $value . ' COLLATE ' . DbalSchemaFactory::DEFAULT_MYSQL_COLLATION . ' LIKE :' . $parameterName . ' COLLATE ' . DbalSchemaFactory::DEFAULT_MYSQL_COLLATION . ' OR ' . $value . ' IS NULL)';
    }

    /**
     * The relation anchor point of the node in the subgraph, null if it has none there
     */
    private function anchorOf(NodeAggregateId $nodeAggregateId, HierarchyRelationSubquery $hierarchyRelations, ContentGraphTableNames $tableNames): ?int
    {
        $anchor = (new NodeQueryBuilder($this->dbal, $tableNames))
            ->buildBasicNodeQuery($hierarchyRelations, 'n', 'n.relationanchorpoint')
            ->andWhere('n.nodeaggregateid = :entryPoint')
            ->setParameter('entryPoint', $nodeAggregateId->value)
            ->executeQuery()
            ->fetchOne();
        return $anchor !== false ? (int)$anchor : null;
    }

    /**
     * The level below the entry point of each of the nodes that is a descendant of it, the entry point itself at 0;
     * the others are left out
     *
     * @param list<int|string> $anchors
     * @return array<int, int>
     */
    private function levelsBelow(int $entryPointAnchor, array $anchors, HierarchyRelationSubquery $hierarchyRelations): array
    {
        $parents = [];
        $unknown = array_values(array_diff(array_unique(array_map(intval(...), $anchors)), [$entryPointAnchor]));
        for ($depth = 0; $unknown !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $found = QueryBuilder::createForConnection($this->dbal)
                ->select('h.childnodeanchor, h.parentnodeanchor')
                ->fromTableSubquery($hierarchyRelations, 'h')
                ->andWhere('h.childnodeanchor IN (:anchors)')
                ->setParameter('anchors', $unknown, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllKeyValue();
            $next = [];
            foreach ($unknown as $anchor) {
                // a node without a parent here is the root, or not in the subgraph
                $parent = isset($found[$anchor]) ? (int)$found[$anchor] : null;
                $parents[$anchor] = $parent;
                if ($parent !== null && $parent !== $entryPointAnchor && !array_key_exists($parent, $parents)) {
                    $next[$parent] = $parent;
                }
            }
            $unknown = array_values($next);
        }

        $levels = [$entryPointAnchor => 0];
        foreach (array_keys($parents) as $anchor) {
            $path = [];
            $current = $anchor;
            while ($current !== null && !array_key_exists($current, $levels) && count($path) <= self::MAX_DEPTH) {
                $path[] = $current;
                $current = $parents[$current] ?? null;
            }
            $level = $current !== null ? ($levels[$current] ?? null) : null;
            foreach (array_reverse($path) as $node) {
                $level = $level !== null ? $level + 1 : null;
                $levels[$node] = $level;
            }
        }
        return array_filter($levels, static fn (?int $level) => $level !== null);
    }
}
