<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;

/**
 * Builds FlatDescendantNodes with ContentRepositoryRegistry::buildService(), with the connection the content
 * repository's Doctrine DBAL adapter uses
 *
 * @implements ContentRepositoryServiceFactoryInterface<FlatDescendantNodes>
 */
final readonly class FlatDescendantNodesFactory implements ContentRepositoryServiceFactoryInterface
{
    public function __construct(
        private Connection $dbal,
    ) {
    }

    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): FlatDescendantNodes
    {
        return new FlatDescendantNodes($this->dbal, $serviceFactoryDependencies->contentGraphReadModel, $serviceFactoryDependencies->nodeTypeManager);
    }
}
