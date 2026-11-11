<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The usages of an asset, as JSON:API's top level: the readable ones in data, how many there are in meta. Not paged,
 * so no links
 */
final readonly class AssetUsageListing implements ProvidesSchema
{
    public function __construct(
        public AssetUsageList $data,
        public AssetUsageListingMeta $meta,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
