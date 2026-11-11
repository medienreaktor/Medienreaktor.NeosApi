<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;

/**
 * Builds ContentStreamEvents with ContentRepositoryRegistry::buildService()
 *
 * @implements ContentRepositoryServiceFactoryInterface<ContentStreamEvents>
 */
final readonly class ContentStreamEventsFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentStreamEvents
    {
        return new ContentStreamEvents($serviceFactoryDependencies->eventStore);
    }
}
