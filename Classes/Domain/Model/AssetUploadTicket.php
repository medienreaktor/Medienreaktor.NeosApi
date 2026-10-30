<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Model;

/**
 * A short-lived permission to upload files as one account, carried by a URL
 * instead of a bearer token. Clients that can only send JSON (an MCP tool
 * call) create the ticket, and whoever holds the URL - a shell with curl, or
 * the user in a browser - sends the bytes.
 *
 * Only a hash of the secret is kept, so a leaked cache entry cannot be turned
 * back into a working URL. The ticket stays valid until it expires and
 * collects every asset uploaded with it.
 */
final readonly class AssetUploadTicket
{
    /**
     * @param array<int, string> $tags
     * @param array<int, string> $assetIdentifiers
     */
    private function __construct(
        public string $id,
        private string $secretHash,
        public string $accountIdentifier,
        public ?string $title,
        public ?string $collection,
        public array $tags,
        public \DateTimeImmutable $expiresAt,
        public array $assetIdentifiers,
    ) {
    }

    /**
     * @param array<int, string> $tags
     * @return array{0: self, 1: string} the ticket and its secret, which is
     *         returned exactly once and never stored
     */
    public static function issue(string $accountIdentifier, ?string $title, ?string $collection, array $tags, \DateTimeImmutable $expiresAt): array
    {
        $secret = bin2hex(random_bytes(32));
        $ticket = new self(
            bin2hex(random_bytes(16)),
            self::hash($secret),
            $accountIdentifier,
            $title,
            $collection,
            array_values($tags),
            $expiresAt,
            [],
        );

        return [$ticket, $secret];
    }

    public function matchesSecret(string $secret): bool
    {
        return hash_equals($this->secretHash, self::hash($secret));
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function withAsset(string $assetIdentifier): self
    {
        if (in_array($assetIdentifier, $this->assetIdentifiers, true)) {
            return $this;
        }

        return new self(
            $this->id,
            $this->secretHash,
            $this->accountIdentifier,
            $this->title,
            $this->collection,
            $this->tags,
            $this->expiresAt,
            [...$this->assetIdentifiers, $assetIdentifier],
        );
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
