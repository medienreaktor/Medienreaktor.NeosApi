<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What an event changed about its node compared to just before it: the values it set with the ones they replaced
 *
 * The value before is the one the latest earlier event of the content stream set, else the base workspace's: the
 * workspace's state then, not its current one. Events that change nothing of this (variants, removals) have an
 * empty diff
 */
final readonly class EventDiff implements ProvidesSchema
{
    /**
     * @param NodeTypeDiff|null $nodeType the node type if the event changed it (NodeAggregateTypeWasChanged), else null, also if the type before is unknown
     * @param NodeNameDiff|null $name the node name if the event changed it (NodeAggregateNameWasChanged), else null
     * @param NodeAggregateIdDiff|null $parentAggregateId the parent if the event moved the node to another one (NodeAggregateWasMoved), else null: a move among the siblings has the same parent, and a parent before that is unknown or one the account may not read is left out
     * @param PropertyDiffList $properties the properties the event set or unset whose values differ from before (NodePropertiesWereSet), or the ones a node was created with (NodeAggregateWithNodeWasCreated), sorted by name
     * @param ReferenceDiffList $references the references the event set whose referenced nodes differ from before (NodeReferencesWereSet), or the ones a node was created with, sorted by name, without the referenced nodes the account may not read
     * @param TagDiff|null $tags the tag the event set on the node (SubtreeWasTagged) or removed from it (SubtreeWasUntagged), removed included, else null
     */
    public function __construct(
        public NodeTypeDiff|null $nodeType,
        public NodeNameDiff|null $name,
        public NodeAggregateIdDiff|null $parentAggregateId,
        public PropertyDiffList $properties,
        public ReferenceDiffList $references,
        public TagDiff|null $tags,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, new PropertyDiffList(), new ReferenceDiffList(), null);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
