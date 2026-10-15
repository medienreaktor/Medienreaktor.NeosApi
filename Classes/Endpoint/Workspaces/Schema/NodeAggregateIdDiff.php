<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node aggregate id changed by a workspace compared to its base workspace, or by an event compared to before it, e.g.
 * the parent of a moved node
 */
final readonly class NodeAggregateIdDiff implements ProvidesSchema
{
    /**
     * @param NodeAggregateId $old the id before (in the base workspace, or before the event)
     * @param NodeAggregateId $new the id after (in the workspace, or as the event changed it)
     */
    public function __construct(
        public NodeAggregateId $old,
        public NodeAggregateId $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
