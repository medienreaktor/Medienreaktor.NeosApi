<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Medienreaktor\NeosApi\Domain\Model\TokenRecord;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @method TokenRecord|null findOneByIdentifier(string $identifier)
 */
#[Flow\Scope('singleton')]
class TokenRecordRepository extends Repository
{
    public const ENTITY_CLASSNAME = TokenRecord::class;

    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    public function findByIdentifierAndType(string $identifier, string $type): ?TokenRecord
    {
        $query = $this->createQuery();
        $result = $query->matching(
            $query->logicalAnd(
                $query->equals('identifier', $identifier),
                $query->equals('type', $type)
            )
        )->execute()->getFirst();

        return $result instanceof TokenRecord ? $result : null;
    }

    /**
     * Bulk-delete expired token records. Safe because a missing record means
     * "revoked" to every revocation check - an expired token stays dead.
     */
    public function removeExpired(\DateTimeImmutable $now): int
    {
        return (int)$this->entityManager
            ->createQuery(sprintf('DELETE FROM %s t WHERE t.expiresAt < :now', TokenRecord::class))
            ->setParameter('now', $now)
            ->execute();
    }

    /**
     * All not-yet-expired, not-yet-revoked token records, optionally filtered
     * by client and/or account identifier.
     *
     * @return array<TokenRecord>
     */
    public function findActive(?string $clientIdentifier, ?string $accountIdentifier, \DateTimeImmutable $now): array
    {
        $query = $this->createQuery();
        $constraints = [
            $query->greaterThan('expiresAt', $now),
            $query->equals('revoked', false),
        ];
        if ($clientIdentifier !== null) {
            $constraints[] = $query->equals('clientIdentifier', $clientIdentifier);
        }
        if ($accountIdentifier !== null) {
            $constraints[] = $query->equals('accountIdentifier', $accountIdentifier);
        }

        return $query->matching($query->logicalAnd(...$constraints))->execute()->toArray();
    }
}
