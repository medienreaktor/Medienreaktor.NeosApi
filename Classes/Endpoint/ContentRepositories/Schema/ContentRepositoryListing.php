<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * All content repositories, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class ContentRepositoryListing implements ProvidesSchema
{
    public function __construct(
        public ContentRepositoryList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(ContentRepositoryList $data): self
    {
        return new self($data, new ListingMeta(count($data->contentRepositories)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
