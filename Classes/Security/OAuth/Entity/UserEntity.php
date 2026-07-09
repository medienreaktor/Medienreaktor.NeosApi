<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Entity;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * Represents the resource owner; the identifier is the Flow account identifier
 * of the Neos user who approved the authorization.
 */
final class UserEntity implements UserEntityInterface
{
    use EntityTrait;

    public static function fromAccountIdentifier(string $accountIdentifier): self
    {
        $entity = new self();
        $entity->setIdentifier($accountIdentifier);

        return $entity;
    }
}
