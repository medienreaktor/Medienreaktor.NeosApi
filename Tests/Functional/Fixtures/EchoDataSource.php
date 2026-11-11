<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Service\DataSource\AbstractDataSource;

/**
 * Returns what it's called with
 */
class EchoDataSource extends AbstractDataSource
{
    protected static $identifier = 'neos-api-test-echo';

    /**
     * @param array<mixed> $arguments
     * @return array<string, mixed>
     */
    public function getData(?Node $node = null, array $arguments = []): array
    {
        return ['node' => $node?->aggregateId->value, 'arguments' => $arguments];
    }
}
