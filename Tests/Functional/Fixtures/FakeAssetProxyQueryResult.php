<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

final class FakeAssetProxyQueryResult implements AssetProxyQueryResultInterface
{
    private int $position = 0;

    /**
     * @param list<FakeAssetProxy> $proxies the page
     */
    public function __construct(
        private readonly FakeAssetProxyQuery $query,
        private readonly array $proxies,
    ) {
    }

    public function getQuery(): AssetProxyQueryInterface
    {
        return $this->query;
    }

    public function getFirst(): ?AssetProxyInterface
    {
        return $this->proxies[0] ?? null;
    }

    public function toArray(): array
    {
        return $this->proxies;
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function current(): mixed
    {
        return $this->proxies[$this->position];
    }

    public function next(): void
    {
        $this->position++;
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->proxies[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->proxies[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->proxies[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('The result is read-only', 1791900102);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('The result is read-only', 1791900103);
    }
}
