<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionTitle;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A collection an asset is in, the whole collection is getAssetCollection's
 */
final readonly class AssetCollectionReference implements ProvidesSchema
{
    public function __construct(
        public AssetCollectionId $id,
        public AssetCollectionTitle $title,
    ) {
    }

    public static function from(Model\AssetCollection $assetCollection, PersistenceManagerInterface $persistenceManager): self
    {
        return new self(
            AssetCollectionId::fromString($persistenceManager->getIdentifierByObject($assetCollection)),
            AssetCollectionTitle::fromString($assetCollection->getTitle()),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
