<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\StringFormat;
use Neos\Schematic\Schematic;

final readonly class AssetCollectionId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The ID of an asset collection',
            examples: ['8c2b4c4e-5d0f-4a1e-9a43-6b0e6c1f2d3a'],
            format: StringFormat::uuid,
        );
    }
}
