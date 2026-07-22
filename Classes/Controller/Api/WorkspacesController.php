<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
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
