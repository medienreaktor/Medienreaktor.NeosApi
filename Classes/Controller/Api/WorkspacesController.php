<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceContainsPublishableChanges;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Neos\Domain\Model\WorkspaceMetadata;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushWorkspaceRequest;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * Workspaces as read resources plus the use-case-level write operations
 * (publish, discard, rebase) on top of the raw command layer.
 *
 * Authorization: listing only reveals workspaces the account may read;
 * publish/discard/rebase are re-checked by the workspace permission model
 * inside the publishing service / content repository.
 */
class WorkspacesController extends AbstractApiController
{
    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected WorkspacePublishingService $workspacePublishingService;

    #[Flow\Inject]
    protected ContentRepositoryAuthorizationService $authorizationService;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected ContentCacheFlusher $contentCacheFlusher;

    /**
     * Base workspaces recur across the list (usually all point to live), so
     * remember their write permission for the duration of the request.
     *
     * @var array<string, bool>
     */
    private array $writePermissionCache = [];

    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $workspaces = [];
        foreach ($this->getContentRepository()->findWorkspaces() as $workspace) {
            $serialized = $this->serializeWorkspace($workspace);
            if ($serialized !== null) {
                $workspaces[] = $serialized;
            }
        }

        return $this->json(['workspaces' => $workspaces]);
    }

    public function showAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        $serialized = $this->serializeWorkspace($workspace);
        if ($serialized === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }
        $serialized['pendingChanges'] = $this->workspacePublishingService->countPendingWorkspaceChanges(
            $this->getContentRepositoryId(),
            $workspace->workspaceName
        );

        return $this->json($serialized);
    }

    /**
     * The pending changes of a workspace relative to its base - which node
     * aggregates were created/changed/moved/deleted. This is what tree UIs
     * need to mark nodes as "dirty".
     *
     * Uses the PendingChangesProjection's ChangeFinder, which is @internal
     * in Neos - the same dependency core's Workspace.Ui module has; revisit
     * when neos/neos-development-collection#5493 lands a public API.
     */
    public function changesAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }

        $contentRepository = $this->getContentRepository();
        $changeFinder = $contentRepository->projectionState(ChangeFinder::class);
        $subgraphs = [];
        $changes = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            // Resolve the containing document and site, so tree UIs can mark
            // documents whose content (not just the document itself) has
            // changes, and clients can scope publish/discard to one site.
            $documentAggregateId = null;
            $siteAggregateId = null;
            if ($change->originDimensionSpacePoint !== null) {
                $dimensionSpacePoint = $change->originDimensionSpacePoint->toDimensionSpacePoint();
                $subgraph = $subgraphs[$dimensionSpacePoint->hash] ??= $contentRepository->getContentSubgraph(
                    $workspace->workspaceName,
                    $dimensionSpacePoint
                );
                $documentAggregateId = $subgraph->findClosestNode(
                    $change->nodeAggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
                )?->aggregateId->value;
                $siteAggregateId = $subgraph->findClosestNode(
                    $change->nodeAggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Site')
                )?->aggregateId->value;
            }
            // Deleted nodes no longer exist in the workspace subgraph; the
            // change record remembers the closest document at removal time.
            // The site cannot be resolved for them, so siteAggregateId stays
            // null - a client-side count nuance only; the server resolves the
            // actual node set itself when a site-scoped publish/discard runs.
            $documentAggregateId ??= $change->getLegacyRemovalAttachmentPoint()?->value;

            $changes[] = [
                'nodeAggregateId' => $change->nodeAggregateId->value,
                'documentAggregateId' => $documentAggregateId,
                'siteAggregateId' => $siteAggregateId,
                'originDimensionSpacePoint' => $change->originDimensionSpacePoint?->coordinates,
                'created' => $change->created,
                'changed' => $change->changed,
                'moved' => $change->moved,
                'deleted' => $change->deleted,
            ];
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            // UP_TO_DATE or OUTDATED - piggybacked on the changes resource
            // because clients poll it anyway, so "base has moved on" surfaces
            // without an extra request.
            'status' => $workspace->status->value,
            'changes' => $changes,
        ]);
    }

    #[Flow\SkipCsrfProtection]
    public function publishAction(string $workspaceName): string
    {
        $this->requireScope('neos.publish');

        return $this->executeWorkspaceOperation($workspaceName, function (WorkspaceName $workspace): array {
            $filter = $this->getOperationFilter();
            if (isset($filter['site'])) {
                $result = $this->workspacePublishingService->publishChangesInSite($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($filter['site']));
            } elseif (isset($filter['document'])) {
                $result = $this->workspacePublishingService->publishChangesInDocument($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($filter['document']));
            } else {
                $result = $this->workspacePublishingService->publishWorkspace($this->getContentRepositoryId(), $workspace);
            }

            return ['publishedChanges' => $result->numberOfPublishedChanges];
        });
    }

    #[Flow\SkipCsrfProtection]
    public function discardAction(string $workspaceName): string
    {
        $this->requireScope('neos.publish');

        return $this->executeWorkspaceOperation($workspaceName, function (WorkspaceName $workspace): array {
            $filter = $this->getOperationFilter();
            if (isset($filter['site'])) {
                $result = $this->workspacePublishingService->discardChangesInSite($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($filter['site']));
            } elseif (isset($filter['document'])) {
                $result = $this->workspacePublishingService->discardChangesInDocument($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($filter['document']));
            } else {
                $result = $this->workspacePublishingService->discardAllWorkspaceChanges($this->getContentRepositoryId(), $workspace);
            }

            return ['discardedChanges' => $result->numberOfDiscardedChanges];
        });
    }

    #[Flow\SkipCsrfProtection]
    public function rebaseAction(string $workspaceName): string
    {
        $this->requireScope('neos.publish');

        return $this->executeWorkspaceOperation($workspaceName, function (WorkspaceName $workspace): array {
            $filter = $this->getOperationFilter();
            $strategy = ($filter['strategy'] ?? '') === 'force'
                ? RebaseErrorHandlingStrategy::STRATEGY_FORCE
                : RebaseErrorHandlingStrategy::STRATEGY_FAIL;
            try {
                $this->workspacePublishingService->rebaseWorkspace($this->getContentRepositoryId(), $workspace, $strategy);
            } catch (WorkspaceRebaseFailed) {
                $this->throwJsonStatus(409, 'rebase_conflicts', 'Some changes in the workspace conflict with changes published to the base workspace. Retry with {"strategy": "force"} to drop the conflicting changes.');
            }

            return ['rebased' => true];
        });
    }

    /**
     * Rebase the workspace onto a different base workspace. This is what
     * "switching the workspace" means in the classic UI: editing always
     * happens in the personal workspace, this operation only retargets where
     * its changes will be published. The content repository enforces manage
     * permission on the workspace, read permission on the new base, and that
     * the workspace has no publishable changes (surfaced as 409
     * workspace_not_empty so clients can prompt to publish/discard first).
     */
    #[Flow\SkipCsrfProtection]
    public function changeBaseWorkspaceAction(string $workspaceName): string
    {
        $this->requireScope('neos.publish');

        $baseWorkspaceName = $this->getOperationFilter()['baseWorkspace'] ?? null;
        if ($baseWorkspaceName === null) {
            $this->throwJsonStatus(400, 'missing_base_workspace', 'The request body must contain a "baseWorkspace".');
        }

        $workspace = WorkspaceName::fromString($workspaceName);
        try {
            $this->workspacePublishingService->changeBaseWorkspace(
                $this->getContentRepositoryId(),
                $workspace,
                WorkspaceName::fromString($baseWorkspaceName)
            );
        } catch (AccessDenied $exception) {
            $this->throwJsonStatus(403, 'access_denied', $exception->getMessage());
        } catch (WorkspaceContainsPublishableChanges) {
            $this->throwJsonStatus(409, 'workspace_not_empty', 'The workspace still has publishable changes; publish or discard them before changing the base workspace.');
        } catch (\Throwable $exception) {
            $this->throwJsonStatus(409, 'operation_failed', $exception->getMessage());
        }

        // The workspace now renders the new base's content, but nothing
        // flushes the Fusion content cache on WorkspaceBaseWorkspaceWasChanged
        // (core's cache-flush hook only covers discard and rebase events), so
        // fragments rendered against the old base would keep being served.
        $this->contentCacheFlusher->flushWorkspace(
            FlushWorkspaceRequest::create($this->getContentRepositoryId(), $workspace),
            CacheFlushingStrategy::IMMEDIATE
        );

        return $this->json(['workspace' => $workspace->value, 'baseWorkspace' => $baseWorkspaceName]);
    }

    /**
     * @param \Closure(WorkspaceName): array<string, mixed> $operation
     */
    private function executeWorkspaceOperation(string $workspaceName, \Closure $operation): string
    {
        $workspace = WorkspaceName::fromString($workspaceName);
        try {
            $result = $operation($workspace);
        } catch (AccessDenied $exception) {
            $this->throwJsonStatus(403, 'access_denied', $exception->getMessage());
        } catch (StopActionException $exception) {
            // An operation already produced its own JSON status response.
            throw $exception;
        } catch (\Throwable $exception) {
            $this->throwJsonStatus(409, 'operation_failed', $exception->getMessage());
        }

        return $this->json(['workspace' => $workspace->value] + $result);
    }

    /**
     * @return array<string, string>
     */
    private function getOperationFilter(): array
    {
        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);

        return is_array($body) ? array_filter($body, 'is_string') : [];
    }

    private function canWriteToWorkspace(WorkspaceName $workspaceName): bool
    {
        return $this->writePermissionCache[$workspaceName->value] ??= $this->authorizationService->getWorkspacePermissions(
            $this->getContentRepositoryId(),
            $workspaceName,
            $this->securityContext->getRoles(),
            $this->userService->getCurrentUser()?->getId()
        )->write;
    }

    /**
     * @return array<string, mixed>|null null if the account may not read the workspace
     */
    private function serializeWorkspace(Workspace $workspace): ?array
    {
        $permissions = $this->authorizationService->getWorkspacePermissions(
            $this->getContentRepositoryId(),
            $workspace->workspaceName,
            $this->securityContext->getRoles(),
            $this->userService->getCurrentUser()?->getId()
        );
        if (!$permissions->read) {
            return null;
        }

        $metadata = $this->workspaceService->getWorkspaceMetadata($this->getContentRepositoryId(), $workspace->workspaceName);

        return [
            'name' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'title' => $metadata->title->value,
            'description' => $metadata->description->value,
            'classification' => $metadata->classification->value,
            'owner' => $metadata->ownerUserId?->value,
            'hasPublishableChanges' => $workspace->hasPublishableChanges(),
            'status' => $workspace->status->value,
            'permissions' => [
                'read' => $permissions->read,
                'write' => $permissions->write,
                'manage' => $permissions->manage,
                // Publishing means writing to the base workspace - the same
                // check the content repository applies to PublishWorkspace.
                // false for root workspaces (there is nothing to publish to).
                'publish' => $workspace->baseWorkspaceName !== null
                    && $this->canWriteToWorkspace($workspace->baseWorkspaceName),
            ],
        ];
    }
}
