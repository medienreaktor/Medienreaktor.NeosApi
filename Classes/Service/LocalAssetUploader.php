<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;

/**
 * Stores an uploaded file as a local asset - shared by the direct multipart
 * upload and the upload via ticket URL, so both behave identically.
 */
#[Flow\Scope('singleton')]
class LocalAssetUploader
{
    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected TagRepository $tagRepository;

    #[Flow\Inject]
    protected AssetCollectionRepository $assetCollectionRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Unknown tag and collection identifiers are ignored. An identical file
     * arrives as the already-persisted asset (one-per-resource dedupe).
     *
     * @param array<int, string> $tags
     */
    public function store(Asset $asset, ?string $collection, array $tags): void
    {
        if ($this->persistenceManager->isNewObject($asset)) {
            $this->assetRepository->add($asset);
        } else {
            $this->assetRepository->update($asset);
        }

        foreach ($tags as $tagIdentifier) {
            $tag = $tagIdentifier === '' ? null : $this->tagRepository->findByIdentifier($tagIdentifier);
            if ($tag instanceof Tag) {
                $asset->addTag($tag);
            }
        }
        $resolvedCollection = $collection === null || $collection === '' ? null : $this->assetCollectionRepository->findByIdentifier($collection);
        if ($resolvedCollection instanceof AssetCollection) {
            // setAssetCollections syncs both sides, so a serialized response
            // reflects the assignment without a re-fetch.
            $asset->setAssetCollections(new ArrayCollection([$resolvedCollection]));
        }
        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();
    }
}
