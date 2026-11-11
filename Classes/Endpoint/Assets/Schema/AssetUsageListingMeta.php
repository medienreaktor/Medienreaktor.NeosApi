<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What the usages of an asset say about themselves: how many there are, and how many the account may not read
 */
final readonly class AssetUsageListingMeta implements ProvidesSchema
{
    public function __construct(
        public int $total,
        public int $inaccessible,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What the list says about itself beyond its items',
            properties: ObjectProperties::create(
                total: IntegerSchema::create(description: 'How many usages are listed', minimum: 0),
                inaccessible: IntegerSchema::create(description: 'How many usages there are besides, in nodes or workspaces the account may not read: the asset is in use even if none are listed', minimum: 0),
            ),
            additionalProperties: false,
            required: ['total', 'inaccessible'],
        );
    }
}
