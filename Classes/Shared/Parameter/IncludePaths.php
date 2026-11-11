<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Parameter;

use Neos\Api\Shared\Response\BadRequest;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * What to include in a resource beyond its own fields: a comma-separated list of paths
 */
final readonly class IncludePaths implements ProvidesSchema
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
     * Nothing to include, for an omitted include
     */
    public static function none(): self
    {
        return new self('');
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->value === '' ? [] : array_values(array_unique(explode(',', $this->value)));
    }

    /**
     * Whether the path is included, as JSON:API's: by itself or by a path going through it, children.references
     * includes children
     */
    public function includes(string $path): bool
    {
        return array_filter($this->paths(), static fn (string $included) => $included === $path || str_starts_with($included, $path . '.')) !== [];
    }

    /**
     * The 400 for the paths the resource can't include, null if it can include them all
     *
     * @param list<string> $supported
     */
    public function unsupported(array $supported): ?BadRequest
    {
        $unknown = array_diff($this->paths(), $supported);
        return $unknown !== [] ? BadRequest::because(sprintf('Can\'t include %s, only: %s', implode(', ', $unknown), implode(', ', $supported))) : null;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'What to include beyond the resource\'s own fields, comma-separated, a path like children.references includes the ones it goes through',
            examples: ['references'],
            pattern: '^[a-zA-Z][a-zA-Z0-9.]*(,[a-zA-Z][a-zA-Z0-9.]*)*$',
        );
    }
}
