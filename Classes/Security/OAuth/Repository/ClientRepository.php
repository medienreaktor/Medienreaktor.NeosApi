<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Medienreaktor\NeosApi\Domain\Repository\OAuthClientRepository;
use Medienreaktor\NeosApi\Security\OAuth\Entity\ClientEntity;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
class ClientRepository implements ClientRepositoryInterface
{
    #[Flow\Inject]
    protected OAuthClientRepository $clientRepository;

    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->clientRepository->findOneByIdentifier((string)$clientIdentifier);

        return $client === null ? null : ClientEntity::fromModel($client);
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = $this->clientRepository->findOneByIdentifier((string)$clientIdentifier);
        if ($client === null) {
            return false;
        }
        if ($grantType !== null && !$client->allowsGrantType((string)$grantType)) {
            return false;
        }
        if ($client->isConfidential()) {
            return is_string($clientSecret) && $clientSecret !== '' && $client->validateSecret($clientSecret);
        }

        // Public clients carry no secret; they are bound via PKCE
        return true;
    }
}
