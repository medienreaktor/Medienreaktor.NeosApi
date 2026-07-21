<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\EnableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeRenaming\Command\ChangeNodeAggregateName;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;

/**
 * Whitelist of content repository commands exposed via POST /api/commands.
 *
 * The discriminated union of the write API: {"type": "<name>", "payload":
 * {...}} resolves here to a command class and is deserialized via the
 * command's own ::fromArray(). Workspace lifecycle commands are deliberately
 * NOT exposed here - publish/discard/rebase have use-case endpoints under
 * /api/workspaces/{name}/... that go through the Neos publishing service.
 *
 * The union has one member beyond this whitelist: the synthetic
 * CopyNodesRecursively, intercepted in CommandsController before it reaches
 * this registry (it is no CR command in Neos 9 and has no class to map to).
 *
 * Authorization happens centrally in ContentRepository::handle() for every
 * command (workspace permissions + EditNodePrivilege).
 */
final class CommandRegistry
{
    /**
     * @var array<string, class-string<CommandInterface>>
     */
    private const COMMANDS = [
        'CreateNodeAggregateWithNode' => CreateNodeAggregateWithNode::class,
        'SetNodeProperties' => SetNodeProperties::class,
        'SetNodeReferences' => SetNodeReferences::class,
        'MoveNodeAggregate' => MoveNodeAggregate::class,
        'RemoveNodeAggregate' => RemoveNodeAggregate::class,
        'DisableNodeAggregate' => DisableNodeAggregate::class,
        'EnableNodeAggregate' => EnableNodeAggregate::class,
        'TagSubtree' => TagSubtree::class,
        'UntagSubtree' => UntagSubtree::class,
        'CreateNodeVariant' => CreateNodeVariant::class,
        'ChangeNodeAggregateType' => ChangeNodeAggregateType::class,
        'ChangeNodeAggregateName' => ChangeNodeAggregateName::class,
    ];

    /**
     * @return array<string>
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::COMMANDS);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function deserialize(string $type, array $payload): CommandInterface
    {
        $commandClass = self::COMMANDS[$type] ?? null;
        if ($commandClass === null) {
            throw new \InvalidArgumentException(sprintf('Unknown command type "%s". Supported: %s', $type, implode(', ', self::getSupportedTypes())), 1751980030);
        }

        return $commandClass::fromArray($payload);
    }
}
