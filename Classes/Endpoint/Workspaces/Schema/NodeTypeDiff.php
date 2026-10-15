<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node type changed by a workspace compared to its base workspace, or by an event compared to before it
 */
final readonly class NodeTypeDiff implements ProvidesSchema
{
    /**
     * @param NodeTypeName $old the node type before (in the base workspace, or before the event)
     * @param NodeTypeName $new the node type after (in the workspace, or as the event changed it)
     */
    public function __construct(
        public NodeTypeName $old,
        public NodeTypeName $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
