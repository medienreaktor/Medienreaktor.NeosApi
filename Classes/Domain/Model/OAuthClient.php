<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * A registered OAuth 2.1 client (confidential via CLI, public via dynamic client registration)
 */
#[Flow\Entity]
class OAuthClient
{
    #[ORM\Column(unique: true)]
    protected string $identifier;

    #[ORM\Column]
    protected string $name;

    /**
     * Hash of the client secret; null for public clients (PKCE only)
     */
    #[ORM\Column(nullable: true, length: 255)]
    protected ?string $secretHash;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    protected array $redirectUris;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    protected array $grantTypes;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    protected array $allowedScopes;

    #[ORM\Column]
    protected \DateTimeImmutable $createdAt;

    /**
     * @param array<string> $redirectUris
     * @param array<string> $grantTypes
     * @param array<string> $allowedScopes
     */
    public function __construct(
        string $identifier,
        string $name,
        ?string $secretHash,
        array $redirectUris,
        array $grantTypes,
        array $allowedScopes
    ) {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->secretHash = $secretHash;
        $this->redirectUris = $redirectUris;
        $this->grantTypes = $grantTypes;
        $this->allowedScopes = $allowedScopes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isConfidential(): bool
    {
        return $this->secretHash !== null;
    }

    public function validateSecret(string $secret): bool
    {
        return $this->secretHash !== null && password_verify($secret, $this->secretHash);
    }

    /**
     * @return array<string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /**
     * @return array<string>
     */
    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    public function allowsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }

    /**
     * @return array<string>
     */
    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
