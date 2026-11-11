<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class DataSourceId implements ProvidesSchema
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
            description: 'The ID of a data source, the identifier its class declares',
            examples: ['acme-site-categories'],
            minLength: 1,
            maxLength: 255,
        );
    }
}
