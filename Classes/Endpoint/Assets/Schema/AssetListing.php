<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Assets that aren't paged (an image's variants), as JSON:API's top level: the items in data, the total in meta
 */
final readonly class AssetListing implements ProvidesSchema
{
    public function __construct(
        public AssetList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(AssetList $data): self
    {
        return new self($data, new ListingMeta(count($data->assets)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
