<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A property changed by a workspace compared to its base workspace (ChangeDiff), or by an event compared to before it
 * (EventDiff)
 *
 * The schema is written out: the values can be anything, which schematic can't discover
 */
final readonly class PropertyDiff implements ProvidesSchema
{
    /**
     * @param mixed $old the value before (in the base workspace, or before the event), serialized as in Node's properties, null if unset
     * @param mixed $new the value after (in the workspace, or set by the event), as $old
     */
    public function __construct(
        public string $name,
        public mixed $old,
        public mixed $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A property changed by a workspace compared to its base workspace (ChangeDiff), or by an event compared to before it (EventDiff)',
            properties: ObjectProperties::create(
                name: StringSchema::create(description: 'The name of the property', examples: ['title']),
                old: AnySchema::create(description: 'The value before: in the base workspace (ChangeDiff) or before the event (EventDiff), serialized as in the node\'s properties (a date as ISO 8601 string, assets as {"id": "…", "url": "…"}), null if it wasn\'t set or the node didn\'t exist'),
                new: AnySchema::create(description: 'The value after: in the workspace (ChangeDiff) or as the event set it (EventDiff), serialized as old, null if it is unset'),
            ),
            additionalProperties: false,
            required: ['name', 'old', 'new'],
        );
    }
}
