<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Removes nodes from the listings this API serves, before they are
 * serialized.
 *
 * The content repository's own visibility constraints (subtree tags, workspace
 * permissions) have already been applied by the time a filter runs - this is
 * for narrowing a package brings that the content repository has no concept
 * of. Registered in the settings under Medienreaktor.NeosApi.nodeReadFilters,
 * the same way workspace data enrichers are, and for the same reason: the API
 * stays out of the business of knowing what the narrowing MEANS.
 *
 *     Medienreaktor:
 *       NeosApi:
 *         nodeReadFilters:
 *           'Vendor.Package:something':
 *             filter: 'Vendor\Package\Service\SomethingNodeReadFilter'
 *
 * Filters run on every node listing of a request, so they must be cheap:
 * batch or memoize per request (the serializer is a singleton, and filters
 * resolved through it live as long as the request does).
 *
 * Two things they must not do. They must not ADD nodes - the result has to be
 * a subset of the input, or the API would start serving content the content
 * repository never handed out. And they must not throw: a filter that cannot
 * decide should return the input unchanged, because failing closed here means
 * a listing that silently loses rows with no error anywhere.
 *
 * Single-node reads are deliberately NOT filtered. A client that already holds
 * a node address is addressing something it was given; hiding it there breaks
 * legitimate reads (an ancestor shown read-only, a link target being resolved)
 * without closing anything a listing did not already close.
 */
interface NodeReadFilterInterface
{
    /**
     * Return the nodes that may be listed, in the given order. Returning the
     * input unchanged is always valid and is what a filter that has nothing to
     * say - or cannot decide - should do.
     *
     * @param array<int, Node> $nodes
     * @return array<int, Node>
     */
    public function filterReadableNodes(array $nodes, ContentSubgraphInterface $subgraph): array;
}
