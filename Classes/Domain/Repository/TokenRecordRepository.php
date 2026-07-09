<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Repository;

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
}
