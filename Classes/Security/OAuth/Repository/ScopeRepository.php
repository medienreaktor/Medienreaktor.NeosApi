<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Medienreaktor\NeosApi\Security\OAuth\Entity\ClientEntity;
use Medienreaktor\NeosApi\Security\OAuth\Entity\ScopeEntity;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * @var array<string, string>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth.scopes')]
    protected array $scopes = [];

    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        if (!array_key_exists((string)$identifier, $this->scopes)) {
            return null;
        }

        return ScopeEntity::fromIdentifier((string)$identifier);
    }

    /**
     * Intersect the requested scopes with the scopes the client is allowed to
     * obtain. Scopes can only ever narrow access: the effective permissions of
     * a request are the intersection of token scopes and the roles/policies of
     * the account the token is bound to.
     *
     * @param array<ScopeEntityInterface> $scopes
     * @return array<ScopeEntityInterface>
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        if (!$clientEntity instanceof ClientEntity) {
            return [];
        }
        $allowed = $clientEntity->getAllowedScopes();

        return array_values(array_filter(
            $scopes,
            fn (ScopeEntityInterface $scope) => in_array($scope->getIdentifier(), $allowed, true)
        ));
    }
}
