<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Domain;

use Neos\Api\Endpoint\Assets\Schema\AssetId;
use Neos\Api\Endpoint\Assets\Schema\AssetSourceId;
use Neos\Api\Infrastructure\Media\AssetSourceFinder;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;

/**
 * Asset source and asset ID of the path → the asset source or asset, or the 404; for changes the asset of neos, the
 * only source whose assets can be changed (AssetSourceFinder::isNeos()), or the 422
 */
#[Flow\Scope('singleton')]
final readonly class AssetResolver
{
    public function __construct(
        private AssetSourceFinder $assetSourceFinder,
    ) {
    }

    public function resolveAssetSource(AssetSourceId $assetSourceId): AssetSourceInterface|NotFound
    {
        return $this->assetSourceFinder->find($assetSourceId->value)
            ?? NotFound::because(sprintf('There is no asset source %s', $assetSourceId->value));
    }

    public function resolveAsset(AssetSourceId $assetSourceId, AssetId $assetId): AssetProxyInterface|NotFound
    {
        $assetSource = $this->resolveAssetSource($assetSourceId);
        if ($assetSource instanceof NotFound) {
            return $assetSource;
        }
        return $this->assetSourceFinder->findAsset($assetSource, $assetId->value)
            ?? NotFound::because(sprintf('There is no asset with the ID %s in the asset source %s', $assetId->value, $assetSourceId->value));
    }

    /**
     * The asset to change: of neos, assets of other sources can only be read (422), they are imported first
     */
    public function resolveAssetToChange(AssetSourceId $assetSourceId, AssetId $assetId): Model\Asset|NotFound|UnprocessableContent
    {
        $proxy = $this->resolveAsset($assetSourceId, $assetId);
        if ($proxy instanceof NotFound) {
            return $proxy;
        }
        $asset = $proxy instanceof NeosAssetProxy && AssetSourceFinder::isNeos($proxy->getAssetSource()) ? $proxy->getAsset() : null;
        if (!$asset instanceof Model\Asset) {
            return UnprocessableContent::because(sprintf('The assets of the asset source %s can\'t be changed, import the asset into neos first', $assetSourceId->value));
        }
        return $asset;
    }
}
