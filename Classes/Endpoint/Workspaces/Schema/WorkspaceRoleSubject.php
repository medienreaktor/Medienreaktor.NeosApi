<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Whom a workspace role is assigned to: a Neos user or a group, every account with a Flow role
 */
final readonly class WorkspaceRoleSubject implements ProvidesSchema
{
    /**
     * @param WorkspaceRoleSubjectType $type user or group
     * @param WorkspaceRoleSubjectId $id the user's ID or the Flow role's identifier
     */
    public function __construct(
        public WorkspaceRoleSubjectType $type,
        public WorkspaceRoleSubjectId $id,
    ) {
    }

    public static function from(Model\WorkspaceRoleSubject $subject): self
    {
        return new self(WorkspaceRoleSubjectType::from(strtolower($subject->type->value)), WorkspaceRoleSubjectId::fromString($subject->value));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
