<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Domain\NodeMoveCommand;
use Neos\Api\Endpoint\Nodes\Domain\NodeQuery;
use Neos\Api\Endpoint\Nodes\Domain\NodeRestoration;
use Neos\Api\Endpoint\Nodes\Domain\NodeVariation;
use Neos\Api\Endpoint\Nodes\Domain\SubgraphResolver;
use Neos\Api\Endpoint\Nodes\Parameter\HierarchyFilter;
use Neos\Api\Endpoint\Nodes\Parameter\NodeAggregateIds;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\PropertyCriteria;
use Neos\Api\Endpoint\Nodes\Parameter\ReferenceFilter;
use Neos\Api\Endpoint\Nodes\Parameter\SearchTerm;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeCopy;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeCreate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeMove;
use Neos\Api\Endpoint\Nodes\RequestBody\NodePropertiesUpdate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeReferencesUpdate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeTypeChange;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeUriPathSegmentGenerate;
use Neos\Api\Endpoint\Nodes\RequestBody\NodeVariantCreate;
use Neos\Api\Endpoint\Nodes\Response\NodeCreated;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePointList;
use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Endpoint\Nodes\Schema\NodeVariantListing;
use Neos\Api\Endpoint\Nodes\Schema\NodeVariantListingMeta;
use Neos\Api\Endpoint\Nodes\Schema\PaginatedNodeListing;
use Neos\Api\Endpoint\Nodes\Schema\SubtreeTag;
use Neos\Api\Endpoint\Nodes\Schema\UriPathSegment;
use Neos\Api\Endpoint\Workspaces\Domain\ReadableWorkspace;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Endpoint\Workspaces\Domain\WorkspaceResolver;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\InvalidPropertyValue;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Infrastructure\ContentRepository\PropertyValues;
use Neos\Api\Infrastructure\ContentRepository\UriPathSegments;
use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeCreation\Dto\NodeAggregateIdsByNodePaths;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeVariation\Exception\DimensionSpacePointIsAlreadyOccupied;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Dto\NodeAggregateTypeChangeChildConstraintConflictResolutionMarkWithTagStrategy;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Exception\SubtreeIsAlreadyTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Exception\SubtreeIsNotTagged;
use Neos\ContentRepository\Core\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Exception\DimensionSpacePointIsNotYetOccupied;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyExists;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePointSet;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsDescendant;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsNoChild;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsNoSibling;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsRoot;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsTethered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateIsUntethered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeConstraintException;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeNameIsAlreadyCovered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeIsAbstract;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeIsOfTypeRoot;
use Neos\ContentRepository\Core\SharedModel\Exception\PropertyCannotBeSet;
use Neos\ContentRepository\Core\SharedModel\Exception\ReferenceCannotBeSet;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Exception\TetheredNodesCannotBePartiallyCopied;
use Neos\Neos\Domain\Service\NodeDuplication\NodeAggregateIdMapping;
use Neos\Neos\Domain\Service\NodeDuplicationService;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\Schematic\Serialization\Serializer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The nodes of the content repositories, read directly, without Fusion
 */
final readonly class Nodes
{
    /**
     * The include paths, as JSON:API's: a path includes the ones it goes through, children.references includes children
     */
    private const array INCLUDE_PATHS = ['references', 'references.node', 'children', 'children.references', 'children.hasChildren', 'variants', 'variants.references', 'ancestors', 'hasChildren'];

    /**
     * The include paths of listNodeVariants: variants have no variants, and their ancestors are the node's
     */
    private const array VARIANT_INCLUDE_PATHS = ['references', 'children', 'children.references'];

    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private ContentSubgraphs $contentSubgraphs,
        private SubgraphResolver $subgraphResolver,
        private SiteFinder $siteFinder,
        private NodeSerializer $nodeSerializer,
        private PropertyValues $propertyValues,
        private UriPathSegments $uriPathSegments,
        private WorkspaceResolver $workspaceResolver,
        private NodeVariation $nodeVariation,
        private NodeRestoration $nodeRestoration,
        private NodeMoveCommand $nodeMoveCommand,
        private NodeDuplicationService $nodeDuplicationService,
    ) {
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes',
        method: 'GET',
        summary: 'List nodes',
        description: 'A page of the nodes in the workspace and dimension space point: with filterByHierarchy the nodes below a node (type childOf its direct child nodes, in their order unless sorted, type descendantOf all of them), with filterByReference the nodes that reference a node, a node once per reference. The two can\'t be combined, each is a query of its own. With filterByNodeAggregateId the nodes with these ids, in their order, e.g. to read the labels of several nodes in one request: ids of nodes the workspace and dimension space point don\'t have, or the account may not read, are left out (no 404), filterByNodeType applies, filterByHierarchy, filterByReference, filterByProperty, search and sort can\'t be combined with it. Without any of them, all nodes below the site node of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name). filterByNodeType, filterByProperty and search narrow them down. sort takes properties.<name> and timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified. offset and limit (25 by default, 100 at most) choose the page, meta.total and links tell about the others. include, hasChildrenOfNodeType and includeRemoved work as in getNode, for each node. Hidden nodes are visible as in getNode.',
        operationId: 'listNodes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function list(
        ContentRepositoryId $contentRepositoryId,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The workspace to read the nodes in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the nodes in, as JSON. If omitted, the default one of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name), the only one of a content repository without a site')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'The nodes below a node: filterByHierarchy[type] childOf (its direct child nodes) or descendantOf (all nodes below it) and filterByHierarchy[nodeAggregateId]. Not with filterByReference')]
        HierarchyFilter|null $filterByHierarchy = null,
        #[Parameter(in: 'query', description: 'The nodes that reference a node: filterByReference[nodeAggregateId], optionally filterByReference[name] for the references of that name only. Not with filterByHierarchy')]
        ReferenceFilter|null $filterByReference = null,
        #[Parameter(in: 'query', description: 'Only nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type and the ones inheriting from it. Unknown node types are a 400')]
        NodeTypeCriteria|null $filterByNodeType = null,
        #[Parameter(in: 'query', description: 'The nodes with these aggregate ids, comma-separated, at most 100, in their order (limit applies, up to 100), the ones not found or not readable left out. Not with filterByHierarchy, filterByReference, filterByProperty, search or sort')]
        NodeAggregateIds|null $filterByNodeAggregateId = null,
        #[Parameter(in: 'query', description: 'Only nodes whose properties match, in the content repository\'s syntax, e.g. title *= \'Neos\' AND NOT (hideInMenu = true)')]
        PropertyCriteria|null $filterByProperty = null,
        #[Parameter(in: 'query', description: 'Only nodes with a property containing this text')]
        SearchTerm|null $search = null,
        #[Parameter(in: 'query', description: 'The fields to sort by, comma-separated, each ascending unless prefixed with -: properties.<name>, timestamps.created, timestamps.lastModified, timestamps.originalCreated, timestamps.originalLastModified')]
        Sort|null $sort = null,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
        #[Parameter(in: 'query', description: 'What to include beyond each node\'s own fields, comma-separated: references, references.node, children, children.references, children.hasChildren, variants, variants.references, ancestors, hasChildren')]
        IncludePaths|null $include = null,
        #[Parameter(in: 'query', description: 'With include=hasChildren or children.hasChildren, only child nodes of these node types count, comma-separated, a ! in front excludes a type, as filterByNodeType. All if omitted')]
        NodeTypeCriteria|null $hasChildrenOfNodeType = null,
        #[Parameter(in: 'query', description: 'Find deleted nodes as well (soft removed, tagged removed), as getNode, false if omitted')]
        bool $includeRemoved = false,
    ): PaginatedNodeListing|NotFound|BadRequest {
        $offset ??= Offset::none();
        $limit ??= Limit::default();
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $query = NodeQuery::create($filterByHierarchy, $filterByReference, $filterByNodeType, $filterByNodeAggregateId, $filterByProperty, $search, $sort, $offset, $limit);
        if ($query instanceof BadRequest) {
            return $query;
        }
        $hasChildrenOf = self::hasChildrenOf($hasChildrenOfNodeType);
        if ($hasChildrenOf instanceof BadRequest) {
            return $hasChildrenOf;
        }
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $includeRemoved);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $nodeTypeManager = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId())->getNodeTypeManager();
        if ($query->selectsByNodeAggregateId()) {
            $found = $query->findByNodeAggregateIds($subgraph, $nodeTypeManager);
        } else {
            $entryPointId = $query->entryPoint();
            $entryPoint = $entryPointId !== null ? $this->subgraphResolver->resolveNodeIn($subgraph, $entryPointId->toNodeAggregateId()) : $this->defaultSiteNode($subgraph);
            if (!$entryPoint instanceof ContentGraph\Node) {
                return $entryPoint;
            }
            $found = $query->find($entryPoint, $subgraph, $nodeTypeManager);
        }
        if ($found instanceof BadRequest) {
            return $found;
        }
        $unknownNodeTypes = $this->unknownNodeTypes($hasChildrenOf, $subgraph->getContentRepositoryId());
        if ($unknownNodeTypes !== null) {
            return $unknownNodeTypes;
        }
        [$nodes, $total] = $found;
        return new PaginatedNodeListing(
            new NodeList(...array_map(fn (ContentGraph\Node $node) => $this->node($node, $include, $hasChildrenOf), $nodes)),
            new ListingMeta($total),
            ListingLinks::for($request, $offset, $limit, $total),
        );
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes',
        method: 'POST',
        summary: 'Create a node',
        description: 'Creates a node of the node type below the parent in the workspace, with the dimension space point as its origin (CreateNodeAggregateWithNode): before the succeeding sibling, else as the parent\'s last child, with the given aggregate id, else a new one. The properties are set as for updateNodeProperties, over the node type\'s defaults, a document without uriPathSegment gets one from its title as in the Neos backend. The references are set as for updateNodeReferences right after the node is created: if they can\'t be (e.g. an unknown target), it\'s a 422 and the node stays without them. Tethered child nodes (e.g. a page\'s main collection) are created with it, with the ids of tetheredDescendantNodeAggregateIds by node path, else new ones: a client that knows them in advance can create content in them right away. A path the node type has no tethered descendant at, or an id given twice or the node\'s own, is a 422. An unknown, abstract or root node type, one the parent doesn\'t allow below it, a sibling that isn\'t a child of the parent or properties that can\'t be written are a 422, an aggregate id that is taken a 409. Whether the account may create the node there is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the new node as getNode returns it without include.',
        operationId: 'createNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_CREATE],
        ],
    )]
    public function create(
        ContentRepositoryId $contentRepositoryId,
        #[Parameter(in: 'query', description: 'The workspace to create the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The node')] NodeCreate $newNode,
        #[Parameter(in: 'query', description: 'The dimension space point to create the node in, its origin, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NodeCreated|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $subgraph = $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $parent = $this->subgraphResolver->resolveNodeIn($subgraph, $newNode->parentNodeAggregateId->toNodeAggregateId());
        if (!$parent instanceof ContentGraph\Node) {
            return $parent;
        }
        // the content repository doesn't check it, the projection would take the position below the sibling's parent
        if ($newNode->succeedingSiblingNodeAggregateId !== null && $subgraph->findParentNode($newNode->succeedingSiblingNodeAggregateId->toNodeAggregateId())?->aggregateId->equals($parent->aggregateId) !== true) {
            return UnprocessableContent::because(sprintf('The node %s has no child node %s to create the node before', $parent->aggregateId->value, $newNode->succeedingSiblingNodeAggregateId->value));
        }
        $nodeTypeName = NodeType\NodeTypeName::fromString($newNode->nodeType->value);
        $nodeType = $this->contentRepositoryRegistry->get($parent->contentRepositoryId)->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            return UnprocessableContent::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        $values = $newNode->properties;
        if (UriPathSegments::appliesTo($nodeType) && !isset($values['uriPathSegment'])) {
            // from the title, else <node type suffix>-<random number> as in the Neos backend
            $values['uriPathSegment'] = $this->uriPathSegments->forNewDocument($nodeTypeName, is_string($values['title'] ?? null) ? $values['title'] : null, $subgraph->getDimensionSpacePoint());
        }
        try {
            $propertyValues = $this->propertyValues->toWrite($nodeType, $values);
            $referencesToWrite = $this->propertyValues->referencesToWrite($nodeType, $newNode->references);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        $nodeAggregateId = $newNode->nodeAggregateId?->toNodeAggregateId() ?? SharedModel\Node\NodeAggregateId::create();
        $tetheredIds = self::tetheredDescendantNodeAggregateIds($newNode->tetheredDescendantNodeAggregateIds, $nodeType, $nodeAggregateId, $this->contentRepositoryRegistry->get($parent->contentRepositoryId)->getNodeTypeManager());
        if ($tetheredIds instanceof UnprocessableContent) {
            return $tetheredIds;
        }
        $origin = OriginDimensionSpacePoint::fromDimensionSpacePoint($subgraph->getDimensionSpacePoint());
        $action = sprintf('create a node below the node %s', $parent->aggregateId->value);
        try {
            $failed = $this->send($parent, $action, CreateNodeAggregateWithNode::create(
                $parent->workspaceName,
                $nodeAggregateId,
                $nodeTypeName,
                $origin,
                $parent->aggregateId,
                $newNode->succeedingSiblingNodeAggregateId?->toNodeAggregateId(),
                $propertyValues,
            )->withTetheredDescendantNodeAggregateIds($tetheredIds));
        } catch (NodeAggregateCurrentlyExists) {
            return Conflict::because($tetheredIds->isEmpty()
                ? sprintf('There is a node with the aggregate id %s already', $nodeAggregateId->value)
                : sprintf('There is a node with the aggregate id %s or one of the tetheredDescendantNodeAggregateIds already', $nodeAggregateId->value));
        } catch (NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint) {
            return Conflict::because(sprintf('The node %s isn\'t in the dimension space point %s', $parent->aggregateId->value, $subgraph->getDimensionSpacePoint()->toJson()));
        } catch (NodeTypeIsAbstract | NodeTypeIsOfTypeRoot | NodeConstraintException | PropertyCannotBeSet $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        if ($failed !== null) {
            return $failed;
        }
        // CreateNodeAggregateWithNode takes references as well, but doesn't check them (targets, constraints), SetNodeReferences does
        if ($newNode->references !== []) {
            try {
                $failed = $this->send($parent, $action, SetNodeReferences::create($parent->workspaceName, $nodeAggregateId, $origin, $referencesToWrite));
            } catch (ReferenceCannotBeSet | NodeAggregateCurrentlyDoesNotExist | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint | NodeAggregateIsRoot $exception) {
                $failed = UnprocessableContent::because($exception->getMessage());
            }
            if ($failed !== null) {
                return UnprocessableContent::because(sprintf('The node %s was created without its references, which can\'t be set: %s', $nodeAggregateId->value, $failed->body()->detail));
            }
        }
        $created = $this->reread($parent, $nodeAggregateId);
        return $created instanceof Node ? NodeCreated::node($created) : $created;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}',
        method: 'GET',
        summary: 'Get a node',
        description: 'A node in the workspace and dimension space point with its properties and what is included: with include=references its references, with children its direct child nodes in their order, with variants the node in its other origin dimension space points (its content variants, not the points that only fall back to it), in the order of the content dimensions, with ancestors the nodes above it up to its site node, nearest first (the parent first, the content repository\'s root left out), e.g. for a breadcrumb: the labels of the documents among them, reversed, with hasChildren whether it has child nodes (of the node types of hasChildrenOfNodeType), e.g. for a tree. children.references and variants.references include their references as well, children.hasChildren whether the children have child nodes, references.node the referenced nodes in the references. Hidden nodes are visible to accounts that may see them in every workspace, live included, as in the Neos backend; isHidden tells them apart. Deleted nodes (soft removed, tagged removed, until the deletion is published and the grace period is over) are only found with includeRemoved, e.g. for a trash, so are the ones below and above them in what is included; their tags or inheritedTags contain removed. The account\'s node privileges apply as always.',
        operationId: 'getNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, as JSON. If omitted, the default one of the content repository\'s default site (Neos.Neos.defaultSiteNodeName if it is in the content repository, else its first online site by name), the only one of a content repository without a site')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node\'s own fields, comma-separated: references, references.node, children, children.references, children.hasChildren, variants, variants.references, ancestors, hasChildren')]
        IncludePaths|null $include = null,
        #[Parameter(in: 'query', description: 'With include=hasChildren or children.hasChildren, only child nodes of these node types count, comma-separated, a ! in front excludes a type, as filterByNodeType of listNodes. All if omitted. Unknown node types are a 400')]
        NodeTypeCriteria|null $hasChildrenOfNodeType = null,
        #[Parameter(in: 'query', description: 'Find deleted nodes as well (soft removed, tagged removed), e.g. for a trash, false if omitted')]
        bool $includeRemoved = false,
    ): Node|NotFound|BadRequest {
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $hasChildrenOf = self::hasChildrenOf($hasChildrenOfNodeType);
        if ($hasChildrenOf instanceof BadRequest) {
            return $hasChildrenOf;
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint, $includeRemoved);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        return $this->unknownNodeTypes($hasChildrenOf, $node->contentRepositoryId) ?? $this->node($node, $include, $hasChildrenOf);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/properties',
        method: 'PATCH',
        summary: 'Change the properties of a node',
        description: 'Sets the given properties of the node in the workspace and dimension space point (SetNodeProperties) and leaves the others as they are, null unsets one. The body is the properties by name, the response the node as getNode returns it without include. Properties the node type doesn\'t declare or values that don\'t fit their type are a 422. A node shown with the content of another dimension space point (isShineThrough) is a 409: create a variant in this one first. Whether the account may change the node in the workspace is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'updateNodeProperties',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function updateProperties(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The properties to change by name')] NodePropertiesUpdate $properties,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The dimension space point to change the node in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $nodeType = $this->nodeType($node);
        if (!$nodeType instanceof NodeType\NodeType) {
            return $nodeType;
        }
        // NodePropertiesUpdate only validated the body, a map schematic can't build
        /** @var array<string, mixed> $values */
        $values = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return $this->setProperties($node, $nodeType, $values) ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/uripathsegment',
        method: 'POST',
        summary: 'Generate the URI path segment of a document',
        description: 'Sets the uriPathSegment property of the document in the workspace and dimension space point to one generated from the text, else from its title, as the Neos backend\'s "sync" button does: transliterated in the language of the dimension space point (ä becomes ae in German), lowercase, with dashes. Not unique among its siblings, the backend\'s isn\'t either. Like updateNodeProperties otherwise, the response is the node as getNode returns it without include. A node that isn\'t a document with a uriPathSegment property or nothing to generate it from (no text and no title) is a 422.',
        operationId: 'generateNodeUriPathSegment',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function generateUriPathSegment(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'What to generate it from, {} for the title')] NodeUriPathSegmentGenerate $source,
        #[Parameter(in: 'query', description: 'The dimension space point to change the node in, its language the one to transliterate in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $nodeType = $this->nodeType($node);
        if (!$nodeType instanceof NodeType\NodeType) {
            return $nodeType;
        }
        if (!UriPathSegments::appliesTo($nodeType)) {
            return UnprocessableContent::because(sprintf('The node %s is no document with a uriPathSegment property', $node->aggregateId->value));
        }
        $uriPathSegment = $this->uriPathSegmentOf($node, $source->text, withLabel: false);
        if ($uriPathSegment === '') {
            return UnprocessableContent::because(sprintf('There is nothing to generate the uriPathSegment of the node %s from, it has no title', $node->aggregateId->value));
        }
        return $this->setProperties($node, $nodeType, ['uriPathSegment' => $uriPathSegment]) ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/uripathsegment',
        method: 'GET',
        summary: 'Preview the URI path segment of a node',
        description: 'The uriPathSegment generateNodeUriPathSegment would set, without setting it, e.g. while a title is typed: generated from the text, else from the node\'s title property, else its label, transliterated in the language of the dimension space point in the same way. A node without a uriPathSegment property gets one as well. Nothing to generate it from (e.g. "!!!") is a 422.',
        operationId: 'getNodeUriPathSegment',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function getUriPathSegment(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, its language the one to transliterate in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'The text to generate it from, e.g. a title not saved yet. If omitted or empty, the node\'s title, else its label')]
        string|null $text = null,
    ): UriPathSegment|NotFound|BadRequest|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $uriPathSegment = $this->uriPathSegmentOf($node, $text, withLabel: true);
        return $uriPathSegment !== ''
            ? new UriPathSegment($uriPathSegment)
            : UnprocessableContent::because(sprintf('There is nothing to generate the uriPathSegment of the node %s from', $node->aggregateId->value));
    }

    /**
     * The uriPathSegment generated for the node as the Neos backend's "sync" button does: from the text, else its title
     * (and with $withLabel its label), '' if there is nothing to generate it from
     */
    private function uriPathSegmentOf(ContentGraph\Node $node, ?string $text, bool $withLabel): string
    {
        $title = $node->getProperty('title');
        foreach ([$text, is_string($title) ? $title : null, $withLabel ? $this->nodeSerializer->label($node) : null] as $source) {
            if ($source !== null && trim($source) !== '') {
                return $this->uriPathSegments->fromText($source, $node->dimensionSpacePoint);
            }
        }
        return '';
    }

    /**
     * Sets the properties as updateNodeProperties does, null if they are set
     *
     * @param array<string, mixed> $values
     */
    private function setProperties(ContentGraph\Node $node, NodeType\NodeType $nodeType, array $values): Forbidden|Conflict|UnprocessableContent|null
    {
        try {
            $propertyValues = $this->propertyValues->toWrite($nodeType, $values);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        // the content repository rejects a command without changes
        if ($values === []) {
            return null;
        }
        try {
            // in the requested point, not the node's origin: the content repository decides whether it may be changed there
            return $this->send($node, sprintf('change the node %s', $node->aggregateId->value), SetNodeProperties::create($node->workspaceName, $node->aggregateId, OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint), $propertyValues));
        } catch (DimensionSpacePointIsNotYetOccupied) {
            return $this->shinesThrough($node);
        } catch (PropertyCannotBeSet $exception) {
            // PropertyValues checks the same, in case they disagree
            return UnprocessableContent::because($exception->getMessage());
        }
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/references',
        method: 'PATCH',
        summary: 'Change the references of a node',
        description: 'Sets the given references of the node in the workspace and dimension space point (SetNodeReferences): each name\'s list replaces all of its references, in its order, an empty list removes them, the names left out stay as they are. The body is the references by name as Node has them, e.g. {"relatedPages": [{"nodeAggregateId": "…"}]}, a reference\'s properties are optional and in the form of node properties. References the node type doesn\'t declare, targets that don\'t exist or aren\'t in the dimension space point, ones the reference doesn\'t allow (its node types or maxItems), or properties that can\'t be written are a 422. A node shown with the content of another dimension space point (isShineThrough) is a 409: create a variant in this one first. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node with its references, as getNode returns it with include=references.',
        operationId: 'updateNodeReferences',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function updateReferences(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The references to change by name')] NodeReferencesUpdate $references,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The dimension space point to change the node in, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $nodeType = $this->nodeType($node);
        if (!$nodeType instanceof NodeType\NodeType) {
            return $nodeType;
        }
        // NodeReferencesUpdate only validated the body, a map schematic can't build
        /** @var array<string, mixed> $values */
        $values = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $referencesToWrite = $this->propertyValues->referencesToWrite($nodeType, $values);
        } catch (InvalidPropertyValue $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        // the content repository rejects a command without changes
        if ($values !== []) {
            try {
                // in the requested point, as updateProperties: the content repository decides whether it may be changed there
                $failed = $this->send($node, sprintf('change the node %s', $node->aggregateId->value), SetNodeReferences::create($node->workspaceName, $node->aggregateId, OriginDimensionSpacePoint::fromDimensionSpacePoint($node->dimensionSpacePoint), $referencesToWrite));
            } catch (DimensionSpacePointIsNotYetOccupied) {
                return $this->shinesThrough($node);
            } catch (ReferenceCannotBeSet | NodeAggregateCurrentlyDoesNotExist | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint | NodeAggregateIsRoot $exception) {
                return UnprocessableContent::because($exception->getMessage());
            }
            if ($failed !== null) {
                return $failed;
            }
        }
        return $this->reread($node, include: IncludePaths::fromString('references'));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}',
        method: 'DELETE',
        summary: 'Delete a node',
        description: 'Removes the node with everything below it in the workspace and dimension space point and the points that fall back to it (e.g. en_UK showing en_US), not in the others, as the Neos backend does: it is soft removed (tagged removed), and becomes a hard removal once it is published and no other workspace has changes to it, after a grace period (Neos.Neos.softRemoval.garbageCollectionGracePeriod), until then restoreNode undoes it. Removed nodes are read nowhere, a removed node is a 404 as an unknown one. A node shown with the content of another point is removed in this one only, it doesn\'t show the other\'s content again. Tethered nodes (e.g. a page\'s main collection) can only be removed with their parent, they and root nodes are a 422. Whether the account may remove the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'deleteNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_DELETE],
        ],
    )]
    public function delete(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to delete the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[Parameter(in: 'query', description: 'The dimension space point to delete the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NotFound|BadRequest|Forbidden|UnprocessableContent|null {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            // Neos soft removes, never RemoveNodeAggregate in a workspace, see NeosSubtreeTag::removed()
            return $this->send($node, sprintf('delete the node %s', $node->aggregateId->value), TagSubtree::create(
                $node->workspaceName,
                $node->aggregateId,
                $node->dimensionSpacePoint,
                NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                NeosSubtreeTag::removed(),
            ));
        } catch (NodeAggregateIsTethered) {
            return UnprocessableContent::because(sprintf('The node %s is tethered to its parent, it can only be deleted with it', $node->aggregateId->value));
        } catch (NodeAggregateIsRoot) {
            return UnprocessableContent::because(sprintf('The node %s is a root node, which can\'t be deleted', $node->aggregateId->value));
        }
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}',
        method: 'PUT',
        summary: 'Tag a node',
        description: 'Tags the node and everything below it in the workspace and dimension space point and the points that fall back to it (TagSubtree), as the Neos backend does: disabled hides it (isHidden), other tags may be matched by node privileges. Nothing happens if the node has the tag already. removed is a 422, deleteNode removes nodes. disabled on a tethered node (e.g. a page\'s main collection) is a 422. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'tagNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function tag(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        SubtreeTag $tag,
        #[Parameter(in: 'query', description: 'The workspace to tag the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[Parameter(in: 'query', description: 'The dimension space point to tag the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|UnprocessableContent {
        if ($tag->toSubtreeTag()->equals(NeosSubtreeTag::removed())) {
            return UnprocessableContent::because('Nodes can\'t be tagged removed, deleteNode removes them');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            $failed = $this->send($node, sprintf('change the node %s', $node->aggregateId->value), TagSubtree::create($node->workspaceName, $node->aggregateId, $node->dimensionSpacePoint, NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS, $tag->toSubtreeTag()));
        } catch (SubtreeIsAlreadyTagged) {
            // idempotent, as for asset tags
            $failed = null;
        } catch (NodeAggregateIsTethered) {
            return UnprocessableContent::because(sprintf('The node %s is tethered to its parent, it can\'t be tagged %s', $node->aggregateId->value, $tag->value));
        }
        return $failed ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/tags/{tag}',
        method: 'DELETE',
        summary: 'Untag a node',
        description: 'Removes the tag from the node and everything below it in the workspace and dimension space point and the points that fall back to it (UntagSubtree), as the Neos backend does: untagging disabled shows it again. A tag the node doesn\'t have itself is a 404, also one it only inherits from an ancestor (inheritedTags), untag that one instead. removed is a 422: removed nodes aren\'t found, restoreNode restores them. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'untagNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function untag(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        SubtreeTag $tag,
        #[Parameter(in: 'query', description: 'The workspace to untag the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[Parameter(in: 'query', description: 'The dimension space point to untag the node in (and the points that fall back to it), as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|UnprocessableContent {
        // removed nodes aren't found, restoring them is up to restore()
        if ($tag->toSubtreeTag()->equals(NeosSubtreeTag::removed())) {
            return UnprocessableContent::because('removed can\'t be untagged, restoreNode restores removed nodes');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        try {
            $failed = $this->send($node, sprintf('change the node %s', $node->aggregateId->value), UntagSubtree::create($node->workspaceName, $node->aggregateId, $node->dimensionSpacePoint, NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS, $tag->toSubtreeTag()));
        } catch (SubtreeIsNotTagged) {
            return NotFound::because(sprintf('The node %s isn\'t tagged %s itself', $node->aggregateId->value, $tag->value));
        }
        return $failed ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/restore',
        method: 'POST',
        summary: 'Restore a deleted node',
        description: 'Undoes deleteNode as Neos\' trash does: the node is restored in all dimension space points it was deleted in, with everything below it, and so are its deleted ancestors, so it has its place in the tree again. Only while the deletion is soft, until it is published and the grace period (Neos.Neos.softRemoval.garbageCollectionGracePeriod) is over, afterwards the node is gone, a 404 as an unknown one. Before publishing, discarding the workspace\'s changes undoes the deletion as well. A node that isn\'t deleted itself (also one inside a deleted node, restore that one) is a 409, as is a workspace that is outdated (rebase it first). Whether the account may restore the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend.',
        operationId: 'restoreNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_DELETE],
        ],
    )]
    public function restore(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to restore the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
    ): NotFound|Forbidden|Conflict|null {
        // removed nodes aren't in any subgraph, they are found in the content graph, as in Neos' RestoreController
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if (!$workspace instanceof ReadableWorkspace) {
            return $workspace;
        }
        $id = $nodeAggregateId->toNodeAggregateId();
        $contentGraph = $workspace->contentRepository->getContentGraph($workspace->workspace->workspaceName);
        $nodeAggregate = $contentGraph->findNodeAggregateById($id);
        if ($nodeAggregate === null || !$workspace->mayReadNodeAggregate($id)) {
            return NotFound::because(sprintf('There is no node %s in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        if (!$this->nodeRestoration->isRemoved($nodeAggregate)) {
            return Conflict::because(sprintf('The node %s isn\'t deleted itself in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        if ($workspace->workspace->status !== SharedModel\Workspace\WorkspaceStatus::UP_TO_DATE) {
            return Conflict::because(sprintf('The workspace %s is outdated, rebase it first', $workspace->workspace->workspaceName->value));
        }
        try {
            $this->nodeRestoration->restore($workspace->contentRepository, $contentGraph, $nodeAggregate);
        } catch (AccessDenied) {
            // no node to send() for, removed nodes aren't in any subgraph
            return Forbidden::because(sprintf('You may not restore the node %s in the workspace %s', $id->value, $workspace->workspace->workspaceName->value));
        }
        return null;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/nodetype',
        method: 'PUT',
        summary: 'Change the node type of a node',
        description: 'Changes the node type of the node in the workspace, in all of its dimension space points (ChangeNodeAggregateType), as the Neos backend does: properties the new node type doesn\'t declare are removed, its defaults are set for the ones the node doesn\'t have, its tethered child nodes are created, and child nodes it doesn\'t allow are deleted as with deleteNode (soft, restoreNode restores them). The same node type changes nothing. An unknown, abstract or root node type, one the parent doesn\'t allow, or a root or tethered node (e.g. a page\'s main collection) are a 422, a child node with the name of one of the new type\'s tethered child nodes a 409. Whether the account may change the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node in the dimension space point as getNode returns it without include.',
        operationId: 'changeNodeType',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function changeNodeType(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to change the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The new node type')] NodeTypeChange $change,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, the change is to all of them, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        $nodeTypeName = NodeType\NodeTypeName::fromString($change->nodeType->value);
        if ($this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($nodeTypeName) === null) {
            return UnprocessableContent::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        if ($nodeTypeName->equals($node->nodeTypeName)) {
            return $this->reread($node);
        }
        try {
            // as Neos UI, which soft removes the child nodes the new node type doesn't allow, see NeosSubtreeTag::removed()
            $failed = $this->send($node, sprintf('change the node %s', $node->aggregateId->value), ChangeNodeAggregateType::create(
                $node->workspaceName,
                $node->aggregateId,
                $nodeTypeName,
                new NodeAggregateTypeChangeChildConstraintConflictResolutionMarkWithTagStrategy(NeosSubtreeTag::removed()),
            ));
        } catch (NodeAggregateIsUntethered $exception) {
            return Conflict::because($exception->getMessage());
        } catch (NodeAggregateIsRoot | NodeAggregateIsTethered | NodeTypeIsAbstract | NodeTypeIsOfTypeRoot | NodeConstraintException $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        return $failed ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/move',
        method: 'POST',
        summary: 'Move a node',
        description: 'Moves the node with everything below it in the workspace (MoveNodeAggregate), as the Neos backend does: below parentNodeAggregateId as its last child, or before succeedingSiblingNodeAggregateId (below its parent, or below parentNodeAggregateId if given, of which it must be a child). In other dimension space points the node type\'s options.moveNodeStrategy decides: gatherAll (Neos\' default for documents, and if there is none) moves all of its variants, scatter (for content) only the one in this very point, not even in the points that fall back to it (e.g. en_UK showing en_US), gatherSpecializations the ones in this point and its specializations. Neither parent nor sibling is a 400, an unknown one a 404. A root or tethered node, a parent that doesn\'t allow the node, one inside the node itself, or a sibling that isn\'t a child of the parent are a 422, a parent that isn\'t in all the points to move in or has a child of the same name a 409. Whether the account may move the node is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node as getNode returns it without include.',
        operationId: 'moveNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_UPDATE],
        ],
    )]
    public function move(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to move the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'Where to move the node')] NodeMove $target,
        #[Parameter(in: 'query', description: 'The dimension space point to move the node in, the others as its moveNodeStrategy says, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): Node|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        if ($target->parentNodeAggregateId === null && $target->succeedingSiblingNodeAggregateId === null) {
            return BadRequest::because('Give parentNodeAggregateId or succeedingSiblingNodeAggregateId, or both');
        }
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        if ($target->succeedingSiblingNodeAggregateId?->value === $node->aggregateId->value) {
            return UnprocessableContent::because('A node can\'t be moved before itself');
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $sibling = $target->succeedingSiblingNodeAggregateId !== null ? $this->subgraphResolver->resolveNodeIn($subgraph, $target->succeedingSiblingNodeAggregateId->toNodeAggregateId()) : null;
        if ($sibling instanceof NotFound) {
            return $sibling;
        }
        $parent = $target->parentNodeAggregateId !== null ? $this->subgraphResolver->resolveNodeIn($subgraph, $target->parentNodeAggregateId->toNodeAggregateId()) : $subgraph->findParentNode($sibling->aggregateId);
        if ($parent instanceof NotFound) {
            return $parent;
        }
        if ($parent === null) {
            return UnprocessableContent::because(sprintf('The node %s is a root node, nodes can\'t be moved before it', $sibling->aggregateId->value));
        }
        try {
            $failed = $this->send($node, sprintf('move the node %s', $node->aggregateId->value), $this->nodeMoveCommand->create($node, $parent, $sibling));
        } catch (NodeAggregateDoesCurrentlyNotCoverDimensionSpacePointSet | NodeNameIsAlreadyCovered $exception) {
            return Conflict::because($exception->getMessage());
        } catch (NodeAggregateIsRoot | NodeAggregateIsTethered | NodeConstraintException | NodeAggregateIsDescendant | NodeAggregateIsNoChild | NodeAggregateIsNoSibling $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        return $failed ?? $this->reread($node);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/variants',
        method: 'GET',
        summary: 'List the variants of a node',
        description: 'The node in each dimension space point it has a variant of its own in (not the points that only fall back to one), each read in its point, in the order of the content dimensions: all of them, unlike getNode\'s include=variants, which are the others. Variants the account may not read are left out, a node without any it may read is a 404. meta.coveredDimensionSpacePoints are all points the node is shown in, its variants\' and the ones that fall back to them (e.g. en_UK showing en_US), in which the account may read it, in the order of the content dimensions. include works as in getNode, for each variant. Hidden nodes are visible as in getNode.',
        operationId: 'listNodeVariants',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_READ],
        ],
    )]
    public function listVariants(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'The workspace to read the variants in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'What to include beyond each variant\'s own fields, comma-separated: references, children, children.references')]
        IncludePaths|null $include = null,
    ): NodeVariantListing|NotFound|BadRequest {
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::VARIANT_INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName ?? WorkspaceName::fromString(SharedModel\Workspace\WorkspaceName::WORKSPACE_NAME_LIVE), $caller->account);
        if (!$workspace instanceof ReadableWorkspace) {
            return $workspace;
        }
        $variants = $this->contentSubgraphs->findAllVariants($workspace->contentRepository, $workspace->workspace->workspaceName, $nodeAggregateId->toNodeAggregateId(), excludeDisabled: false) ?? [];
        if ($variants === []) {
            return NotFound::because(sprintf('There is no node %s in the workspace %s', $nodeAggregateId->value, $workspace->workspace->workspaceName->value));
        }
        $covered = $this->contentSubgraphs->findCoveredDimensionSpacePoints($workspace->contentRepository, $workspace->workspace->workspaceName, $nodeAggregateId->toNodeAggregateId(), excludeDisabled: false) ?? [];
        return new NodeVariantListing(
            new NodeList(...array_map(fn (ContentGraph\Node $variant) => $this->node($variant, $include), $variants)),
            new NodeVariantListingMeta(count($variants), DimensionSpacePointList::from($covered)),
        );
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/variants',
        method: 'POST',
        summary: 'Create a variant of a node',
        description: 'Copies the node as read in the workspace and dimension space point (its origin\'s content, e.g. en_US for a node shining through in en_UK) to the dimension space point of the body, a variant of its own there (CreateNodeVariant), as the Neos backend does: its tethered child nodes are varied with it, and so are its ancestors that aren\'t in that point yet (as when translating a document), top-down, so it has a parent there. A tethered node is varied with its nearest ancestor that isn\'t tethered. With copyContent, the content below it (not documents) is varied as well. Each variant is a command of its own, if one fails, the ones before it stay. A body dimensionSpacePoint that is invalid or not one of the content repository is a 422, a node that has a variant in that point already a 409, a root node a 422. Whether the account may create the variants is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the node in the new point as getNode returns it without include.',
        operationId: 'createNodeVariant',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_CREATE],
        ],
    )]
    public function createVariant(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to create the variant in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'Where to create the variant')] NodeVariantCreate $variant,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, whose content is copied, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NodeCreated|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        // the body's point is no query parameter, so its errors are 422s, not SubgraphResolver's 400s
        try {
            $targetPoint = $variant->dimensionSpacePoint->toDimensionSpacePoint();
        } catch (\InvalidArgumentException | \RuntimeException | \TypeError $exception) {
            return UnprocessableContent::because(sprintf('The dimensionSpacePoint of the body is invalid: %s', $exception->getMessage()));
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($targetPoint)) {
            return UnprocessableContent::because(sprintf('There is no dimension space point %s (the dimensionSpacePoint of the body) in the content repository %s, GET /cr/%2$s lists its dimensions and their values', $targetPoint->toJson(), $node->contentRepositoryId->value));
        }
        // with the visibility the node was read with
        $target = $contentRepository->getContentGraph($node->workspaceName)->getSubgraph($targetPoint, $node->visibilityConstraints);
        $varied = $this->nodeVariation->nodeToVary($node);
        if ($this->nodeVariation->hasVariantIn($varied, OriginDimensionSpacePoint::fromDimensionSpacePoint($targetPoint))) {
            return Conflict::because(sprintf('The node %s has a variant in the dimension space point %s already', $varied->aggregateId->value, $targetPoint->toJson()));
        }
        try {
            $failed = $this->send($node, sprintf('create variants of the node %s', $varied->aggregateId->value), fn () => $this->nodeVariation->vary($varied, $target, $variant->copyContent === true));
        } catch (DimensionSpacePointIsAlreadyOccupied | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint $exception) {
            return Conflict::because($exception->getMessage());
        } catch (NodeAggregateIsRoot | NodeAggregateIsTethered $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        if ($failed !== null) {
            return $failed;
        }
        $created = $this->read($target, $node->aggregateId);
        return $created instanceof Node ? NodeCreated::variant($created) : $created;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodes/{nodeAggregateId}/copy',
        method: 'POST',
        summary: 'Copy a node',
        description: 'Copies the node as read in the workspace and dimension space point, with everything below it, below the parent of the body in the workspace, as the Neos backend\'s paste does (Neos\' NodeDuplicationService): as the parent\'s last child or before succeedingSiblingNodeAggregateId, in targetDimensionSpacePoint (its origin, the point the node is read in if omitted), with new aggregate ids, its properties, references and tags (e.g. disabled). A copied document gets the uriPathSegment of the original, with -1, -2, … if a sibling has it already. Each node is a command of its own, if one fails, the ones before it stay. A parent or sibling that isn\'t in the target point (or that the account may not read), a sibling that isn\'t a child of the parent, a parent that doesn\'t allow the node, or a body targetDimensionSpacePoint that is invalid or not one of the content repository are a 422, a copy that conflicts with what is there (e.g. a node name taken, tethered nodes that can\'t be copied alone) a 409. Whether the account may create the nodes is up to its workspace roles and node privileges (else a 403), as in the Neos backend. The response is the copy as getNode returns it without include, in the target point.',
        operationId: 'copyNode',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODES_CREATE],
        ],
    )]
    public function copy(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        #[Parameter(in: 'query', description: 'The workspace to copy the node in, required: changing live directly is rarely what is meant')]
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'Where to put the copy')] NodeCopy $target,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, whose content is copied, as JSON. If omitted, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): NodeCreated|NotFound|BadRequest|Forbidden|Conflict|UnprocessableContent {
        $node = $this->nodeIn($contentRepositoryId, $nodeAggregateId, $workspaceName, $dimensionSpacePoint);
        if (!$node instanceof ContentGraph\Node) {
            return $node;
        }
        // the body's point is no query parameter, so its errors are 422s, as for createNodeVariant
        try {
            $targetPoint = $target->targetDimensionSpacePoint?->toDimensionSpacePoint() ?? $node->dimensionSpacePoint;
        } catch (\InvalidArgumentException | \RuntimeException | \TypeError $exception) {
            return UnprocessableContent::because(sprintf('The targetDimensionSpacePoint of the body is invalid: %s', $exception->getMessage()));
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if (!$contentRepository->getVariationGraph()->getDimensionSpacePoints()->contains($targetPoint)) {
            return UnprocessableContent::because(sprintf('There is no dimension space point %s (the targetDimensionSpacePoint of the body) in the content repository %s, GET /cr/%2$s lists its dimensions and their values', $targetPoint->toJson(), $node->contentRepositoryId->value));
        }
        // with the visibility the node was read with
        $targetSubgraph = $contentRepository->getContentGraph($node->workspaceName)->getSubgraph($targetPoint, $node->visibilityConstraints);
        $parent = $targetSubgraph->findNodeById($target->parentNodeAggregateId->toNodeAggregateId());
        if ($parent === null) {
            return UnprocessableContent::because(sprintf('There is no node %s (the parentNodeAggregateId of the body) in the workspace %s and the dimension space point %s', $target->parentNodeAggregateId->value, $node->workspaceName->value, $targetPoint->toJson()));
        }
        $siblingId = $target->succeedingSiblingNodeAggregateId?->toNodeAggregateId();
        // the content repository doesn't check it, as for createNode
        if ($siblingId !== null && $targetSubgraph->findParentNode($siblingId)?->aggregateId->equals($parent->aggregateId) !== true) {
            return UnprocessableContent::because(sprintf('The node %s has no child node %s to put the copy before', $parent->aggregateId->value, $siblingId->value));
        }
        $copyId = SharedModel\Node\NodeAggregateId::create();
        try {
            $failed = $this->send($node, sprintf('copy the node %s below the node %s', $node->aggregateId->value, $parent->aggregateId->value), fn () => $this->nodeDuplicationService->copyNodesRecursively(
                $node->contentRepositoryId,
                $node->workspaceName,
                $node->dimensionSpacePoint,
                $node->aggregateId,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetPoint),
                $parent->aggregateId,
                $siblingId,
                // the copy's id, to read it afterwards
                NodeAggregateIdMapping::fromArray([$node->aggregateId->value => $copyId]),
            ));
        } catch (NodeAggregateCurrentlyExists | NodeNameIsAlreadyCovered | NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint | DimensionSpacePointIsNotYetOccupied | TetheredNodesCannotBePartiallyCopied $exception) {
            return Conflict::because($exception->getMessage());
        } catch (NodeAggregateCurrentlyDoesNotExist | NodeTypeIsAbstract | NodeTypeIsOfTypeRoot | NodeConstraintException | PropertyCannotBeSet | ReferenceCannotBeSet $exception) {
            return UnprocessableContent::because($exception->getMessage());
        }
        if ($failed !== null) {
            return $failed;
        }
        $copy = $this->read($targetSubgraph, $copyId);
        return $copy instanceof Node ? NodeCreated::copy($copy) : $copy;
    }

    /**
     * Sends the command (or runs the writes) for the node, a 403 if the account may not (AccessDenied: its workspace
     * roles or node privileges), null if it was sent. The command's other exceptions are up to the operation
     *
     * @param string $action what the account may not do, e.g. "change the node …"
     * @param CommandInterface|\Closure(): void $write
     */
    private function send(ContentGraph\Node $node, string $action, CommandInterface|\Closure $write): ?Forbidden
    {
        try {
            $write instanceof CommandInterface ? $this->contentRepositoryRegistry->get($node->contentRepositoryId)->handle($write) : $write();
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not %s in the workspace %s', $action, $node->workspaceName->value));
        }
        return null;
    }

    /**
     * The 409 of a command whose origin is the requested point (DimensionSpacePointIsNotYetOccupied): the node only
     * shines through there, Neos' UI creates the variant silently
     */
    private function shinesThrough(ContentGraph\Node $node): Conflict
    {
        return Conflict::because(sprintf(
            'The node is shown in the dimension space point %s with the content of %s, create a variant in %1$s first',
            $node->dimensionSpacePoint->toJson(),
            $node->originDimensionSpacePoint->toJson(),
        ));
    }

    /**
     * The node's node type, a 422 if it doesn't exist anymore: writing properties and references needs it
     */
    private function nodeType(ContentGraph\Node $node): NodeType\NodeType|UnprocessableContent
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName)
            ?? UnprocessableContent::because(sprintf('The node type %s of the node doesn\'t exist anymore', $node->nodeTypeName->value));
    }

    /**
     * The node in the subgraph of the request, see SubgraphResolver::resolveNode()
     */
    private function nodeIn(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint, bool $includeRemoved = false): ContentGraph\Node|NotFound|BadRequest
    {
        return $this->subgraphResolver->resolveNode($contentRepositoryId, $nodeAggregateId->toNodeAggregateId(), $workspaceName, $dimensionSpacePoint, excludeDisabled: false, includeRemoved: $includeRemoved);
    }

    /**
     * The node read again after a write, nodes are read-only: the node itself, or another one in its subgraph (one it
     * created)
     */
    private function reread(ContentGraph\Node $node, ?SharedModel\Node\NodeAggregateId $nodeAggregateId = null, ?IncludePaths $include = null): Node|NotFound
    {
        return $this->read($this->contentRepositoryRegistry->subgraphForNode($node), $nodeAggregateId ?? $node->aggregateId, $include);
    }

    /**
     * The node in the subgraph as Node, a 404 if there is none the account may read
     */
    private function read(ContentSubgraphInterface $subgraph, SharedModel\Node\NodeAggregateId $nodeAggregateId, ?IncludePaths $include = null): Node|NotFound
    {
        $node = $this->subgraphResolver->resolveNodeIn($subgraph, $nodeAggregateId);
        return $node instanceof ContentGraph\Node ? $this->node($node, $include ?? IncludePaths::none()) : $node;
    }

    /**
     * The subgraph with the visibility of the Neos backend, see SubgraphResolver::resolve()
     */
    private function subgraph(ContentRepositoryId $contentRepositoryId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint, bool $includeRemoved = false): ContentSubgraphInterface|BadRequest|NotFound
    {
        return $this->subgraphResolver->resolve($contentRepositoryId, $workspaceName, $dimensionSpacePoint, excludeDisabled: false, includeRemoved: $includeRemoved);
    }

    /**
     * The tethered descendants' ids of the body as the content repository takes them, a 422 for a path the node type
     * has no tethered descendant at, or an id given twice or the node's own: the content repository checks neither
     *
     * @param array<string, string> $byPath
     */
    private static function tetheredDescendantNodeAggregateIds(array $byPath, NodeType\NodeType $nodeType, SharedModel\Node\NodeAggregateId $nodeAggregateId, NodeType\NodeTypeManager $nodeTypeManager): NodeAggregateIdsByNodePaths|UnprocessableContent
    {
        try {
            $ids = NodeAggregateIdsByNodePaths::fromArray($byPath);
        } catch (\InvalidArgumentException $exception) {
            return UnprocessableContent::because(sprintf('The tetheredDescendantNodeAggregateIds are invalid: %s', $exception->getMessage()));
        }
        $unknown = array_diff(array_keys($ids->getNodeAggregateIds()), array_keys(NodeAggregateIdsByNodePaths::createForNodeType($nodeType->name, $nodeTypeManager)->getNodeAggregateIds()));
        if ($unknown !== []) {
            return UnprocessableContent::because(sprintf('The node type %s has no tethered descendant at %s (tetheredDescendantNodeAggregateIds)', $nodeType->name->value, implode(', ', $unknown)));
        }
        $values = array_map(static fn (SharedModel\Node\NodeAggregateId $id) => $id->value, array_values($ids->getNodeAggregateIds()));
        if (count(array_unique($values)) !== count($values) || in_array($nodeAggregateId->value, $values, true)) {
            return UnprocessableContent::because('The tetheredDescendantNodeAggregateIds must differ from each other and from the node\'s nodeAggregateId');
        }
        return $ids;
    }

    /**
     * The node types of hasChildrenOfNodeType, a 400 for a syntax error before anything is looked up, see
     * unknownNodeTypes() for the node types themselves
     */
    private static function hasChildrenOf(?NodeTypeCriteria $hasChildrenOfNodeType): ContentGraph\Filter\NodeType\NodeTypeCriteria|BadRequest|null
    {
        try {
            return $hasChildrenOfNodeType?->toNodeTypeCriteria();
        } catch (\InvalidArgumentException $exception) {
            return BadRequest::because(sprintf('hasChildrenOfNodeType is invalid: %s', $exception->getMessage()));
        }
    }

    /**
     * The 400 if hasChildrenOfNodeType names node types the content repository doesn't have, as filterByNodeType
     */
    private function unknownNodeTypes(?ContentGraph\Filter\NodeType\NodeTypeCriteria $hasChildrenOf, SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId): ?BadRequest
    {
        $unknown = $hasChildrenOf !== null ? NodeQuery::unknownNodeTypes($hasChildrenOf, $this->contentRepositoryRegistry->get($contentRepositoryId)->getNodeTypeManager()) : [];
        return $unknown !== [] ? BadRequest::because(sprintf('There is no node type %s (hasChildrenOfNodeType)', implode(', ', $unknown))) : null;
    }

    /**
     * The site node of the content repository's default site in the subgraph, a 404 if there is none the account may
     * read
     */
    private function defaultSiteNode(ContentSubgraphInterface $subgraph): ContentGraph\Node|NotFound
    {
        $site = $this->siteFinder->findDefault($subgraph->getContentRepositoryId());
        if ($site === null) {
            return NotFound::because(sprintf('There is no site in the content repository %s to list the nodes of, give filterByHierarchy or filterByReference', $subgraph->getContentRepositoryId()->value));
        }
        return $this->contentSubgraphs->findSiteNodeIn($subgraph, $site)
            ?? NotFound::because(sprintf(
                'There is no site node of the site %s in the workspace %s and the dimension space point %s',
                $site->getNodeName()->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
    }

    /**
     * @param ContentGraph\Filter\NodeType\NodeTypeCriteria|null $hasChildrenOf the child nodes hasChildren counts, all if null
     */
    private function node(ContentGraph\Node $node, IncludePaths $include, ?ContentGraph\Filter\NodeType\NodeTypeCriteria $hasChildrenOf = null): Node
    {
        return Node::from(
            $node,
            $this->nodeSerializer,
            $include->includes('references'),
            $include->includes('children') ? $this->nodeList($this->children($node), $include->includes('children.references'), $include->includes('children.hasChildren') ? $hasChildrenOf ?? true : false) : null,
            $include->includes('variants') ? $this->nodeList($this->contentSubgraphs->findVariants($node), $include->includes('variants.references')) : null,
            $include->includes('ancestors') ? $this->nodeList($this->ancestors($node), false) : null,
            $include->includes('hasChildren') ? $this->nodeSerializer->hasChildren($node, $hasChildrenOf) : null,
            // the referenced nodes as getNode returns them without include
            $include->includes('references.node') ? fn (ContentGraph\Node $referenced) => Serializer::serialize(Node::from($referenced, $this->nodeSerializer, false), Node::schema()) : null,
        );
    }

    /**
     * The nodes above the node, nearest first, without the root
     *
     * @return list<ContentGraph\Node>
     */
    private function ancestors(ContentGraph\Node $node): array
    {
        $ancestors = $this->contentRepositoryRegistry->subgraphForNode($node)->findAncestorNodes($node->aggregateId, FindAncestorNodesFilter::create());
        return array_values(array_filter(iterator_to_array($ancestors, false), static fn (ContentGraph\Node $ancestor) => !$ancestor->classification->isRoot()));
    }

    /**
     * @return list<ContentGraph\Node>
     */
    private function children(ContentGraph\Node $node): array
    {
        return iterator_to_array($this->contentRepositoryRegistry->subgraphForNode($node)->findChildNodes($node->aggregateId, FindChildNodesFilter::create()), false);
    }

    /**
     * @param list<ContentGraph\Node> $nodes
     * @param ContentGraph\Filter\NodeType\NodeTypeCriteria|bool $hasChildren whether to tell if they have child nodes,
     *     of these node types (true: of all)
     */
    private function nodeList(array $nodes, bool $includeReferences, ContentGraph\Filter\NodeType\NodeTypeCriteria|bool $hasChildren = false): NodeList
    {
        return new NodeList(...array_map(fn (ContentGraph\Node $node) => Node::from(
            $node,
            $this->nodeSerializer,
            $includeReferences,
            hasChildren: $hasChildren !== false ? $this->nodeSerializer->hasChildren($node, $hasChildren === true ? null : $hasChildren) : null,
        ), $nodes));
    }
}
