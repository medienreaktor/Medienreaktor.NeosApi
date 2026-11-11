<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

final class FakeAssetProxyQuery implements AssetProxyQueryInterface
{
    private int $offset = 0;

    private int $limit = PHP_INT_MAX;

    private string $searchTerm = '';

    /**
     * @param list<FakeAssetProxy> $proxies
     */
    public function __construct(
        private readonly array $proxies,
    ) {
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setSearchTerm(string $searchTerm)
    {
        $this->searchTerm = $searchTerm;
    }

    public function getSearchTerm()
    {
        return $this->searchTerm;
    }

    public function execute(): AssetProxyQueryResultInterface
    {
        return new FakeAssetProxyQueryResult($this, array_slice($this->proxies, $this->offset, $this->limit));
    }

    public function count(): int
    {
        return count($this->proxies);
    }
}
