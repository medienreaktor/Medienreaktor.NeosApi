<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class PackageKey implements ProvidesSchema
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
            description: 'The key of a Flow package',
            examples: ['Neos.Demo'],
            pattern: '^[A-Za-z0-9]+(\\.[A-Za-z0-9]+)+$',
        );
    }
}
