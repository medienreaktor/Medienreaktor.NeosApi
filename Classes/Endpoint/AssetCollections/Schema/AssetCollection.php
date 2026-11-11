<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Schema;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagReferenceList;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A collection of the media library, a group of assets: an asset may be in several
 */
final readonly class AssetCollection implements ProvidesSchema
{
    /**
     * @param int $assetCount how many assets are in the collection, of the ones the account may see
     * @param AssetTagReferenceList $tags the tags of the collection, sorted by label, read-only in the API
     */
    public function __construct(
        public AssetCollectionId $id,
        public AssetCollectionTitle $title,
        public int $assetCount,
        public AssetTagReferenceList $tags,
    ) {
    }

    public static function from(Model\AssetCollection $assetCollection, PersistenceManagerInterface $persistenceManager, int $assetCount): self
    {
        return new self(
            AssetCollectionId::fromString($persistenceManager->getIdentifierByObject($assetCollection)),
            AssetCollectionTitle::fromString($assetCollection->getTitle()),
            $assetCount,
            AssetTagReferenceList::of($assetCollection->getTags(), $persistenceManager),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
