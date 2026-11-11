<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user
 */
final readonly class User implements ProvidesSchema
{
    /**
     * @param EmailAddress|null $email the primary email address, null if the user has none
     * @param bool $active whether one of the user's accounts may log in
     */
    public function __construct(
        public UserId $id,
        public string $label,
        public string $firstName,
        public string $lastName,
        public EmailAddress|null $email,
        public bool $active,
        public AccountList $accounts,
    ) {
    }

    public static function from(Model\User $user): self
    {
        return new self(
            UserId::fromString($user->getId()->value),
            $user->getLabel(),
            $user->getName()->getFirstName(),
            $user->getName()->getLastName(),
            EmailAddress::primaryOf($user),
            $user->isActive(),
            new AccountList(...array_map(
                Account::from(...),
                self::accountsOf($user),
            )),
        );
    }

    /**
     * The user's accounts: Neos.Party declares a collection or an array, Doctrine hands out a collection
     *
     * @return list<\Neos\Flow\Security\Account>
     */
    public static function accountsOf(Model\User $user): array
    {
        $accounts = $user->getAccounts();
        return array_values(is_array($accounts) ? $accounts : $accounts->toArray());
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
