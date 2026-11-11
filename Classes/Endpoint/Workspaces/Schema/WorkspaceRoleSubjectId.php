<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * Who a workspace role is assigned to, by its type: the ID of a Neos user or the identifier of a Flow role
 */
final readonly class WorkspaceRoleSubjectId implements ProvidesSchema
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
        // the length of Neos\Neos\Domain\Model\WorkspaceRoleSubject, which checks the characters
        return $schema ??= StringSchema::create(
            description: 'The ID of a Neos user for the subject type user, the identifier of a Flow role for group',
            examples: ['0fd65ee4-9e85-4b4a-9f2c-4b7b2f3b9e44', 'Neos.Neos:AbstractEditor'],
            minLength: 1,
            maxLength: 200,
        );
    }
}
