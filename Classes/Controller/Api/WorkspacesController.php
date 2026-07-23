<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
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
use Neos\Neos\Domain\Model\WorkspaceMetadata;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceRoleSubject;
use Neos\Neos\Domain\Model\WorkspaceRoleSubjectType;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Medienreaktor\NeosApi\Service\NodeAddressCodec;
use Medienreaktor\NeosApi\Service\WorkspaceEventFeed;
use Medienreaktor\NeosApi\Service\WorkspaceEventFeedFactory;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Flow\Cache\CacheManager;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushWorkspaceRequest;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
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

    #[Flow\Inject]
    protected CacheManager $cacheManager;

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

    /**
     * How feed clients should react to an event type: 'content' = a node's
     * rendered element / properties changed (re-render in place), 'structure'
     * = the node tree changed shape (refresh trees, reload the preview).
     * Event types not listed (stream bookkeeping like ContentStreamWasForked)
     * are not surfaced to clients.
     */
    private const FEED_EVENT_KINDS = [
        'NodePropertiesWereSet' => 'content',
        'NodeReferencesWereSet' => 'content',
        'NodeAggregateWithNodeWasCreated' => 'structure',
        'NodeAggregateWasMoved' => 'structure',
        'NodeAggregateWasRemoved' => 'structure',
        'SubtreeWasTagged' => 'structure',
        'SubtreeWasUntagged' => 'structure',
        'NodeAggregateTypeWasChanged' => 'structure',
        'NodeAggregateNameWasChanged' => 'structure',
        'NodeSpecializationVariantWasCreated' => 'structure',
        'NodeGeneralizationVariantWasCreated' => 'structure',
        'NodePeerVariantWasCreated' => 'structure',
    ];

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

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }

        $contentRepository = $this->getContentRepository();
        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $changeFinder = $contentRepository->projectionState(ChangeFinder::class);
        $subgraphs = [];
        $baseSubgraphs = [];
        // Accumulated per document aggregate id, in first-seen order.
        $documents = [];
        foreach ($changeFinder->findByContentStreamId($workspace->currentContentStreamId) as $change) {
            $documentNode = null;
            $siteNode = null;
            $documentSubgraph = null;
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
                if ($documentNode !== null) {
                    $documentSubgraph = $subgraph;
                }
                // A node removed in this workspace is gone from its subgraph, so
                // resolve its document/site in the base workspace instead - the
                // same fallback the changes resource uses - otherwise a deleted
                // page would never appear in the review list. The base node is
                // display-only (label/icon/breadcrumb); it is not offered for
                // navigation.
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
                    // Left null on purpose: a base-resolved document is not
                    // navigable in this workspace.
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
                    'siteLabel' => $siteNode !== null
                        ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($siteNode))
                        : null,
                    'label' => $documentNode !== null
                        ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($documentNode))
                        : $documentId,
                    'nodeType' => $documentNode?->nodeTypeName->value,
                    'icon' => $documentNode !== null
                        ? ($nodeTypeManager->getNodeType($documentNode->nodeTypeName)?->getFullConfiguration()['ui']['icon'] ?? null)
                        : null,
                    'breadcrumb' => $documentNode !== null && $documentSubgraph !== null
                        ? $this->documentBreadcrumb($documentNode, $documentSubgraph)
                        : [],
                    'hidden' => $documentNode?->tags->contain(NeosSubtreeTag::disabled()) ?? false,
                    'created' => false,
                    'changed' => false,
                    'moved' => false,
                    'deleted' => false,
                    'changeCount' => 0,
                ];
            }

            $documents[$documentId]['changeCount']++;
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
        if ($baseWorkspace === null || $this->serializeWorkspace($baseWorkspace) === null) {
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

        return $this->json(['workspace' => $this->serializeWorkspace($workspace)], 201);
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

        return $this->json(['workspace' => $this->serializeWorkspace($workspace)]);
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

        return $this->json(['assignments' => $this->serializeRoleAssignments($workspace->workspaceName)]);
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

        return $this->json(['assignments' => $this->serializeRoleAssignments($workspace->workspaceName)], 201);
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

        return $this->json(['assignments' => $this->serializeRoleAssignments($workspace->workspaceName)]);
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
        $permissions = $this->authorizationService->getWorkspacePermissions(
            $this->getContentRepositoryId(),
            $workspaceName,
            $this->securityContext->getRoles(),
            $this->userService->getCurrentUser()?->getId()
        );
        if (!$permissions->manage) {
            $this->throwJsonStatus(403, 'access_denied', 'You are not allowed to manage this workspace.');
        }
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
     * @return array<int, array<string, mixed>>
     */
    private function serializeRoleAssignments(WorkspaceName $workspaceName): array
    {
        $assignments = [];
        foreach ($this->workspaceService->getWorkspaceRoleAssignments($this->getContentRepositoryId(), $workspaceName) as $assignment) {
            $label = null;
            if ($assignment->subject->type === WorkspaceRoleSubjectType::USER) {
                try {
                    $label = $this->userService->findUserById(UserId::fromString($assignment->subject->value))?->getLabel();
                } catch (\InvalidArgumentException) {
                    // Keep the raw subject value as the label.
                }
            }
            $assignments[] = [
                'subjectType' => $assignment->subject->type->value,
                'subject' => $assignment->subject->value,
                // Human-readable name for USER subjects; GROUP subjects show
                // their Flow role identifier.
                'label' => $label ?? $assignment->subject->value,
                'role' => $assignment->role->value,
            ];
        }

        return $assignments;
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

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }

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
            $serialized = $this->serializeFeedEvent($envelope);
            if ($serialized !== null) {
                $events[] = $serialized;
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

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }

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
            $this->enrichFeedEvents($envelopes, $workspace)
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
     * Serialize and enrich feed events: the affected node's label/type/icon,
     * the initiating user's name, and the containing document (id, label and
     * a navigable address - what "go to page" on a history entry follows).
     * The node is resolved in the workspace's own subgraph, falling back to
     * the base for nodes removed in the workspace, in the dimension the event
     * names (same fallback the changes resources use). Envelopes whose event
     * type is not client-facing are dropped.
     *
     * @param list<EventEnvelope> $envelopes
     * @return list<array{event: array<string, mixed>, envelope: EventEnvelope, node: ?Node, subgraph: ?ContentSubgraphInterface}>
     */
    private function enrichFeedEvents(array $envelopes, Workspace $workspace): array
    {
        $nodeTypeManager = $this->getContentRepository()->getNodeTypeManager();
        $subgraphs = [];
        $baseSubgraphs = [];
        $documentNodes = [];
        $userLabels = [];
        $items = [];
        foreach ($envelopes as $envelope) {
            $serialized = $this->serializeFeedEvent($envelope);
            if ($serialized === null) {
                continue;
            }

            [$node, $subgraph] = $this->resolveFeedEventNode($serialized, $workspace, $subgraphs, $baseSubgraphs);

            // Cached per node: consecutive events usually hit the same few nodes.
            $documentNode = null;
            if ($node !== null && $subgraph !== null) {
                $documentCacheKey = $node->aggregateId->value . '|' . $node->originDimensionSpacePoint->hash;
                if (!array_key_exists($documentCacheKey, $documentNodes)) {
                    try {
                        $documentNodes[$documentCacheKey] = $subgraph->findClosestNode(
                            $node->aggregateId,
                            FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
                        );
                    } catch (\Throwable) {
                        $documentNodes[$documentCacheKey] = null;
                    }
                }
                $documentNode = $documentNodes[$documentCacheKey];
            }

            $userId = $serialized['initiatingUserId'];
            if (is_string($userId) && !array_key_exists($userId, $userLabels)) {
                try {
                    $userLabels[$userId] = $this->userService->findUserById(UserId::fromString($userId))?->getLabel();
                } catch (\InvalidArgumentException) {
                    $userLabels[$userId] = null;
                }
            }

            $items[] = [
                'event' => $serialized + [
                    'nodeLabel' => $node !== null
                        ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($node))
                        : null,
                    'nodeType' => $node?->nodeTypeName->value,
                    'icon' => $node !== null
                        ? ($nodeTypeManager->getNodeType($node->nodeTypeName)?->getFullConfiguration()['ui']['icon'] ?? null)
                        : null,
                    'initiatingUserLabel' => is_string($userId) ? ($userLabels[$userId] ?? null) : null,
                    'documentAggregateId' => $documentNode?->aggregateId->value,
                    'documentLabel' => $documentNode !== null
                        ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($documentNode))
                        : null,
                    'documentAddress' => $documentNode !== null
                        ? NodeAddressCodec::encode(NodeAddress::fromNode($documentNode))
                        : null,
                ],
                'envelope' => $envelope,
                'node' => $node,
                'subgraph' => $subgraph,
            ];
        }

        return $items;
    }

    /**
     * The affected node of a serialized feed event plus the subgraph it was
     * resolved in: the workspace's own, falling back to the base workspace
     * for nodes removed in the workspace (so a deletion still shows what it
     * deleted).
     *
     * @param array<string, mixed> $serialized
     * @param array<string, ContentSubgraphInterface> $subgraphs
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     * @return array{?Node, ?ContentSubgraphInterface}
     */
    private function resolveFeedEventNode(array $serialized, Workspace $workspace, array &$subgraphs, array &$baseSubgraphs): array
    {
        $nodeId = $serialized['nodeAggregateId'];
        $coordinates = $serialized['dimensionSpacePoints'][0] ?? null;
        if (!is_string($nodeId) || !is_array($coordinates)) {
            return [null, null];
        }
        $contentRepository = $this->getContentRepository();
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);
        $subgraph = $subgraphs[$dimensionSpacePoint->hash] ??= $contentRepository->getContentSubgraph(
            $workspace->workspaceName,
            $dimensionSpacePoint
        );
        $node = $subgraph->findNodeById(NodeAggregateId::fromString($nodeId));
        if ($node !== null) {
            return [$node, $subgraph];
        }
        if ($workspace->baseWorkspaceName !== null) {
            $baseSubgraph = $baseSubgraphs[$dimensionSpacePoint->hash] ??= $contentRepository->getContentSubgraph(
                $workspace->baseWorkspaceName,
                $dimensionSpacePoint
            );
            $node = $baseSubgraph->findNodeById(NodeAggregateId::fromString($nodeId));
            if ($node !== null) {
                return [$node, $baseSubgraph];
            }
        }
        return [null, null];
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

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }
        if ($from < 1 || $to < $from || $to - $from >= self::DIFF_RANGE_LIMIT) {
            $this->throwJsonStatus(400, 'invalid_range', sprintf(
                'Provide 1 <= from <= to with a span below %d.',
                self::DIFF_RANGE_LIMIT
            ));
        }

        /** @var WorkspaceEventFeed $feed */
        $feed = $this->contentRepositoryRegistry->buildService($this->getContentRepositoryId(), new WorkspaceEventFeedFactory());
        $slice = $feed->eventsBetween($workspace->currentContentStreamId, $from, $to);

        // The backward-scan window, newest first, pre-decoded once. Only
        // needed for event types whose diff looks into the past.
        $beforeEvents = null;
        $loadBeforeEvents = function () use (&$beforeEvents, $feed, $workspace, $from): array {
            if ($beforeEvents === null) {
                $beforeEvents = [];
                foreach ($feed->eventsBefore($workspace->currentContentStreamId, $from, self::DIFF_SCAN_LIMIT) as $envelope) {
                    $payload = json_decode($envelope->event->data->value, true);
                    if (is_array($payload)) {
                        $beforeEvents[] = ['type' => $envelope->event->type->value, 'payload' => $payload];
                    }
                }
            }
            return $beforeEvents;
        };

        $subgraphs = [];
        $baseSubgraphs = [];
        $events = [];
        foreach ($this->enrichFeedEvents($slice, $workspace) as $item) {
            $events[] = $item['event'] + [
                'changes' => $this->diffEventChanges(
                    $item['envelope'],
                    $item['node'],
                    $workspace,
                    $loadBeforeEvents,
                    $subgraphs,
                    $baseSubgraphs
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
     * What one pending event changed, as before/after rows. Kinds: 'property'
     * (old/new property value), 'reference' (old/new target lists of
     * {id,label}), 'nodeType', 'name', 'parent' (old/new {id,label}),
     * 'position' (reorder under the same parent), 'tag' (visibility et al.),
     * 'variant' (old/new dimension coordinates).
     *
     * @param callable(): list<array{type: string, payload: array<string, mixed>}> $loadBeforeEvents
     * @param array<string, ContentSubgraphInterface> $subgraphs
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     * @return list<array<string, mixed>>
     */
    private function diffEventChanges(
        EventEnvelope $envelope,
        ?Node $node,
        Workspace $workspace,
        callable $loadBeforeEvents,
        array &$subgraphs,
        array &$baseSubgraphs
    ): array {
        $type = $envelope->event->type->value;
        $payload = json_decode($envelope->event->data->value, true);
        $payload = is_array($payload) ? $payload : [];
        $nodeId = $payload['nodeAggregateId'] ?? null;
        if (!is_string($nodeId)) {
            return [];
        }

        switch ($type) {
            case 'NodePropertiesWereSet': {
                $origin = is_array($payload['originDimensionSpacePoint'] ?? null) ? $payload['originDimensionSpacePoint'] : null;
                $rows = [];
                foreach ((array)($payload['propertyValues'] ?? []) as $name => $descriptor) {
                    $rows[] = [
                        'kind' => 'property',
                        'property' => (string)$name,
                        'label' => $this->propertyLabel($node, (string)$name, 'properties'),
                        'old' => $this->propertyValueBefore($nodeId, $origin, (string)$name, $loadBeforeEvents(), $workspace, $baseSubgraphs),
                        'new' => is_array($descriptor) && array_key_exists('value', $descriptor) ? $descriptor['value'] : null,
                    ];
                }
                foreach ((array)($payload['propertiesToUnset'] ?? []) as $name) {
                    $rows[] = [
                        'kind' => 'property',
                        'property' => (string)$name,
                        'label' => $this->propertyLabel($node, (string)$name, 'properties'),
                        'old' => $this->propertyValueBefore($nodeId, $origin, (string)$name, $loadBeforeEvents(), $workspace, $baseSubgraphs),
                        'new' => null,
                    ];
                }
                return $rows;
            }
            case 'NodeAggregateWithNodeWasCreated': {
                $rows = [];
                foreach ((array)($payload['initialPropertyValues'] ?? []) as $name => $descriptor) {
                    $rows[] = [
                        'kind' => 'property',
                        'property' => (string)$name,
                        'label' => $this->propertyLabel($node, (string)$name, 'properties'),
                        'old' => null,
                        'new' => is_array($descriptor) && array_key_exists('value', $descriptor) ? $descriptor['value'] : null,
                    ];
                }
                return $rows;
            }
            case 'NodeReferencesWereSet': {
                $origin = is_array($payload['affectedSourceOriginDimensionSpacePoints'][0] ?? null)
                    ? $payload['affectedSourceOriginDimensionSpacePoints'][0]
                    : null;
                $rows = [];
                foreach ((array)($payload['references'] ?? []) as $referencesForName) {
                    $referenceName = $referencesForName['referenceName'] ?? null;
                    if (!is_string($referenceName)) {
                        continue;
                    }
                    $newTargets = [];
                    foreach ((array)($referencesForName['references'] ?? []) as $reference) {
                        if (is_string($reference['target'] ?? null)) {
                            $newTargets[] = $reference['target'];
                        }
                    }
                    $oldTargets = $this->referenceTargetsBefore($nodeId, $origin, $referenceName, $loadBeforeEvents(), $workspace, $baseSubgraphs);
                    $rows[] = [
                        'kind' => 'reference',
                        'property' => $referenceName,
                        'label' => $this->propertyLabel($node, $referenceName, 'references'),
                        'old' => $this->describeNodes($oldTargets, $origin, $workspace, $subgraphs, $baseSubgraphs),
                        'new' => $this->describeNodes($newTargets, $origin, $workspace, $subgraphs, $baseSubgraphs),
                    ];
                }
                return $rows;
            }
            case 'NodeAggregateTypeWasChanged':
                return [[
                    'kind' => 'nodeType',
                    'property' => null,
                    'label' => null,
                    'old' => $this->nodeTypeBefore($nodeId, $loadBeforeEvents(), $workspace),
                    'new' => $payload['newNodeTypeName'] ?? null,
                ]];
            case 'NodeAggregateNameWasChanged':
                return [[
                    'kind' => 'name',
                    'property' => null,
                    'label' => null,
                    'old' => $this->nodeNameBefore($nodeId, $loadBeforeEvents(), $workspace),
                    'new' => $payload['newNodeName'] ?? null,
                ]];
            case 'NodeAggregateWasMoved': {
                $newParentId = $payload['newParentNodeAggregateId'] ?? null;
                if (!is_string($newParentId)) {
                    // Reorder among its siblings - same parent, new position.
                    return [['kind' => 'position', 'property' => null, 'label' => null, 'old' => null, 'new' => null]];
                }
                $coordinates = is_array($payload['succeedingSiblingsForCoverage'][0]['dimensionSpacePoint'] ?? null)
                    ? $payload['succeedingSiblingsForCoverage'][0]['dimensionSpacePoint']
                    : null;
                $oldParentId = $this->parentBefore($nodeId, $coordinates, $loadBeforeEvents(), $workspace, $baseSubgraphs);
                return [[
                    'kind' => 'parent',
                    'property' => null,
                    'label' => null,
                    'old' => $oldParentId !== null
                        ? ($this->describeNodes([$oldParentId], $coordinates, $workspace, $subgraphs, $baseSubgraphs)[0] ?? null)
                        : null,
                    'new' => $this->describeNodes([$newParentId], $coordinates, $workspace, $subgraphs, $baseSubgraphs)[0] ?? null,
                ]];
            }
            case 'SubtreeWasTagged':
            case 'SubtreeWasUntagged': {
                $tag = is_string($payload['tag'] ?? null) ? $payload['tag'] : null;
                return [[
                    'kind' => 'tag',
                    'property' => $tag,
                    'label' => null,
                    'old' => $type === 'SubtreeWasUntagged' ? $tag : null,
                    'new' => $type === 'SubtreeWasTagged' ? $tag : null,
                ]];
            }
            case 'NodeSpecializationVariantWasCreated':
            case 'NodeGeneralizationVariantWasCreated':
            case 'NodePeerVariantWasCreated':
                return [[
                    'kind' => 'variant',
                    'property' => null,
                    'label' => null,
                    'old' => $payload['sourceOrigin'] ?? null,
                    'new' => $payload['specializationOrigin'] ?? $payload['generalizationOrigin'] ?? $payload['peerOrigin'] ?? null,
                ]];
            default:
                return [];
        }
    }

    /**
     * The configured human label of a property or reference from the node's
     * type - possibly an XLIFF shorthand the client translates, like every
     * node-type label the API emits.
     */
    private function propertyLabel(?Node $node, string $name, string $section): ?string
    {
        if ($node === null) {
            return null;
        }
        $configuration = $this->getContentRepository()->getNodeTypeManager()
            ->getNodeType($node->nodeTypeName)?->getFullConfiguration();
        $label = $configuration[$section][$name]['ui']['label'] ?? null;
        return is_string($label) ? $label : null;
    }

    /**
     * The value a property had just before the diffed event: the newest
     * earlier write to it in the same stream (a set, an unset, the node's
     * creation, or - transitively - the variant source it was copied from),
     * falling back to the value the base workspace holds.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $beforeEvents newest first
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     */
    private function propertyValueBefore(
        string $nodeId,
        ?array $origin,
        string $property,
        array $beforeEvents,
        Workspace $workspace,
        array &$baseSubgraphs
    ): mixed {
        foreach ($beforeEvents as ['type' => $type, 'payload' => $payload]) {
            if (($payload['nodeAggregateId'] ?? null) !== $nodeId) {
                continue;
            }
            switch ($type) {
                case 'NodePropertiesWereSet':
                    if (!$this->sameCoordinates($payload['originDimensionSpacePoint'] ?? null, $origin)) {
                        break;
                    }
                    $descriptor = $payload['propertyValues'][$property] ?? null;
                    if (is_array($descriptor) && array_key_exists('value', $descriptor)) {
                        return $descriptor['value'];
                    }
                    if (in_array($property, (array)($payload['propertiesToUnset'] ?? []), true)) {
                        return null;
                    }
                    break;
                case 'NodeAggregateWithNodeWasCreated':
                    if (!$this->sameCoordinates($payload['originDimensionSpacePoint'] ?? null, $origin)) {
                        break;
                    }
                    // The node was created inside this workspace: its initial
                    // value is definitive, the base cannot know it.
                    $descriptor = $payload['initialPropertyValues'][$property] ?? null;
                    return is_array($descriptor) && array_key_exists('value', $descriptor) ? $descriptor['value'] : null;
                case 'NodeSpecializationVariantWasCreated':
                case 'NodeGeneralizationVariantWasCreated':
                case 'NodePeerVariantWasCreated':
                    // The variant copied the source origin's values when it
                    // was created - continue the scan in the source origin.
                    $target = $payload['specializationOrigin'] ?? $payload['generalizationOrigin'] ?? $payload['peerOrigin'] ?? null;
                    if ($this->sameCoordinates($target, $origin) && is_array($payload['sourceOrigin'] ?? null)) {
                        $origin = $payload['sourceOrigin'];
                    }
                    break;
            }
        }

        if ($workspace->baseWorkspaceName === null || !is_array($origin)) {
            return null;
        }
        try {
            $dimensionSpacePoint = DimensionSpacePoint::fromArray($origin);
            $subgraph = $baseSubgraphs[$dimensionSpacePoint->hash] ??= $this->getContentRepository()->getContentSubgraph(
                $workspace->baseWorkspaceName,
                $dimensionSpacePoint
            );
            return $subgraph->findNodeById(NodeAggregateId::fromString($nodeId))
                ?->properties->serialized()->getProperty($property)?->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The reference targets a name pointed to just before the diffed event.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $beforeEvents newest first
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     * @return list<string>
     */
    private function referenceTargetsBefore(
        string $nodeId,
        ?array $origin,
        string $referenceName,
        array $beforeEvents,
        Workspace $workspace,
        array &$baseSubgraphs
    ): array {
        foreach ($beforeEvents as ['type' => $type, 'payload' => $payload]) {
            if (($payload['nodeAggregateId'] ?? null) !== $nodeId) {
                continue;
            }
            if ($type === 'NodeReferencesWereSet') {
                $affected = (array)($payload['affectedSourceOriginDimensionSpacePoints'] ?? []);
                $matches = $origin === null || array_filter($affected, fn ($point) => $this->sameCoordinates($point, $origin)) !== [];
                if (!$matches) {
                    continue;
                }
                foreach ((array)($payload['references'] ?? []) as $referencesForName) {
                    if (($referencesForName['referenceName'] ?? null) !== $referenceName) {
                        continue;
                    }
                    $targets = [];
                    foreach ((array)($referencesForName['references'] ?? []) as $reference) {
                        if (is_string($reference['target'] ?? null)) {
                            $targets[] = $reference['target'];
                        }
                    }
                    return $targets;
                }
            }
            if ($type === 'NodeAggregateWithNodeWasCreated' && $this->sameCoordinates($payload['originDimensionSpacePoint'] ?? null, $origin)) {
                foreach ((array)($payload['nodeReferences'] ?? []) as $referencesForName) {
                    if (($referencesForName['referenceName'] ?? null) !== $referenceName) {
                        continue;
                    }
                    $targets = [];
                    foreach ((array)($referencesForName['references'] ?? []) as $reference) {
                        if (is_string($reference['target'] ?? null)) {
                            $targets[] = $reference['target'];
                        }
                    }
                    return $targets;
                }
                return [];
            }
        }

        if ($workspace->baseWorkspaceName === null || !is_array($origin)) {
            return [];
        }
        try {
            $dimensionSpacePoint = DimensionSpacePoint::fromArray($origin);
            $subgraph = $baseSubgraphs[$dimensionSpacePoint->hash] ??= $this->getContentRepository()->getContentSubgraph(
                $workspace->baseWorkspaceName,
                $dimensionSpacePoint
            );
            $targets = [];
            foreach ($subgraph->findReferences(NodeAggregateId::fromString($nodeId), FindReferencesFilter::create(referenceName: $referenceName)) as $reference) {
                $targets[] = $reference->node->aggregateId->value;
            }
            return $targets;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The node type an aggregate had before the diffed event: an earlier type
     * change or its creation in this stream, else what the base knows.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $beforeEvents newest first
     */
    private function nodeTypeBefore(string $nodeId, array $beforeEvents, Workspace $workspace): ?string
    {
        foreach ($beforeEvents as ['type' => $type, 'payload' => $payload]) {
            if (($payload['nodeAggregateId'] ?? null) !== $nodeId) {
                continue;
            }
            if ($type === 'NodeAggregateTypeWasChanged' && is_string($payload['newNodeTypeName'] ?? null)) {
                return $payload['newNodeTypeName'];
            }
            if ($type === 'NodeAggregateWithNodeWasCreated' && is_string($payload['nodeTypeName'] ?? null)) {
                return $payload['nodeTypeName'];
            }
        }
        if ($workspace->baseWorkspaceName === null) {
            return null;
        }
        try {
            return $this->getContentRepository()->getContentGraph($workspace->baseWorkspaceName)
                ->findNodeAggregateById(NodeAggregateId::fromString($nodeId))?->nodeTypeName->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The node name an aggregate had before the diffed event.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $beforeEvents newest first
     */
    private function nodeNameBefore(string $nodeId, array $beforeEvents, Workspace $workspace): ?string
    {
        foreach ($beforeEvents as ['type' => $type, 'payload' => $payload]) {
            if (($payload['nodeAggregateId'] ?? null) !== $nodeId) {
                continue;
            }
            if ($type === 'NodeAggregateNameWasChanged' && is_string($payload['newNodeName'] ?? null)) {
                return $payload['newNodeName'];
            }
            if ($type === 'NodeAggregateWithNodeWasCreated') {
                return is_string($payload['nodeName'] ?? null) ? $payload['nodeName'] : null;
            }
        }
        if ($workspace->baseWorkspaceName === null) {
            return null;
        }
        try {
            return $this->getContentRepository()->getContentGraph($workspace->baseWorkspaceName)
                ->findNodeAggregateById(NodeAggregateId::fromString($nodeId))?->nodeName?->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The parent an aggregate hung under before the diffed move: an earlier
     * move or its creation in this stream, else the base workspace's parent.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $beforeEvents newest first
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     */
    private function parentBefore(
        string $nodeId,
        ?array $coordinates,
        array $beforeEvents,
        Workspace $workspace,
        array &$baseSubgraphs
    ): ?string {
        foreach ($beforeEvents as ['type' => $type, 'payload' => $payload]) {
            if (($payload['nodeAggregateId'] ?? null) !== $nodeId) {
                continue;
            }
            if ($type === 'NodeAggregateWasMoved' && is_string($payload['newParentNodeAggregateId'] ?? null)) {
                return $payload['newParentNodeAggregateId'];
            }
            if ($type === 'NodeAggregateWithNodeWasCreated' && is_string($payload['parentNodeAggregateId'] ?? null)) {
                return $payload['parentNodeAggregateId'];
            }
        }
        if ($workspace->baseWorkspaceName === null || !is_array($coordinates)) {
            return null;
        }
        try {
            $dimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);
            $subgraph = $baseSubgraphs[$dimensionSpacePoint->hash] ??= $this->getContentRepository()->getContentSubgraph(
                $workspace->baseWorkspaceName,
                $dimensionSpacePoint
            );
            return $subgraph->findParentNode(NodeAggregateId::fromString($nodeId))?->aggregateId->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Node ids as {id, label} pairs a human can read, resolved in the
     * workspace (falling back to the base) in the given dimension.
     *
     * @param list<string> $nodeIds
     * @param array<string, ContentSubgraphInterface> $subgraphs
     * @param array<string, ContentSubgraphInterface> $baseSubgraphs
     * @return list<array{id: string, label: ?string}>
     */
    private function describeNodes(
        array $nodeIds,
        ?array $coordinates,
        Workspace $workspace,
        array &$subgraphs,
        array &$baseSubgraphs
    ): array {
        $described = [];
        foreach ($nodeIds as $nodeId) {
            $node = null;
            if (is_array($coordinates)) {
                [$node] = $this->resolveFeedEventNode(
                    ['nodeAggregateId' => $nodeId, 'dimensionSpacePoints' => [$coordinates]],
                    $workspace,
                    $subgraphs,
                    $baseSubgraphs
                );
            }
            $described[] = [
                'id' => $nodeId,
                'label' => $node !== null ? $this->plainTextLabel($this->nodeLabelGenerator->getLabel($node)) : null,
            ];
        }
        return $described;
    }

    /** Dimension coordinates compare as unordered maps; null only equals null. */
    private function sameCoordinates(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === null && $b === null;
        }
        ksort($a);
        ksort($b);
        return $a == $b;
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

        $workspace = $this->getContentRepository()->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if ($workspace === null) {
            $this->throwJsonStatus(404, 'workspace_not_found', 'The workspace does not exist.');
        }
        if ($this->serializeWorkspace($workspace) === null) {
            $this->throwJsonStatus(403, 'access_denied', 'You have no read access to this workspace.');
        }
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            // e.g. a client-credentials token: no user, no presence.
            $this->throwJsonStatus(403, 'no_user', 'Presence requires a user-bound authentication.');
        }

        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        $body = is_array($body) ? $body : [];

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
     * One change-feed event as clients consume it, or null for event types
     * the feed does not surface. Payload fields are read from the raw event
     * JSON - stable across event types without importing every event class.
     *
     * @return array<string, mixed>|null
     */
    private function serializeFeedEvent(EventEnvelope $envelope): ?array
    {
        $type = $envelope->event->type->value;
        $kind = self::FEED_EVENT_KINDS[$type] ?? null;
        if ($kind === null) {
            return null;
        }

        $payload = json_decode($envelope->event->data->value, true);
        $payload = is_array($payload) ? $payload : [];

        // The dimension space points an event names, whatever it calls them -
        // single points and sets alike. Clients treat an empty list as
        // "affects every dimension".
        $dimensionSpacePoints = [];
        foreach (['originDimensionSpacePoint', 'coveredDimensionSpacePoint', 'dimensionSpacePoint', 'sourceOrigin', 'targetOrigin', 'specializationOrigin', 'generalizationOrigin', 'peerOrigin'] as $pointKey) {
            if (is_array($payload[$pointKey] ?? null)) {
                $dimensionSpacePoints[] = $payload[$pointKey];
            }
        }
        foreach (['affectedDimensionSpacePoints', 'affectedOccupiedDimensionSpacePoints', 'affectedCoveredDimensionSpacePoints', 'affectedSourceOriginDimensionSpacePoints'] as $setKey) {
            if (is_array($payload[$setKey] ?? null)) {
                foreach ($payload[$setKey] as $point) {
                    if (is_array($point)) {
                        $dimensionSpacePoints[] = $point;
                    }
                }
            }
        }

        return [
            'sequenceNumber' => $envelope->sequenceNumber->value,
            // The event's 0-based position within its content stream -
            // comparable with a fork's versionOfSourceContentStream, which is
            // how clients locate a workspace's branch point in its base.
            'version' => $envelope->version->value,
            'type' => $type,
            'kind' => $kind,
            'nodeAggregateId' => is_string($payload['nodeAggregateId'] ?? null)
                ? $payload['nodeAggregateId']
                : (is_string($payload['sourceNodeAggregateId'] ?? null) ? $payload['sourceNodeAggregateId'] : null),
            // The collection a structural change happened inside, when the
            // event knows it - lets clients refresh that subtree.
            'parentNodeAggregateId' => is_string($payload['parentNodeAggregateId'] ?? null)
                ? $payload['parentNodeAggregateId']
                : (is_string($payload['newParentNodeAggregateId'] ?? null) ? $payload['newParentNodeAggregateId'] : null),
            'dimensionSpacePoints' => $dimensionSpacePoints,
            'initiatingUserId' => $envelope->event->metadata?->get('initiatingUserId'),
            'recordedAt' => $envelope->recordedAt->format(\DateTimeInterface::ATOM),
            // The originating command (short class name) and the moment it was
            // handled, from the metadata the CR keeps for rebasing. Together
            // with the user they identify one editing step: all events of one
            // command share one initiatingTimestamp, and unlike the events'
            // correlation id these survive a rebase/partial publish (which
            // re-stamps correlation ids but never touches this metadata).
            'command' => is_string($commandClass = $envelope->event->metadata?->get('commandClass'))
                ? substr($commandClass, (int)strrpos($commandClass, '\\') + 1)
                : null,
            'initiatingTimestamp' => $envelope->event->metadata?->get('initiatingTimestamp'),
        ];
    }

    /**
     * The chain of document labels from the site down to (and including) the
     * given document, e.g. ["Products", "Widgets", "Widget X"] - the path a
     * reviewer reads to place a change. The site node is excluded (the review
     * UI groups by site already); non-document ancestors are skipped.
     *
     * @return list<string>
     */
    private function documentBreadcrumb(Node $documentNode, ContentSubgraphInterface $subgraph): array
    {
        $ancestors = $subgraph->findAncestorNodes(
            $documentNode->aggregateId,
            FindAncestorNodesFilter::create(nodeTypes: 'Neos.Neos:Document')
        );
        $breadcrumb = [];
        // findAncestorNodes yields nearest-first; reverse for site-to-here order.
        foreach (array_reverse(iterator_to_array($ancestors)) as $ancestor) {
            $breadcrumb[] = $this->plainTextLabel($this->nodeLabelGenerator->getLabel($ancestor));
        }
        $breadcrumb[] = $this->plainTextLabel($this->nodeLabelGenerator->getLabel($documentNode));

        return $breadcrumb;
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

    /**
     * The "documents" list of a scoped publish/discard body ({"documents":
     * ["id", ...]}) - the document aggregate ids a review selection targets.
     * Empty when the body carries no such list.
     *
     * @return list<string>
     */
    private function getOperationDocuments(): array
    {
        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        if (!is_array($body) || !isset($body['documents']) || !is_array($body['documents'])) {
            return [];
        }

        return array_values(array_filter($body['documents'], 'is_string'));
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
