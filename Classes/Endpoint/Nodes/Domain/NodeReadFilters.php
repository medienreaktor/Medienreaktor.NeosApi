<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\Api\Extension\NodeReadFilter;
use Neos\Api\Security\ApiCaller;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * The NodeReadFilter implementations of the settings Neos.Api.nodeReadFilters, applied to the nodes of listNodes
 */
#[Flow\Scope('singleton')]
final readonly class NodeReadFilters
{
    /**
     * @var list<string> the class names, in the order of the settings
     */
    private array $classNames;

    /**
     * @param array<string, string|null>|null $classNames by key, null removes one
     */
    public function __construct(
        private ObjectManagerInterface $objectManager,
        #[Flow\InjectConfiguration(path: 'nodeReadFilters', package: 'Neos.Api')]
        array|null $classNames,
    ) {
        $this->classNames = array_values(array_filter($classNames ?? [], static fn (mixed $className) => is_string($className) && $className !== ''));
    }

    /**
     * The nodes all filters let through, in their order
     *
     * @param list<Node> $nodes
     * @return list<Node>
     * @throws \LogicException if a filter doesn't implement NodeReadFilter
     */
    public function apply(array $nodes, ApiCaller $caller): array
    {
        if ($this->classNames === []) {
            return $nodes;
        }
        $filtered = Nodes::fromArray($nodes);
        foreach ($this->classNames as $className) {
            $filter = $this->objectManager->get($className);
            if (!$filter instanceof NodeReadFilter) {
                throw new \LogicException(sprintf('The node read filter %s (Neos.Api.nodeReadFilters) doesn\'t implement %s', $className, NodeReadFilter::class), 1791900001);
            }
            $filtered = $filter->filter($filtered, $caller);
        }
        return iterator_to_array($filtered, false);
    }
}
