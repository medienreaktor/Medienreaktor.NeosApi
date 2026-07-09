<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Repository;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Medienreaktor\NeosApi\Domain\Model\TokenRecord;
use Medienreaktor\NeosApi\Domain\Repository\TokenRecordRepository;
use Medienreaktor\NeosApi\Security\OAuth\Entity\RefreshTokenEntity;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

#[Flow\Scope('singleton')]
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    #[Flow\Inject]
    protected TokenRecordRepository $tokenRecordRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = $refreshTokenEntity->getAccessToken();
        $record = new TokenRecord(
            $refreshTokenEntity->getIdentifier(),
            TokenRecord::TYPE_REFRESH,
            $accessToken->getClient()->getIdentifier(),
            $accessToken->getUserIdentifier() === null ? null : (string)$accessToken->getUserIdentifier(),
            [],
            \DateTimeImmutable::createFromInterface($refreshTokenEntity->getExpiryDateTime())
        );
        $this->tokenRecordRepository->add($record);
        $this->persistenceManager->persistAll();
    }

    public function revokeRefreshToken($tokenId): void
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$tokenId, TokenRecord::TYPE_REFRESH);
        if ($record !== null) {
            $record->revoke();
            $this->tokenRecordRepository->update($record);
            $this->persistenceManager->persistAll();
        }
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$tokenId, TokenRecord::TYPE_REFRESH);

        return $record === null || $record->isRevoked();
    }
}
