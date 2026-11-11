<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Media;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\AssetCollectionRepository;

/**
 * Finds the collections of the media library as the account may see them (Neos' media privileges, Doctrine filters)
 * and counts their assets
 */
#[Flow\Scope('singleton')]
final readonly class AssetCollectionFinder
{
    public function __construct(
        private AssetCollectionRepository $assetCollectionRepository,
        private AssetCounter $assetCounter,
    ) {
    }

    /**
     * The collection, null if there is none with the ID the account may see
     */
    public function find(string $assetCollectionId): ?Model\AssetCollection
    {
        $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId);
        return $assetCollection instanceof Model\AssetCollection ? $assetCollection : null;
    }

    /**
     * How many assets are in each collection, by collection ID, see AssetCounter
     *
     * @return array<string, int>
     */
    public function assetCounts(): array
    {
        return $this->assetCounter->countBy('assetCollections');
    }
}
