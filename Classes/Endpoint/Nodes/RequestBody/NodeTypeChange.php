<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node type a node changes to
 *
 * There is no applyTo(): a node's type is changed by a command, which the endpoint builds
 */
final readonly class NodeTypeChange implements ProvidesSchema
{
    /**
     * @param NodeTypeName $nodeType the new node type, as Node has it
     */
    public function __construct(
        public NodeTypeName $nodeType,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
