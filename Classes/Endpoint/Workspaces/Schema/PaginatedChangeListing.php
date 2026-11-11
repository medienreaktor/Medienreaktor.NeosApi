<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A page of changes, as JSON:API's top level: the changes in data, the total in meta, the other pages in links
 */
final readonly class PaginatedChangeListing implements ProvidesSchema
{
    public function __construct(
        public ChangeList $data,
        public ListingMeta $meta,
        public ListingLinks $links,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
