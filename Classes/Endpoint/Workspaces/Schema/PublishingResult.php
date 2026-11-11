<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a publish did: how many changes went to the base workspace, and the workspace afterwards
 */
final readonly class PublishingResult implements ProvidesSchema
{
    /**
     * @param int $numberOfPublishedChanges as Neos counts them (Neos' PublishingResult, summed over the documents), 0 if there was nothing to publish
     * @param Workspace $workspace the published workspace as it is now, as getWorkspace returns it
     */
    public function __construct(
        public int $numberOfPublishedChanges,
        public Workspace $workspace,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
