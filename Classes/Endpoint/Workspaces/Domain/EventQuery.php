<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Nodes\Parameter\NodeAggregateIds;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Parameter\EventTypes;
use Neos\Api\Endpoint\Workspaces\Parameter\SubtreeTags;
use Neos\Api\Endpoint\Workspaces\Schema\Event;
use Neos\Api\Endpoint\Workspaces\Schema\EventDiff;
use Neos\Api\Endpoint\Workspaces\Schema\EventList;
use Neos\Api\Endpoint\Workspaces\Schema\EventListingMeta;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedEventListing;
use Neos\Api\Endpoint\Workspaces\Schema\SequenceNumber;
use Neos\Api\Infrastructure\Json\JsonValues;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Schema\CursorListingLinks;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The query of listWorkspaceEvents in the event store's terms: filters, sort, after and limit → a page of the content
 * stream's events the account may see
 *
 * The event store has no offset and no count, and the sequence numbers have gaps (other streams, pruning), so the
 * page starts after a sequence number. The Doctrine event store reads all events of a query at once, so they are read
 * in batches of limit + 1 until the page is full, events left out don't count. The event store filters by type
 * (filterByEventType, or the tag events for filterByTag), the other filters are applied here like the permissions,
 * in larger batches: a filtered page is full unless the content stream ends, and its links work as without filters.
 * What is included is read for the page only: the diffs by replaying the events before it (EventDiffer, from all the
 * content stream's events, not only the filtered ones), the nodes as for the changes (IncludedNodes)
 */
final readonly class EventQuery
{
    /**
     * What listWorkspaceEvents can include
     */
    private const array INCLUDE_PATHS = ['diff', ...IncludedNodes::PATHS];

    /**
     * The events with a tag in their payload, for filterByTag
     */
    private const array TAG_EVENT_TYPES = ['SubtreeWasTagged', 'SubtreeWasUntagged'];

    /**
     * How many events are read at once at least when filters are applied here, which may leave many out
     */
    private const int FILTERED_BATCH_SIZE = 500;

    private function __construct(
        private bool $newestFirst,
        private SequenceNumber|null $after,
        private Limit $limit,
        private IncludePaths $include,
        private EventTypes|null $eventTypes,
        private NodeAggregateIds|null $nodeAggregateIds,
        private NodeAggregateId|null $document,
        private SubtreeTags|null $tags,
    ) {
    }

    /**
     * The query, a 400 for a sort other than by sequenceNumber or an include it can't include
     */
    public static function create(
        SequenceNumber|null $after,
        Limit $limit,
        Sort|null $sort,
        IncludePaths $include,
        EventTypes|null $filterByEventType = null,
        NodeAggregateIds|null $filterByNodeAggregateId = null,
        NodeAggregateId|null $filterByDocument = null,
        SubtreeTags|null $filterByTag = null,
    ): self|BadRequest {
        $unsupported = $include->unsupported(self::INCLUDE_PATHS);
        if ($unsupported !== null) {
            return $unsupported;
        }
        $newestFirst = match ($sort?->value) {
            null, 'sequenceNumber' => false,
            '-sequenceNumber' => true,
            default => null,
        };
        if ($newestFirst === null) {
            return BadRequest::because(sprintf('Can\'t sort by %s, only by sequenceNumber or -sequenceNumber', $sort?->value));
        }
        return new self($newestFirst, $after, $limit, $include, $filterByEventType, $filterByNodeAggregateId, $filterByDocument, $filterByTag);
    }

    /**
     * The event types to read from the event store (short class names), null for all: filterByEventType, else the tag
     * events for filterByTag
     *
     * @return list<string>|null
     */
    public function eventTypes(): array|null
    {
        return $this->eventTypes?->types() ?? ($this->tags !== null ? self::TAG_EVENT_TYPES : null);
    }

    /**
     * What to include of the nodes, see IncludedNodes
     */
    public function include(): IncludePaths
    {
        return $this->include;
    }

    /**
     * Whether after is from an earlier content stream of the workspace: a new content stream's sequence numbers are
     * all higher than the old one's, so a cursor lower than the first event of the current one is from before the
     * workspace was published, discarded or rebased
     */
    public function isBefore(EventEnvelope|null $firstEvent): bool
    {
        return $this->after !== null && $firstEvent !== null && $this->after->value < $firstEvent->sequenceNumber->value;
    }

    /**
     * The page of the content stream's events, oldest first or with backwards() newest first. Events about a node the
     * account may not read are left out, so are the ones the filters don't match, the IDs of other nodes it may not
     * read in their payloads are null
     *
     * @param EventStreamInterface $contentStream the content stream's events, oldest first
     * @param EventStreamInterface|null $selected the ones of eventTypes() if not null, oldest first, else all of them
     */
    public function page(ServerRequestInterface $request, EventStreamInterface $contentStream, EventStreamInterface|null $selected, ReadableWorkspace $workspace, EventListingMeta $meta, IncludedNodes $includedNodes, EventDiffer $eventDiffer): PaginatedEventListing
    {
        $selected ??= $contentStream;
        $stream = $this->newestFirst ? $selected->backwards() : $selected;
        $filtered = $this->nodeAggregateIds !== null || $this->document !== null || $this->tags !== null;
        $batchSize = $filtered ? max($this->limit->value + 1, self::FILTERED_BATCH_SIZE) : $this->limit->value + 1;
        $locator = $this->document !== null ? new ChangeLocator($workspace) : null;
        /** @var list<array{EventEnvelope, array<mixed>|\stdClass}> $events with the decoded payloads */
        $events = [];
        $hasMore = false;
        // the next sequence number to read from in the listing's order; sequence numbers start at 1
        $next = $this->newestFirst ? ($this->after !== null ? $this->after->value - 1 : null) : ($this->after !== null ? $this->after->value + 1 : 1);
        do {
            if ($next === 0) {
                break;
            }
            $page = $next === null ? $stream : ($this->newestFirst
                ? $stream->withMaximumSequenceNumber(SequenceNumber::fromInteger($next)->toSequenceNumber())
                : $stream->withMinimumSequenceNumber(SequenceNumber::fromInteger($next)->toSequenceNumber()));
            $batch = iterator_to_array($page->limit($batchSize), false);
            foreach ($batch as $envelope) {
                $next = $envelope->sequenceNumber->value + ($this->newestFirst ? -1 : 1);
                // decoded once, for the check and the response
                $payload = JsonValues::decodeObject($envelope->event->data->value);
                $nodeAggregateId = is_array($payload) ? ($payload['nodeAggregateId'] ?? null) : null;
                if (!$this->matchesPayload($payload, $nodeAggregateId)) {
                    continue;
                }
                if (is_string($nodeAggregateId) && !$workspace->mayReadNodeAggregate(SharedModel\Node\NodeAggregateId::fromString($nodeAggregateId))) {
                    continue;
                }
                if ($locator !== null && !$this->isInDocument($locator, $payload, $nodeAggregateId)) {
                    continue;
                }
                // one more than the page, so there is a next one
                if (count($events) === $this->limit->value) {
                    $hasMore = true;
                    break 2;
                }
                $events[] = [$envelope, $payload];
            }
        } while (count($batch) === $batchSize);
        return $this->listing($request, $this->events($events, $contentStream, $workspace, $includedNodes, $eventDiffer), $meta, $hasMore);
    }

    /**
     * Whether the event matches filterByNodeAggregateId and filterByTag, which its payload tells
     *
     * @param array<mixed>|\stdClass $payload
     */
    private function matchesPayload(array|\stdClass $payload, mixed $nodeAggregateId): bool
    {
        if ($this->nodeAggregateIds !== null && (!is_string($nodeAggregateId) || !$this->nodeAggregateIds->contains($nodeAggregateId))) {
            return false;
        }
        if ($this->tags !== null) {
            $tag = is_array($payload) ? ($payload['tag'] ?? null) : null;
            return is_string($tag) && $this->tags->contains($tag);
        }
        return true;
    }

    /**
     * Whether the event's node is the document of filterByDocument or in it (not in a child document): its closest
     * document now, as for the changes, in the dimension space point the event is about
     *
     * @param array<mixed>|\stdClass $payload
     */
    private function isInDocument(ChangeLocator $locator, array|\stdClass $payload, mixed $nodeAggregateId): bool
    {
        if (!is_string($nodeAggregateId) || !is_array($payload)) {
            return false;
        }
        try {
            $id = SharedModel\Node\NodeAggregateId::fromString($nodeAggregateId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $dimensionSpacePoint = self::dimensionSpacePointOf($payload);
        $origin = $dimensionSpacePoint !== null ? OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint) : null;
        return $locator->locate($id, $origin)['document']?->value === $this->document?->value;
    }

    /**
     * The events of the page with their redacted payloads and what is included
     *
     * @param list<array{EventEnvelope, array<mixed>|\stdClass}> $events
     * @return list<Event>
     */
    private function events(array $events, EventStreamInterface $contentStream, ReadableWorkspace $workspace, IncludedNodes $includedNodes, EventDiffer $eventDiffer): array
    {
        $withDiff = $this->include->includes('diff');
        $diffs = $withDiff && $events !== [] ? self::diffs($events, $contentStream, $eventDiffer) : [];
        return array_map(static function (array $item) use ($workspace, $includedNodes, $withDiff, $diffs): Event {
            [$envelope, $payload] = $item;
            $event = Event::from($envelope, is_array($payload) ? self::redacted($payload, $workspace) : $payload);
            $nodeAggregateId = is_array($payload) && is_string($payload['nodeAggregateId'] ?? null) ? SharedModel\Node\NodeAggregateId::fromString($payload['nodeAggregateId']) : null;
            $dimensionSpacePoint = is_array($payload) ? self::dimensionSpacePointOf($payload) : null;
            return $event->withIncluded(
                $withDiff ? $diffs[$envelope->sequenceNumber->value] ?? EventDiff::none() : null,
                $nodeAggregateId !== null ? $includedNodes->nodeOf($nodeAggregateId, $dimensionSpacePoint) : null,
                $nodeAggregateId !== null ? $includedNodes->documentOf($nodeAggregateId, $dimensionSpacePoint) : null,
            );
        }, $events);
    }

    /**
     * The diffs of the events, by sequence number: the content stream replayed from EventDiffer::LOOKBACK events before
     * the first of them to the last
     *
     * @param non-empty-list<array{EventEnvelope, mixed}> $events
     * @return array<int, EventDiff>
     */
    private static function diffs(array $events, EventStreamInterface $contentStream, EventDiffer $eventDiffer): array
    {
        $sequenceNumbers = array_map(static fn (array $item): int => $item[0]->sequenceNumber->value, $events);
        $first = min($sequenceNumbers);
        $before = $first > 1
            ? array_reverse(iterator_to_array($contentStream->backwards()->withMaximumSequenceNumber(SequenceNumber::fromInteger($first - 1)->toSequenceNumber())->limit(EventDiffer::LOOKBACK), false))
            : [];
        $diffed = $contentStream
            ->withMinimumSequenceNumber(SequenceNumber::fromInteger($first)->toSequenceNumber())
            ->withMaximumSequenceNumber(SequenceNumber::fromInteger(max($sequenceNumbers))->toSequenceNumber());
        return $eventDiffer->diff([...$before, ...iterator_to_array($diffed, false)], array_fill_keys($sequenceNumbers, true));
    }

    /**
     * The dimension space point an event is about, as the event classes name it: the new variant's origin, the node's
     * origin, else the first one it affects. Null if it names none (e.g. a node type change, which is about all of them)
     *
     * @param array<mixed> $payload
     */
    private static function dimensionSpacePointOf(array $payload): DimensionSpacePoint|null
    {
        $candidates = [
            $payload['specializationOrigin'] ?? null,
            $payload['generalizationOrigin'] ?? null,
            $payload['peerOrigin'] ?? null,
            $payload['originDimensionSpacePoint'] ?? null,
            $payload['affectedSourceOriginDimensionSpacePoints'][0] ?? null,
            $payload['affectedDimensionSpacePoints'][0] ?? null,
            $payload['affectedCoveredDimensionSpacePoints'][0] ?? null,
            $payload['succeedingSiblingsForCoverage'][0]['dimensionSpacePoint'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            // JsonValues keeps an empty one (no dimensions) as {}
            $coordinates = $candidate instanceof \stdClass ? (array)$candidate : $candidate;
            if (is_array($coordinates)) {
                try {
                    return DimensionSpacePoint::fromArray($coordinates);
                } catch (\InvalidArgumentException) {
                    // not one after all
                }
            }
        }
        return null;
    }

    /**
     * The payload with the IDs of other nodes the event names (parent, siblings, reference targets, tethered
     * descendants, …: every string under a key ending in NodeAggregateId or NodeAggregateIds, at any depth) null if the
     * account may not read them, so an event about a readable node doesn't tell about unreadable ones
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function redacted(array $values, ReadableWorkspace $workspace, bool $nodeAggregateIds = false): array
    {
        foreach ($values as $key => $value) {
            $isIdKey = $nodeAggregateIds || (is_string($key) && preg_match('/nodeAggregateIds?$/i', $key) === 1);
            if (is_array($value)) {
                // a list or map of IDs (tetheredDescendantNodeAggregateIds) or objects that have some
                $values[$key] = self::redacted($value, $workspace, $isIdKey && is_string($key) && str_ends_with(strtolower($key), 'ids'));
            } elseif ($isIdKey && is_string($value) && !self::mayRead($value, $workspace)) {
                $values[$key] = null;
            }
        }
        return $values;
    }

    private static function mayRead(string $nodeAggregateId, ReadableWorkspace $workspace): bool
    {
        try {
            return $workspace->mayReadNodeAggregate(SharedModel\Node\NodeAggregateId::fromString($nodeAggregateId));
        } catch (\InvalidArgumentException) {
            // no node aggregate id after all
            return true;
        }
    }

    /**
     * Oldest first, the list grows at its end and is polled: next is always there. Newest first, it ends with the
     * oldest event
     *
     * @param list<Event> $events
     */
    private function listing(ServerRequestInterface $request, array $events, EventListingMeta $meta, bool $hasMore): PaginatedEventListing
    {
        $last = $events !== [] ? $events[array_key_last($events)]->sequenceNumber->value : null;
        return new PaginatedEventListing(
            new EventList(...$events),
            $meta,
            $this->newestFirst
                ? CursorListingLinks::for($request, $this->limit, $hasMore ? $last : null)
                : CursorListingLinks::forGrowingList($request, $this->limit, $last ?? $this->after?->value),
        );
    }
}
