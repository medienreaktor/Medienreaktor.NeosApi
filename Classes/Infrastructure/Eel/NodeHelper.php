<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Eel;

use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * Neos.Api.Node in Fusion: what views need about a node beyond Neos.Node, e.g. for trees of an editing UI
 *
 * Eel creates helpers with `new`, so the dependencies are injected properties, not constructor arguments (and the
 * class can't be readonly, Flow can't proxy that)
 */
final class NodeHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeSerializer $nodeSerializer;

    #[Flow\Inject]
    protected NodeTypeUi $nodeTypeUi;

    #[Flow\Inject]
    protected LabelTranslator $labelTranslator;

    /**
     * The child nodes of the node types in their order, unlike FlowQuery's children() with several filters, which
     * returns them grouped by filter
     *
     * @param string $nodeTypes a node type filter, e.g. "Neos.Neos:Content,Neos.Neos:ContentCollection" or "!Neos.Neos:Document"
     * @return list<Node>
     */
    public function childNodes(Node $node, string $nodeTypes): array
    {
        return iterator_to_array($this->contentRepositoryRegistry->subgraphForNode($node)->findChildNodes(
            $node->aggregateId,
            FindChildNodesFilter::create(nodeTypes: $nodeTypes),
        ), false);
    }

    /**
     * Whether the node has child nodes of the node types, without loading them
     *
     * @param string $nodeTypes a node type filter as for childNodes()
     */
    public function hasChildNodes(Node $node, string $nodeTypes): bool
    {
        return $this->nodeSerializer->hasChildren($node, $nodeTypes);
    }

    /**
     * The properties in their serialized form, as the content repository stores them, so they are JSON-safe: e.g. a
     * date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone.
     * An object, so a node without properties encodes as {}, not []
     */
    public function properties(Node $node): \stdClass
    {
        return (object)$this->nodeSerializer->properties($node->properties);
    }

    /**
     * The references the node type declares, each with the referenced nodes in their order as Node.references of the
     * nodes endpoint has them, {"nodeAggregateId": "…", "properties": {…}}, an empty list if there are none. An
     * object, so a node without references encodes as {}, not []
     */
    public function references(Node $node): \stdClass
    {
        return (object)$this->nodeSerializer->references($node);
    }

    /**
     * The label as plain text: the label generator may return markup, e.g. of a text property
     */
    public function label(Node $node): string
    {
        return $this->nodeSerializer->label($node);
    }

    /**
     * Whether the node itself is hidden, not just inside a hidden node
     */
    public function isHidden(Node $node): bool
    {
        return $this->nodeSerializer->isHidden($node);
    }

    /**
     * Whether the node is inside a hidden node, which hides it as well
     */
    public function isHiddenByAncestor(Node $node): bool
    {
        return $this->nodeSerializer->isHiddenByAncestor($node);
    }

    /**
     * Whether the node is removed (soft-deleted), itself or inside a removed node. Neos never shows removed nodes,
     * so this is false for every node a view finds, unless its subgraph includes removed nodes
     */
    public function isRemoved(Node $node): bool
    {
        return $node->tags->contain(NeosSubtreeTag::removed());
    }

    /**
     * Whether the node is shown in its dimension space point with the content of another one (a fallback), e.g. en_UK
     * showing en_US
     */
    public function isShineThrough(Node $node): bool
    {
        return $this->nodeSerializer->isShineThrough($node);
    }

    /**
     * @return array{created: string, lastModified: string|null, originalCreated: string, originalLastModified: string|null} as ISO 8601, the original ones are of the live workspace
     */
    public function timestamps(Node $node): array
    {
        return $this->nodeSerializer->timestamps($node);
    }

    /**
     * The node type's ui.icon as Font Awesome classes, legacy names ("file", "icon-file") mapped as in the Neos UI and
     * the node types endpoint
     */
    public function nodeTypeIcon(Node $node): ?string
    {
        $nodeType = $this->nodeType($node);
        return $nodeType !== null ? $this->nodeTypeUi->icon($nodeType) : null;
    }

    /**
     * The node type's ui.label, translated to the Accept-Language of the request as all labels of the API, its name
     * if it has none as in the node types endpoint. Null if the node type doesn't exist anymore
     *
     * @param ActionRequest $request the Fusion global request
     */
    public function nodeTypeLabel(Node $node, ActionRequest $request): ?string
    {
        $nodeType = $this->nodeType($node);
        if ($nodeType === null) {
            return null;
        }
        $acceptLanguage = $request->getHttpRequest()->getHeaderLine('Accept-Language');
        return $this->nodeTypeUi->label($nodeType, $this->labelTranslator->forAcceptLanguage($acceptLanguage !== '' ? AcceptLanguage::fromString($acceptLanguage) : null));
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }

    private function nodeType(Node $node): ?NodeType
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
    }
}
