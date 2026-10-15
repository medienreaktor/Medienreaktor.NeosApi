<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The event types of filterByEventType, comma-separated: the short names of the event classes, as an event's type
 */
final readonly class EventTypes implements ProvidesSchema
{
    private const string TYPE = '[A-Za-z][A-Za-z0-9]*';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * The types, each once
     *
     * @return non-empty-list<string>
     */
    public function types(): array
    {
        return array_values(array_unique(explode(',', $this->value)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Event types, comma-separated: the short names of the event classes, as an event\'s type. An event matches if it is of one of them',
            examples: ['SubtreeWasTagged,SubtreeWasUntagged'],
            pattern: '^' . self::TYPE . '(,' . self::TYPE . ')*$',
        );
    }
}
