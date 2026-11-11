<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Caches\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class CacheId implements ProvidesSchema
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
        // the rules of Neos\Flow\Cache's cache identifiers
        return $schema ??= StringSchema::create(
            description: 'The identifier of a cache, its key in the Caches.yaml configuration',
            examples: ['Neos_Fusion_Content'],
            minLength: 1,
            maxLength: 250,
            pattern: '^[a-zA-Z0-9_%\-&]+$',
        );
    }
}
