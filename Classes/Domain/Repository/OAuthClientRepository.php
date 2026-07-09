<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Repository;

use Medienreaktor\NeosApi\Domain\Model\OAuthClient;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @method OAuthClient|null findOneByIdentifier(string $identifier)
 */
#[Flow\Scope('singleton')]
class OAuthClientRepository extends Repository
{
    public const ENTITY_CLASSNAME = OAuthClient::class;
}
