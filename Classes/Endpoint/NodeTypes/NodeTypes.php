<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeType;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeGroup;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeGroupList;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeList;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeListing;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core;
use Neos\Flow\Annotations as Flow;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\Utility\PositionalArraySorter;

/**
 * The node types of a content repository, the content model generic clients like form generators work with
 */
final readonly class NodeTypes
{
    private const array INCLUDE_PATHS = ['properties', 'references', 'configuration', 'allowedChildNodeTypes', 'tetheredChildNodes'];

    /**
     * @var array<mixed> the groups the Neos UI offers node types in, by name
     */
    private array $groups;

    /**
     * @param array<mixed>|null $groups
     */
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private LabelTranslator $labelTranslator,
        private NodeTypeUi $nodeTypeUi,
        #[Flow\InjectConfiguration(path: 'nodeTypes.groups', package: 'Neos.Neos')]
        array|null $groups,
    ) {
        $this->groups = $groups ?? [];
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodetypes',
        method: 'GET',
        summary: 'List the node types',
        description: 'All node types of the content repository, abstract ones included, sorted by name, or with filterBySuperType only the ones of that type. Their properties, references, configuration, allowedChildNodeTypes and tetheredChildNodes are null unless included, getNodeType has them all. Which node types a node may have as children follows from the model alone: a regular node\'s from its node type\'s allowedChildNodeTypes, a tethered node\'s (e.g. a page\'s main content collection) from its parent\'s node type\'s tetheredChildNodes, by its name. meta.groups are the groups a node type\'s group refers to (Neos.Neos.nodeTypes.groups), in the order of their positions, as the Neos UI offers them for creation. The labels are translated to the Accept-Language.',
        operationId: 'listNodeTypes',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function list(
        ContentRepositoryId $contentRepositoryId,
        #[Parameter(in: 'query', description: 'Only the node types of this type: it and the ones inheriting from it, directly or not. An unknown node type is a 400')]
        NodeTypeName|null $filterBySuperType = null,
        #[Parameter(in: 'query', description: 'What to include beyond the node types\' own fields, comma-separated: properties, references, configuration, allowedChildNodeTypes, tetheredChildNodes')]
        IncludePaths|null $include = null,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeTypeListing|NotFound|BadRequest {
        $include ??= IncludePaths::none();
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        $superType = $filterBySuperType;
        if ($superType !== null && !$nodeTypeManager->hasNodeType($superType->value)) {
            return BadRequest::because(sprintf('There is no node type %s', $superType->value));
        }
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        $nodeTypes = $nodeTypeManager->getNodeTypes();
        if ($superType !== null) {
            $nodeTypes = array_filter($nodeTypes, static fn (Core\NodeType\NodeType $nodeType) => $nodeType->isOfType($superType->value));
        }
        ksort($nodeTypes);
        // the same for every node type, computed once
        $candidates = $include->includes('allowedChildNodeTypes') || $include->includes('tetheredChildNodes') ? NodeType::childNodeTypeCandidates($nodeTypeManager) : [];
        return NodeTypeListing::of(new NodeTypeList(...array_map(
            fn (Core\NodeType\NodeType $nodeType) => NodeType::from(
                $nodeType,
                $labels,
                $this->nodeTypeUi,
                $nodeTypeManager,
                $candidates,
                withProperties: $include->includes('properties'),
                withReferences: $include->includes('references'),
                withConfiguration: $include->includes('configuration'),
                withAllowedChildNodeTypes: $include->includes('allowedChildNodeTypes'),
                withTetheredChildNodes: $include->includes('tetheredChildNodes'),
            ),
            array_values($nodeTypes),
        )), $this->groups($labels));
    }

    /**
     * The groups of the settings in the order of their positions, ones set to null left out
     */
    private function groups(Labels $labels): NodeTypeGroupList
    {
        $groups = [];
        foreach ((new PositionalArraySorter(array_filter($this->groups, is_array(...))))->toArray() as $name => $configuration) {
            $groups[] = NodeTypeGroup::from((string)$name, $configuration, $labels);
        }
        return new NodeTypeGroupList(...$groups);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/nodetypes/{nodeTypeName}',
        method: 'GET',
        summary: 'Get a node type',
        description: 'A node type with its properties, references, whole configuration, merged with its super types\', and the node types allowed below its nodes and its tethered child nodes. The labels are translated to the Accept-Language, the ones in the configuration as well.',
        operationId: 'getNodeType',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::NODETYPES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        NodeTypeName $nodeTypeName,
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): NodeType|NotFound {
        $nodeTypeManager = $this->nodeTypeManager($contentRepositoryId);
        if ($nodeTypeManager === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        $nodeType = $nodeTypeManager->getNodeType($nodeTypeName->value);
        if ($nodeType === null) {
            return NotFound::because(sprintf('There is no node type %s', $nodeTypeName->value));
        }
        return NodeType::from(
            $nodeType,
            $this->labelTranslator->forAcceptLanguage($acceptLanguage),
            $this->nodeTypeUi,
            $nodeTypeManager,
            NodeType::childNodeTypeCandidates($nodeTypeManager),
            withProperties: true,
            withReferences: true,
            withConfiguration: true,
            withAllowedChildNodeTypes: true,
            withTetheredChildNodes: true,
        );
    }

    private function nodeTypeManager(ContentRepositoryId $contentRepositoryId): Core\NodeType\NodeTypeManager|null
    {
        return $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId())?->getNodeTypeManager();
    }
}
