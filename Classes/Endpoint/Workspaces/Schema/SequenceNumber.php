<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\EventStore\Model\Event;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Schematic;

final readonly class SequenceNumber implements ProvidesSchema
{
    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInteger(int $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public function toSequenceNumber(): Event\SequenceNumber
    {
        return Event\SequenceNumber::fromInteger($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= IntegerSchema::create(
            description: 'The position of an event in the event store of its content repository, over all streams. It only grows, but has gaps: other streams\' events are in between, and pruning removes events',
            examples: [1234],
            minimum: 1,
        );
    }
}
