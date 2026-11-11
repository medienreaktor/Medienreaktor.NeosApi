<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * All sites, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class SiteListing implements ProvidesSchema
{
    public function __construct(
        public SiteList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(SiteList $data): self
    {
        return new self($data, new ListingMeta(count($data->sites)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
