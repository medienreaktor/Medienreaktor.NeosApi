<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The text of search, a full-text search in the node properties
 */
final readonly class SearchTerm implements ProvidesSchema
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
            description: 'Only nodes with a property containing this text',
            examples: ['neos'],
            minLength: 1,
        );
    }
}
