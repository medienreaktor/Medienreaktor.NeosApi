<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api\Media;

use Medienreaktor\NeosApi\Controller\Api\AbstractApiController;
use Medienreaktor\NeosApi\Service\MediaSerializer;
use Medienreaktor\NeosApi\Service\NodeSerializer;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Persistence\QueryInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\AssetSourceService;
use Neos\Media\TypeConverter\AssetInterfaceConverter;
use Neos\Neos\AssetUsage\Dto\AssetUsageReference;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * Assets as a REST resource across all configured asset sources.
 *
 * Reads go through the active asset source's AssetProxyRepository (like the
 * classic Media.Browser), so remote DAM sources and the local "neos" source
 * are queried identically and pagination is applied at the source's query
 * level. Writes (upload, metadata, delete, tagging) operate on the local
 * Neos source only - remote assets are brought local via importAction first.
 */
class AssetsController extends AbstractApiController
{
    private const DEFAULT_ASSET_SOURCE = 'neos';
    private const DEFAULT_LIMIT = 40;

    #[Flow\Inject]
    protected AssetSourceService $assetSourceService;

    #[Flow\Inject]
    protected AssetService $assetService;

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected TagRepository $tagRepository;

    #[Flow\Inject]
    protected AssetCollectionRepository $assetCollectionRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected MediaSerializer $mediaSerializer;

    #[Flow\Inject]
    protected NodeSerializer $nodeSerializer;

    /**
     * The paginated, filtered, searchable asset list.
     *
     * Query params: assetSource, collection, tag, tagMode (given|none),
     * type (All|Image|Document|Video|Audio), search, sortBy (name|modified),
     * sortDirection (asc|desc), limit, offset.
     */
    public function indexAction(): string
    {
        $this->requireScope('neos.media');

        $params = $this->request->getHttpRequest()->getQueryParams();
        $assetProxyRepository = $this->assetProxyRepository($this->param($params, 'assetSource', self::DEFAULT_ASSET_SOURCE));

        // Type filter and (for capable sources) sorting + collection scope.
        $type = $this->param($params, 'type', 'All');
        if (!in_array($type, AssetTypeFilter::getAllowedValues(), true)) {
            $type = 'All';
        }
        $assetProxyRepository->filterByType(new AssetTypeFilter($type));

        if ($assetProxyRepository instanceof SupportsSortingInterface) {
            $direction = strtolower((string)$this->param($params, 'sortDirection', 'desc')) === 'asc'
                ? QueryInterface::ORDER_ASCENDING
                : QueryInterface::ORDER_DESCENDING;
            $property = $this->param($params, 'sortBy') === 'name' ? 'resource.filename' : 'lastModified';
            $assetProxyRepository->orderBy([$property => $direction]);
        }

        $collection = $this->resolveCollection($this->param($params, 'collection'));
        if ($assetProxyRepository instanceof SupportsCollectionsInterface) {
            $assetProxyRepository->filterByCollection($collection);
        }

        // Result set selection mirrors the classic browser precedence.
        $search = $this->param($params, 'search');
        $tag = $this->resolveTag($this->param($params, 'tag'));
        if ($search !== null && $search !== '') {
            $result = $assetProxyRepository->findBySearchTerm($search);
        } elseif ($this->param($params, 'tagMode') === 'none') {
            $result = $assetProxyRepository->findUntagged();
        } elseif ($tag !== null) {
            $result = $assetProxyRepository->findByTag($tag);
        } else {
            $result = $assetProxyRepository->findAll();
        }

        $limit = max(1, (int)($params['limit'] ?? self::DEFAULT_LIMIT));
        $offset = max(0, (int)($params['offset'] ?? 0));
        $total = $result->count();

        $query = $result->getQuery();
        $query->setOffset($offset);
        $query->setLimit($limit);

        return $this->json([
            'assets' => $this->mediaSerializer->serializeProxies($query->execute()),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    public function showAction(string $assetSource, string $assetIdentifier): string
    {
        $this->requireScope('neos.media');
        $proxy = $this->resolveProxy($assetSource, $assetIdentifier);

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($proxy)]);
    }

    protected function initializeCreateAction(): void
    {
        // The uploaded file arrives as asset[resource]; the AssetInterfaceConverter
        // builds the correct Asset subclass and dedupes by resource sha1.
        $configuration = $this->arguments->getArgument('asset')->getPropertyMappingConfiguration();
        $configuration->allowProperties('title', 'resource');
        $configuration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, true);
        $configuration->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_ONE_PER_RESOURCE, true);
    }

    /**
     * Upload a new local asset (multipart/form-data).
     *
     * @param array<int, string> $tags
     */
    #[Flow\SkipCsrfProtection]
    public function createAction(Asset $asset, ?string $collection = null, array $tags = []): string
    {
        $this->requireScope('neos.media');

        // One-per-resource dedupe may return an already-persisted asset.
        if ($this->persistenceManager->isNewObject($asset)) {
            $this->assetRepository->add($asset);
        } else {
            $this->assetRepository->update($asset);
        }

        foreach ($tags as $tagIdentifier) {
            $tag = $this->resolveTag($tagIdentifier);
            if ($tag !== null) {
                $asset->addTag($tag);
            }
        }
        $resolvedCollection = $this->resolveCollection($collection);
        if ($resolvedCollection !== null) {
            // setAssetCollections syncs both sides, so the serialized response
            // reflects the assignment without a re-fetch.
            $asset->setAssetCollections(new ArrayCollection([$resolvedCollection]));
        }
        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();

        return $this->json([
            'asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset)),
        ], 201);
    }

    /**
     * Update local asset metadata. JSON body: title, caption, copyrightNotice,
     * tags (identifiers), collections (identifiers). Absent keys are left as-is.
     *
     * @param array<int, string>|null $tags
     * @param array<int, string>|null $collections
     */
    #[Flow\SkipCsrfProtection]
    public function updateAction(string $assetIdentifier, ?string $title = null, ?string $caption = null, ?string $copyrightNotice = null, ?array $tags = null, ?array $collections = null): string
    {
        $this->requireScope('neos.media');
        $asset = $this->requireLocalAsset($assetIdentifier);

        if ($title !== null) {
            $asset->setTitle($title);
        }
        if ($caption !== null) {
            $asset->setCaption($caption);
        }
        if ($copyrightNotice !== null) {
            $asset->setCopyrightNotice($copyrightNotice);
        }
        if ($tags !== null) {
            $asset->setTags($this->collectTags($tags));
        }
        if ($collections !== null) {
            $asset->setAssetCollections($this->collectCollections($collections));
        }

        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset))]);
    }

    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $assetIdentifier): string
    {
        $this->requireScope('neos.media');
        $asset = $this->requireLocalAsset($assetIdentifier);

        if ($this->assetService->getUsageReferences($asset) !== []) {
            $this->throwJsonStatus(409, 'asset_in_use', 'The asset is still in use and cannot be deleted.');
        }

        $this->assetRepository->remove($asset);
        $this->persistenceManager->persistAll();

        return $this->json(['deleted' => true]);
    }

    /**
     * Add or remove a tag. JSON body: { tag: <identifier> }.
     */
    #[Flow\SkipCsrfProtection]
    public function tagAction(string $assetIdentifier, string $tag): string
    {
        return $this->toggleTag($assetIdentifier, $tag, true);
    }

    #[Flow\SkipCsrfProtection]
    public function untagAction(string $assetIdentifier, string $tag): string
    {
        return $this->toggleTag($assetIdentifier, $tag, false);
    }

    /**
     * Add or remove a collection assignment. JSON body: { collection: <identifier> }.
     */
    #[Flow\SkipCsrfProtection]
    public function addToCollectionAction(string $assetIdentifier, string $collection): string
    {
        return $this->toggleCollection($assetIdentifier, $collection, true);
    }

    #[Flow\SkipCsrfProtection]
    public function removeFromCollectionAction(string $assetIdentifier, string $collection): string
    {
        return $this->toggleCollection($assetIdentifier, $collection, false);
    }

    /**
     * Import a remote asset from an asset source into the local Neos source.
     * JSON body: { assetSource, assetIdentifier }.
     */
    #[Flow\SkipCsrfProtection]
    public function importAction(string $assetSource, string $assetIdentifier): string
    {
        $this->requireScope('neos.media');

        try {
            $importedAsset = $this->assetSourceService->importAsset($assetSource, $assetIdentifier);
        } catch (\Throwable $exception) {
            $this->throwJsonStatus(400, 'import_failed', $exception->getMessage());
        }

        $asset = $this->assetRepository->findByIdentifier($importedAsset->getLocalAssetIdentifier());
        if (!$asset instanceof Asset) {
            $this->throwJsonStatus(500, 'import_failed', 'The asset was imported but could not be loaded.');
        }

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset))], 201);
    }

    /**
     * Replace the binary resource of a local asset (multipart/form-data, field
     * "resource"), keeping its identity, metadata and usages. Cross-media-type
     * replacement (e.g. image -> pdf) is rejected by the AssetService.
     */
    #[Flow\SkipCsrfProtection]
    public function replaceResourceAction(string $assetIdentifier, PersistentResource $resource): string
    {
        $this->requireScope('neos.media');
        $asset = $this->requireLocalAsset($assetIdentifier);

        try {
            $this->assetService->replaceAssetResource($asset, $resource);
        } catch (\Throwable $exception) {
            $this->throwJsonStatus(400, 'replace_failed', $exception->getMessage());
        }
        $this->persistenceManager->persistAll();

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset))]);
    }

    /**
     * Which content nodes reference this asset. Resolves each usage to a node
     * in its workspace/dimension, honouring the account's read access.
     */
    public function usageAction(string $assetSource, string $assetIdentifier): string
    {
        $this->requireScope('neos.media');
        $proxy = $this->resolveProxy($assetSource, $assetIdentifier);
        $localIdentifier = $proxy->getLocalAssetIdentifier();
        if ($localIdentifier === null) {
            return $this->json(['usages' => [], 'inaccessibleCount' => 0, 'total' => 0]);
        }

        $asset = $this->assetRepository->findByIdentifier($localIdentifier);
        if (!$asset instanceof AssetInterface) {
            return $this->json(['usages' => [], 'inaccessibleCount' => 0, 'total' => 0]);
        }

        $usageReferences = $this->assetService->getUsageReferences($asset);
        $usages = [];
        $inaccessible = 0;

        foreach ($usageReferences as $usage) {
            if (!$usage instanceof AssetUsageReference) {
                $inaccessible++;
                continue;
            }

            $contentRepository = $this->contentRepositoryRegistry->get($usage->getContentRepositoryId());
            $workspaceName = $usage->getWorkspaceName();
            $dimensionSpacePoint = $usage->getOriginDimensionSpacePoint()->toDimensionSpacePoint();

            // Security-aware subgraph: a node the account may not read simply
            // does not resolve, so it counts as an inaccessible relation.
            $subgraph = $contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint);
            $node = $subgraph->findNodeById($usage->getNodeAggregateId());
            if ($node === null) {
                $inaccessible++;
                continue;
            }

            $documentNode = $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));

            $usages[] = [
                'node' => $this->nodeSerializer->serializeNode($node, $subgraph),
                'documentNode' => $documentNode !== null ? $this->nodeSerializer->serializeNode($documentNode, $subgraph) : null,
                'workspaceName' => $workspaceName->value,
                'dimension' => $dimensionSpacePoint->coordinates,
            ];
        }

        return $this->json([
            'usages' => $usages,
            'inaccessibleCount' => $inaccessible,
            'total' => count($usageReferences),
        ]);
    }

    // ----------------------------------------------------------------------

    private function toggleTag(string $assetIdentifier, string $tagIdentifier, bool $add): string
    {
        $this->requireScope('neos.media');
        $asset = $this->requireLocalAsset($assetIdentifier);
        $tag = $this->resolveTag($tagIdentifier);
        if ($tag === null) {
            $this->throwJsonStatus(404, 'tag_not_found', 'The tag does not exist.');
        }

        $add ? $asset->addTag($tag) : $asset->removeTag($tag);
        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset))]);
    }

    private function toggleCollection(string $assetIdentifier, string $collectionIdentifier, bool $add): string
    {
        $this->requireScope('neos.media');
        $asset = $this->requireLocalAsset($assetIdentifier);
        $collection = $this->resolveCollection($collectionIdentifier);
        if ($collection === null) {
            $this->throwJsonStatus(404, 'collection_not_found', 'The collection does not exist.');
        }

        $target = new ArrayCollection(iterator_to_array($asset->getAssetCollections()));
        if ($add && !$target->contains($collection)) {
            $target->add($collection);
        } elseif (!$add) {
            $target->removeElement($collection);
        }
        $asset->setAssetCollections($target);
        $this->assetRepository->update($asset);
        $this->persistenceManager->persistAll();

        return $this->json(['asset' => $this->mediaSerializer->serializeProxy($this->neosProxy($asset))]);
    }

    private function assetProxyRepository(string $assetSourceIdentifier): AssetProxyRepositoryInterface
    {
        $assetSources = $this->assetSourceService->getAssetSources();
        if (!isset($assetSources[$assetSourceIdentifier])) {
            $this->throwJsonStatus(404, 'asset_source_not_found', sprintf('Asset source "%s" is not configured.', $assetSourceIdentifier));
        }

        return $assetSources[$assetSourceIdentifier]->getAssetProxyRepository();
    }

    private function resolveProxy(string $assetSource, string $assetIdentifier): \Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface
    {
        try {
            return $this->assetProxyRepository($assetSource)->getAssetProxy($assetIdentifier);
        } catch (\Throwable) {
            $this->throwJsonStatus(404, 'asset_not_found', 'The asset does not exist in this asset source.');
        }
    }

    private function neosProxy(Asset $asset): \Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface
    {
        return $this->assetProxyRepository(self::DEFAULT_ASSET_SOURCE)->getAssetProxy(
            $this->persistenceManager->getIdentifierByObject($asset)
        );
    }

    private function requireLocalAsset(string $assetIdentifier): Asset
    {
        $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
        if (!$asset instanceof Asset) {
            $this->throwJsonStatus(404, 'asset_not_found', 'The local asset does not exist.');
        }

        return $asset;
    }

    private function resolveTag(?string $identifier): ?Tag
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }
        $tag = $this->tagRepository->findByIdentifier($identifier);

        return $tag instanceof Tag ? $tag : null;
    }

    private function resolveCollection(?string $identifier): ?AssetCollection
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }
        $collection = $this->assetCollectionRepository->findByIdentifier($identifier);

        return $collection instanceof AssetCollection ? $collection : null;
    }

    /**
     * @param array<int, string> $identifiers
     * @return \Doctrine\Common\Collections\ArrayCollection<int, Tag>
     */
    private function collectTags(array $identifiers): \Doctrine\Common\Collections\ArrayCollection
    {
        $tags = new \Doctrine\Common\Collections\ArrayCollection();
        foreach ($identifiers as $identifier) {
            $tag = $this->resolveTag($identifier);
            if ($tag !== null && !$tags->contains($tag)) {
                $tags->add($tag);
            }
        }

        return $tags;
    }

    /**
     * @param array<int, string> $identifiers
     * @return ArrayCollection<int, AssetCollection>
     */
    private function collectCollections(array $identifiers): ArrayCollection
    {
        $collections = new ArrayCollection();
        foreach ($identifiers as $identifier) {
            $collection = $this->resolveCollection($identifier);
            if ($collection !== null && !$collections->contains($collection)) {
                $collections->add($collection);
            }
        }

        return $collections;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function param(array $params, string $name, ?string $default = null): ?string
    {
        $value = $params[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
