<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\Flow\Security;
use Neos\Flow\Security\Policy\Role;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos account and the roles assigned to it
 */
final readonly class Account implements ProvidesSchema
{
    public function __construct(
        public AccountIdentifier $identifier,
        public RoleIdentifierList $roles,
    ) {
    }

    public static function from(Security\Account $account): self
    {
        return new self(
            AccountIdentifier::fromString($account->getAccountIdentifier()),
            new RoleIdentifierList(...array_map(
                static fn (Role $role) => RoleIdentifier::fromString($role->getIdentifier()),
                array_values($account->getRoles()),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
