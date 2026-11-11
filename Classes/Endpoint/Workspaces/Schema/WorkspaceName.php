<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class WorkspaceName implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public function toWorkspaceName(): SharedModel\Workspace\WorkspaceName
    {
        // can't throw: the schema has the same rules
        return SharedModel\Workspace\WorkspaceName::fromString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        // the rules of Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName
        return $schema ??= StringSchema::create(
            description: 'The name of a workspace, live or e.g. a user\'s personal workspace',
            examples: ['live', 'user-admin'],
            minLength: 1,
            maxLength: 36,
            pattern: '^[a-z0-9][a-z0-9\-]*$',
        );
    }
}
