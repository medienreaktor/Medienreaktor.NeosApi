<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Domain;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Infrastructure\Media\AssetCollectionFinder;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;

/**
 * Collection IDs of a request → the collections as the account may see them, or the error of where the ID came from:
 * the 404 of the path, the 422 of the body, the 400 of a filter. For every endpoint that refers to collections
 */
#[Flow\Scope('singleton')]
final readonly class AssetCollectionResolver
{
    public function __construct(
        private AssetCollectionFinder $assetCollectionFinder,
    ) {
    }

    /**
     * The collection of the path
     */
    public function resolve(AssetCollectionId $assetCollectionId): Model\AssetCollection|NotFound
    {
        return $this->assetCollectionFinder->find($assetCollectionId->value) ?? NotFound::because(self::noCollection($assetCollectionId));
    }

    /**
     * The collections the body refers to, each once, or the 422 for the first unknown one
     *
     * @param iterable<AssetCollectionId> $assetCollectionIds
     * @return list<Model\AssetCollection>|UnprocessableContent
     */
    public function resolveReferences(iterable $assetCollectionIds): array|UnprocessableContent
    {
        $assetCollections = [];
        foreach ($assetCollectionIds as $assetCollectionId) {
            $assetCollection = $this->assetCollectionFinder->find($assetCollectionId->value);
            if ($assetCollection === null) {
                return UnprocessableContent::because(self::noCollection($assetCollectionId));
            }
            $assetCollections[spl_object_id($assetCollection)] = $assetCollection;
        }
        return array_values($assetCollections);
    }

    /**
     * The collection a query parameter filters by
     */
    public function resolveFilter(AssetCollectionId $assetCollectionId): Model\AssetCollection|BadRequest
    {
        return $this->assetCollectionFinder->find($assetCollectionId->value) ?? BadRequest::because(self::noCollection($assetCollectionId));
    }

    private static function noCollection(AssetCollectionId $assetCollectionId): string
    {
        return sprintf('There is no asset collection with the ID %s', $assetCollectionId->value);
    }
}
