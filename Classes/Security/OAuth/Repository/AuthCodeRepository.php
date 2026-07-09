<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Repository;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Medienreaktor\NeosApi\Domain\Model\TokenRecord;
use Medienreaktor\NeosApi\Domain\Repository\TokenRecordRepository;
use Medienreaktor\NeosApi\Security\OAuth\Entity\AuthCodeEntity;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

#[Flow\Scope('singleton')]
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    #[Flow\Inject]
    protected TokenRecordRepository $tokenRecordRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $record = new TokenRecord(
            $authCodeEntity->getIdentifier(),
            TokenRecord::TYPE_AUTH_CODE,
            $authCodeEntity->getClient()->getIdentifier(),
            $authCodeEntity->getUserIdentifier() === null ? null : (string)$authCodeEntity->getUserIdentifier(),
            array_map(static fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $authCodeEntity->getScopes()),
            \DateTimeImmutable::createFromInterface($authCodeEntity->getExpiryDateTime())
        );
        $this->tokenRecordRepository->add($record);
        $this->persistenceManager->persistAll();
    }

    public function revokeAuthCode($codeId): void
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$codeId, TokenRecord::TYPE_AUTH_CODE);
        if ($record !== null) {
            $record->revoke();
            $this->tokenRecordRepository->update($record);
            $this->persistenceManager->persistAll();
        }
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        $record = $this->tokenRecordRepository->findByIdentifierAndType((string)$codeId, TokenRecord::TYPE_AUTH_CODE);

        return $record === null || $record->isRevoked();
    }
}
