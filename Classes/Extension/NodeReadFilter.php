<?php
declare(strict_types=1);

namespace Neos\Api\Extension;

use Neos\Api\Security\ApiCaller;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;

/**
 * Narrows down the nodes listNodes returns, e.g. to hide nodes a package manages itself from searches and trees,
 * beyond what node privileges can express
 *
 * Registered in the settings Neos.Api.nodeReadFilters by a key of their own (class name, ~ removes one), applied in
 * that order to each page of listNodes after it is read: meta.total and the links count the nodes before filtering, a
 * page may have fewer items than limit. Single reads (getNode, includes like children or ancestors) aren't filtered:
 * a filter decides what lists show, node privileges decide what may be read
 */
interface NodeReadFilter
{
    /**
     * The nodes the caller is to see, in their order
     */
    public function filter(Nodes $nodes, ApiCaller $caller): Nodes;
}
