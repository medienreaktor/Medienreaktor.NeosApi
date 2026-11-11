<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A source of assets: neos, the media library of Neos, or a remote one like a DAM, whose assets are imported into
 * neos when they are used
 */
final readonly class AssetSource implements ProvidesSchema
{
    /**
     * @param string $iconUri the URI of the source's icon
     * @param bool $isReadOnly whether its assets can only be read: only the ones of neos can be changed, the others are imported first
     * @param bool $supportsTagging whether its assets can be found by tag (filterByTag, filterByUntagged of listAssets)
     * @param bool $supportsCollections whether its assets can be found by collection (filterByCollection)
     * @param bool $supportsSorting whether its assets can be sorted (sort)
     */
    public function __construct(
        public AssetSourceId $id,
        public string $label,
        public string $description,
        public string $iconUri,
        public bool $isReadOnly,
        public bool $supportsTagging,
        public bool $supportsCollections,
        public bool $supportsSorting,
    ) {
    }

    public static function from(AssetSourceInterface $assetSource): self
    {
        $repository = $assetSource->getAssetProxyRepository();
        return new self(
            AssetSourceId::fromString($assetSource->getIdentifier()),
            $assetSource->getLabel(),
            $assetSource->getDescription(),
            $assetSource->getIconUri(),
            $assetSource->isReadOnly(),
            $repository instanceof SupportsTaggingInterface,
            $repository instanceof SupportsCollectionsInterface,
            $repository instanceof SupportsSortingInterface,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
