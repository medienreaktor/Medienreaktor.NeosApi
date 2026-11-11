<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\OAuth\Security\AuthenticatedGrant;

/**
 * Who calls an operation: the #[AuthContext] argument of every secured operation
 *
 * The account's roles decide what the caller may do, the scopes can only narrow that down. Plain values, the API's
 * schema types for them are built where they are returned (Me)
 */
#[Flow\Proxy(false)]
final readonly class ApiCaller
{
    /**
     * @param list<string> $scopes
     * @param string|null $accessTokenIdentifier the identifier of the token's record in Neos.OAuth, see OAuthTokens
     */
    private function __construct(
        public Account $account,
        public string $clientIdentifier,
        public array $scopes,
        public ?string $accessTokenIdentifier,
    ) {
    }

    public static function fromGrant(AuthenticatedGrant $grant): self
    {
        return new self($grant->account, $grant->clientIdentifier, $grant->scopes, $grant->accessTokenIdentifier);
    }
}
