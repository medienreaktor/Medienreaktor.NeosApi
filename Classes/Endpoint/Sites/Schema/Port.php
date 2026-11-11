<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Schematic;

/**
 * The port of a domain, with the limits the Domain entity is validated with
 */
final readonly class Port implements ProvidesSchema
{
    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInteger(int $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= IntegerSchema::create(
            description: 'A TCP port',
            examples: [8080],
            minimum: 1,
            maximum: 49151,
        );
    }
}
