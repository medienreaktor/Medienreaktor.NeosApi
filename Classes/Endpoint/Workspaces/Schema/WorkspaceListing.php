<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The workspaces of a content repository the account may read, as JSON:API's top level: the items in data, the total
 * in meta. Not paged, so no links
 */
final readonly class WorkspaceListing implements ProvidesSchema
{
    public function __construct(
        public WorkspaceList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(WorkspaceList $data): self
    {
        return new self($data, new ListingMeta(count($data->workspaces)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
