<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * How to sort a list, as JSON:API's sort: comma-separated fields, ascending unless prefixed with -
 */
final readonly class Sort implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * @return list<array{field: string, descending: bool}>
     */
    public function fields(): array
    {
        return array_map(
            static fn (string $field) => ['field' => ltrim($field, '-'), 'descending' => str_starts_with($field, '-')],
            explode(',', $this->value),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The fields to sort by, comma-separated, the first one first, each ascending unless prefixed with -',
            examples: ['-timestamps.lastModified'],
            pattern: '^-?[a-zA-Z_][a-zA-Z0-9_.]*(,-?[a-zA-Z_][a-zA-Z0-9_.]*)*$',
        );
    }
}
