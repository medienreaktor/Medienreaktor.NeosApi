<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\Authentication\Token;

use Neos\Flow\Security\Authentication\Token\BearerToken;

/**
 * Flow's BearerToken (which already extracts "Authorization: Bearer ..." and
 * is sessionless), extended to carry the OAuth scopes granted to the token so
 * controllers can enforce scope narrowing.
 */
class ApiBearerToken extends BearerToken
{
    /**
     * @var array<string>
     */
    protected array $scopes = [];

    protected ?string $clientIdentifier = null;

    /**
     * @param array<string> $scopes
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function setClientIdentifier(string $clientIdentifier): void
    {
        $this->clientIdentifier = $clientIdentifier;
    }

    public function getClientIdentifier(): ?string
    {
        return $this->clientIdentifier;
    }
}
