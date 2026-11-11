<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Response;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Domain\ChangeLocator;
use Neos\Api\Endpoint\Workspaces\Domain\ReadableWorkspace;
use Neos\Api\Endpoint\Workspaces\Schema\ConflictingChange;
use Neos\Api\Endpoint\Workspaces\Schema\ConflictingChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\DocumentAggregateIdList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceConflictProblem;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\SharedModel;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 409 for a publish, discard or rebase whose changes conflict, with the conflicting changes and the documents done
 * before the conflict
 */
final readonly class WorkspaceConflict implements ApiResponse
{
    private function __construct(
        private WorkspaceConflictProblem $problem,
    ) {
    }

    /**
     * The content repository's exception of a publish, discard or rebase: the workspace's changes conflict with its
     * base workspace (rebase) or the selected ones can't be separated from the others (partial). Several events about
     * one node are one conflict, the ones about nodes the account may not read are only counted, as the changes and
     * events leave them out
     *
     * @param ReadableWorkspace $workspace as it is now (refreshed() after documents were published or discarded)
     * @param list<SharedModel\Node\NodeAggregateId> $completedDocuments
     */
    public static function of(WorkspaceRebaseFailed|PartialWorkspaceRebaseFailed $exception, ReadableWorkspace $workspace, string $detail, array $completedDocuments = [], int $numberOfCompletedChanges = 0): self
    {
        $locator = new ChangeLocator($workspace);
        $changes = [];
        $inaccessible = [];
        foreach ($exception->conflictingEvents as $conflictingEvent) {
            $nodeAggregateId = $conflictingEvent->getAffectedNodeAggregateId();
            if ($nodeAggregateId !== null && !$workspace->mayReadNodeAggregate($nodeAggregateId)) {
                $inaccessible[$nodeAggregateId->value] = true;
                continue;
            }
            $changes[$nodeAggregateId->value ?? '#' . count($changes)] ??= ConflictingChange::from($conflictingEvent, $locator);
        }
        return new self(self::problem(
            $exception instanceof PartialWorkspaceRebaseFailed ? 'partial' : 'rebase',
            $detail,
            new ConflictingChangeList(...array_values($changes)),
            count($inaccessible),
            $completedDocuments,
            $numberOfCompletedChanges,
        ));
    }

    /**
     * A selected site or document was removed from the workspace after it was checked, maybe after other documents
     * were published or discarded already
     *
     * @param list<SharedModel\Node\NodeAggregateId> $completedDocuments
     */
    public static function removedMeanwhile(string $detail, array $completedDocuments, int $numberOfCompletedChanges): self
    {
        return new self(self::problem('removed', $detail, new ConflictingChangeList(), 0, $completedDocuments, $numberOfCompletedChanges));
    }

    /**
     * @param list<SharedModel\Node\NodeAggregateId> $completedDocuments
     */
    private static function problem(string $title, string $detail, ConflictingChangeList $conflicts, int $inaccessibleConflicts, array $completedDocuments, int $numberOfCompletedChanges): WorkspaceConflictProblem
    {
        return new WorkspaceConflictProblem(
            sprintf('https://www.rfc-editor.org/rfc/rfc9110#status.%d', self::statusCode()->value),
            $title,
            self::statusCode()->value,
            $detail,
            $conflicts,
            $inaccessibleConflicts,
            new DocumentAggregateIdList(...array_map(static fn (SharedModel\Node\NodeAggregateId $id) => NodeAggregateId::fromString($id->value), $completedDocuments)),
            $numberOfCompletedChanges,
        );
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(409);
    }

    public static function description(): string
    {
        return 'The request conflicts with the current state, or the workspace\'s changes conflict with its base workspace';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(WorkspaceConflictProblem::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return ProblemDocument::contentType();
    }

    public function body(): WorkspaceConflictProblem
    {
        return $this->problem;
    }
}
