<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceContainsPublishableChanges;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Neos\Domain\Model\WorkspaceMetadata;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Medienreaktor\NeosApi\Service\NodeAddressCodec;
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

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

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

        // Provision the acting user's personal workspace if it does not exist
        // yet. In classic Neos this happens on backend module load
        // (Neos.Neos.Ui BackendController); the Studio must not depend on a
        // user ever having opened the old UI, so the API creates it on demand.
        // The Studio selects the PERSONAL workspace to edit in and cannot
        // initialise without one. Idempotent: a no-op once the workspace exists.
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser !== null) {
            $this->workspaceService->createPersonalWorkspaceForUserIfMissing($this->getContentRepositoryId(), $currentUser);
        }

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
        $baseSubgraphs = [];
        $changes = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            // Resolve the containing document and site, so tree UIs can mark
            // documents whose content (not just the document itself) has
            // changes, and clients can scope publish/discard to one site.
            $documentNode = null;
            $siteNode = null;
            if ($change->originDimensionSpacePoint !== null) {
                $dimensionSpacePoint = $change->originDimensionSpacePoint->toDimensionSpacePoint();
                $subgraph = $subgraphs[$dimensionSpacePoint->hash] ??= $contentRepository->getContentSubgraph(
                    $workspace->workspaceName,
                    $dimensionSpacePoint
                );
                $documentNode = $subgraph->findClosestNode(
                    $change->nodeAggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
                );
                $siteNode = $subgraph->findClosestNode(
                    $change->nodeAggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Site')
                );
                // A node removed in this workspace is gone from its subgraph, so
                // the lookups above return null - but it still exists in the
                // base workspace. Resolve its document and site there so a
                // deletion is still attributed to a document and site;
                // otherwise a site-scoped client drops it from the change count
                // and the deletion looks like it never happened. (The change's
                // removal attachment point is not reliably populated, so we do
                // not depend on it.)
                if ($documentNode === null && $workspace->baseWorkspaceName !== null) {
                    $baseSubgraph = $baseSubgraphs[$dimensionSpacePoint->hash] ??= $contentRepository->getContentSubgraph(
                        $workspace->baseWorkspaceName,
                        $dimensionSpacePoint
                    );
                    $documentNode = $baseSubgraph->findClosestNode(
                        $change->nodeAggregateId,
                        FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
                    );
                    $siteNode ??= $baseSubgraph->findClosestNode(
                        $change->nodeAggregateId,
                        FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Site')
                    );
                }
            }
            $documentAggregateId = $documentNode?->aggregateId->value
                ?? $change->getLegacyRemovalAttachmentPoint()?->value;
            $siteAggregateId = $siteNode?->aggregateId->value;

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
            // A FAIL-strategy conflict surfaces as WorkspaceRebaseFailed, which
            // executeWorkspaceOperation turns into a 409 with the conflict list.
            $this->workspacePublishingService->rebaseWorkspace($this->getContentRepositoryId(), $workspace, $strategy);

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
        } catch (WorkspaceRebaseFailed $exception) {
            // Own changes collide with changes already published to the base
            // workspace. Retrying a rebase/publish with {"strategy":"force"}
            // drops the conflicting own changes; the client decides.
            $this->throwJsonStatus(409, 'rebase_conflicts', $exception->getMessage(), [
                'conflicts' => $this->serializeRebaseConflicts($workspace, $exception->conflictingEvents),
            ]);
        } catch (PartialWorkspaceRebaseFailed $exception) {
            // A scoped publish/discard whose selected changes cannot be
            // separated from the rest (e.g. a move that depends on a create not
            // in the selection). Not resolvable by force - the remedy is a
            // different scope or publishing everything.
            $this->throwJsonStatus(409, 'partial_publish_conflicts', $exception->getMessage(), [
                'conflicts' => $this->serializeRebaseConflicts($workspace, $exception->conflictingEvents),
            ]);
        } catch (StopActionException $exception) {
            // An operation already produced its own JSON status response.
            throw $exception;
        } catch (\Throwable $exception) {
            $this->throwJsonStatus(409, 'operation_failed', $exception->getMessage());
        }

        return $this->json(['workspace' => $workspace->value] + $result);
    }

    /**
     * Turn a rebase/publish conflict set into a client-consumable list: which
     * node conflicts, what kind of change was rejected, and why. Mirrors the
     * document/site fields of the changes resource so a UI can group conflicts
     * and navigate to them. Deduplicated per affected node.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeRebaseConflicts(WorkspaceName $workspaceName, ConflictingEvents $conflictingEvents): array
    {
        $contentRepository = $this->getContentRepository();
        $conflicts = [];
        $seen = [];
        foreach ($conflictingEvents as $conflictingEvent) {
            $nodeAggregateId = $conflictingEvent->getAffectedNodeAggregateId();
            $nodeId = $nodeAggregateId?->value;
            if ($nodeId !== null && isset($seen[$nodeId])) {
                continue;
            }
            if ($nodeId !== null) {
                $seen[$nodeId] = true;
            }

            $affectedNode = null;
            $documentNode = null;
            $siteAggregateId = null;
            if ($nodeAggregateId !== null) {
                // The node still exists in the (losing) workspace even when the
                // conflict is that the base deleted it, so its ancestors are
                // usually resolvable. Any covered dimension yields the same
                // document/site ids, so the first one is enough. Guarded: a
                // resolution failure must not turn the 409 into a 500.
                try {
                    $nodeAggregate = $contentRepository->getContentGraph($workspaceName)->findNodeAggregateById($nodeAggregateId);
                    foreach ($nodeAggregate?->coveredDimensionSpacePoints ?? [] as $dimensionSpacePoint) {
                        $subgraph = $contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint);
                        $affectedNode = $subgraph->findNodeById($nodeAggregateId);
                        $documentNode = $subgraph->findClosestNode(
                            $nodeAggregateId,
                            FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
                        );
                        $siteAggregateId = $subgraph->findClosestNode(
                            $nodeAggregateId,
                            FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Site')
                        )?->aggregateId->value;
                        break;
                    }
                } catch (\Throwable) {
                    // Leave node/document/site null - the id, type of change and
                    // reason are still enough for the client to act.
                }
            }

            $conflicts[] = [
                'nodeAggregateId' => $nodeId,
                // Human-readable labels + a navigable document address so the UI
                // can name the conflict and jump to the affected page.
                'nodeLabel' => $affectedNode !== null ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($affectedNode)) : null,
                'documentAggregateId' => $documentNode?->aggregateId->value,
                'documentLabel' => $documentNode !== null ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($documentNode)) : null,
                'documentAddress' => $documentNode !== null ? NodeAddressCodec::encode(NodeAddress::fromNode($documentNode)) : null,
                'siteAggregateId' => $siteAggregateId,
                'typeOfChange' => $this->conflictTypeOfChange($conflictingEvent->getEvent()),
                'reason' => $this->conflictReason($conflictingEvent->getException()),
                'message' => $conflictingEvent->getException()->getMessage(),
                'sequenceNumber' => $conflictingEvent->getSequenceNumber()->value,
            ];
        }

        return $conflicts;
    }

    /**
     * The kind of change a conflicting event represents, in the vocabulary of
     * the changes resource. Matched by event short name to avoid importing
     * every event class - the same approach core's own conflict serializer uses.
     */
    private function conflictTypeOfChange(EventInterface $event): ?string
    {
        return match ($this->shortClassName($event)) {
            'NodeAggregateWithNodeWasCreated', 'NodePeerVariantWasCreated', 'NodeGeneralizationVariantWasCreated' => 'created',
            'NodePropertiesWereSet', 'NodeReferencesWereSet', 'SubtreeWasTagged', 'SubtreeWasUntagged', 'NodeAggregateTypeWasChanged' => 'changed',
            'NodeAggregateWasMoved' => 'moved',
            'NodeAggregateWasRemoved' => 'deleted',
            default => null,
        };
    }

    /** A machine-readable reason code for a conflict, or null if unclassified. */
    private function conflictReason(\Throwable $exception): ?string
    {
        return match ($this->shortClassName($exception)) {
            'NodeAggregateCurrentlyDoesNotExist' => 'node_has_been_deleted',
            default => null,
        };
    }

    /**
     * The label generator may return HTML entities/tags; conflicts are shown as
     * plain text, so decode and strip - mirroring NodeSerializer.
     */
    private function plainTextLabel(string $label): string
    {
        return trim(strip_tags(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function shortClassName(object $object): string
    {
        $class = $object::class;
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
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
