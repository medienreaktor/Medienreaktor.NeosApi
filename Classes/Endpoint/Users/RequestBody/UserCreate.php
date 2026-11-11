<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\RequestBody;

use Neos\Api\Endpoint\Users\Schema\AccountIdentifier;
use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\Api\Endpoint\Users\Schema\Password;
use Neos\Api\Endpoint\Users\Schema\PersonNamePart;
use Neos\Api\Endpoint\Users\Schema\RoleIdentifierList;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Party\Domain\Model\PersonName;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user to create, with a backend account to log in with
 */
final readonly class UserCreate implements ProvidesSchema
{
    public const string DEFAULT_ROLE = 'Neos.Neos:Editor';

    /**
     * @param AccountIdentifier $username the identifier of the backend account, unique among them
     * @param EmailAddress|null $email the primary email address
     * @param RoleIdentifierList|null $roles the roles of the account, Neos.Neos:Editor if left out. Unless you are an administrator, only roles you have yourself
     */
    public function __construct(
        public AccountIdentifier $username,
        public Password $password,
        public PersonNamePart $firstName,
        public PersonNamePart $lastName,
        public EmailAddress|null $email = null,
        public RoleIdentifierList|null $roles = null,
    ) {
    }

    /**
     * Applies the name and email address, the account is added by UserService::addUser()
     */
    public function applyTo(Model\User $user): void
    {
        $user->setName(new PersonName('', $this->firstName->value, '', $this->lastName->value, '', $this->username->value));
        $this->email?->makePrimaryOf($user);
    }

    /**
     * The roles the account gets, the default of UserService::addUser() if left out
     *
     * @return list<string>
     */
    public function roleIdentifiers(): array
    {
        return $this->roles?->identifiers() ?? [self::DEFAULT_ROLE];
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
