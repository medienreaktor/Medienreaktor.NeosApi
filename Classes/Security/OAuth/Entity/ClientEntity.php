<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use Medienreaktor\NeosApi\Domain\Model\OAuthClient;

final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    /**
     * @var array<string>
     */
    private array $allowedScopes = [];

    public static function fromModel(OAuthClient $client): self
    {
        $entity = new self();
        $entity->setIdentifier($client->getIdentifier());
        $entity->name = $client->getName();
        $entity->redirectUri = $client->getRedirectUris();
        $entity->isConfidential = $client->isConfidential();
        $entity->allowedScopes = $client->getAllowedScopes();

        return $entity;
    }

    /**
     * @return array<string>
     */
    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }
}
