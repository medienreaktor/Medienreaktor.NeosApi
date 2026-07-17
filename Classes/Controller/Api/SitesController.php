<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\NodeAddressCodec;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Repository\SiteRepository;

/**
 * Entry points into the content: the configured sites, each with the encoded
 * node address of its site node - the starting point for all node traversal.
 */
class SitesController extends AbstractApiController
{
    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $contentRepository = $this->getContentRepository();

        $workspaceName = WorkspaceName::fromString($this->getStringQueryParam('workspace') ?? WorkspaceName::WORKSPACE_NAME_LIVE);
        $dimensionsParam = $this->getStringQueryParam('dimensions');
        $dimensionSpacePoint = $dimensionsParam !== null
            ? DimensionSpacePoint::fromJsonString($dimensionsParam)
            : (array_values($contentRepository->getVariationGraph()->getRootGeneralizations())[0] ?? DimensionSpacePoint::createWithoutDimensions());

        $subgraph = $this->getContentRepository()->getContentSubgraph($workspaceName, $dimensionSpacePoint);
        $sitesRootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));

        $sites = [];
        foreach ($this->siteRepository->findOnline() as $site) {
            $siteNode = $sitesRootNode === null
                ? null
                : $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesRootNode->aggregateId);

            $sites[] = [
                'name' => $site->getName(),
                'nodeName' => (string)$site->getNodeName(),
                // The site node's aggregate id, used to scope workspace
                // publish/discard to a single site in multi-site setups. null
                // when the site node is absent from the current subgraph.
                'aggregateId' => $siteNode?->aggregateId->value,
                'nodeAddress' => $siteNode === null ? null : NodeAddressCodec::encode(NodeAddress::fromNode($siteNode)),
            ];
        }

        return $this->json([
            'workspace' => $workspaceName->value,
            'dimensionSpacePoint' => $dimensionSpacePoint->coordinates,
            'sites' => $sites,
        ]);
    }

    private function getStringQueryParam(string $name): ?string
    {
        $value = $this->request->getHttpRequest()->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
