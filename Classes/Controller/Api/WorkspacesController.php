<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
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
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Model\WorkspaceClassification;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceRoleSubject;
use Neos\Neos\Domain\Model\WorkspaceRoleSubjectType;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Medienreaktor\NeosApi\Service\EventBeforeWindow;
use Medienreaktor\NeosApi\Service\NodeAddressCodec;
use Medienreaktor\NeosApi\Service\NodeSerializer;
use Medienreaktor\NeosApi\Service\WorkspaceDiffService;
use Medienreaktor\NeosApi\Service\WorkspaceEventFeed;
use Medienreaktor\NeosApi\Service\WorkspaceEventFeedFactory;
use Medienreaktor\NeosApi\Service\WorkspaceHistoryService;
use Medienreaktor\NeosApi\Service\WorkspaceReadContext;
use Medienreaktor\NeosApi\Service\WorkspaceSerializer;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Cache\CacheManager;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushWorkspaceRequest;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * Workspaces as read resources plus the use-case-level write operations
 * (publish, discard, rebase) on top of the raw command layer.
 *
 * Authorization: listing only reveals workspaces the account may read;
 * publish/discard/rebase are re-checked by the workspace permission model
 * inside the publishing service / content repository.
 *
 * The heavy lifting lives in services: WorkspaceSerializer (permissions +
 * JSON shape), WorkspaceHistoryService (event feed enrichment),
 * WorkspaceDiffService (before/after change rows) - all reading through a
 * per-request WorkspaceReadContext that caches subgraphs, ancestor lookups
 * and node-type configuration across the request's fan-out.
 */
class WorkspacesController extends AbstractApiController
{
    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected WorkspacePublishingService $workspacePublishingService;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected ContentCacheFlusher $contentCacheFlusher;

    #[Flow\Inject]
    protected CacheManager $cacheManager;

    #[Flow\Inject]
    protected NodeSerializer $nodeSerializer;

    #[Flow\Inject]
    protected WorkspaceSerializer $workspaceSerializer;

    #[Flow\Inject]
    protected WorkspaceHistoryService $workspaceHistoryService;

    #[Flow\Inject]
    protected WorkspaceDiffService $workspaceDiffService;

    /** Seconds a presence heartbeat stays valid; clients beat every ~5s. */
    private const PRESENCE_LIFETIME = 30;

    /** Page size of the event feed; a full page makes clients fully refresh. */
    private const EVENT_FEED_LIMIT = 200;

    /** Newest events the pending-history resource returns per workspace. */
    private const PENDING_EVENTS_LIMIT = 100;

    /** Widest sequence-number slice one pending-events diff may cover. */
    private const DIFF_RANGE_LIMIT = 200;

    /** Events the before-value scan walks back through, at most. Beyond this
     * window the diff falls back to the base workspace's current value. */
    private const DIFF_SCAN_LIMIT = 500;

    /** The request body, parsed once (several helpers read it). */
    private ?array $parsedRequestBody = null;

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
            $serialized = $this->workspaceSerializer->serialize($this->getContentRepositoryId(), $workspace);
            if ($serialized !== null) {
                $workspaces[] = $serialized;
            }
        }

        return $this->json(['workspaces' => $workspaces]);
    }

    public function showAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);
        $serialized = $this->workspaceSerializer->serialize($this->getContentRepositoryId(), $workspace);
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

        $workspace = $this->requireReadableWorkspace($workspaceName);
        $context = $this->readContext($workspace);
        $changeFinder = $context->contentRepository->projectionState(ChangeFinder::class);

        $changes = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            // Resolve the containing document and site, so tree UIs can mark
            // documents whose content (not just the document itself) has
            // changes, and clients can scope publish/discard to one site.
            // (The change's removal attachment point is not reliably
            // populated, so the resolution does not depend on it.)
            $documentNode = null;
            $siteNode = null;
            if ($change->originDimensionSpacePoint !== null) {
                $resolved = $context->closestDocumentAndSite(
                    $change->nodeAggregateId,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint()
                );
                $documentNode = $resolved['document'];
                $siteNode = $resolved['site'];
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

    /**
     * The pending changes of a workspace grouped by their containing document,
     * enriched for a human-facing review UI: each entry carries the document's
     * label, icon, breadcrumb, the kinds of change it contains, and how many
     * pending changes it groups. This is what the Studio's "Review changes"
     * dialog lists and lets an editor publish or discard per document.
     *
     * Granularity is deliberately the document: publish/discard scope to a
     * document ({"documents": [...]}), which is the unit the content repository
     * can separate cleanly - arbitrary per-node selections routinely collide
     * with their own dependencies (partial publish conflicts). Mirrors the
     * document grouping of the classic Workspace module's review table.
     */
    public function documentChangesAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);
        $context = $this->readContext($workspace);
        $changeFinder = $context->contentRepository->projectionState(ChangeFinder::class);

        // Accumulated per document aggregate id, in first-seen order.
        $documents = [];
        // The distinct changed nodes per document: the projection records one
        // row per node AND dimension (a move fans out per covered dimension
        // space point), but the review UI shows no dimensions - counting rows
        // would show phantom changes no user action explains.
        $countedNodeIds = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            $documentNode = null;
            $siteNode = null;
            $documentSubgraph = null;
            if ($change->originDimensionSpacePoint !== null) {
                $dimensionSpacePoint = $change->originDimensionSpacePoint->toDimensionSpacePoint();
                // Shared document/site resolution incl. the base-workspace
                // fallback for removed nodes: without it a deleted page would
                // never appear in the review list. A base-resolved document is
                // display-only (label/icon/breadcrumb) - not navigable, hence
                // no subgraph (and a null address below).
                $resolved = $context->closestDocumentAndSite($change->nodeAggregateId, $dimensionSpacePoint);
                $documentNode = $resolved['document'];
                $siteNode = $resolved['site'];
                if ($documentNode !== null && $resolved['inWorkspace']) {
                    $documentSubgraph = $context->subgraph($dimensionSpacePoint);
                }
            }

            $documentId = $documentNode?->aggregateId->value
                ?? $change->getLegacyRemovalAttachmentPoint()?->value;
            // A change we cannot attribute to any document (no origin, no
            // removal attachment point) - skip rather than invent a bucket.
            if ($documentId === null) {
                continue;
            }

            if (!isset($documents[$documentId])) {
                $documents[$documentId] = [
                    'documentAggregateId' => $documentId,
                    // Only workspace-resolved documents are navigable; a deleted
                    // (base-resolved) one gets a null address.
                    'documentAddress' => $documentSubgraph !== null && $documentNode !== null
                        ? NodeAddressCodec::encode(NodeAddress::fromNode($documentNode))
                        : null,
                    'siteAggregateId' => $siteNode?->aggregateId->value,
                    'siteLabel' => $siteNode !== null ? $context->label($siteNode) : null,
                    'label' => $documentNode !== null ? $context->label($documentNode) : $documentId,
                    'nodeType' => $documentNode?->nodeTypeName->value,
                    'icon' => $documentNode !== null ? $context->icon($documentNode->nodeTypeName) : null,
                    'breadcrumb' => $documentNode !== null && $documentSubgraph !== null
                        ? $this->nodeSerializer->breadcrumb($documentNode, $documentSubgraph)
                        : [],
                    'hidden' => $documentNode?->tags->contain(NeosSubtreeTag::disabled()) ?? false,
                    'created' => false,
                    'changed' => false,
                    'moved' => false,
                    'deleted' => false,
                    'changeCount' => 0,
                ];
            }

            if (!isset($countedNodeIds[$documentId][$change->nodeAggregateId->value])) {
                $countedNodeIds[$documentId][$change->nodeAggregateId->value] = true;
                $documents[$documentId]['changeCount']++;
            }
            // The document node's own change gives the page-level verbs; any
            // change on a descendant is content that changed within the page.
            if ($documentNode !== null && $change->nodeAggregateId->equals($documentNode->aggregateId)) {
                $documents[$documentId]['created'] = $change->created;
                $documents[$documentId]['moved'] = $change->moved;
                $documents[$documentId]['deleted'] = $change->deleted;
                if ($change->changed) {
                    $documents[$documentId]['changed'] = true;
                }
            } else {
                $documents[$documentId]['changed'] = true;
            }
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'status' => $workspace->status->value,
            'documents' => array_values($documents),
        ]);
    }

    /**
     * The NET difference one document's changed nodes carry against the base
     * workspace - what publishing this document would actually apply. Unlike
     * the pending-events diff (which walks the event history step by step),
     * this compares STATE: each changed node's current properties, references,
     * type, name, parent and visibility against the base workspace's version.
     * Five edits of the same text arrive squashed into one old -> new row by
     * construction, and no event-history cap applies.
     *
     * Change rows come from the pending-changes projection (the same source
     * the document-changes listing counts), deduplicated per node VARIANT:
     * the projection fans rows out per covered dimension, but rows resolving
     * to the same origin variant describe one and the same difference.
     */
    public function documentDiffAction(string $workspaceName, string $documentAggregateId): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);
        $context = $this->readContext($workspace);
        $changeFinder = $context->contentRepository->projectionState(ChangeFinder::class);

        $seenVariants = [];
        $nodes = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            if ($change->originDimensionSpacePoint === null) {
                continue;
            }
            $nodeId = $change->nodeAggregateId;
            $dimensionSpacePoint = $change->originDimensionSpacePoint->toDimensionSpacePoint();
            $subgraph = $context->subgraph($dimensionSpacePoint);
            $baseSubgraph = $context->baseSubgraph($dimensionSpacePoint);

            $wsNode = $subgraph->findNodeById($nodeId);
            $baseNode = $baseSubgraph?->findNodeById($nodeId);
            if ($wsNode === null && $baseNode === null) {
                continue;
            }

            // Belongs to the requested document? Removed nodes resolve their
            // document in the base (same fallback the listing uses).
            $documentNode = $wsNode !== null
                ? $context->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Document')
                : $context->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Document', inBase: true);
            $documentId = $documentNode?->aggregateId->value
                ?? $change->getLegacyRemovalAttachmentPoint()?->value;
            if ($documentId !== $documentAggregateId) {
                continue;
            }

            // Rows fanned out over covered dimensions resolve to the same
            // origin variant - one difference, listed once.
            $node = $wsNode ?? $baseNode;
            $variantKey = $nodeId->value . '|' . $node->originDimensionSpacePoint->hash;
            if (isset($seenVariants[$variantKey])) {
                continue;
            }
            $seenVariants[$variantKey] = true;

            $status = $change->deleted
                ? 'removed'
                : ($change->created ? 'created' : ($change->moved ? 'moved' : 'changed'));
            $changes = $this->workspaceDiffService->diffNodeAgainstBase($wsNode, $baseNode, $change->moved, $subgraph, $baseSubgraph, $context);

            // A "changed" node whose state does not differ visibly (e.g.
            // edited and manually reverted) would render as an empty block.
            if ($changes === [] && $status === 'changed') {
                continue;
            }

            $nodes[] = [
                'nodeAggregateId' => $nodeId->value,
                'dimensions' => $node->originDimensionSpacePoint->coordinates,
                'status' => $status,
                'nodeLabel' => $context->label($node),
                'nodeType' => $node->nodeTypeName->value,
                'icon' => $context->icon($node->nodeTypeName),
                'changes' => $changes,
            ];
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'documentAggregateId' => $documentAggregateId,
            'nodes' => $nodes,
        ]);
    }

    #[Flow\SkipCsrfProtection]
    public function publishAction(string $workspaceName): string
    {
        $this->requireScope('neos.publish');

        return $this->executeWorkspaceOperation($workspaceName, function (WorkspaceName $workspace): array {
            $filter = $this->getOperationFilter();
            $documents = $this->getOperationDocuments();
            if ($documents !== []) {
                // A selection of documents from the review dialog. Published one
                // by one - the content repository has no multi-document publish
                // - so a conflict on the Nth document leaves the earlier ones
                // published (the same behaviour as the classic review module).
                $publishedChanges = 0;
                foreach ($documents as $documentId) {
                    $publishedChanges += $this->workspacePublishingService->publishChangesInDocument($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($documentId))->numberOfPublishedChanges;
                }
                return ['publishedChanges' => $publishedChanges];
            }
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
            $documents = $this->getOperationDocuments();
            if ($documents !== []) {
                // A selection of documents from the review dialog, discarded one
                // by one (see publishAction for the per-document rationale).
                $discardedChanges = 0;
                foreach ($documents as $documentId) {
                    $discardedChanges += $this->workspacePublishingService->discardChangesInDocument($this->getContentRepositoryId(), $workspace, NodeAggregateId::fromString($documentId))->numberOfDiscardedChanges;
                }
                return ['discardedChanges' => $discardedChanges];
            }
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
        } catch (\Exception $exception) {
            // \Exception, not \Throwable: an \Error is a server bug and must
            // surface as a logged 500.
            $this->logger->warning(sprintf('Changing the base workspace of "%s" failed: %s', $workspaceName, $exception->getMessage()), ['exception' => $exception]);
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
     * Create a shared or private workspace - the Studio's replacement for the
     * classic Workspaces module's create. JSON body: title, description?,
     * baseWorkspaceName? (default "live"), visibility? ("shared": every
     * editor may collaborate, the creator manages; "private": only the
     * creator). The workspace name is derived from the title.
     */
    #[Flow\SkipCsrfProtection]
    public function createAction(
        string $title,
        ?string $description = null,
        ?string $baseWorkspaceName = null,
        string $visibility = 'shared'
    ): string {
        $this->requireScope('neos.write');

        if (trim($title) === '') {
            $this->throwJsonStatus(400, 'invalid_title', 'The title must not be empty.');
        }
        if (!in_array($visibility, ['shared', 'private'], true)) {
            $this->throwJsonStatus(400, 'invalid_visibility', 'The visibility must be "shared" or "private".');
        }
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            $this->throwJsonStatus(404, 'no_user', 'The authenticated account is not associated with a Neos user.');
        }

        $base = WorkspaceName::fromString($baseWorkspaceName ?? WorkspaceName::WORKSPACE_NAME_LIVE);
        $baseWorkspace = $this->getContentRepository()->findWorkspaceByName($base);
        if ($baseWorkspace === null || !$this->workspaceSerializer->canRead($this->getContentRepositoryId(), $base)) {
            $this->throwJsonStatus(400, 'invalid_base_workspace', sprintf('The base workspace "%s" does not exist or is not accessible.', $base->value));
        }

        $workspaceName = $this->workspaceService->getUniqueWorkspaceName($this->getContentRepositoryId(), trim($title));
        $assignments = $visibility === 'shared'
            ? WorkspaceRoleAssignments::createForSharedWorkspace($currentUser->getId())
            : WorkspaceRoleAssignments::createForPrivateWorkspace($currentUser->getId());

        $this->workspaceService->createSharedWorkspace(
            $this->getContentRepositoryId(),
            $workspaceName,
            WorkspaceTitle::fromString(trim($title)),
            WorkspaceDescription::fromString(trim($description ?? '')),
            $base,
            $assignments
        );

        $workspace = $this->getContentRepository()->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->throwJsonStatus(500, 'workspace_not_ready', 'The workspace was created but could not be read back yet.');
        }

        return $this->json(['workspace' => $this->workspaceSerializer->serialize($this->getContentRepositoryId(), $workspace)], 201);
    }

    /**
     * Update a workspace's title and/or description. Requires the manage
     * permission on the workspace (owners and administrators).
     */
    #[Flow\SkipCsrfProtection]
    public function updateAction(string $workspaceName, ?string $title = null, ?string $description = null): string
    {
        $this->requireScope('neos.write');

        $workspace = $this->requireWorkspaceObject($workspaceName);
        $this->requireManagePermission($workspace->workspaceName);
        if ($title !== null && trim($title) === '') {
            $this->throwJsonStatus(400, 'invalid_title', 'The title must not be empty.');
        }

        if ($title !== null) {
            $this->workspaceService->setWorkspaceTitle($this->getContentRepositoryId(), $workspace->workspaceName, WorkspaceTitle::fromString(trim($title)));
        }
        if ($description !== null) {
            $this->workspaceService->setWorkspaceDescription($this->getContentRepositoryId(), $workspace->workspaceName, WorkspaceDescription::fromString(trim($description)));
        }

        return $this->json(['workspace' => $this->workspaceSerializer->serialize($this->getContentRepositoryId(), $workspace)]);
    }

    /**
     * Delete a workspace including its metadata and role assignments. Root
     * and personal workspaces cannot be deleted; workspaces other workspaces
     * are based on report a conflict. Pending changes block deletion unless
     * the body sets force=true (the changes are then discarded with it).
     */
    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $workspaceName, bool $force = false): string
    {
        $this->requireScope('neos.write');

        $workspace = $this->requireWorkspaceObject($workspaceName);
        $this->requireManagePermission($workspace->workspaceName);

        $metadata = $this->workspaceService->getWorkspaceMetadata($this->getContentRepositoryId(), $workspace->workspaceName);
        if ($metadata->classification === WorkspaceClassification::ROOT) {
            $this->throwJsonStatus(400, 'cannot_delete_root', 'Root workspaces cannot be deleted.');
        }
        if ($metadata->classification === WorkspaceClassification::PERSONAL) {
            $this->throwJsonStatus(400, 'cannot_delete_personal', 'Personal workspaces cannot be deleted - delete the user instead.');
        }

        $dependents = [];
        foreach ($this->getContentRepository()->findWorkspaces()->getDependantWorkspaces($workspace->workspaceName) as $dependent) {
            $dependents[] = $dependent->workspaceName->value;
        }
        if ($dependents !== []) {
            $this->throwJsonStatus(409, 'workspace_has_dependents', 'Other workspaces are based on this workspace.', ['dependents' => $dependents]);
        }

        $pendingChanges = $this->workspacePublishingService->countPendingWorkspaceChanges($this->getContentRepositoryId(), $workspace->workspaceName);
        if ($pendingChanges > 0 && !$force) {
            $this->throwJsonStatus(409, 'workspace_has_changes', sprintf('The workspace has %d unpublished change(s); pass force=true to delete it anyway.', $pendingChanges), ['pendingChanges' => $pendingChanges]);
        }

        $this->workspaceService->deleteWorkspace($this->getContentRepositoryId(), $workspace->workspaceName);

        return $this->json(['success' => true]);
    }

    /**
     * The role assignments of a workspace: who may view, collaborate or
     * manage. Requires the manage permission.
     */
    public function rolesAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireWorkspaceObject($workspaceName);
        $this->requireManagePermission($workspace->workspaceName);

        return $this->json(['assignments' => $this->workspaceSerializer->serializeRoleAssignments($this->getContentRepositoryId(), $workspace->workspaceName)]);
    }

    /**
     * Assign a workspace role. JSON body: subjectType ("USER"/"GROUP"),
     * subject (a user id / a Flow role identifier), role
     * ("VIEWER"/"COLLABORATOR"/"MANAGER"). One role per subject - assigning
     * to a subject that already has one reports a conflict.
     */
    #[Flow\SkipCsrfProtection]
    public function assignRoleAction(string $workspaceName, string $subjectType, string $subject, string $role): string
    {
        $this->requireScope('neos.write');

        $workspace = $this->requireWorkspaceObject($workspaceName);
        $this->requireManagePermission($workspace->workspaceName);

        $subjectValue = $this->buildRoleSubject($subjectType, $subject);
        $workspaceRole = WorkspaceRole::tryFrom($role);
        if ($workspaceRole === null) {
            $this->throwJsonStatus(400, 'invalid_role', 'The role must be VIEWER, COLLABORATOR or MANAGER.');
        }
        foreach ($this->workspaceService->getWorkspaceRoleAssignments($this->getContentRepositoryId(), $workspace->workspaceName) as $existing) {
            if ($existing->subject->equals($subjectValue)) {
                $this->throwJsonStatus(409, 'subject_already_assigned', 'This subject already has a role in the workspace; remove it first.');
            }
        }

        $this->workspaceService->assignWorkspaceRole(
            $this->getContentRepositoryId(),
            $workspace->workspaceName,
            WorkspaceRoleAssignment::create($subjectValue, $workspaceRole)
        );

        return $this->json(['assignments' => $this->workspaceSerializer->serializeRoleAssignments($this->getContentRepositoryId(), $workspace->workspaceName)], 201);
    }

    /**
     * Remove a workspace role assignment. JSON body: subjectType, subject.
     */
    #[Flow\SkipCsrfProtection]
    public function unassignRoleAction(string $workspaceName, string $subjectType, string $subject): string
    {
        $this->requireScope('neos.write');

        $workspace = $this->requireWorkspaceObject($workspaceName);
        $this->requireManagePermission($workspace->workspaceName);

        $subjectValue = $this->buildRoleSubject($subjectType, $subject);
        $found = false;
        foreach ($this->workspaceService->getWorkspaceRoleAssignments($this->getContentRepositoryId(), $workspace->workspaceName) as $existing) {
            if ($existing->subject->equals($subjectValue)) {
                $found = true;
            }
        }
        if (!$found) {
            $this->throwJsonStatus(404, 'assignment_not_found', 'This subject has no role in the workspace.');
        }

        $this->workspaceService->unassignWorkspaceRole($this->getContentRepositoryId(), $workspace->workspaceName, $subjectValue);

        return $this->json(['assignments' => $this->workspaceSerializer->serializeRoleAssignments($this->getContentRepositoryId(), $workspace->workspaceName)]);
    }

    /**
     * The collaboration change feed: what happened in the workspace since the
     * client's cursor. Clients poll this (1-2s) while editing a shared
     * workspace and update trees/preview from the answer - the transport of
     * Studio's multiplayer mode (deliberately plain HTTP: no extra server).
     *
     * Query parameters:
     * - stream: the contentStreamId the client is tailing
     * - since:  the last seen event sequence number (global, monotonic)
     *
     * Without both (the baseline request), or when the workspace has moved to
     * a different content stream since (publish/discard/rebase fork streams),
     * no events are enumerated - the response hands out the current cursor
     * and, for a moved stream, reset=true ("your workspace content changed
     * wholesale, refresh everything"). A full page sets truncated=true with
     * the same client remedy.
     */
    public function eventsAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);

        $query = $this->request->getHttpRequest()->getQueryParams();
        $knownStream = isset($query['stream']) && is_string($query['stream']) && $query['stream'] !== '' ? $query['stream'] : null;
        $since = isset($query['since']) && is_numeric($query['since']) ? (int)$query['since'] : null;

        /** @var WorkspaceEventFeed $feed */
        $feed = $this->contentRepositoryRegistry->buildService($this->getContentRepositoryId(), new WorkspaceEventFeedFactory());
        $contentStreamId = $workspace->currentContentStreamId;

        if ($knownStream === null || $since === null || $knownStream !== $contentStreamId->value) {
            return $this->json([
                'workspace' => $workspace->workspaceName->value,
                'contentStreamId' => $contentStreamId->value,
                'sequenceNumber' => $feed->latestSequenceNumber($contentStreamId),
                // reset only for an already-tailing client whose stream moved;
                // a baseline request is not a change.
                'reset' => $knownStream !== null && $knownStream !== $contentStreamId->value,
                'truncated' => false,
                'events' => [],
            ]);
        }

        $envelopes = $feed->eventsSince($contentStreamId, $since, self::EVENT_FEED_LIMIT);
        $cursor = $since;
        $events = [];
        foreach ($envelopes as $envelope) {
            $cursor = $envelope->sequenceNumber->value;
            $parsed = $this->workspaceHistoryService->parseFeedEvent($envelope);
            if ($parsed !== null) {
                $events[] = $parsed['event'];
            }
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'contentStreamId' => $contentStreamId->value,
            'sequenceNumber' => $cursor,
            'reset' => false,
            'truncated' => count($envelopes) === self::EVENT_FEED_LIMIT,
            'events' => $events,
        ]);
    }

    /**
     * The pending history of a workspace, oldest first - the events recorded
     * in its current content stream. A stream exists exactly since the
     * workspace last forked off its base (publish/discard/rebase start a
     * fresh one), so this is "every change since the branch point": what the
     * Studio's Workspaces graph draws as commits on a branch. Events are
     * enriched with the affected node's label/type/icon and the initiating
     * user's name, and capped at the newest PENDING_EVENTS_LIMIT entries
     * (truncated=true when older ones were dropped).
     */
    public function pendingEventsAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);

        /** @var WorkspaceEventFeed $feed */
        $feed = $this->contentRepositoryRegistry->buildService($this->getContentRepositoryId(), new WorkspaceEventFeedFactory());
        $envelopes = $feed->latestEvents($workspace->currentContentStreamId, self::PENDING_EVENTS_LIMIT);

        // Where this stream branched off: its first event is the fork event,
        // naming the source stream and the version it had at that moment.
        // Events of the base with a HIGHER version happened after the fork -
        // they are what makes this workspace OUTDATED. Read separately from
        // the tail page above, which may not reach back to the first event.
        $forkedFrom = null;
        $firstEvent = $feed->firstEvent($workspace->currentContentStreamId);
        if ($firstEvent !== null && $firstEvent->event->type->value === 'ContentStreamWasForked') {
            $forkPayload = json_decode($firstEvent->event->data->value, true);
            $sourceStream = is_array($forkPayload) ? ($forkPayload['sourceContentStreamId'] ?? null) : null;
            $sourceVersion = is_array($forkPayload) ? ($forkPayload['versionOfSourceContentStream'] ?? null) : null;
            if (is_string($sourceStream) && is_numeric($sourceVersion)) {
                $forkedFrom = [
                    'contentStreamId' => $sourceStream,
                    'version' => (int)$sourceVersion,
                ];
            }
        }

        $events = array_map(
            static fn (array $item): array => $item['event'],
            $this->workspaceHistoryService->enrichFeedEvents($envelopes, $this->readContext($workspace))
        );

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'status' => $workspace->status->value,
            'contentStreamId' => $workspace->currentContentStreamId->value,
            'forkedFrom' => $forkedFrom,
            'truncated' => count($envelopes) === self::PENDING_EVENTS_LIMIT,
            'events' => $events,
        ]);
    }

    /**
     * Before/after detail for a slice of a workspace's pending history - the
     * events of one editing step, addressed by the sequence-number range the
     * pending-events resource reported (a command commits its events
     * contiguously, so a step IS a range). Every event answers WHAT changed:
     * per-property old and new values, old/new reference targets, old/new
     * node type or parent, the visibility tag.
     *
     * "Old" is the value just before the event: resolved by scanning the same
     * stream backwards (through earlier edits of this workspace, capped at
     * DIFF_SCAN_LIMIT), falling back to the value the base workspace holds
     * now. null means "did not exist". The fallback is approximate for an
     * OUTDATED workspace - the base may have moved on since the fork - which
     * matches how the review UI presents changes elsewhere.
     */
    public function pendingEventsDiffAction(string $workspaceName, int $from = 0, int $to = 0): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);
        if ($from < 1 || $to < $from || $to - $from >= self::DIFF_RANGE_LIMIT) {
            $this->throwJsonStatus(400, 'invalid_range', sprintf(
                'Provide 1 <= from <= to with a span below %d.',
                self::DIFF_RANGE_LIMIT
            ));
        }

        /** @var WorkspaceEventFeed $feed */
        $feed = $this->contentRepositoryRegistry->buildService($this->getContentRepositoryId(), new WorkspaceEventFeedFactory());
        $slice = $feed->eventsBetween($workspace->currentContentStreamId, $from, $to);

        // The backward-scan window: loaded lazily on the first before-value
        // lookup, decoded once, indexed per node (see EventBeforeWindow).
        $beforeWindow = new EventBeforeWindow(function () use ($feed, $workspace, $from): array {
            $events = [];
            foreach ($feed->eventsBefore($workspace->currentContentStreamId, $from, self::DIFF_SCAN_LIMIT) as $envelope) {
                $payload = json_decode($envelope->event->data->value, true);
                if (is_array($payload)) {
                    $events[] = ['type' => $envelope->event->type->value, 'payload' => $payload];
                }
            }
            return $events;
        });

        $context = $this->readContext($workspace);
        $events = [];
        foreach ($this->workspaceHistoryService->enrichFeedEvents($slice, $context) as $item) {
            $events[] = $item['event'] + [
                'changes' => $this->workspaceDiffService->diffEventChanges(
                    $item['envelope'],
                    $item['payload'],
                    $item['node'],
                    $context,
                    $beforeWindow
                ),
            ];
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'contentStreamId' => $workspace->currentContentStreamId->value,
            'from' => $from,
            'to' => $to,
            'events' => $events,
        ]);
    }

    /**
     * Presence heartbeat: "I am editing this workspace, standing on this
     * document, focusing this node". Stores the beat (30s TTL - a closed tab
     * simply expires) and answers with everyone currently present in the
     * workspace, so one poll both announces and observes. {"leave": true}
     * removes the own entry immediately (switching back to the personal
     * workspace).
     *
     * Deliberately ephemeral state in a cache, not content: presence never
     * touches the content repository.
     */
    #[Flow\SkipCsrfProtection]
    public function presenceAction(string $workspaceName): string
    {
        $this->requireScope('neos.read');

        $workspace = $this->requireReadableWorkspace($workspaceName);
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            // e.g. a client-credentials token: no user, no presence.
            $this->throwJsonStatus(403, 'no_user', 'Presence requires a user-bound authentication.');
        }

        $body = $this->requestBody();

        $userId = $user->getId()->value;
        // Cache entry identifiers only allow a narrow character set - hash
        // both parts. One entry per user and workspace, tagged by workspace
        // so the roster is one getByTag().
        $entryIdentifier = md5($workspace->workspaceName->value) . '_' . md5($userId);
        $workspaceTag = 'workspace_' . md5($workspace->workspaceName->value);

        $presenceCache = $this->presenceCache();
        if (($body['leave'] ?? false) === true) {
            $presenceCache->remove($entryIdentifier);
        } else {
            $presenceCache->set($entryIdentifier, [
                'userId' => $userId,
                'name' => $user->getLabel(),
                'documentAggregateId' => is_string($body['documentAggregateId'] ?? null) ? $body['documentAggregateId'] : null,
                'focusedAggregateId' => is_string($body['focusedAggregateId'] ?? null) ? $body['focusedAggregateId'] : null,
                'dimensionSpacePoint' => is_array($body['dimensionSpacePoint'] ?? null) ? $body['dimensionSpacePoint'] : null,
                'updatedAt' => time(),
            ], [$workspaceTag], self::PRESENCE_LIFETIME);
        }

        $users = [];
        foreach ($presenceCache->getByTag($workspaceTag) as $entry) {
            // Defensive re-check of the TTL - backends differ in how eagerly
            // they drop expired entries from tag lookups.
            if (!is_array($entry) || ($entry['updatedAt'] ?? 0) < time() - self::PRESENCE_LIFETIME) {
                continue;
            }
            $users[] = $entry;
        }

        return $this->json([
            'workspace' => $workspace->workspaceName->value,
            'you' => $userId,
            'users' => $users,
        ]);
    }

    /**
     * The workspace by name, or a JSON 404/403: the shared preamble of every
     * read resource - the workspace must exist and the account must have read
     * permission on it.
     */
    private function requireReadableWorkspace(string $workspaceName): Workspace
    {
        $workspace = $this->requireWorkspaceObject($workspaceName);
        if (!$this->workspaceSerializer->canRead($this->getContentRepositoryId(), $workspace->workspaceName)) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }

        return $workspace;
    }

    private function requireWorkspaceObject(string $workspaceName): Workspace
    {
        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }

        return $workspace;
    }

    /** 403 unless the account may manage the workspace (owner, manager role, administrator). */
    private function requireManagePermission(WorkspaceName $workspaceName): void
    {
        if (!$this->workspaceSerializer->permissions($this->getContentRepositoryId(), $workspaceName)->manage) {
            $this->throwJsonStatus(403, 'access_denied', 'You are not allowed to manage this workspace.');
        }
    }

    /** The request-scoped read context the history/diff services cache through. */
    private function readContext(Workspace $workspace): WorkspaceReadContext
    {
        return new WorkspaceReadContext($this->getContentRepository(), $workspace, $this->nodeSerializer);
    }

    private function buildRoleSubject(string $subjectType, string $subject): WorkspaceRoleSubject
    {
        $type = WorkspaceRoleSubjectType::tryFrom($subjectType);
        if ($type === null) {
            $this->throwJsonStatus(400, 'invalid_subject_type', 'The subjectType must be USER or GROUP.');
        }
        if ($type === WorkspaceRoleSubjectType::USER) {
            try {
                $userId = UserId::fromString($subject);
            } catch (\InvalidArgumentException) {
                $this->throwJsonStatus(400, 'invalid_subject', 'The subject is not a valid user id.');
            }
            if ($this->userService->findUserById($userId) === null) {
                $this->throwJsonStatus(400, 'invalid_subject', 'No user with this id exists.');
            }
        }

        try {
            return WorkspaceRoleSubject::create($type, $subject);
        } catch (\InvalidArgumentException) {
            $this->throwJsonStatus(400, 'invalid_subject', 'The subject is not valid.');
        }
    }

    /**
     * The presence entries (who is editing in which workspace), see
     * Caches.yaml. Fetched from the manager instead of property-injected:
     * Flow's lazy property injection cannot initialize typed non-nullable
     * properties. Ephemeral by design - entries expire PRESENCE_LIFETIME
     * seconds after the last heartbeat, so a closed tab disappears on its own.
     */
    private function presenceCache(): VariableFrontend
    {
        $cache = $this->cacheManager->getCache('MedienreaktorNeosApi_Presence');
        assert($cache instanceof VariableFrontend);

        return $cache;
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
        } catch (\Exception $exception) {
            // \Exception, not \Throwable: an \Error is a server bug and must
            // surface as a logged 500.
            $this->logger->warning(sprintf('Workspace operation on "%s" failed: %s', $workspaceName, $exception->getMessage()), ['exception' => $exception]);
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
                'nodeLabel' => $affectedNode !== null ? $this->nodeSerializer->label($affectedNode) : null,
                'documentAggregateId' => $documentNode?->aggregateId->value,
                'documentLabel' => $documentNode !== null ? $this->nodeSerializer->label($documentNode) : null,
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

    private function shortClassName(object $object): string
    {
        $class = $object::class;
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * The JSON request body, parsed once per request (the operation filter,
     * the documents list and the presence beat all read it).
     *
     * @return array<string, mixed>
     */
    private function requestBody(): array
    {
        if ($this->parsedRequestBody === null) {
            $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
            $this->parsedRequestBody = is_array($body) ? $body : [];
        }

        return $this->parsedRequestBody;
    }

    /**
     * @return array<string, string>
     */
    private function getOperationFilter(): array
    {
        return array_filter($this->requestBody(), 'is_string');
    }

    /**
     * The "documents" list of a scoped publish/discard body ({"documents":
     * ["id", ...]}) - the document aggregate ids a review selection targets.
     * Empty when the body carries no such list.
     *
     * @return list<string>
     */
    private function getOperationDocuments(): array
    {
        $documents = $this->requestBody()['documents'] ?? null;
        if (!is_array($documents)) {
            return [];
        }

        return array_values(array_filter($documents, 'is_string'));
    }
}
