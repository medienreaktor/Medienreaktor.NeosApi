<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of events
 *
 * @implements \IteratorAggregate<Event>
 */
final readonly class EventList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Event>
     */
    public array $events;

    public function __construct(Event ...$events)
    {
        $this->events = array_values($events);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->events;
    }
}
