<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;

    public static function fromIdentifier(string $identifier): self
    {
        $entity = new self();
        $entity->setIdentifier($identifier);

        return $entity;
    }
}
