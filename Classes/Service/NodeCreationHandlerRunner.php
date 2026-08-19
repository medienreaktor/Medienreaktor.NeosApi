<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationCommands;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationElements;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerFactoryInterface;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerInterface;
use Neos\Utility\PositionalArraySorter;

/**
 * Runs the node type's configured `options.nodeCreationHandlers` for a
 * CreateNodeAggregateWithNode arriving via POST /api/commands - the server-side
 * enrichment step the classic UI performs in its Create change and the raw CR
 * command path otherwise skips entirely. Without it, everything hooked into
 * that seam is silently ignored for API clients: Flowpack.NodeTemplates'
 * options.template, the promoted-elements handler, uriPathSegment generation,
 * and any custom handler a site registers.
 *
 * The handler contract is Neos.Neos.Ui's (NodeCreationHandlerFactoryInterface
 * et al.) - that is the interface third-party handlers implement, so parity
 * means running exactly those. The dependency is soft: on a headless install
 * without Neos.Neos.Ui the seam does not exist (no package could register a
 * handler against it) and the command passes through unchanged.
 *
 * Creation-dialog element values travel in the command envelope under the
 * transport-only "elements" key (serialized form, stripped before the command
 * is deserialized) and are converted per the node type's
 * ui.creationDialog.elements schema, mirroring the classic UI's
 * NodePropertyConversionService: reference elements become NodeAggregateIds,
 * date elements become date objects, object references are hydrated.
 *
 * One deliberate deviation from the classic UI: property values the client set
 * explicitly on the command win over handler output. The UI's creation dialog
 * never pre-fills initialPropertyValues, so its handlers may overwrite freely
 * (UriPathSegmentNodeCreationHandler unconditionally replaces uriPathSegment
 * with a title slug or a random one) - but an API client that states a value
 * means it, and clobbering it would break the command's documented semantics.
 */
final class NodeCreationHandlerRunner
{
    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    #[Flow\Inject]
    protected PropertyValueHydrator $propertyValueHydrator;

    /**
     * The node type declarations the content repository treats as a date, kept
     * in sync with PropertyType::tryFromString (same list as PropertyTypeCoercer).
     */
    private const DATE_TYPES = [
        'DateTime',
        '\DateTime',
        'DateTimeImmutable',
        '\DateTimeImmutable',
        'DateTimeInterface',
        '\DateTimeInterface',
    ];

    /**
     * Expand a creation command into the full command chain the node type's
     * creation handlers produce. Returns just the command itself when the seam
     * is absent (no Neos.Neos.Ui) or the node type configures no handlers.
     *
     * @param array<int|string, mixed> $rawElements creation-dialog element values in serialized transport form
     * @return list<CommandInterface>
     * @throws \InvalidArgumentException on unconvertible element values (a client error)
     * @throws \RuntimeException on misconfigured handlers (a server configuration error)
     */
    public function run(CreateNodeAggregateWithNode $command, array $rawElements, ContentRepository $contentRepository): array
    {
        if (!interface_exists(NodeCreationHandlerFactoryInterface::class)) {
            return [$command];
        }
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($command->nodeTypeName);
        $handlerConfigurations = $nodeType?->getOptions()['nodeCreationHandlers'] ?? null;
        if ($nodeType === null || !is_array($handlerConfigurations) || $handlerConfigurations === []) {
            return [$command];
        }

        $commands = NodeCreationCommands::fromFirstCommand($command, $contentRepository->getNodeTypeManager());
        $elements = $this->buildElements($nodeType, $rawElements);

        foreach ((new PositionalArraySorter($handlerConfigurations))->toArray() as $key => $configuration) {
            $factoryClassName = is_array($configuration) ? ($configuration['factoryClassName'] ?? null) : null;
            if (!is_string($factoryClassName)) {
                throw new \RuntimeException(sprintf('Node creation handler "%s" has no "factoryClassName" specified.', $key), 1755600001);
            }
            $factory = $this->objectManager->get($factoryClassName);
            if (!$factory instanceof NodeCreationHandlerFactoryInterface) {
                throw new \RuntimeException(sprintf('Node creation handler "%s" factory %s does not implement %s.', $key, $factoryClassName, NodeCreationHandlerFactoryInterface::class), 1755600002);
            }
            $handler = $factory->build($contentRepository);
            if (!$handler instanceof NodeCreationHandlerInterface) {
                throw new \RuntimeException(sprintf('Node creation handler "%s" factory %s built a %s, expected %s.', $key, $factoryClassName, get_class($handler), NodeCreationHandlerInterface::class), 1755600003);
            }
            $commands = $handler->handle($commands, $elements);
        }

        // Client wins: re-apply the property values the command stated
        // explicitly over whatever the handlers produced.
        $allCommands = iterator_to_array($commands, false);
        if ($command->initialPropertyValues->values !== []) {
            $allCommands[0] = $commands->first->withInitialPropertyValues(
                $commands->first->initialPropertyValues->merge($command->initialPropertyValues)
            );
        }

        return $allCommands;
    }

    /**
     * Convert the serialized element values into what handlers expect,
     * following the node type's ui.creationDialog.elements schema (which, via
     * the CreationDialogNodeTypePostprocessor, also covers promoted
     * showInCreationDialog properties/references). Unconfigured keys stay
     * available through NodeCreationElements::serialized() only - exactly the
     * classic UI's behavior.
     *
     * @param array<int|string, mixed> $rawElements
     */
    private function buildElements(NodeType $nodeType, array $rawElements): NodeCreationElements
    {
        $elementConfigurations = $nodeType->getConfiguration('ui.creationDialog.elements') ?? [];
        $converted = [];
        foreach ($elementConfigurations as $name => $elementConfiguration) {
            if (!array_key_exists($name, $rawElements) || $rawElements[$name] === null) {
                continue;
            }
            $value = $this->propertyValueHydrator->hydrate($rawElements[$name]);
            $elementType = is_array($elementConfiguration) ? ($elementConfiguration['type'] ?? 'string') : 'string';
            if ($elementType === 'reference' || $elementType === 'references') {
                $ids = is_string($value) && $value !== '' ? [$value] : (is_array($value) ? $value : []);
                try {
                    $converted[$name] = NodeAggregateIds::fromArray($ids);
                } catch (\Throwable $exception) {
                    throw new \InvalidArgumentException(sprintf('Element "%s" is not a valid node reference: %s', $name, $exception->getMessage()), 1755600004, $exception);
                }
                continue;
            }
            if (in_array($elementType, self::DATE_TYPES, true) && is_string($value) && $value !== '') {
                try {
                    $converted[$name] = new \DateTimeImmutable($value);
                } catch (\Exception $exception) {
                    throw new \InvalidArgumentException(sprintf('Element "%s" is not a valid date: "%s".', $name, $value), 1755600005, $exception);
                }
                continue;
            }
            $converted[$name] = $value;
        }

        return new NodeCreationElements(elementValues: $converted, serializedValues: $rawElements);
    }
}
