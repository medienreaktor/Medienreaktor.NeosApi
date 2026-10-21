<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeName;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\Workspaces\Schema\EventDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeAggregateIdDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeNameDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeTypeDiff;
use Neos\Api\Endpoint\Workspaces\Schema\PropertyDiff;
use Neos\Api\Endpoint\Workspaces\Schema\PropertyDiffList;
use Neos\Api\Endpoint\Workspaces\Schema\ReferenceDiff;
use Neos\Api\Endpoint\Workspaces\Schema\ReferenceDiffList;
use Neos\Api\Endpoint\Workspaces\Schema\TagDiff;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Flow\Annotations as Flow;

/**
 * Events of a content stream → what each changed about its node compared to just before it (events, not state), for
 * listWorkspaceEvents' include=diff, for one request
 *
 * The events are replayed oldest first from some events before the diffed ones ($lookback), keeping what they set of
 * each node: its properties and references per origin dimension space point, its node type, name and parent. A value
 * no event set is the base workspace's current one, read as ChangeDiffer reads it (without visibility constraints,
 * compared by the serialized values), unless the node or variant was created in the stream: it had none. A variant
 * created in the stream has its source's values. If the base workspace doesn't have the node either (there is none,
 * as for live, or the node was created before the events replayed), the value before is unknown: the diff is
 * incomplete. The payloads are the content repository's (see EventQuery), read by their keys as the event classes
 * serialize them
 */
#[Flow\Proxy(false)]
final class EventDiffer
{
    /**
     * How many events before the diffed ones are replayed at most, unless the settings say otherwise
     * (Neos.Api.workspaceEvents.diffLookback): earlier values are taken from the base workspace
     */
    public const int DEFAULT_LOOKBACK = 1000;

    /**
     * @var array<string, array<string, array<string, mixed>>> the plain property values set (null unset), by node aggregate id, origin hash and name
     */
    private array $properties = [];

    /**
     * @var array<string, array<string, array<string, list<string>>>> the referenced node aggregate ids set, by node aggregate id, origin hash and name
     */
    private array $references = [];

    /**
     * @var array<string, array<string, bool>> the variants created in the stream, which have nothing in the base workspace, by node aggregate id and origin hash
     */
    private array $created = [];

    /**
     * @var array<string, array<string, array<string, string>>> where to read a variant created in the stream from in the base workspace: its source's origin, by node aggregate id and origin hash
     */
    private array $baseOrigins = [];

    /**
     * @var array<string, string> by node aggregate id
     */
    private array $nodeTypeNames = [];

    /**
     * @var array<string, string|null> by node aggregate id
     */
    private array $nodeNames = [];

    /**
     * @var array<string, string> by node aggregate id
     */
    private array $parents = [];

    /**
     * @var array<string, ContentSubgraphInterface|null> by dimension space point hash
     */
    private array $baseSubgraphs = [];

    /**
     * Whether a value before of the event diffed now is unknown, see diffOf()
     */
    private bool $unknownBefore = false;

    /**
     * @param int $lookback how many events before the diffed ones are replayed at most
     */
    public function __construct(
        private readonly ReadableWorkspace $workspace,
        private readonly NodeSerializer $nodeSerializer,
        public readonly int $lookback = self::DEFAULT_LOOKBACK,
    ) {
    }

    /**
     * The diffs of the events with the sequence numbers, by sequence number
     *
     * @param iterable<EventEnvelope> $events the content stream's, oldest first: the ones before and the diffed ones
     * @param array<int, true> $sequenceNumbers the events to diff
     * @return array<int, EventDiff>
     */
    public function diff(iterable $events, array $sequenceNumbers): array
    {
        $diffs = [];
        foreach ($events as $envelope) {
            $payload = json_decode($envelope->event->data->value, true);
            if (!is_array($payload) || !is_string($payload['nodeAggregateId'] ?? null)) {
                continue;
            }
            $type = $envelope->event->type->value;
            if (isset($sequenceNumbers[$envelope->sequenceNumber->value])) {
                $diffs[$envelope->sequenceNumber->value] = $this->diffOf($type, $payload['nodeAggregateId'], $payload);
            }
            $this->apply($type, $payload['nodeAggregateId'], $payload);
        }
        return $diffs;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function diffOf(string $type, string $node, array $payload): EventDiff
    {
        $this->unknownBefore = false;
        $diff = match ($type) {
            'NodePropertiesWereSet' => $this->propertiesSet($node, $payload),
            'NodeAggregateWithNodeWasCreated' => $this->nodeCreated($payload),
            'NodeReferencesWereSet' => $this->referencesSet($node, $payload),
            'NodeAggregateTypeWasChanged' => $this->nodeTypeChanged($node, $payload),
            'NodeAggregateNameWasChanged' => $this->nodeNameChanged($node, $payload),
            'NodeAggregateWasMoved' => $this->moved($node, $payload),
            'SubtreeWasTagged', 'SubtreeWasUntagged' => self::tagged($type === 'SubtreeWasTagged', $payload),
            default => EventDiff::none(),
        };
        return $this->unknownBefore ? $diff->incomplete() : $diff;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply(string $type, string $node, array $payload): void
    {
        switch ($type) {
            case 'NodeAggregateWithNodeWasCreated':
                $origin = self::coordinates($payload['originDimensionSpacePoint'] ?? null);
                if ($origin !== null) {
                    $hash = self::hash($origin);
                    $this->properties[$node][$hash] = self::propertyValues($payload['initialPropertyValues'] ?? null);
                    $this->references[$node][$hash] = self::referenceTargets($payload['nodeReferences'] ?? null);
                    $this->created[$node][$hash] = true;
                }
                if (is_string($payload['nodeTypeName'] ?? null)) {
                    $this->nodeTypeNames[$node] = $payload['nodeTypeName'];
                }
                $this->nodeNames[$node] = is_string($payload['nodeName'] ?? null) ? $payload['nodeName'] : null;
                if (is_string($payload['parentNodeAggregateId'] ?? null)) {
                    $this->parents[$node] = $payload['parentNodeAggregateId'];
                }
                break;
            case 'NodePropertiesWereSet':
                $origin = self::coordinates($payload['originDimensionSpacePoint'] ?? null);
                if ($origin !== null) {
                    $hash = self::hash($origin);
                    foreach (self::propertiesSetBy($payload) as $name => $value) {
                        $this->properties[$node][$hash][$name] = $value;
                    }
                }
                break;
            case 'NodeReferencesWereSet':
                foreach ((array)($payload['affectedSourceOriginDimensionSpacePoints'] ?? []) as $affected) {
                    $origin = self::coordinates($affected);
                    if ($origin === null) {
                        continue;
                    }
                    foreach (self::referenceTargets($payload['references'] ?? null) as $name => $targets) {
                        $this->references[$node][self::hash($origin)][$name] = $targets;
                    }
                }
                break;
            case 'NodeAggregateTypeWasChanged':
                if (is_string($payload['newNodeTypeName'] ?? null)) {
                    $this->nodeTypeNames[$node] = $payload['newNodeTypeName'];
                }
                break;
            case 'NodeAggregateNameWasChanged':
                $this->nodeNames[$node] = is_string($payload['newNodeName'] ?? null) ? $payload['newNodeName'] : null;
                break;
            case 'NodeAggregateWasMoved':
                if (is_string($payload['newParentNodeAggregateId'] ?? null)) {
                    $this->parents[$node] = $payload['newParentNodeAggregateId'];
                }
                break;
            case 'NodeSpecializationVariantWasCreated':
            case 'NodeGeneralizationVariantWasCreated':
            case 'NodePeerVariantWasCreated':
                // the variant is a copy of its source as it is then
                $source = self::coordinates($payload['sourceOrigin'] ?? null);
                $target = self::coordinates($payload['specializationOrigin'] ?? $payload['generalizationOrigin'] ?? $payload['peerOrigin'] ?? null);
                if ($source === null || $target === null) {
                    break;
                }
                $sourceHash = self::hash($source);
                $targetHash = self::hash($target);
                $this->properties[$node][$targetHash] = $this->properties[$node][$sourceHash] ?? [];
                $this->references[$node][$targetHash] = $this->references[$node][$sourceHash] ?? [];
                $this->created[$node][$targetHash] = $this->created[$node][$sourceHash] ?? false;
                $this->baseOrigins[$node][$targetHash] = $this->baseOrigins[$node][$sourceHash] ?? $source;
                break;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function propertiesSet(string $node, array $payload): EventDiff
    {
        $origin = self::coordinates($payload['originDimensionSpacePoint'] ?? null);
        if ($origin === null) {
            return EventDiff::none();
        }
        $values = self::propertiesSetBy($payload);
        ksort($values);
        $diffs = [];
        foreach ($values as $name => $value) {
            $old = $this->propertyBefore($node, $origin, $name);
            // decoded JSON values are identical exactly when their serialization is
            if ($old !== $value) {
                $diffs[] = new PropertyDiff($name, $this->nodeSerializer->serializedValue($old), $this->nodeSerializer->serializedValue($value));
            }
        }
        return new EventDiff(null, null, null, new PropertyDiffList(...$diffs), new ReferenceDiffList(), null, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nodeCreated(array $payload): EventDiff
    {
        $values = self::propertyValues($payload['initialPropertyValues'] ?? null);
        ksort($values);
        $properties = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $properties[] = new PropertyDiff($name, null, $this->nodeSerializer->serializedValue($value));
            }
        }
        $targets = self::referenceTargets($payload['nodeReferences'] ?? null);
        ksort($targets);
        $references = [];
        foreach ($targets as $name => $new) {
            $new = $this->readable($new);
            if ($new !== []) {
                $references[] = new ReferenceDiff($name, [], $new);
            }
        }
        return new EventDiff(null, null, null, new PropertyDiffList(...$properties), new ReferenceDiffList(...$references), null, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function referencesSet(string $node, array $payload): EventDiff
    {
        $origin = self::coordinates(((array)($payload['affectedSourceOriginDimensionSpacePoints'] ?? []))[0] ?? null);
        if ($origin === null) {
            return EventDiff::none();
        }
        $targets = self::referenceTargets($payload['references'] ?? null);
        ksort($targets);
        $diffs = [];
        foreach ($targets as $name => $new) {
            $old = $this->readable($this->referencesBefore($node, $origin, $name));
            $new = $this->readable($new);
            if ($old !== $new) {
                $diffs[] = new ReferenceDiff($name, $old, $new);
            }
        }
        return new EventDiff(null, null, null, new PropertyDiffList(), new ReferenceDiffList(...$diffs), null, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nodeTypeChanged(string $node, array $payload): EventDiff
    {
        $new = $payload['newNodeTypeName'] ?? null;
        $old = $this->nodeTypeNames[$node] ?? $this->baseNodeAggregate($node)?->nodeTypeName->value;
        if ($old === null) {
            $this->unknownBefore = true;
        }
        if (!is_string($new) || $old === null || $old === $new) {
            return EventDiff::none();
        }
        return new EventDiff(new NodeTypeDiff(NodeTypeName::fromString($old), NodeTypeName::fromString($new)), null, null, new PropertyDiffList(), new ReferenceDiffList(), null, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nodeNameChanged(string $node, array $payload): EventDiff
    {
        $new = is_string($payload['newNodeName'] ?? null) ? $payload['newNodeName'] : null;
        if (array_key_exists($node, $this->nodeNames)) {
            $old = $this->nodeNames[$node];
        } else {
            $baseNodeAggregate = $this->baseNodeAggregate($node);
            if ($baseNodeAggregate === null) {
                $this->unknownBefore = true;
                return EventDiff::none();
            }
            $old = $baseNodeAggregate->nodeName?->value;
        }
        if ($old === $new) {
            return EventDiff::none();
        }
        $diff = new NodeNameDiff($old !== null ? NodeName::fromString($old) : null, $new !== null ? NodeName::fromString($new) : null);
        return new EventDiff(null, $diff, null, new PropertyDiffList(), new ReferenceDiffList(), null, true);
    }

    /**
     * Only a move to another parent, see EventDiff::$parentAggregateId
     *
     * @param array<string, mixed> $payload
     */
    private function moved(string $node, array $payload): EventDiff
    {
        $new = $payload['newParentNodeAggregateId'] ?? null;
        if (!is_string($new)) {
            return EventDiff::none();
        }
        $old = $this->parents[$node] ?? null;
        if ($old === null) {
            $coverage = self::coordinates(((array)($payload['succeedingSiblingsForCoverage'] ?? []))[0]['dimensionSpacePoint'] ?? null);
            $old = $coverage !== null ? $this->baseSubgraph($coverage)?->findParentNode(SharedModel\Node\NodeAggregateId::fromString($node))?->aggregateId->value : null;
        }
        if ($old === null) {
            $this->unknownBefore = true;
        }
        if ($old === null || $old === $new || $this->readable([$old, $new]) !== [$old, $new]) {
            return EventDiff::none();
        }
        return new EventDiff(null, null, new NodeAggregateIdDiff(NodeAggregateId::fromString($old), NodeAggregateId::fromString($new)), new PropertyDiffList(), new ReferenceDiffList(), null, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function tagged(bool $tagged, array $payload): EventDiff
    {
        $tag = $payload['tag'] ?? null;
        if (!is_string($tag)) {
            return EventDiff::none();
        }
        return new EventDiff(null, null, null, new PropertyDiffList(), new ReferenceDiffList(), $tagged ? new TagDiff([$tag], []) : new TagDiff([], [$tag]), true);
    }

    /**
     * The plain value of the property before: set by an earlier event, else the base workspace's
     *
     * @param array<string, string> $origin
     */
    private function propertyBefore(string $node, array $origin, string $name): mixed
    {
        $hash = self::hash($origin);
        if (array_key_exists($name, $this->properties[$node][$hash] ?? [])) {
            return $this->properties[$node][$hash][$name];
        }
        if ($this->created[$node][$hash] ?? false) {
            return null;
        }
        $baseNode = $this->baseNode($node, $this->baseOrigins[$node][$hash] ?? $origin);
        if ($baseNode === null) {
            $this->unknownBefore = true;
            return null;
        }
        return $baseNode->properties->serialized()->getProperty($name)?->value;
    }

    /**
     * The referenced node aggregate ids before: set by an earlier event, else the base workspace's
     *
     * @param array<string, string> $origin
     * @return list<string>
     */
    private function referencesBefore(string $node, array $origin, string $name): array
    {
        $hash = self::hash($origin);
        if (array_key_exists($name, $this->references[$node][$hash] ?? [])) {
            return $this->references[$node][$hash][$name];
        }
        if ($this->created[$node][$hash] ?? false) {
            return [];
        }
        $baseSubgraph = $this->baseSubgraph($this->baseOrigins[$node][$hash] ?? $origin);
        if ($baseSubgraph?->findNodeById(SharedModel\Node\NodeAggregateId::fromString($node)) === null) {
            $this->unknownBefore = true;
            return [];
        }
        $targets = [];
        foreach ($baseSubgraph->findReferences(SharedModel\Node\NodeAggregateId::fromString($node), FindReferencesFilter::create(referenceName: $name)) as $reference) {
            $targets[] = $reference->node->aggregateId->value;
        }
        return $targets;
    }

    /**
     * @param array<string, string> $coordinates
     */
    private function baseNode(string $node, array $coordinates): Node|null
    {
        return $this->baseSubgraph($coordinates)?->findNodeById(SharedModel\Node\NodeAggregateId::fromString($node));
    }

    private function baseNodeAggregate(string $node): NodeAggregate|null
    {
        return $this->workspace->contentGraph(base: true)?->findNodeAggregateById(SharedModel\Node\NodeAggregateId::fromString($node));
    }

    /**
     * The base workspace in the dimension space point, null for none
     *
     * @param array<string, string> $coordinates
     */
    private function baseSubgraph(array $coordinates): ContentSubgraphInterface|null
    {
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);
        if (!array_key_exists($dimensionSpacePoint->hash, $this->baseSubgraphs)) {
            $this->baseSubgraphs[$dimensionSpacePoint->hash] = $this->workspace->contentGraph(base: true)?->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
        }
        return $this->baseSubgraphs[$dimensionSpacePoint->hash];
    }

    /**
     * The node aggregate ids the account may read, as EventQuery redacts payloads
     *
     * @param list<string> $nodeAggregateIds
     * @return list<string>
     */
    private function readable(array $nodeAggregateIds): array
    {
        return array_values(array_filter($nodeAggregateIds, function (string $nodeAggregateId): bool {
            try {
                return $this->workspace->mayReadNodeAggregate(SharedModel\Node\NodeAggregateId::fromString($nodeAggregateId));
            } catch (\InvalidArgumentException) {
                return false;
            }
        }));
    }

    /**
     * The plain values a NodePropertiesWereSet sets, null for the ones it unsets
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function propertiesSetBy(array $payload): array
    {
        $values = self::propertyValues($payload['propertyValues'] ?? null);
        foreach ((array)($payload['propertiesToUnset'] ?? []) as $name) {
            if (is_string($name)) {
                $values[$name] = null;
            }
        }
        return $values;
    }

    /**
     * The plain values of serialized property values ({"name": {"value": …, "type": "…"}})
     *
     * @return array<string, mixed>
     */
    private static function propertyValues(mixed $serialized): array
    {
        $values = [];
        foreach (is_array($serialized) ? $serialized : [] as $name => $value) {
            if (is_array($value) && array_key_exists('value', $value)) {
                $values[(string)$name] = $value['value'];
            }
        }
        return $values;
    }

    /**
     * The referenced node aggregate ids by name of serialized node references
     * ([{"referenceName": "…", "references": [{"target": "…", …}]}])
     *
     * @return array<string, list<string>>
     */
    private static function referenceTargets(mixed $serialized): array
    {
        $targets = [];
        foreach (is_array($serialized) ? $serialized : [] as $referencesForName) {
            $name = is_array($referencesForName) ? $referencesForName['referenceName'] ?? null : null;
            if (!is_string($name)) {
                continue;
            }
            $targets[$name] = [];
            foreach ((array)($referencesForName['references'] ?? []) as $reference) {
                if (is_array($reference) && is_string($reference['target'] ?? null)) {
                    $targets[$name][] = $reference['target'];
                }
            }
        }
        return $targets;
    }

    /**
     * The coordinates of a dimension space point in a payload, null if it isn't one
     *
     * @return array<string, string>|null
     */
    private static function coordinates(mixed $value): array|null
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $dimension => $dimensionValue) {
            if (!is_string($dimension) || !is_string($dimensionValue)) {
                return null;
            }
        }
        return $value;
    }

    /**
     * @param array<string, string> $coordinates
     */
    private static function hash(array $coordinates): string
    {
        return DimensionSpacePoint::fromArray($coordinates)->hash;
    }
}
