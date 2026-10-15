<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\SubtreeTag;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The tags a workspace set on or removed from a node itself compared to its base workspace, or an event tagged or
 * untagged it with
 *
 * The schema is written out as Node's tags: lists of value objects that are plain strings here
 */
final readonly class TagDiff implements ProvidesSchema
{
    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The tags a workspace set on or removed from a node itself (as Node\'s tags, not the inherited ones) compared to its base workspace (ChangeDiff), or the tag an event set or removed (EventDiff)',
            properties: ObjectProperties::create(
                added: ArraySchema::create(
                    description: 'The tags the node has in the workspace but not in the base workspace, or the event set, sorted, e.g. disabled if it was hidden',
                    items: SubtreeTag::schema(),
                ),
                removed: ArraySchema::create(
                    description: 'The tags the node has in the base workspace but not in the workspace, or the event removed, sorted, e.g. disabled if it was shown again',
                    items: SubtreeTag::schema(),
                ),
            ),
            additionalProperties: false,
            required: ['added', 'removed'],
        );
    }
}
