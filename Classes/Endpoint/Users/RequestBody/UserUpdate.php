<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\RequestBody;

use Neos\Api\Endpoint\Users\Domain\NameAndEmailChange;
use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\Api\Endpoint\Users\Schema\PersonNamePart;
use Neos\Api\Endpoint\Users\Schema\RoleIdentifierList;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a Neos user: what is left out or null stays as it is
 */
final readonly class UserUpdate implements ProvidesSchema
{
    /**
     * @param EmailAddress|null $email the new primary email address
     * @param RoleIdentifierList|null $roles replace the roles of all of the user's accounts, at least one. Unless you are an administrator, only roles you have yourself
     * @param bool|null $active activates or deactivates all of the user's accounts, a deactivated account can't log in and its access tokens stop working
     */
    public function __construct(
        public PersonNamePart|null $firstName = null,
        public PersonNamePart|null $lastName = null,
        public EmailAddress|null $email = null,
        public RoleIdentifierList|null $roles = null,
        public bool|null $active = null,
    ) {
    }

    /**
     * Applies the name and email address, roles and active state are changed through the UserService by the caller
     */
    public function applyTo(Model\User $user): void
    {
        (new NameAndEmailChange($this->firstName, $this->lastName, $this->email))->applyTo($user);
    }

    /**
     * @return list<string>|null
     */
    public function roleIdentifiers(): ?array
    {
        return $this->roles?->identifiers();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
