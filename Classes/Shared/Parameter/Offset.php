<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Parameter;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Schematic;

/**
 * Where a page of a list starts: ?offset=50, how many items are skipped
 */
final readonly class Offset implements ProvidesSchema
{
    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInteger(int $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * The first page
     */
    public static function none(): self
    {
        return new self(0);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= IntegerSchema::create(description: 'How many items to skip', default: 0, minimum: 0);
    }
}
