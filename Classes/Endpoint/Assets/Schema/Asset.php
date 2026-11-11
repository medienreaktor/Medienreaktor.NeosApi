<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagReferenceList;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An asset of an asset source, in the same shape for every source. The metadata Neos keeps (title, caption,
 * copyrightNotice, tags, collections) only for the ones of neos, the others have them once they are imported
 */
final readonly class Asset implements ProvidesSchema
{
    /**
     * @param AssetSourceId $assetSourceId the asset source it's from: neos for the media library of Neos, others for remote sources
     * @param AssetId $id the ID in its asset source, with assetSourceId what the asset's path is made of
     * @param AssetId|null $localAssetId the ID of the asset in neos: in neos its id, for a remote asset the imported one, null if it isn't imported
     * @param int $fileSize in bytes
     * @param string $lastModified RFC 3339
     * @param int|null $width in pixels, null if it isn't an image or video
     * @param int|null $height in pixels, null if it isn't an image or video
     * @param string|null $thumbnailUri a small preview image for lists, null if there is none
     * @param string|null $previewUri a larger preview image, null if there is none
     * @param bool $isReadOnly whether its asset source is read-only (only neos isn't): changing it means importing it first
     * @param string|null $title null for an asset of another source than neos
     * @param string|null $caption null for an asset of another source than neos
     * @param string|null $copyrightNotice null for an asset of another source than neos
     * @param AssetTagReferenceList|null $tags sorted by label, null for an asset of another source than neos
     * @param AssetCollectionReferenceList|null $collections sorted by title, null for an asset of another source than neos
     * @param AssetId|null $originalAssetId for an image variant the image it was made from, else null
     * @param CropRectangle|null $crop for an image variant its crop, else null
     * @param AssetIptc|null $iptc for a remote asset with IPTC metadata what importing it would copy, else null
     */
    public function __construct(
        public AssetSourceId $assetSourceId,
        public AssetId $id,
        public AssetId|null $localAssetId,
        public string $label,
        public string $filename,
        public string $mediaType,
        public AssetType $assetType,
        public int $fileSize,
        public string $lastModified,
        public int|null $width,
        public int|null $height,
        public string|null $thumbnailUri,
        public string|null $previewUri,
        public bool $isReadOnly,
        public string|null $title,
        public string|null $caption,
        public string|null $copyrightNotice,
        public AssetTagReferenceList|null $tags,
        public AssetCollectionReferenceList|null $collections,
        public AssetId|null $originalAssetId,
        public CropRectangle|null $crop,
        public AssetIptc|null $iptc,
    ) {
    }

    public static function from(AssetProxyInterface $proxy, PersistenceManagerInterface $persistenceManager): self
    {
        $asset = $proxy instanceof NeosAssetProxy ? $proxy->getAsset() : null;
        $local = $asset instanceof Model\Asset ? $asset : null;
        $localAssetId = $proxy->getLocalAssetIdentifier();
        $thumbnailUri = $proxy->getThumbnailUri();
        $previewUri = $proxy->getPreviewUri();
        return new self(
            AssetSourceId::fromString($proxy->getAssetSource()->getIdentifier()),
            AssetId::fromString($proxy->getIdentifier()),
            $localAssetId !== null ? AssetId::fromString($localAssetId) : null,
            $proxy->getLabel(),
            $proxy->getFilename(),
            $proxy->getMediaType(),
            AssetType::of($asset, $proxy->getMediaType()),
            $proxy->getFileSize(),
            $proxy->getLastModified()->format(\DateTimeInterface::ATOM),
            // the neos source has 0 for none
            $proxy->getWidthInPixels() ?: null,
            $proxy->getHeightInPixels() ?: null,
            $thumbnailUri !== null ? (string)$thumbnailUri : null,
            $previewUri !== null ? (string)$previewUri : null,
            $proxy->getAssetSource()->isReadOnly(),
            $local?->getTitle(),
            $local?->getCaption(),
            $local?->getCopyrightNotice(),
            $local !== null ? AssetTagReferenceList::of($local->getTags(), $persistenceManager) : null,
            $local !== null ? new AssetCollectionReferenceList(...array_map(
                static fn (Model\AssetCollection $assetCollection) => AssetCollectionReference::from($assetCollection, $persistenceManager),
                self::sorted($local->getAssetCollections()->toArray(), static fn (Model\AssetCollection $assetCollection) => $assetCollection->getTitle()),
            )) : null,
            $local instanceof Model\ImageVariant ? AssetId::fromString($persistenceManager->getIdentifierByObject($local->getOriginalAsset())) : null,
            $local instanceof Model\ImageVariant ? CropRectangle::of($local) : null,
            $local === null && $proxy instanceof SupportsIptcMetadataInterface ? AssetIptc::from($proxy) : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    /**
     * @template T of object
     * @param array<T> $items
     * @param \Closure(T): string $label
     * @return list<T>
     */
    private static function sorted(array $items, \Closure $label): array
    {
        $items = array_values($items);
        usort($items, static fn (object $a, object $b) => strcasecmp($label($a), $label($b)));
        return $items;
    }
}
