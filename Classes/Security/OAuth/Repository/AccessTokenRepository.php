<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Repository;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Medienreaktor\NeosApi\Domain\Model\TokenRecord;
use Medienreaktor\NeosApi\Domain\Repository\TokenRecordRepository;
use Medienreaktor\NeosApi\Security\OAuth\Entity\AccessTokenEntity;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

#[Flow\Scope('singleton')]
class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    #[Flow\Inject]
    protected TokenRecordRepository $tokenRecordRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * @param array<ScopeEntityInterface> $scopes
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $accessToken->setUserIdentifier((string)$userIdentifier);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $record = new TokenRecord(
            $accessTokenEntity->getIdentifier(),
            TokenRecord::TYPE_ACCESS,
            $accessTokenEntity->getClient()->getIdentifier(),
            $accessTokenEntity->getUserIdentifier() === null ? null : (string)$accessTokenEntity->getUserIdentifier(),
            array_map(static fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            \DateTimeImmutable::createFromInterface($accessTokenEntity->getExpiryDateTime())
        );
        $this->tokenRecordRepository->add($record);
        $this->persistenceManager->persistAll();
    }

    public function revokeAccessToken($tokenId): void
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$tokenId, TokenRecord::TYPE_ACCESS);
        if ($record !== null) {
            $record->revoke();
            $this->tokenRecordRepository->update($record);
            $this->persistenceManager->persistAll();
        }
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$tokenId, TokenRecord::TYPE_ACCESS);

        return $record === null || $record->isRevoked();
    }
}
