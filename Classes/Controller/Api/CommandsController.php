<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\CommandRegistry;
use Medienreaktor\NeosApi\Service\PropertyTypeCoercer;
use Medienreaktor\NeosApi\Service\PropertyValueHydrator;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * The write API: execute whitelisted content repository commands.
 *
 * Single:  POST /api/commands        {"type": "...", "payload": {...}}
 * Batch:   POST /api/commands/batch  {"commands": [{"type": ..., "payload": ...}, ...]}
 *
 * Batches execute sequentially and stop at the first failure; the response
 * reports how many commands were applied. (Commands on an event-sourced CR
 * are not transactional as a group - partial application is reported, not
 * rolled back.)
 *
 * Every command is authorized centrally by the content repository
 * (workspace permissions, EditNodePrivilege) - a denial maps to 403 here.
 */
class CommandsController extends AbstractApiController
{
    #[Flow\Inject]
    protected PropertyValueHydrator $propertyValueHydrator;

    #[Flow\Inject]
    protected PropertyTypeCoercer $propertyTypeCoercer;

    /**
     * Authenticated exclusively via sessionless bearer tokens - CSRF does not apply
     */
    #[Flow\SkipCsrfProtection]
    public function executeAction(): string
    {
        $this->requireScope('neos.write');

        $body = $this->getJsonBody();
        $result = $this->handleCommand($body);
        if (isset($result['error'])) {
            $this->throwJsonStatus($result['statusCode'], $result['error'], $result['message']);
        }

        return $this->json(['status' => 'ok', 'type' => $body['type']]);
    }

    #[Flow\SkipCsrfProtection]
    public function batchAction(): string
    {
        $this->requireScope('neos.write');

        $body = $this->getJsonBody();
        $commands = $body['commands'] ?? null;
        if (!is_array($commands) || $commands === []) {
            $this->throwJsonStatus(400, 'invalid_request', 'Expected a non-empty "commands" array.');
        }

        $executed = 0;
        foreach (array_values($commands) as $index => $command) {
            $result = $this->handleCommand(is_array($command) ? $command : []);
            if (isset($result['error'])) {
                $this->throwJsonStatus($result['statusCode'], $result['error'], sprintf(
                    'Command #%d (%s) failed after %d successfully executed commands: %s',
                    $index,
                    is_array($command) && is_string($command['type'] ?? null) ? $command['type'] : 'invalid',
                    $executed,
                    $result['message']
                ));
            }
            $executed++;
        }

        return $this->json(['status' => 'ok', 'executed' => $executed]);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{error?: string, message?: string, statusCode?: int}
     */
    private function handleCommand(array $envelope): array
    {
        $type = $envelope['type'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (!is_string($type) || !is_array($payload)) {
            return ['error' => 'invalid_request', 'message' => 'Each command needs a string "type" and an object "payload".', 'statusCode' => 400];
        }

        // Object-typed property values (assets, images, ...) arrive as
        // serialized references and must be resolved to real objects before the
        // command's instanceof validation - see PropertyValueHydrator.
        try {
            $payload = (array)$this->propertyValueHydrator->hydrate($payload);
        } catch (\Throwable $exception) {
            return ['error' => 'invalid_payload', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        // Scalar property values whose JSON transport form differs from the
        // declared PHP type (today: DateTime, sent as an ISO 8601 string) are
        // coerced to that type before the command's instanceof validation - see
        // PropertyTypeCoercer.
        try {
            $payload = $this->coerceScalarProperties($type, $payload);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => 'invalid_payload', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        // SetNodeReferences carries value objects the command's fromArray()
        // cannot build from plain JSON (NodeReferencesToWrite::fromArray()
        // expects DTO instances) - translate the JSON transport shape
        // [{referenceName, targets: [ids]}] into those DTOs here.
        if ($type === 'SetNodeReferences') {
            try {
                $payload['references'] = $this->buildReferencesToWrite($payload['references'] ?? null);
            } catch (\InvalidArgumentException $exception) {
                return ['error' => 'invalid_payload', 'message' => $exception->getMessage(), 'statusCode' => 422];
            }
        }

        // Record where a removed node lived, so a deletion can still be scoped
        // to its document/site when publishing individual changes. See
        // resolveRemovalAttachmentPoint().
        if ($type === 'RemoveNodeAggregate' && !isset($payload['removalAttachmentPoint'])) {
            $attachmentPoint = $this->resolveRemovalAttachmentPoint($payload);
            if ($attachmentPoint !== null) {
                $payload['removalAttachmentPoint'] = $attachmentPoint;
            }
        }

        try {
            $command = CommandRegistry::deserialize($type, $payload);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => 'unknown_command', 'message' => $exception->getMessage(), 'statusCode' => 400];
        } catch (\Throwable $exception) {
            return ['error' => 'invalid_payload', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        try {
            $this->getContentRepository()->handle($command);
        } catch (AccessDenied $exception) {
            return ['error' => 'access_denied', 'message' => $exception->getMessage(), 'statusCode' => 403];
        } catch (\Throwable $exception) {
            return ['error' => 'command_failed', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        return [];
    }

    /**
     * The JSON transport shape of SetNodeReferences' "references" field:
     * [{referenceName: string, targets: [nodeAggregateId, ...]}, ...]. An
     * empty targets list is valid and clears the named reference (the CR's
     * "writing no references deletes the previous ones" semantics); an empty
     * or missing references list is not.
     *
     * @return array<NodeReferencesForName>
     */
    private function buildReferencesToWrite(mixed $references): array
    {
        if (!is_array($references) || $references === []) {
            throw new \InvalidArgumentException('SetNodeReferences needs a non-empty "references" list of {referenceName, targets} entries.', 1752990010);
        }
        $result = [];
        foreach ($references as $entry) {
            if (!is_array($entry) || !is_string($entry['referenceName'] ?? null) || !is_array($entry['targets'] ?? null)) {
                throw new \InvalidArgumentException('Each SetNodeReferences entry needs a string "referenceName" and a "targets" array of node aggregate ids.', 1752990011);
            }
            $result[] = NodeReferencesForName::fromTargets(
                ReferenceName::fromString($entry['referenceName']),
                NodeAggregateIds::fromArray($entry['targets'])
            );
        }

        return $result;
    }

    /**
     * Coerce the property values of a property-bearing command to their
     * declared PHP types. Only SetNodeProperties and CreateNodeAggregateWithNode
     * carry a property map; every other command is returned untouched. The node
     * type is resolved from the command (the existing node for a modification,
     * the payload's nodeTypeName for a creation); if it cannot be resolved we
     * leave the payload as-is and let the command run - a genuinely wrong type
     * still fails downstream, exactly as before this step existed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function coerceScalarProperties(string $type, array $payload): array
    {
        $key = match ($type) {
            'SetNodeProperties' => 'propertyValues',
            'CreateNodeAggregateWithNode' => 'initialPropertyValues',
            default => null,
        };
        if ($key === null || !is_array($payload[$key] ?? null) || $payload[$key] === []) {
            return $payload;
        }
        $nodeType = $this->resolveNodeTypeForCommand($type, $payload);
        if ($nodeType === null) {
            return $payload;
        }
        $payload[$key] = $this->propertyTypeCoercer->coerce($nodeType, $payload[$key]);
        return $payload;
    }

    /**
     * The node type a property-bearing command writes to: taken from the
     * payload for a creation (the node does not exist yet), or looked up from
     * the existing node for a modification. Best-effort - returns null on any
     * failure so coercion is simply skipped.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveNodeTypeForCommand(string $type, array $payload): ?NodeType
    {
        try {
            $nodeTypeManager = $this->getContentRepository()->getNodeTypeManager();
            if ($type === 'CreateNodeAggregateWithNode') {
                if (!is_string($payload['nodeTypeName'] ?? null)) {
                    return null;
                }
                return $nodeTypeManager->getNodeType(NodeTypeName::fromString($payload['nodeTypeName']));
            }
            // SetNodeProperties: resolve the existing node to read its type.
            if (
                !is_string($payload['workspaceName'] ?? null)
                || !is_string($payload['nodeAggregateId'] ?? null)
                || !is_array($payload['originDimensionSpacePoint'] ?? null)
            ) {
                return null;
            }
            $node = $this->getContentRepository()->getContentSubgraph(
                WorkspaceName::fromString($payload['workspaceName']),
                DimensionSpacePoint::fromArray($payload['originDimensionSpacePoint'])
            )->findNodeById(NodeAggregateId::fromString($payload['nodeAggregateId']));
            return $node === null ? null : $nodeTypeManager->getNodeType($node->nodeTypeName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the "removal attachment point" for a RemoveNodeAggregate: the
     * closest document of the node being removed, captured while the node still
     * exists. Partial (site/document-scoped) publishing attributes a change by
     * walking up from the changed node to a Document/Site ancestor - but a
     * removed node is gone from the workspace, so a deletion is unscopable
     * unless the removal recorded a surviving ancestor. The ESCR command makes
     * this optional and Neos leaves it unset otherwise, which makes deletions
     * impossible to publish per site/document. Filling it here keeps every
     * removal (from any client) scopable. Best-effort: on any failure we return
     * null and let the command run without it, as before.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveRemovalAttachmentPoint(array $payload): ?string
    {
        if (
            !is_string($payload['workspaceName'] ?? null)
            || !is_string($payload['nodeAggregateId'] ?? null)
            || !is_array($payload['coveredDimensionSpacePoint'] ?? null)
        ) {
            return null;
        }
        try {
            $subgraph = $this->getContentRepository()->getContentSubgraph(
                WorkspaceName::fromString($payload['workspaceName']),
                DimensionSpacePoint::fromArray($payload['coveredDimensionSpacePoint'])
            );
            $nodeAggregateId = NodeAggregateId::fromString($payload['nodeAggregateId']);
            $documentFilter = FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document');
            $closestDocument = $subgraph->findClosestNode($nodeAggregateId, $documentFilter);
            // The removed node is itself a document: its own id would point at a
            // node that no longer exists after removal, so attach to the parent's
            // document instead.
            if ($closestDocument !== null && $closestDocument->aggregateId->equals($nodeAggregateId)) {
                $parent = $subgraph->findParentNode($nodeAggregateId);
                $closestDocument = $parent === null
                    ? null
                    : $subgraph->findClosestNode($parent->aggregateId, $documentFilter);
            }
            return $closestDocument?->aggregateId->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(): array
    {
        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        if (!is_array($body)) {
            $this->throwJsonStatus(400, 'invalid_request', 'Request body must be a JSON object.');
        }

        return $body;
    }
}
