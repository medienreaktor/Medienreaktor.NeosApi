<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections;

use Neos\Api\Endpoint\AssetCollections\Domain\AssetCollectionResolver;
use Neos\Api\Endpoint\AssetCollections\RequestBody\AssetCollectionCreate;
use Neos\Api\Endpoint\AssetCollections\RequestBody\AssetCollectionUpdate;
use Neos\Api\Endpoint\AssetCollections\Response\AssetCollectionCreated;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollection;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionList;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionListing;
use Neos\Api\Infrastructure\Media\AssetCollectionFinder;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;

/**
 * The collections of the media library, groups of assets
 */
final readonly class AssetCollections
{
    public function __construct(
        private AssetCollectionRepository $assetCollectionRepository,
        private AssetCollectionFinder $assetCollectionFinder,
        private AssetCollectionResolver $assetCollectionResolver,
        private SiteRepository $siteRepository,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    #[Operation(
        path: '/assetcollections',
        method: 'GET',
        summary: 'List the asset collections',
        description: 'All collections of the media library, sorted by title, each with its tags. The collections, and the assets assetCount counts, are the account\'s, as Neos\' media privileges allow.',
        operationId: 'listAssetCollections',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETCOLLECTIONS_READ],
        ],
    )]
    public function list(): AssetCollectionListing
    {
        /** @var iterable<Model\AssetCollection> $assetCollections */
        $assetCollections = $this->assetCollectionRepository->findAll();
        return AssetCollectionListing::of(new AssetCollectionList(
            ...$this->read($assetCollections),
        ));
    }

    #[Operation(
        path: '/assetcollections',
        method: 'POST',
        summary: 'Create an asset collection',
        operationId: 'createAssetCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETCOLLECTIONS_CREATE],
        ],
    )]
    public function create(
        #[RequestBody(description: 'The collection')] AssetCollectionCreate $newAssetCollection,
    ): AssetCollectionCreated {
        $assetCollection = new Model\AssetCollection($newAssetCollection->title->value);
        $this->assetCollectionRepository->add($assetCollection);
        $this->persistenceManager->persistAll();
        return new AssetCollectionCreated($this->assetCollection($assetCollection));
    }

    #[Operation(
        path: '/assetcollections/{assetCollectionId}',
        method: 'GET',
        summary: 'Get an asset collection',
        operationId: 'getAssetCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETCOLLECTIONS_READ],
        ],
    )]
    public function get(AssetCollectionId $assetCollectionId): AssetCollection|NotFound
    {
        $assetCollection = $this->assetCollectionResolver->resolve($assetCollectionId);
        return $assetCollection instanceof Model\AssetCollection ? $this->assetCollection($assetCollection) : $assetCollection;
    }

    #[Operation(
        path: '/assetcollections/{assetCollectionId}',
        method: 'PATCH',
        summary: 'Change an asset collection',
        description: 'Changes the given properties and leaves the others as they are.',
        operationId: 'updateAssetCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETCOLLECTIONS_UPDATE],
        ],
    )]
    public function update(
        AssetCollectionId $assetCollectionId,
        #[RequestBody(description: 'The properties to change')] AssetCollectionUpdate $patch,
    ): AssetCollection|NotFound {
        $assetCollection = $this->assetCollectionResolver->resolve($assetCollectionId);
        if ($assetCollection instanceof NotFound) {
            return $assetCollection;
        }
        $patch->applyTo($assetCollection);
        $this->assetCollectionRepository->update($assetCollection);
        $this->persistenceManager->persistAll();
        return $this->assetCollection($assetCollection);
    }

    #[Operation(
        path: '/assetcollections/{assetCollectionId}',
        method: 'DELETE',
        summary: 'Delete an asset collection',
        description: 'Deletes the collection, the assets in it stay. Sites with it as their asset collection get none, as in the Neos media browser.',
        operationId: 'deleteAssetCollection',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ASSETCOLLECTIONS_DELETE],
        ],
    )]
    public function delete(AssetCollectionId $assetCollectionId): NotFound|null
    {
        $assetCollection = $this->assetCollectionResolver->resolve($assetCollectionId);
        if ($assetCollection instanceof NotFound) {
            return $assetCollection;
        }
        // the site's reference would keep it from being deleted
        $query = $this->siteRepository->createQuery();
        /** @var iterable<Site> $sites */
        $sites = $query->matching($query->equals('assetCollection', $assetCollection))->execute();
        foreach ($sites as $site) {
            $site->setAssetCollection(null);
            $this->siteRepository->update($site);
        }
        $this->assetCollectionRepository->remove($assetCollection);
        $this->persistenceManager->persistAll();
        return null;
    }

    private function assetCollection(Model\AssetCollection $assetCollection): AssetCollection
    {
        return $this->read([$assetCollection])[0];
    }

    /**
     * The collections as AssetCollection, with one query for all their asset counts
     *
     * @param iterable<Model\AssetCollection> $assetCollections
     * @return list<AssetCollection>
     */
    private function read(iterable $assetCollections): array
    {
        $assetCounts = $this->assetCollectionFinder->assetCounts();
        $read = [];
        foreach ($assetCollections as $assetCollection) {
            $read[] = AssetCollection::from($assetCollection, $this->persistenceManager, $assetCounts[$this->persistenceManager->getIdentifierByObject($assetCollection)] ?? 0);
        }
        return $read;
    }
}
