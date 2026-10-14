<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Api\Extension\NodeReadFilter;
use Neos\Api\Security\ApiCaller;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;

/**
 * Leaves out the nodes titled "Filtered out", registered in the Testing settings
 */
class TitleNodeReadFilter implements NodeReadFilter
{
    public const string TITLE = 'Filtered out';

    public function filter(Nodes $nodes, ApiCaller $caller): Nodes
    {
        return $nodes->filter(static fn (Node $node) => $node->getProperty('title') !== self::TITLE);
    }
}
