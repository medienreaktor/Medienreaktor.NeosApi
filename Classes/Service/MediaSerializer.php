<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Media\Domain\Model\Audio;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Adjustment\CropImageAdjustment;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Model\Video;

/**
 * Serializes assets into the JSON shape the Media API returns.
 *
 * Everything is serialized from an AssetProxyInterface so local and remote
 * (DAM) assets share one shape - a client renders a proxy from any source
 * identically. For local Neos assets we additionally unwrap the domain Asset
 * (via NeosAssetProxy::getAsset) to expose the editable metadata block
 * (title/caption/copyright, tags, collections) the details pane writes back.
 */
class MediaSerializer
{
    /**
     * A single asset proxy, with the editable detail block for local assets.
     *
     * @return array<string, mixed>
     */
    public function serializeProxy(AssetProxyInterface $proxy): array
    {
        $localAsset = $proxy instanceof NeosAssetProxy ? $proxy->getAsset() : null;

        $data = [
            'assetSource' => $proxy->getAssetSource()->getIdentifier(),
            'identifier' => $proxy->getIdentifier(),
            'localAssetIdentifier' => $proxy->getLocalAssetIdentifier(),
            'label' => $proxy->getLabel(),
            'filename' => $proxy->getFilename(),
            'mediaType' => $proxy->getMediaType(),
            'assetType' => $this->assetType($localAsset, $proxy->getMediaType()),
            'fileSize' => $proxy->getFileSize(),
            'lastModified' => $proxy->getLastModified()->format(DATE_ATOM),
            'width' => $proxy->getWidthInPixels(),
            'height' => $proxy->getHeightInPixels(),
            'thumbnailUri' => $this->uriToString($proxy->getThumbnailUri()),
            'previewUri' => $this->uriToString($proxy->getPreviewUri()),
            // A remote proxy that already exists locally reports a local
            // identifier; a purely remote one does not. isImported drives the
            // "Import" affordance in the picker.
            'isImported' => $proxy->getLocalAssetIdentifier() !== null,
            'isRemote' => $proxy instanceof HasRemoteOriginalInterface,
            'isReadOnly' => $proxy->getAssetSource()->isReadOnly(),
        ];

        if ($localAsset instanceof Asset) {
            $data = array_merge($data, $this->localDetail($localAsset));
        } elseif ($proxy instanceof SupportsIptcMetadataInterface) {
            // Remote assets have no editable local metadata yet; surface the
            // IPTC fields the import would copy so the client can preview them.
            $data['iptc'] = [
                'title' => $proxy->getIptcProperty('Title'),
                'caption' => $proxy->getIptcProperty('CaptionAbstract'),
                'copyrightNotice' => $proxy->getIptcProperty('CopyrightNotice'),
            ];
        }

        return $data;
    }

    /**
     * @param iterable<AssetProxyInterface> $proxies
     * @return array<int, array<string, mixed>>
     */
    public function serializeProxies(iterable $proxies): array
    {
        $items = [];
        foreach ($proxies as $proxy) {
            $items[] = $this->serializeProxy($proxy);
        }

        return $items;
    }

    /**
     * The editable metadata block, only present for local Neos assets.
     *
     * @return array<string, mixed>
     */
    private function localDetail(Asset $asset): array
    {
        $tags = [];
        foreach ($asset->getTags() as $tag) {
            /** @var Tag $tag */
            $tags[] = $this->serializeTagRef($tag);
        }

        $collections = [];
        foreach ($asset->getAssetCollections() as $collection) {
            /** @var AssetCollection $collection */
            $collections[] = [
                'identifier' => $this->persistenceIdentifier($collection),
                'title' => $collection->getTitle(),
            ];
        }

        $detail = [
            'title' => $asset->getTitle(),
            'caption' => $asset->getCaption(),
            'copyrightNotice' => $asset->getCopyrightNotice(),
            'tags' => $tags,
            'collections' => $collections,
        ];

        if ($asset instanceof ImageInterface) {
            $detail['width'] = $asset->getWidth();
            $detail['height'] = $asset->getHeight();
        }

        // A cropped image is stored as an ImageVariant of an original Image.
        // Surface the original's identifier and the current crop rectangle (in
        // the original's pixel coordinates) so the crop editor can re-crop the
        // original - never a variant of a variant - and prefill the last crop.
        if ($asset instanceof ImageVariant) {
            $detail['originalAssetIdentifier'] = $this->persistenceIdentifier($asset->getOriginalAsset());
            $detail['crop'] = $this->cropRect($asset);
        }

        return $detail;
    }

    /**
     * The CropImageAdjustment of an image variant as {x, y, width, height} in
     * original-image pixels, or null when the variant carries no crop.
     *
     * @return array<string, int>|null
     */
    private function cropRect(ImageVariant $variant): ?array
    {
        foreach ($variant->getAdjustments() as $adjustment) {
            if ($adjustment instanceof CropImageAdjustment) {
                return [
                    'x' => (int)$adjustment->getX(),
                    'y' => (int)$adjustment->getY(),
                    'width' => (int)$adjustment->getWidth(),
                    'height' => (int)$adjustment->getHeight(),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTagRef(Tag $tag): array
    {
        return [
            'identifier' => $this->persistenceIdentifier($tag),
            'label' => $tag->getLabel(),
        ];
    }

    private function assetType(?AssetInterface $asset, string $mediaType): string
    {
        if ($asset instanceof Image) {
            return 'Image';
        }
        if ($asset instanceof Document) {
            return 'Document';
        }
        if ($asset instanceof Video) {
            return 'Video';
        }
        if ($asset instanceof Audio) {
            return 'Audio';
        }

        // Remote proxy without a local model: derive from the media type.
        return match (true) {
            str_starts_with($mediaType, 'image/') => 'Image',
            str_starts_with($mediaType, 'video/') => 'Video',
            str_starts_with($mediaType, 'audio/') => 'Audio',
            default => 'Document',
        };
    }

    private function persistenceIdentifier(object $object): string
    {
        return $this->persistenceManager->getIdentifierByObject($object);
    }

    private function uriToString(?object $uri): ?string
    {
        return $uri === null ? null : (string)$uri;
    }

    #[Flow\Inject]
    protected \Neos\Flow\Persistence\PersistenceManagerInterface $persistenceManager;
}
