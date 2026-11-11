<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class Hostname implements ProvidesSchema
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
            description: 'A host name without scheme, port or path',
            examples: ['www.example.com'],
            minLength: 4,
            maxLength: 253,
            // the host names of Neos' HostnameValidator, which the Domain entity is validated with
            pattern: '^(localhost|((?!-)[a-zA-Z0-9-]{1,63}(?<!-)\\.)*(?!-)[a-zA-Z]{2,63}(?<!-))$',
        );
    }
}
