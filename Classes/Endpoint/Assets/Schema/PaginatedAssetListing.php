<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A page of assets, as JSON:API's top level: the assets in data, the total in meta, the other pages in links
 */
final readonly class PaginatedAssetListing implements ProvidesSchema
{
    public function __construct(
        public AssetList $data,
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
