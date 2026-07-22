<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\IconNameMappingService;

/**
 * Node type schema information - what generic clients (form generators, MCP
 * tools, validators) need to understand the content model.
 */
class NodeTypesController extends AbstractApiController
{
    #[Flow\Inject]
    protected IconNameMappingService $iconNameMappingService;

    /**
     * The node type groups (general, structure, plugins + site-specific ones)
     * that creation UIs group creatable node types by - each entry carries
     * label, position and collapsed.
     *
     * @var array<string, array<string, mixed>>
     */
    #[Flow\InjectConfiguration(package: 'Neos.Neos', path: 'nodeTypes.groups')]
    protected array $nodeTypeGroups;

    /**
     * Normalizes configured ui.icon values to modern Font Awesome classes.
     * Node types configure anything from FA3-era bare names ("picture") over
     * "icon-*" to full "fas fa-*" classes; Neos' own IconNameMappingService
     * holds the legacy mapping but only converts "icon-*" names, so bare
     * legacy names are prefixed first. Full classes (with a space) pass
     * through untouched.
     */
    private function normalizeIcon(?string $icon): ?string
    {
        if ($icon === null || $icon === '' || str_contains($icon, ' ')) {
            return $icon;
        }

        return $this->iconNameMappingService->convert(
            str_starts_with($icon, 'icon-') ? $icon : 'icon-' . $icon
        );
    }
    /**
     * @param bool $includeProperties Also serialize each node type's merged
     *                                property and reference declarations
     *                                (name, type, label) - opt-in because it
     *                                multiplies the payload and only schema
     *                                visualizations need it.
     */
    public function indexAction(bool $includeProperties = false): string
    {
        $this->requireScope('neos.read');

        $nodeTypes = [];
        foreach ($this->getContentRepository()->getNodeTypeManager()->getNodeTypes(true) as $nodeType) {
            $entry = [
                'name' => $nodeType->name->value,
                'abstract' => $nodeType->isAbstract(),
                'superTypes' => array_keys($nodeType->getDeclaredSuperTypes()),
                // untranslated label id / icon name as configured (ui.icon is
                // a Font Awesome name by Neos convention) - what tree UIs
                // need without fetching the full configuration
                'label' => $nodeType->getConfiguration('ui.label'),
                'icon' => $this->normalizeIcon($nodeType->getConfiguration('ui.icon')),
                // group + position drive creation UIs: only node types with a
                // group are offered for creation (Neos convention), sorted by
                // position within their group
                'group' => $nodeType->getConfiguration('ui.group'),
                'position' => $nodeType->getConfiguration('ui.position'),
            ];
            if ($includeProperties) {
                $properties = [];
                foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                    // Underscore-prefixed properties (_hidden, _nodeType) are
                    // internal plumbing, not content model.
                    if (str_starts_with((string)$propertyName, '_')) {
                        continue;
                    }
                    $properties[(string)$propertyName] = [
                        'type' => $propertyConfiguration['type'] ?? null,
                        'label' => $propertyConfiguration['ui']['label'] ?? null,
                    ];
                }
                $references = [];
                foreach (($nodeType->getConfiguration('references') ?? []) as $referenceName => $referenceConfiguration) {
                    $references[(string)$referenceName] = [
                        'label' => $referenceConfiguration['ui']['label'] ?? null,
                        // maxItems 1 marks a singular reference (Neos 9 folds
                        // legacy `type: reference` declarations in this way)
                        'maxItems' => $referenceConfiguration['constraints']['maxItems'] ?? null,
                    ];
                }
                // Force JSON objects - empty PHP arrays would serialize as []
                $entry['properties'] = (object)$properties;
                $entry['references'] = (object)$references;
            }
            $nodeTypes[] = $entry;
        }

        return $this->json(['nodeTypes' => $nodeTypes, 'groups' => $this->nodeTypeGroups]);
    }

    public function showAction(string $nodeTypeName): string
    {
        $this->requireScope('neos.read');

        $nodeType = $this->getContentRepository()->getNodeTypeManager()->getNodeType(NodeTypeName::fromString($nodeTypeName));
        if ($nodeType === null) {
            $this->throwJsonStatus(404, 'nodetype_not_found', 'The node type does not exist.');
        }

        return $this->json([
            'name' => $nodeType->name->value,
            'abstract' => $nodeType->isAbstract(),
            'superTypes' => array_keys($nodeType->getDeclaredSuperTypes()),
            'configuration' => $nodeType->getFullConfiguration(),
        ]);
    }
}
