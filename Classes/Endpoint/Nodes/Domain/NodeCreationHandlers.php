<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationCommands;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationElements;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerFactoryInterface;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerInterface;
use Neos\Utility\PositionalArraySorter;

/**
 * The node creation handlers of a node type (options.nodeCreationHandlers), run for createNode as the Neos UI runs
 * them for its creation dialog (AbstractCreate::applyNodeCreationHandlers()): the UI's uriPathSegment and promoted
 * elements handlers, Flowpack.NodeTemplates' templates and any a site registers
 *
 * The handler contract is the Neos UI's, so neos/neos-ui is only suggested: without it there are no handlers to run
 * (no package could register one) and the command is sent as it is. Its classes are only used in method bodies, which
 * PHP and Flow don't resolve unless they run
 *
 * Unlike the creation dialog, which never sets properties itself, createNode takes properties as well: the ones it
 * states win over what the handlers set (the uriPathSegment handler always replaces it, with a random one if there is
 * no title element)
 */
#[Flow\Scope('singleton')]
final readonly class NodeCreationHandlers
{
    private const string FACTORY_INTERFACE = 'Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerFactoryInterface';

    public function __construct(
        private ObjectManagerInterface $objectManager,
    ) {
    }

    /**
     * Whether neos/neos-ui is installed, with the handler contract
     */
    public static function areAvailable(): bool
    {
        return interface_exists(self::FACTORY_INTERFACE);
    }

    /**
     * The command chain the node type's handlers make of the creation: the command, changed, and the commands they
     * add, e.g. a template's child nodes. The command alone without neos/neos-ui or handlers
     *
     * @param array<string, mixed> $elements the creation dialog's values converted for the handlers, see PropertyValues::creationElements()
     * @param array<mixed> $serializedElements the values as given
     * @return list<CommandInterface>
     * @throws \RuntimeException if a handler is misconfigured
     */
    public function apply(CreateNodeAggregateWithNode $command, NodeType $nodeType, array $elements, array $serializedElements, ContentRepository $contentRepository): array
    {
        $configurations = $nodeType->getOptions()['nodeCreationHandlers'] ?? null;
        if (!self::areAvailable() || !is_array($configurations) || $configurations === []) {
            return [$command];
        }
        $commands = NodeCreationCommands::fromFirstCommand($command, $contentRepository->getNodeTypeManager());
        $creationElements = new NodeCreationElements(elementValues: $elements, serializedValues: $serializedElements);
        foreach ((new PositionalArraySorter($configurations))->toArray() as $key => $configuration) {
            $factoryClassName = is_array($configuration) ? $configuration['factoryClassName'] ?? null : null;
            if (!is_string($factoryClassName) || $factoryClassName === '') {
                throw new \RuntimeException(sprintf('The node creation handler %s of the node type %s has no factoryClassName', $key, $nodeType->name->value), 1791900011);
            }
            $factory = $this->objectManager->get($factoryClassName);
            if (!$factory instanceof NodeCreationHandlerFactoryInterface) {
                throw new \RuntimeException(sprintf('The factory %s of the node creation handler %s doesn\'t implement %s', $factoryClassName, $key, NodeCreationHandlerFactoryInterface::class), 1791900012);
            }
            $handler = $factory->build($contentRepository);
            if (!$handler instanceof NodeCreationHandlerInterface) {
                throw new \RuntimeException(sprintf('The factory %s of the node creation handler %s built no %s', $factoryClassName, $key, NodeCreationHandlerInterface::class), 1791900013);
            }
            $commands = $handler->handle($commands, $creationElements);
        }
        // the stated properties win, see above
        $first = $commands->first->withInitialPropertyValues($commands->first->initialPropertyValues->merge($command->initialPropertyValues));
        return [$first, ...iterator_to_array($commands->additionalCommands, false)];
    }
}
