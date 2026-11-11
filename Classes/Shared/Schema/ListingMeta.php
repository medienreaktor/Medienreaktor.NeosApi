<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What a list says about itself beyond its items, JSON:API's meta
 */
final readonly class ListingMeta implements ProvidesSchema
{
    public function __construct(
        public int $total,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What the list says about itself beyond its items',
            properties: ObjectProperties::create(
                total: IntegerSchema::create(description: 'How many items there are on all pages', minimum: 0),
            ),
            additionalProperties: false,
            required: ['total'],
        );
    }
}
