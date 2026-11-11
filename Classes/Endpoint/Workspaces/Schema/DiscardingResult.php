<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a discard did: how many changes were dropped, and the workspace afterwards
 */
final readonly class DiscardingResult implements ProvidesSchema
{
    /**
     * @param int $numberOfDiscardedChanges as Neos counts them (Neos' DiscardingResult, summed over the documents), 0 if there was nothing to discard
     * @param Workspace $workspace the workspace as it is now, as getWorkspace returns it
     */
    public function __construct(
        public int $numberOfDiscardedChanges,
        public Workspace $workspace,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
