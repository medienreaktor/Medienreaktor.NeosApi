<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Service\DataSource\AbstractDataSource;

/**
 * Throws with a message that must not get out
 */
class FailingDataSource extends AbstractDataSource
{
    protected static $identifier = 'neos-api-test-failing';

    /**
     * @param array<mixed> $arguments
     */
    public function getData(?Node $node = null, array $arguments = []): never
    {
        throw new \RuntimeException('secret internals', 1791400001);
    }
}
