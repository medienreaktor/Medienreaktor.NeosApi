<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Domain;

use Neos\Api\Endpoint\AssetCollections\Domain\AssetCollectionResolver;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Endpoint\Assets\Schema\AssetType;
use Neos\Api\Endpoint\AssetTags\Domain\AssetTagResolver;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;

/**
 * The query of listAssets in the asset sources' terms: the filters, search and sort of the request as the filters,
 * finder and orderings of the source's asset proxy repository. The repositories have one finder each (all, search
 * term, tag, untagged), so only one of search, filterByTag and filterByUntagged can be given
 */
final readonly class AssetQuery
{
    /**
     * The sort fields → the properties the asset sources sort by, as the Neos media browser does
     */
    private const array SORT_FIELDS = [
        'name' => 'resource.filename',
        'lastModified' => 'lastModified',
    ];

    /**
     * @param array<string, string> $orderings property → SupportsSortingInterface::ORDER_*, [] for the default
     */
    private function __construct(
        private ?AssetType $assetType,
        private ?AssetCollectionId $collectionId,
        private ?AssetTagId $tagId,
        private bool $untagged,
        private ?string $search,
        private array $orderings,
    ) {
    }

    /**
     * The query, a 400 if more than one of search, filterByTag and filterByUntagged are given or the sort is invalid
     */
    public static function create(
        ?AssetType $assetType,
        ?AssetCollectionId $collectionId,
        ?AssetTagId $tagId,
        bool $untagged,
        ?string $search,
        ?Sort $sort,
    ): self|BadRequest {
        $search = $search !== null && trim($search) !== '' ? $search : null;
        if (count(array_filter([$search !== null, $tagId !== null, $untagged])) > 1) {
            return BadRequest::because('search, filterByTag and filterByUntagged can\'t be combined');
        }
        $orderings = [];
        foreach ($sort?->fields() ?? [] as ['field' => $field, 'descending' => $descending]) {
            if (!isset(self::SORT_FIELDS[$field])) {
                return BadRequest::because(sprintf('The assets can\'t be sorted by %s, only by %s', $field, implode(', ', array_keys(self::SORT_FIELDS))));
            }
            $orderings[self::SORT_FIELDS[$field]] = $descending ? SupportsSortingInterface::ORDER_DESCENDING : SupportsSortingInterface::ORDER_ASCENDING;
        }
        return new self($assetType, $collectionId, $tagId, $untagged, $search, $orderings);
    }

    /**
     * The matching assets of the source's repository (fresh from AssetSourceFinder::repository()), a 400 for a filter
     * or sort the source doesn't support and for an unknown tag or collection
     */
    public function find(
        string $assetSourceId,
        AssetProxyRepositoryInterface $repository,
        AssetTagResolver $assetTagResolver,
        AssetCollectionResolver $assetCollectionResolver,
    ): AssetProxyQueryResultInterface|BadRequest {
        if (($this->tagId !== null || $this->untagged) && !$repository instanceof SupportsTaggingInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support tags', $assetSourceId));
        }
        if ($this->collectionId !== null && !$repository instanceof SupportsCollectionsInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support collections', $assetSourceId));
        }
        if ($this->orderings !== [] && !$repository instanceof SupportsSortingInterface) {
            return BadRequest::because(sprintf('The asset source %s doesn\'t support sorting', $assetSourceId));
        }
        $tag = $this->tagId !== null ? $assetTagResolver->resolveFilter($this->tagId) : null;
        if ($tag instanceof BadRequest) {
            return $tag;
        }
        $collection = $this->collectionId !== null ? $assetCollectionResolver->resolveFilter($this->collectionId) : null;
        if ($collection instanceof BadRequest) {
            return $collection;
        }

        if ($this->assetType !== null) {
            $repository->filterByType($this->assetType->toAssetTypeFilter());
        }
        if ($collection !== null) {
            $repository->filterByCollection($collection);
        }
        if ($repository instanceof SupportsSortingInterface) {
            $repository->orderBy($this->orderings ?: [self::SORT_FIELDS['lastModified'] => SupportsSortingInterface::ORDER_DESCENDING]);
        }
        return match (true) {
            $tag !== null => $repository->findByTag($tag),
            $this->untagged => $repository->findUntagged(),
            $this->search !== null => $repository->findBySearchTerm($this->search),
            default => $repository->findAll(),
        };
    }
}
