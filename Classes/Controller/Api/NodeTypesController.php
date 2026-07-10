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
    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $nodeTypes = [];
        foreach ($this->getContentRepository()->getNodeTypeManager()->getNodeTypes(true) as $nodeType) {
            $nodeTypes[] = [
                'name' => $nodeType->name->value,
                'abstract' => $nodeType->isAbstract(),
                'superTypes' => array_keys($nodeType->getDeclaredSuperTypes()),
                // untranslated label id / icon name as configured (ui.icon is
                // a Font Awesome name by Neos convention) - what tree UIs
                // need without fetching the full configuration
                'label' => $nodeType->getConfiguration('ui.label'),
                'icon' => $this->normalizeIcon($nodeType->getConfiguration('ui.icon')),
            ];
        }

        return $this->json(['nodeTypes' => $nodeTypes]);
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
