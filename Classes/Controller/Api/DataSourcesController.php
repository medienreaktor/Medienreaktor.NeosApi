<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\Neos\Service\Controller\DataSourceController;
use Neos\Neos\Service\DataSource\DataSourceInterface;
use Neos\Utility\ObjectAccess;

/**
 * Bearer-token access to the Neos data sources (DataSourceInterface
 * implementations) that back select-box style editors. This wraps the same
 * discovery and invocation the session-authenticated core endpoint
 * (/neos/service/data-source) performs, so every existing data source works
 * unchanged - only the transport differs:
 *
 *  - the node is passed as a base64url node address (the API-wide codec),
 *    not the raw NodeAddress JSON the old UI sends
 *  - the raw getData() return value is wrapped in a {"data": ...} envelope,
 *    keeping error responses ({"error": ...}) distinguishable and the
 *    top level an object regardless of what the data source returns
 *
 * All query parameters besides "node" are forwarded verbatim as the
 * $arguments array (the editorOptions.dataSourceAdditionalData contract).
 */
class DataSourcesController extends AbstractApiController
{
    public function showAction(string $dataSourceIdentifier): string
    {
        $this->requireScope('neos.read');

        // Reuse the core controller's compile-static registry so identifier
        // resolution (and its duplicate-identifier guarantee) stays identical.
        $dataSources = DataSourceController::getDataSources($this->objectManager);
        if (!isset($dataSources[$dataSourceIdentifier])) {
            $this->throwJsonStatus(404, 'unknown_data_source', sprintf('No data source with identifier "%s" exists.', $dataSourceIdentifier));
        }

        /** @var DataSourceInterface $dataSource */
        $dataSource = new $dataSources[$dataSourceIdentifier]();
        // AbstractDataSource exposes a controllerContext some data sources use
        // (e.g. to build URIs) - inject ours the way the core endpoint does.
        if (ObjectAccess::isPropertySettable($dataSource, 'controllerContext')) {
            ObjectAccess::setProperty($dataSource, 'controllerContext', $this->controllerContext);
        }

        $node = null;
        $arguments = $this->request->getArguments();
        unset($arguments['dataSourceIdentifier'], $arguments['node']);

        $nodeArgument = $this->request->hasArgument('node') ? $this->request->getArgument('node') : null;
        if (is_string($nodeArgument) && $nodeArgument !== '') {
            $address = $this->decodeNodeAddress($nodeArgument);
            $node = $this->getSubgraph($address)->findNodeById($address->aggregateId);
            if ($node === null) {
                $this->throwJsonStatus(404, 'node_not_found', 'The node given as data source context does not exist.');
            }
        }

        try {
            $value = $dataSource->getData($node, $arguments);
        } catch (\Exception $exception) {
            // The exception text stays out of the response: data sources are
            // arbitrary third-party code and their messages may leak internals.
            $this->logger->error(sprintf('Data source "%s" threw: %s', $dataSourceIdentifier, $exception->getMessage()), ['exception' => $exception]);
            $this->throwJsonStatus(500, 'data_source_failed', sprintf('Data source "%s" failed to execute.', $dataSourceIdentifier));
        }

        return $this->json(['data' => $value]);
    }
}
