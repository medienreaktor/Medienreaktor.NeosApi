<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\ContentStreamForking\Event\ContentStreamWasForked;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * The events of a content stream, read from the content repository's event store
 *
 * The content repository doesn't expose its event store, a service of it (built with ContentStreamEventsFactory) is
 * the only way to it. Both that and the stream name are @internal in Neos 9, so they stay in this class
 */
final readonly class ContentStreamEvents implements ContentRepositoryServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
    ) {
    }

    /**
     * The events of the content stream, oldest first (backwards() for newest first), with $eventTypes only the ones of
     * these types (the short names of the event classes), filtered by the event store
     *
     * @param list<string>|null $eventTypes
     */
    public function load(ContentStreamId $contentStreamId, array|null $eventTypes = null): EventStreamInterface
    {
        return $this->eventStore->load(
            ContentStreamEventStreamName::fromContentStreamId($contentStreamId)->getEventStreamName(),
            $eventTypes !== null ? EventStreamFilter::create(EventTypes::create(...array_map(EventType::fromString(...), $eventTypes))) : null,
        );
    }

    /**
     * The first event of the content stream, null for an empty one. Its sequence number is the lowest of the stream:
     * events of the workspace's earlier content streams all have lower ones
     */
    public function findFirst(ContentStreamId $contentStreamId): EventEnvelope|null
    {
        foreach ($this->load($contentStreamId)->limit(1) as $envelope) {
            return $envelope;
        }
        return null;
    }

    /**
     * Where the content stream branched off another one if its first event is the fork, else null (e.g. live's,
     * created empty)
     */
    public static function forkOf(EventEnvelope $firstEvent): ContentStreamWasForked|null
    {
        // the event store keeps the short class name as the type
        if ($firstEvent->event->type->value !== 'ContentStreamWasForked') {
            return null;
        }
        return ContentStreamWasForked::fromArray(json_decode($firstEvent->event->data->value, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * The event the content stream starts with if it was forked from another one, else null. Reads the first event
     * only
     */
    public function findFork(ContentStreamId $contentStreamId): ContentStreamWasForked|null
    {
        $firstEvent = $this->findFirst($contentStreamId);
        return $firstEvent !== null ? self::forkOf($firstEvent) : null;
    }
}
