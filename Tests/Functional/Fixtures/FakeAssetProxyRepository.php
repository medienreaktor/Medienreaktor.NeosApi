<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\Tag;

/**
 * The assets of FakeAssetSource: no tags, collections or sorting, a search in the filenames
 */
final class FakeAssetProxyRepository implements AssetProxyRepositoryInterface
{
    public function __construct(
        private readonly FakeAssetSource $assetSource,
    ) {
    }

    public function getAssetProxy(string $identifier): AssetProxyInterface
    {
        if (!isset(FakeAssetSource::ASSETS[$identifier])) {
            throw new FakeAssetNotFound(sprintf('There is no asset %s', $identifier), 1791900101);
        }
        return new FakeAssetProxy($this->assetSource, $identifier);
    }

    public function filterByType(?AssetTypeFilter $assetType = null): void
    {
    }

    public function findAll(): AssetProxyQueryResultInterface
    {
        return $this->find(static fn () => true);
    }

    public function findBySearchTerm(string $searchTerm): AssetProxyQueryResultInterface
    {
        return $this->find(static fn (string $filename) => str_contains($filename, $searchTerm));
    }

    public function findByTag(Tag $tag): AssetProxyQueryResultInterface
    {
        return $this->find(static fn () => false);
    }

    public function findUntagged(): AssetProxyQueryResultInterface
    {
        return $this->findAll();
    }

    public function countAll(): int
    {
        return count(FakeAssetSource::ASSETS);
    }

    /**
     * @param \Closure(string): bool $filenameMatches
     */
    private function find(\Closure $filenameMatches): AssetProxyQueryResultInterface
    {
        $proxies = [];
        foreach (FakeAssetSource::ASSETS as $identifier => [$filename]) {
            if ($filenameMatches($filename)) {
                $proxies[] = new FakeAssetProxy($this->assetSource, $identifier);
            }
        }
        return (new FakeAssetProxyQuery($proxies))->execute();
    }
}
