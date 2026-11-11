<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a user can be created or changed with, by the caller
 */
final readonly class UserOptions implements ProvidesSchema
{
    /**
     * @param AccountRoleList $roles the roles the caller can give users, ordered by label: all roles that aren't abstract for administrators, else the caller's own
     */
    public function __construct(
        public AccountRoleList $roles,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
