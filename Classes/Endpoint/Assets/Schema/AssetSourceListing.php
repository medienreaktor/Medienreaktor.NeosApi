<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * All asset sources, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class AssetSourceListing implements ProvidesSchema
{
    public function __construct(
        public AssetSourceList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(AssetSourceList $data): self
    {
        return new self($data, new ListingMeta(count($data->assetSources)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
