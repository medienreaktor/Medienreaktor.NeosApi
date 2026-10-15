<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node name changed by a workspace compared to its base workspace, or by an event compared to before it
 */
final readonly class NodeNameDiff implements ProvidesSchema
{
    /**
     * @param NodeName|null $old the name before (in the base workspace, or before the event), null if it had none
     * @param NodeName|null $new the name after (in the workspace, or as the event changed it), null if it has none
     */
    public function __construct(
        public NodeName|null $old,
        public NodeName|null $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
