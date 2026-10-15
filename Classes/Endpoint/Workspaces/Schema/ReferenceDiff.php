<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A reference changed by a workspace compared to its base workspace, or by an event compared to before it: the
 * referenced nodes
 *
 * The schema is written out as Node's tags: lists of value objects that are plain strings here
 */
final readonly class ReferenceDiff implements ProvidesSchema
{
    /**
     * @param list<string> $old the referenced node aggregate ids before (in the base workspace, or before the event), in their order
     * @param list<string> $new the ones after (in the workspace, or as the event set them), in their order
     */
    public function __construct(
        public string $name,
        public array $old,
        public array $new,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A reference changed by a workspace compared to its base workspace (ChangeDiff), or by an event compared to before it (EventDiff)',
            properties: ObjectProperties::create(
                name: StringSchema::create(description: 'The name of the reference', examples: ['relatedPages']),
                old: ArraySchema::create(
                    description: 'The referenced nodes before: in the base workspace (ChangeDiff) or before the event (EventDiff), in their order, empty if none or the node didn\'t exist',
                    items: NodeAggregateId::schema(),
                ),
                new: ArraySchema::create(
                    description: 'The referenced nodes after: in the workspace (ChangeDiff) or as the event set them (EventDiff), in their order, empty if none',
                    items: NodeAggregateId::schema(),
                ),
            ),
            additionalProperties: false,
            required: ['name', 'old', 'new'],
        );
    }
}
