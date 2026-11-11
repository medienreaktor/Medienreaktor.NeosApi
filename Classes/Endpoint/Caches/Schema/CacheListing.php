<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Caches\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * All caches, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class CacheListing implements ProvidesSchema
{
    public function __construct(
        public CacheList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(CacheList $data): self
    {
        return new self($data, new ListingMeta(count($data->caches)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
