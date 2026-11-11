<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Domain;

use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\Api\Endpoint\Users\Schema\PersonNamePart;
use Neos\Neos\Domain\Model;

/**
 * Changes to the name and email address of a user, what is null stays as it is: what updateUser
 * (RequestBody\UserUpdate) and updateProfile (Me\RequestBody\ProfileUpdate) have in common
 */
final readonly class NameAndEmailChange
{
    public function __construct(
        private PersonNamePart|null $firstName,
        private PersonNamePart|null $lastName,
        private EmailAddress|null $email,
    ) {
    }

    public function applyTo(Model\User $user): void
    {
        if ($this->firstName !== null) {
            $user->getName()->setFirstName($this->firstName->value);
        }
        if ($this->lastName !== null) {
            $user->getName()->setLastName($this->lastName->value);
        }
        $this->email?->makePrimaryOf($user);
    }
}
