<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api\Media;

use Medienreaktor\NeosApi\Controller\Api\AbstractApiController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\Domain\Repository\SiteRepository;

/**
 * Asset collections as a flat list with CRUD. Collections group assets
 * (a many-to-many); deleting one only detaches it, the assets remain.
 */
class CollectionsController extends AbstractApiController
{
    #[Flow\Inject]
    protected AssetCollectionRepository $assetCollectionRepository;

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function indexAction(): string
    {
        $this->requireScope('neos.media');

        $collections = [];
        foreach ($this->assetCollectionRepository->findAll() as $collection) {
            /** @var AssetCollection $collection */
            $collections[] = $this->serialize($collection);
        }

        return $this->json(['collections' => $collections]);
    }

    #[Flow\SkipCsrfProtection]
    public function createAction(string $title): string
    {
        $this->requireScope('neos.media');

        $collection = new AssetCollection($title);
        $this->assetCollectionRepository->add($collection);
        $this->persistenceManager->persistAll();

        return $this->json(['collection' => $this->serialize($collection)], 201);
    }

    #[Flow\SkipCsrfProtection]
    public function updateAction(string $collectionIdentifier, string $title): string
    {
        $this->requireScope('neos.media');
        $collection = $this->requireCollection($collectionIdentifier);

        $collection->setTitle($title);
        $this->assetCollectionRepository->update($collection);
        $this->persistenceManager->persistAll();

        return $this->json(['collection' => $this->serialize($collection)]);
    }

    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $collectionIdentifier): string
    {
        $this->requireScope('neos.media');
        $collection = $this->requireCollection($collectionIdentifier);

        // A collection may be a site's asset collection; detach it first so the
        // foreign key does not block removal (mirrors the classic module).
        foreach ($this->siteRepository->findByAssetCollection($collection) as $site) {
            $site->setAssetCollection(null);
            $this->siteRepository->update($site);
        }

        $this->assetCollectionRepository->remove($collection);
        $this->persistenceManager->persistAll();

        return $this->json(['deleted' => true]);
    }

    private function requireCollection(string $identifier): AssetCollection
    {
        $collection = $this->assetCollectionRepository->findByIdentifier($identifier);
        if (!$collection instanceof AssetCollection) {
            $this->throwJsonStatus(404, 'collection_not_found', 'The collection does not exist.');
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AssetCollection $collection): array
    {
        return [
            'identifier' => $this->persistenceManager->getIdentifierByObject($collection),
            'title' => $collection->getTitle(),
            'assetCount' => $this->assetRepository->countByAssetCollection($collection),
        ];
    }
}
