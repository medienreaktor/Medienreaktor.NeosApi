<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class ContentRepositoryId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public function toContentRepositoryId(): SharedModel\ContentRepository\ContentRepositoryId
    {
        // can't throw: the schema has the same rules
        return SharedModel\ContentRepository\ContentRepositoryId::fromString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        // the rules of Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId
        return $schema ??= StringSchema::create(
            description: 'The identifier of a content repository, its key in the Neos.ContentRepositoryRegistry.contentRepositories settings',
            examples: ['default'],
            minLength: 2,
            maxLength: 15,
            pattern: '^[a-z][a-z0-9_]*[a-z]$',
        );
    }
}
