<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\People\Schema;

use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * All people, as JSON:API's top level: the items in data, the total in meta. Not paged, so no links
 */
final readonly class PersonListing implements ProvidesSchema
{
    public function __construct(
        public PersonList $data,
        public ListingMeta $meta,
    ) {
    }

    public static function of(PersonList $data): self
    {
        return new self($data, new ListingMeta(count($data->people)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
