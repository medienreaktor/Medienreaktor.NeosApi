<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Parameter;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Schematic;

/**
 * How long a page of a list is: ?limit=25, how many items at most
 */
final readonly class Limit implements ProvidesSchema
{
    public const int DEFAULT = 25;
    public const int MAX = 100;

    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInteger(int $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= IntegerSchema::create(description: 'How many items at most', default: self::DEFAULT, minimum: 1, maximum: self::MAX);
    }
}
