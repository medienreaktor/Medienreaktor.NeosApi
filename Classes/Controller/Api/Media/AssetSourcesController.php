<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api\Media;

use Medienreaktor\NeosApi\Controller\Api\AbstractApiController;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;
use Neos\Media\Domain\Service\AssetSourceService;

/**
 * The configured asset sources - the local "neos" source plus any remote DAM
 * integrations. The client renders a source switcher and adapts its filters to
 * each source's advertised capabilities.
 */
class AssetSourcesController extends AbstractApiController
{
    #[Flow\Inject]
    protected AssetSourceService $assetSourceService;

    public function indexAction(): string
    {
        $this->requireScope('neos.media');

        $sources = [];
        foreach ($this->assetSourceService->getAssetSources() as $assetSource) {
            $proxyRepository = $assetSource->getAssetProxyRepository();
            $sources[] = [
                'identifier' => $assetSource->getIdentifier(),
                'label' => $assetSource->getLabel(),
                'description' => $assetSource->getDescription(),
                'iconUri' => $assetSource->getIconUri(),
                'isReadOnly' => $assetSource->isReadOnly(),
                'supportsTagging' => $proxyRepository instanceof SupportsTaggingInterface,
                'supportsCollections' => $proxyRepository instanceof SupportsCollectionsInterface,
                'supportsSorting' => $proxyRepository instanceof SupportsSortingInterface,
            ];
        }

        return $this->json(['assetSources' => $sources]);
    }
}
