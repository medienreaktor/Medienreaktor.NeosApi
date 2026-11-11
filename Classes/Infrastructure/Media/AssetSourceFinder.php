<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Media;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetSource;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Service\AssetSourceService;

/**
 * Finds the asset sources and their assets; neos, the media library of Neos, is the only source whose assets can be
 * changed and that takes uploads (isNeos())
 *
 * A source keeps one repository whose filters are state (the neos one swaps the repository it reads from by type, so
 * an image filter hides documents from getAssetProxy()): they are reset each time, the caller sets its own
 */
#[Flow\Scope('singleton')]
final readonly class AssetSourceFinder
{
    /**
     * The asset source of the media library of Neos
     */
    public const string NEOS = 'neos';

    public function __construct(
        private AssetSourceService $assetSourceService,
    ) {
    }

    /**
     * The asset source, null if none is configured with the ID
     */
    public function find(string $assetSourceId): ?AssetSourceInterface
    {
        return $this->assetSourceService->getAssetSources()[$assetSourceId] ?? null;
    }

    /**
     * The asset of the source, null if it has none with the ID
     */
    public function findAsset(AssetSourceInterface $assetSource, string $assetId): ?AssetProxyInterface
    {
        try {
            return $this->repository($assetSource)->getAssetProxy($assetId);
        } catch (AssetNotFoundExceptionInterface) {
            return null;
        }
    }

    /**
     * Whether the source is neos, whose assets can be changed and which takes uploads: other sources are read-only
     * here, their assets are imported into neos first
     */
    public static function isNeos(AssetSourceInterface $assetSource): bool
    {
        return $assetSource->getIdentifier() === self::NEOS && $assetSource instanceof NeosAssetSource && !$assetSource->isReadOnly();
    }

    /**
     * The source's repository without filters
     */
    public function repository(AssetSourceInterface $assetSource): AssetProxyRepositoryInterface
    {
        $repository = $assetSource->getAssetProxyRepository();
        $repository->filterByType(null);
        if ($repository instanceof SupportsCollectionsInterface) {
            $repository->filterByCollection(null);
        }
        return $repository;
    }

    /**
     * An asset of neos as its asset proxy, which is what Asset is made from
     */
    public function neosProxy(Model\AssetInterface $asset): AssetProxyInterface
    {
        $neos = $this->assetSourceService->getAssetSources()[self::NEOS] ?? null;
        if (!$neos instanceof NeosAssetSource) {
            throw new \RuntimeException('There is no asset source neos', 1791800001);
        }
        return new NeosAssetProxy($asset, $neos);
    }
}
