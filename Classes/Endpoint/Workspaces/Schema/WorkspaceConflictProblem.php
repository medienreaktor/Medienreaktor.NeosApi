<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An RFC 9457 problem document (as Shared\Response\Conflict's) with the conflicting changes and what was done before
 * the conflict as extension members
 */
final readonly class WorkspaceConflictProblem implements ProvidesSchema
{
    /**
     * @param string $type a URI identifying the problem type
     * @param string $title rebase (the workspace's changes conflict with its base workspace, rebasing with the force strategy drops the conflicting ones), partial (the selected changes can't be published or discarded without others they depend on) or removed (a selected site or document was removed from the workspace meanwhile)
     * @param int $status the HTTP status code, 409
     * @param string $detail a human-readable explanation
     * @param ConflictingChangeList $conflicts the conflicting changes of nodes the account may read
     * @param int $inaccessibleConflicts how many conflicting changes are about nodes the account may not read, left out of conflicts
     * @param DocumentAggregateIdList $completedDocumentAggregateIds the selected documents whose changes were published or discarded before the conflict, they stay so. Empty unless documentAggregateIds were selected
     * @param int $numberOfCompletedChanges how many changes of those documents were published or discarded, as Neos counts them
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public ConflictingChangeList $conflicts,
        public int $inaccessibleConflicts,
        public DocumentAggregateIdList $completedDocumentAggregateIds,
        public int $numberOfCompletedChanges,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
