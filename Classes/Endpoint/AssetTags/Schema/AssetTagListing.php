<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The tag tree, as JSON:API's top level: the tags at the root in data, how many tags there are in meta. Not paged, so
 * no links
 */
final readonly class AssetTagListing implements ProvidesSchema
{
    public function __construct(
        public AssetTagList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
