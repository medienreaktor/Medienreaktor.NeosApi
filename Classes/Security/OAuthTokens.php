<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\OAuth\Domain\Model\TokenType;
use Neos\OAuth\Domain\Repository\TokenRecordRepository;

/**
 * Revokes the OAuth tokens issued for an account, e.g. when its password changes
 *
 * Neos.OAuth tracks every access token, refresh token and authorization code as a TokenRecord. A refresh token's
 * record doesn't say which access token it belongs to, so the calling client's refresh token can't be told apart
 * from the others and is revoked as well: only the calling access token stays valid until it expires
 */
#[Flow\Scope('singleton')]
final readonly class OAuthTokens
{
    public function __construct(
        private TokenRecordRepository $tokenRecordRepository,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    /**
     * @param string|null $exceptAccessToken the identifier of an access token to keep, the caller's own (ApiCaller)
     * @return int how many were revoked
     */
    public function revokeAllOf(Account $account, ?string $exceptAccessToken = null): int
    {
        $revoked = 0;
        foreach ($this->tokenRecordRepository->findActive(null, $account->getAccountIdentifier(), new \DateTimeImmutable()) as $record) {
            if ($record->getType() === TokenType::ACCESS_TOKEN && $record->getIdentifier() === $exceptAccessToken) {
                continue;
            }
            $record->revoke();
            $this->tokenRecordRepository->update($record);
            $revoked++;
        }
        $this->persistenceManager->persistAll();
        return $revoked;
    }
}
