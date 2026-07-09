<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Persisted state for issued OAuth tokens (access tokens, refresh tokens and
 * authorization codes) - required for revocation checks. The token itself is
 * never stored, only its identifier and lifecycle metadata.
 */
#[Flow\Entity]
class TokenRecord
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';
    public const TYPE_AUTH_CODE = 'auth_code';

    #[ORM\Column(unique: true)]
    protected string $identifier;

    #[ORM\Column(length: 20)]
    protected string $type;

    #[ORM\Column]
    protected string $clientIdentifier;

    #[ORM\Column(nullable: true)]
    protected ?string $accountIdentifier;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    protected array $scopes;

    #[ORM\Column]
    protected \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    protected bool $revoked = false;

    /**
     * @param array<string> $scopes
     */
    public function __construct(
        string $identifier,
        string $type,
        string $clientIdentifier,
        ?string $accountIdentifier,
        array $scopes,
        \DateTimeImmutable $expiresAt
    ) {
        $this->identifier = $identifier;
        $this->type = $type;
        $this->clientIdentifier = $clientIdentifier;
        $this->accountIdentifier = $accountIdentifier;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getAccountIdentifier(): ?string
    {
        return $this->accountIdentifier;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }
}
