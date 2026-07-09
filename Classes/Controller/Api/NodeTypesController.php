<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;

/**
 * Node type schema information - what generic clients (form generators, MCP
 * tools, validators) need to understand the content model.
 */
class NodeTypesController extends AbstractApiController
{
    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $nodeTypes = [];
        foreach ($this->getContentRepository()->getNodeTypeManager()->getNodeTypes(true) as $nodeType) {
            $nodeTypes[] = [
                'name' => $nodeType->name->value,
                'abstract' => $nodeType->isAbstract(),
                'superTypes' => array_keys($nodeType->getDeclaredSuperTypes()),
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
