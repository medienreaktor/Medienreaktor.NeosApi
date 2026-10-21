<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Domain\ChangeLocator;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Event\NodeAggregateTypeWasChanged;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeGeneralizationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodePeerVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeSpecializationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvent;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A change of a workspace that can't be applied to its base workspace (any more), e.g. to a node removed there
 */
final readonly class ConflictingChange implements ProvidesSchema
{
    /**
     * @param NodeAggregateId|null $nodeAggregateId the node the change is about, null if it isn't about one
     * @param NodeAggregateId|null $documentAggregateId the document the node is in (or is), to group the conflicts, null if unknown
     * @param NodeAggregateId|null $siteAggregateId the site the node is in, null if unknown
     * @param string|null $reason why the change conflicts, as the Neos UI's ReasonForConflict: node_has_been_deleted if the node was removed in the base workspace (by someone else), else null
     * @param string|null $typeOfChange what the conflicting change of this workspace does, as the Neos UI's TypeOfChange: node_has_been_created, node_has_been_changed (its properties, references, tags or node type), node_has_been_moved or node_has_been_deleted (this workspace deletes it), null for another change. Not the same as reason: a change of a node deleted in the base workspace has reason node_has_been_deleted and e.g. typeOfChange node_has_been_changed
     * @param string $message what the content repository says about it
     */
    public function __construct(
        public NodeAggregateId|null $nodeAggregateId,
        public NodeAggregateId|null $documentAggregateId,
        public NodeAggregateId|null $siteAggregateId,
        public string|null $reason,
        public string|null $typeOfChange,
        public string $message,
    ) {
    }

    /**
     * The document and site are located as for the changes (ChangeLocator): in the workspace, where the node still
     * is, in any dimension space point it covers, without visibility constraints, so a conflict about a hidden or
     * removed node is still grouped
     */
    public static function from(ConflictingEvent $conflictingEvent, ChangeLocator $locator): self
    {
        $nodeAggregateId = $conflictingEvent->getAffectedNodeAggregateId();
        $location = $nodeAggregateId !== null ? $locator->locate($nodeAggregateId, null) : null;
        $exception = $conflictingEvent->getException();
        $shortName = substr(strrchr('\\' . $exception::class, '\\') ?: '', 1);
        return new self(
            $nodeAggregateId !== null ? NodeAggregateId::fromString($nodeAggregateId->value) : null,
            isset($location['document']) ? NodeAggregateId::fromString($location['document']->value) : null,
            isset($location['site']) ? NodeAggregateId::fromString($location['site']->value) : null,
            $shortName === 'NodeAggregateCurrentlyDoesNotExist' ? 'node_has_been_deleted' : null,
            // the event is internal to the content repository, the Neos UI reads it as well (ConflictsFactory)
            self::typeOfChange($conflictingEvent->getEvent()),
            $exception->getMessage(),
        );
    }

    /**
     * As the Neos UI's ConflictsFactory::createTypeOfChange(), a soft removal (tagged removed) is a deletion
     */
    private static function typeOfChange(EventInterface $event): ?string
    {
        return match (true) {
            $event instanceof SubtreeWasTagged && $event->tag->equals(NeosSubtreeTag::removed()),
            $event instanceof NodeAggregateWasRemoved => 'node_has_been_deleted',
            $event instanceof NodeAggregateWithNodeWasCreated,
            $event instanceof NodePeerVariantWasCreated,
            $event instanceof NodeGeneralizationVariantWasCreated,
            $event instanceof NodeSpecializationVariantWasCreated => 'node_has_been_created',
            $event instanceof NodePropertiesWereSet,
            $event instanceof NodeReferencesWereSet,
            $event instanceof SubtreeWasTagged,
            $event instanceof SubtreeWasUntagged,
            $event instanceof NodeAggregateTypeWasChanged => 'node_has_been_changed',
            $event instanceof NodeAggregateWasMoved => 'node_has_been_moved',
            default => null,
        };
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
