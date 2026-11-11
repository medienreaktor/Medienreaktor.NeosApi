<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\DataSources\Response\DataSourceFailed;
use Neos\Api\Endpoint\DataSources\Response\DataSourceResult;
use Neos\Api\Endpoint\DataSources\Schema\DataSource;
use Neos\Api\Endpoint\DataSources\Schema\DataSourceId;
use Neos\Api\Endpoint\DataSources\Schema\DataSourceList;
use Neos\Api\Endpoint\DataSources\Schema\DataSourceListing;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Domain\SubgraphResolver;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\Arguments;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Service\Controller\DataSourceController;
use Neos\Neos\Service\DataSource\DataSourceInterface;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The data sources: PHP code that provides data, e.g. the options of select boxes in the Neos UI's inspector
 *
 * They are the implementations of Neos' DataSourceInterface, the same ones Neos' own endpoint
 * (/neos/service/data-source) calls. They belong to no content repository, a node is only what one is called for
 */
final readonly class DataSources
{
    public function __construct(
        private ObjectManagerInterface $objectManager,
        private SubgraphResolver $subgraphResolver,
        private LoggerInterface $logger,
    ) {
    }

    #[Operation(
        path: '/datasources',
        method: 'GET',
        summary: 'List all data sources',
        description: 'The data sources of all packages, sorted by ID. A data source says nothing about itself but its ID, what it provides and which arguments it takes are up to it.',
        operationId: 'listDataSources',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::DATASOURCES_READ],
        ],
    )]
    public function list(): DataSourceListing
    {
        $ids = array_map('strval', array_keys(DataSourceController::getDataSources($this->objectManager)));
        sort($ids);
        return DataSourceListing::of(new DataSourceList(...array_map(
            static fn (string $id) => new DataSource(DataSourceId::fromString($id)),
            $ids,
        )));
    }

    #[Operation(
        path: '/datasources/{dataSourceId}',
        method: 'GET',
        summary: 'Get the data of a data source',
        description: 'Calls the data source with the arguments and, with nodeAggregateId, the node, and returns what it provides in data. The node is read as for getNode: contentRepositoryId defaults to the default site\'s (Neos.Neos.defaultSiteNodeName, else the first online site), workspaceName to live and dimensionSpacePoint to the default site\'s default one. Without nodeAggregateId the data source is called without a node, and contentRepositoryId, workspaceName and dimensionSpacePoint are a 400. If the data source fails, it\'s a 500 whose details are in the log only.',
        operationId: 'getDataSourceData',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::DATASOURCES_READ],
        ],
    )]
    public function get(
        DataSourceId $dataSourceId,
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'The arguments to call the data source with: arguments[name]=value, arrays as arguments[name][]=value. What the Neos UI sends from editorOptions.dataSourceAdditionalData')]
        Arguments|null $arguments = null,
        #[Parameter(in: 'query', description: 'The content repository of the node, the one of the default site if omitted. Only with nodeAggregateId')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'query', description: 'The aggregate id of the node to call the data source for, e.g. the one edited in the inspector. No node if omitted')]
        NodeAggregateId|null $nodeAggregateId = null,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted. Only with nodeAggregateId')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, as JSON. If omitted, the default one of the content repository\'s default site, as for getNode. Only with nodeAggregateId')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
    ): DataSourceResult|NotFound|BadRequest|DataSourceFailed {
        $className = DataSourceController::getDataSources($this->objectManager)[$dataSourceId->value] ?? null;
        if ($className === null) {
            return NotFound::because(sprintf('There is no data source %s', $dataSourceId->value));
        }
        if ($nodeAggregateId === null) {
            if ($contentRepositoryId !== null || $workspaceName !== null || $dimensionSpacePoint !== null) {
                return BadRequest::because('contentRepositoryId, workspaceName and dimensionSpacePoint are where to read the node, give nodeAggregateId as well');
            }
            $node = null;
        } else {
            $node = $this->node($nodeAggregateId, $contentRepositoryId, $workspaceName, $dimensionSpacePoint);
            if (!$node instanceof Node) {
                return $node;
            }
        }
        // the parameter only validates the arguments, see Arguments
        /** @var DataSourceInterface $dataSource */
        $dataSource = $this->objectManager->get($className);
        try {
            return DataSourceResult::of($dataSource->getData($node, Arguments::values($request)));
        } catch (\Throwable $throwable) {
            $this->logger->error(sprintf('The data source %s failed: %s', $dataSourceId->value, $throwable->getMessage()), ['exception' => $throwable]);
            return DataSourceFailed::because(sprintf('The data source %s failed', $dataSourceId->value));
        }
    }

    private function node(NodeAggregateId $nodeAggregateId, ?ContentRepositoryId $contentRepositoryId, ?WorkspaceName $workspaceName, ?DimensionSpacePoint $dimensionSpacePoint): Node|NotFound|BadRequest
    {
        return $this->subgraphResolver->resolveNode(
            $contentRepositoryId,
            $nodeAggregateId->toNodeAggregateId(),
            $workspaceName,
            $dimensionSpacePoint,
            excludeDisabled: false,
            withoutContentRepository: 'There is no default site to read the node in, give contentRepositoryId',
        );
    }
}
