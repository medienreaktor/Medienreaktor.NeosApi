<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Parameter\NodeAggregateIds;
use Neos\Api\Endpoint\Nodes\Parameter\NodeTypeCriteria;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Domain\ChangeDiffer;
use Neos\Api\Endpoint\Workspaces\Domain\ChangeQuery;
use Neos\Api\Endpoint\Workspaces\Domain\DocumentAncestry;
use Neos\Api\Endpoint\Workspaces\Domain\EventDiffer;
use Neos\Api\Endpoint\Workspaces\Domain\EventQuery;
use Neos\Api\Endpoint\Workspaces\Domain\IncludedNodes;
use Neos\Api\Endpoint\Workspaces\Domain\ReadableWorkspace;
use Neos\Api\Endpoint\Workspaces\Domain\WorkspaceResolver;
use Neos\Api\Endpoint\Workspaces\Domain\WorkspaceRoleSubjects;
use Neos\Api\Endpoint\Workspaces\Parameter\ChangeTypes;
use Neos\Api\Endpoint\Workspaces\Parameter\EventTypes;
use Neos\Api\Endpoint\Workspaces\Parameter\SubtreeTags;
use Neos\Api\Endpoint\Workspaces\RequestBody\BaseWorkspaceChange;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceCreate;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceDiscard;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspacePublish;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceRebase;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceUpdate;
use Neos\Api\Endpoint\Workspaces\Response\WorkspaceConflict;
use Neos\Api\Endpoint\Workspaces\Response\WorkspaceCreated;
use Neos\Api\Endpoint\Workspaces\Schema\ContentStreamId;
use Neos\Api\Endpoint\Workspaces\Schema\ContentStreamPosition;
use Neos\Api\Endpoint\Workspaces\Schema\EventListingMeta;
use Neos\Api\Endpoint\Workspaces\Schema\DiscardingResult;
use Neos\Api\Endpoint\Workspaces\Schema\DocumentAggregateIdList;
use Neos\Api\Endpoint\Workspaces\Schema\PublishingResult;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedChangeListing;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedEventListing;
use Neos\Api\Endpoint\Workspaces\Schema\SequenceNumber;
use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceClassification;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceListing;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Endpoint\Workspaces\RequestBody\WorkspaceRoleAssign;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleAssignment;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleAssignmentList;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleAssignmentListing;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleSubjectId;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleSubjectType;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Infrastructure\ContentRepository\ContentStreamEvents;
use Neos\Api\Infrastructure\ContentRepository\ContentStreamEventsFactory;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Parameter\Sort;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\BaseWorkspaceDoesNotExist;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception\BaseWorkspaceEqualsWorkspaceException;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception\CircularRelationBetweenWorkspacesException;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\PartialWorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyDoesNotExist;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceContainsPublishableChanges;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushWorkspaceRequest;
use Neos\Flow\Security\Account;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\Flow\Annotations as Flow;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The workspaces of a content repository, live and the ones changes are made in before they're published, their
 * changes compared to their base workspace and their events, what was changed in them as the event store records it
 */
final readonly class Workspaces
{
    /**
     * How many events listWorkspaceEvents reads at most (Neos.Api.workspaceEvents.maxRead), see EventQuery
     */
    private int $maxEventsRead;

    /**
     * How many events before a page its diffs replay (Neos.Api.workspaceEvents.diffLookback), see EventDiffer
     */
    private int $eventDiffLookback;

    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private WorkspaceResolver $workspaceResolver,
        private WorkspacePublishingService $workspacePublishingService,
        private WorkspaceService $workspaceService,
        private ContentCacheFlusher $contentCacheFlusher,
        private ChangeDiffer $changeDiffer,
        private WorkspaceRoleSubjects $workspaceRoleSubjects,
        private ContentSubgraphs $contentSubgraphs,
        private NodeSerializer $nodeSerializer,
        #[Flow\InjectConfiguration(path: 'workspaceEvents.maxRead')]
        int|null $maxEventsRead = null,
        #[Flow\InjectConfiguration(path: 'workspaceEvents.diffLookback')]
        int|null $eventDiffLookback = null,
    ) {
        $this->maxEventsRead = $maxEventsRead ?? EventQuery::DEFAULT_MAX_READ;
        $this->eventDiffLookback = max(0, $eventDiffLookback ?? EventDiffer::DEFAULT_LOOKBACK);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces',
        method: 'GET',
        summary: 'List the workspaces',
        description: 'The workspaces of the content repository the account may read, by its workspace roles or as the owner, sorted by name, with their Neos metadata and what the account may do in them. Neos administrators may manage every workspace, but read only the ones a role grants them.',
        operationId: 'listWorkspaces',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function list(
        ContentRepositoryId $contentRepositoryId,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceListing|NotFound {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        $workspaces = [];
        foreach ($contentRepository->findWorkspaces() as $workspace) {
            $permissions = $this->workspaceResolver->permissionsOf($contentRepository->id, $workspace->workspaceName, $caller->account);
            if (!$permissions->read) {
                continue;
            }
            $workspaces[$workspace->workspaceName->value] = Workspace::from(
                $workspace,
                $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName),
                $permissions,
            );
        }
        ksort($workspaces);
        return WorkspaceListing::of(new WorkspaceList(...array_values($workspaces)));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'GET',
        summary: 'Get a workspace',
        description: 'The workspace with its Neos metadata and what the account may do in it, as listWorkspaces lists it. The workspace must be one the account may read, by its workspace roles or as the owner, else it is a 404 as an unknown one.',
        operationId: 'getWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function get(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
    ): Workspace|NotFound {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces',
        method: 'POST',
        summary: 'Create a workspace',
        description: 'Creates a workspace on the base workspace (live if left out, else one the account may read, else a 422) with Neos\' WorkspaceService, as the Neos workspace module does. A shared workspace (the default) needs a title, its name is derived from it if left out, and its visibility decides the role assignments: public (the default) makes the caller its manager and every editor a collaborator, private only the caller its manager. A personal workspace is the caller\'s own, as Neos creates it on the first login: titled and named after the user if left out, without role assignments, and only one per user and content repository, a second one is a 409 that names the first. Root and unknown workspaces can\'t be created, a visibility for a personal workspace, an empty title or an account without a Neos user are 422s, a name that is taken a 409. An unknown content repository is a 404 before anything else. The response is the new workspace as getWorkspace returns it.',
        operationId: 'createWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_CREATE],
        ],
    )]
    public function create(
        ContentRepositoryId $contentRepositoryId,
        #[RequestBody(description: 'The workspace')] WorkspaceCreate $newWorkspace,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceCreated|NotFound|Forbidden|Conflict|UnprocessableContent {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        $classification = $newWorkspace->classification();
        if ($classification !== WorkspaceClassification::SHARED && $classification !== WorkspaceClassification::PERSONAL) {
            return UnprocessableContent::because(sprintf('Only shared and personal workspaces can be created, not %s ones', $classification->value));
        }
        if ($classification === WorkspaceClassification::PERSONAL && $newWorkspace->visibility !== null) {
            return UnprocessableContent::because('A personal workspace has no visibility, only its owner may work in it');
        }
        try {
            $title = $newWorkspace->workspaceTitle();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The title must be 1 to 200 letters, digits, spaces and punctuation');
        }
        try {
            $description = $newWorkspace->workspaceDescription();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The description must be at most 500 letters, digits, spaces and punctuation');
        }
        if ($classification === WorkspaceClassification::SHARED && $title === null) {
            return UnprocessableContent::because('A shared workspace needs a title');
        }
        // the creator of a shared workspace manages it, a personal one is the owner's: both need a Neos user
        $user = $this->workspaceResolver->userOf($caller->account);
        if ($user === null) {
            return UnprocessableContent::because('The account has no Neos user to create the workspace for');
        }
        // in the body, not the path: a base the account may not read is unprocessable, as an unknown one
        $baseWorkspaceName = $newWorkspace->baseWorkspaceName();
        if (!$this->isReadable($contentRepository, $baseWorkspaceName, $caller->account)) {
            return UnprocessableContent::because(sprintf('There is no workspace %s the account may read in the content repository %s', $baseWorkspaceName->value, $contentRepositoryId->value));
        }
        if ($classification === WorkspaceClassification::PERSONAL) {
            // Neos allows one per user and content repository, found by its metadata
            try {
                $personalWorkspace = $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $user->getId());
                return Conflict::because(sprintf('The user has a personal workspace %s in the content repository %s already', $personalWorkspace->workspaceName->value, $contentRepositoryId->value));
            } catch (WorkspaceDoesNotExist) {
                // its metadata is left, createPersonalWorkspace() refuses it below
            } catch (\RuntimeException $exception) {
                if ($exception->getCode() !== 1718293801) {
                    throw $exception;
                }
                // none yet
            }
            try {
                // as Neos names a user's personal workspace, WorkspaceService::createPersonalWorkspaceForUserIfMissing()
                $title ??= Model\WorkspaceTitle::fromString($user->getLabel());
            } catch (\InvalidArgumentException) {
                return UnprocessableContent::because('The user\'s name can\'t be a workspace title, give one');
            }
        }
        if ($newWorkspace->name !== null) {
            $workspaceName = $newWorkspace->name->toWorkspaceName();
            if ($contentRepository->findWorkspaceByName($workspaceName) !== null) {
                return Conflict::because(sprintf('There is a workspace %s in the content repository %s already', $workspaceName->value, $contentRepositoryId->value));
            }
        } else {
            try {
                $workspaceName = $this->workspaceService->getUniqueWorkspaceName($contentRepository->id, $classification === WorkspaceClassification::PERSONAL ? $user->getLabel() : $title->value);
            } catch (\RuntimeException $exception) {
                // only after 10 taken names
                return Conflict::because($exception->getMessage() . ' Give a name.');
            }
        }

        try {
            if ($classification === WorkspaceClassification::PERSONAL) {
                $this->workspaceService->createPersonalWorkspace($contentRepository->id, $workspaceName, $title, $description, $baseWorkspaceName->toWorkspaceName(), $user->getId());
            } else {
                $this->workspaceService->createSharedWorkspace($contentRepository->id, $workspaceName, $title, $description, $baseWorkspaceName->toWorkspaceName(), $newWorkspace->visibility()->roleAssignmentsFor($user->getId()));
            }
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not create a workspace on the workspace %s', $baseWorkspaceName->value));
        } catch (WorkspaceAlreadyExists) {
            return Conflict::because(sprintf('There is a workspace %s in the content repository %s already', $workspaceName->value, $contentRepositoryId->value));
        } catch (BaseWorkspaceDoesNotExist) {
            return UnprocessableContent::because(sprintf('There is no workspace %s the account may read in the content repository %s', $baseWorkspaceName->value, $contentRepositoryId->value));
        } catch (\RuntimeException $exception) {
            // the user's personal workspace is gone, its metadata not: WorkspaceService still counts it
            if ($exception->getCode() === 1733754904) {
                return Conflict::because($exception->getMessage());
            }
            throw $exception;
        }

        $created = $this->workspaceResolver->resolve($contentRepositoryId, WorkspaceName::fromString($workspaceName->value), $caller->account);
        if ($created instanceof NotFound) {
            // can't happen: its manager or owner may read it
            throw new \RuntimeException(sprintf('The workspace %s was created, but the account may not read it', $workspaceName->value), 1760280001);
        }
        return new WorkspaceCreated($this->workspaceOf($created));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'PATCH',
        summary: 'Change a workspace',
        description: 'Changes the given Neos metadata of the workspace, its title and description, and leaves the others as they are. The title is trimmed and must not be empty, an empty description removes it. The base workspace is changed with changeBaseWorkspace. The workspace must be one the account may read (else it is a 404 as an unknown one) and manage, as its owner, a manager or a Neos administrator (else a 403). Root workspaces like live can\'t be changed, as in Neos\' workspace module: a 422, whoever asks.',
        operationId: 'updateWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_UPDATE],
        ],
    )]
    public function update(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'The metadata to change')] WorkspaceUpdate $patch,
    ): Workspace|NotFound|Forbidden|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never changeable, whoever asks: Neos' workspace module offers no editing for them (WorkspaceController::editAction())
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, which can\'t be changed', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        try {
            $title = $patch->newTitle();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The title must be 1 to 200 letters, digits, spaces and punctuation characters');
        }
        try {
            $description = $patch->newDescription();
        } catch (\InvalidArgumentException) {
            return UnprocessableContent::because('The description must be at most 500 letters, digits, spaces and punctuation characters');
        }
        $crId = $workspace->contentRepository->id;
        $name = $workspace->workspace->workspaceName;
        $metadata = $this->workspaceService->getWorkspaceMetadata($crId, $name);
        try {
            // only what changes
            if ($title !== null && !$title->equals($metadata->title)) {
                $this->workspaceService->setWorkspaceTitle($crId, $name, $title);
            }
            if ($description !== null && !$description->equals($metadata->description)) {
                $this->workspaceService->setWorkspaceDescription($crId, $name, $description);
            }
        } catch (AccessDenied) {
            // the service checks Flow's security context, which may know other roles than the token's account
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}',
        method: 'DELETE',
        summary: 'Delete a workspace',
        description: 'Deletes the workspace with its Neos metadata and role assignments, as the Neos workspace module does. The workspace must be one the account may read (else a 404 as an unknown one) and manage, by its workspace roles, as the owner or as a Neos administrator (else a 403). Root workspaces like live can\'t be deleted, personal workspaces only with their user: both are a 422. A workspace other workspaces are based on is a 409 that names them, they have to be deleted or based on another workspace first. A workspace with unpublished changes is a 409 as well, unless force is true: then the changes are discarded with the workspace.',
        operationId: 'deleteWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_DELETE],
        ],
    )]
    public function delete(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'Whether to delete the workspace with its unpublished changes, which are lost then. false if omitted: a workspace with changes is a 409')]
        bool $force = false,
    ): NotFound|Forbidden|UnprocessableContent|Conflict|null {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $contentRepositoryId = $workspace->contentRepository->id;
        $workspaceName = $workspace->workspace->workspaceName;
        // never deletable, whoever asks
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, which can\'t be deleted', $workspaceName->value));
        }
        if ($this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName)->classification === Model\WorkspaceClassification::PERSONAL) {
            return UnprocessableContent::because(sprintf('The workspace %s is a personal workspace, which is deleted with its user only', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        $dependants = $workspace->contentRepository->findWorkspaces()->getDependantWorkspaces($workspaceName);
        if (!$dependants->isEmpty()) {
            return Conflict::because(sprintf('The workspace %s can\'t be deleted, other workspaces are based on it: %s', $workspaceName->value, $this->namesOf($contentRepositoryId, $dependants, $caller)));
        }
        // the content repository's own check before publishing or discarding, the count of the changes is @internal
        if ($workspace->workspace->hasPublishableChanges() && !$force) {
            return Conflict::because(sprintf('The workspace %s has unpublished changes, publish or discard them first, or delete it with force=true to discard them with it', $workspaceName->value));
        }
        try {
            // removes the content stream (and with it the changes), the metadata and the role assignments
            $this->workspaceService->deleteWorkspace($contentRepositoryId, $workspaceName);
        } catch (AccessDenied) {
            // the content repository checks Flow's security context, which may know other roles than the token's account
            return Forbidden::because(sprintf('You may not delete the workspace %s', $workspaceName->value));
        }
        return null;
    }

    /**
     * The names of the workspaces the account may read, the others only counted, so they can't be probed
     */
    private function namesOf(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, SharedModel\Workspace\Workspaces $workspaces, ApiCaller $caller): string
    {
        $names = [];
        $others = 0;
        foreach ($workspaces as $workspace) {
            if ($this->workspaceResolver->permissionsOf($contentRepositoryId, $workspace->workspaceName, $caller->account)->read) {
                $names[] = $workspace->workspaceName->value;
            } else {
                $others++;
            }
        }
        sort($names);
        if ($others > 0) {
            $names[] = sprintf('%d other%s the account may not read', $others, $others === 1 ? '' : 's');
        }
        return implode(', ', $names);
    }

    /**
     * Whether the workspace (one a request body names, so a 422 if not) exists and the token's account may read it
     */
    private function isReadable(ContentRepository $contentRepository, WorkspaceName $workspaceName, Account $account): bool
    {
        return $contentRepository->findWorkspaceByName($workspaceName->toWorkspaceName()) !== null
            && $this->workspaceResolver->permissionsOf($contentRepository->id, $workspaceName->toWorkspaceName(), $account)->read;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/baseworkspace',
        method: 'PUT',
        summary: 'Change the base of a workspace',
        description: 'Bases the workspace on another workspace from now on (ChangeBaseWorkspace): its content is replaced as a whole by the new base\'s current content, and its changes will be published there. PUT as the base is one value replaced as a whole, like changeNodeType. Only a workspace without publishable changes can be rebased onto another workspace, else it is a 409: publish or discard them first. The workspace must be one the account may read (else a 404, as an unknown one) and manage (else a 403). The new base must exist and be readable by the account, and can\'t be the workspace itself or one based on it, else it is a 422, as is a root workspace like live, which has no base. The current base sends no command. The response is the workspace as getWorkspace returns it, with its new base.',
        operationId: 'changeBaseWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_UPDATE],
        ],
    )]
    public function changeBaseWorkspace(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[RequestBody(description: 'The new base workspace')] BaseWorkspaceChange $change,
        #[AuthContext] ApiCaller $caller,
    ): Workspace|NotFound|Forbidden|Conflict|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never, whoever asks
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, it has no base to change', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        // the body names it, so it's a 422, not the 404 of the path
        if (!$this->isReadable($workspace->contentRepository, $change->baseWorkspaceName, $caller->account)) {
            return UnprocessableContent::because(sprintf('There is no workspace %s in the content repository %s to base the workspace on', $change->baseWorkspaceName->value, $contentRepositoryId->value));
        }
        // the content repository would skip the command
        if ($workspace->workspace->baseWorkspaceName?->equals($change->baseWorkspaceName->toWorkspaceName())) {
            return $this->workspaceOf($workspace);
        }
        try {
            $this->workspacePublishingService->changeBaseWorkspace($workspace->contentRepository->id, $workspace->workspace->workspaceName, $change->baseWorkspaceName->toWorkspaceName());
        } catch (WorkspaceCommandSkipped) {
            // the base was changed to it meanwhile
            return $this->workspaceOf($workspace);
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not change the base of the workspace %s to %s', $workspaceName->value, $change->baseWorkspaceName->value));
        } catch (WorkspaceContainsPublishableChanges) {
            return Conflict::because(sprintf('The workspace %s has publishable changes, publish or discard them before changing its base', $workspaceName->value));
        } catch (BaseWorkspaceEqualsWorkspaceException) {
            return UnprocessableContent::because(sprintf('The workspace %s can\'t be based on itself', $workspaceName->value));
        } catch (CircularRelationBetweenWorkspacesException) {
            return UnprocessableContent::because(sprintf('The workspace %s can\'t be based on %s, which is based on it', $workspaceName->value, $change->baseWorkspaceName->value));
        }
        // Neos' cache flushing hook doesn't handle WorkspaceBaseWorkspaceWasChanged, the content of the old base would be rendered
        $this->contentCacheFlusher->flushWorkspace(
            FlushWorkspaceRequest::create($workspace->contentRepository->id, $workspace->workspace->workspaceName),
            CacheFlushingStrategy::IMMEDIATE,
        );
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/publish',
        method: 'POST',
        summary: 'Publish the changes of a workspace',
        description: 'Publishes the workspace\'s changes to its base workspace with Neos\' WorkspacePublishingService, as the Neos UI and workspace module do: all of them if the body is left out or empty, the ones in a site with siteAggregateId, or the ones in some documents with documentAggregateIds (each document with its content, not its child documents). Documents are published one after the other, the content repository has no command for several: ancestors first, as a new page needs its new parent in the base workspace. If one of them conflicts, the ones before it stay published, as in Neos\' workspace module: the 409 lists them in completedDocumentAggregateIds and counts their changes in numberOfCompletedChanges. An outdated workspace is rebased on its base workspace first: if its changes conflict with the base\'s, or the selected changes can\'t be published without others they depend on, it is a 409 that lists the conflicting changes of nodes the account may read and counts the others (inaccessibleConflicts). A selected site or document removed meanwhile by another request is a 409 as well (title removed). The workspace must be one the account may read (else a 404 as an unknown one) and write to, as must be its base workspace (else a 403). A root workspace like live has no base to publish to: a 422, whoever asks. Both siteAggregateId and documentAggregateIds, an empty documentAggregateIds, and a site or document that is no such node in the workspace the account may read are 422s. The response counts the published changes as Neos does, 0 if there were none, and is the workspace afterwards.',
        operationId: 'publishWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_PUBLISH],
        ],
    )]
    public function publish(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'Which changes to publish, all of them if left out')] WorkspacePublish $selection = new WorkspacePublish(),
    ): PublishingResult|NotFound|Forbidden|WorkspaceConflict|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never, whoever asks
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, it has no base to publish to', $workspaceName->value));
        }
        $baseWorkspaceName = $workspace->workspace->baseWorkspaceName->value;
        if (!$workspace->permissions->write) {
            return Forbidden::because(sprintf('You may not publish the workspace %s', $workspaceName->value));
        }
        // Neos' auth provider requires it as well, with Flow's security context: checked with the token's account first
        if (!$this->workspaceResolver->permissionsOf($workspace->contentRepository->id, $workspace->workspace->baseWorkspaceName, $caller->account)->write) {
            return Forbidden::because(sprintf('You may not publish to the base workspace %s', $baseWorkspaceName));
        }
        $scope = $this->changeScopeOf($workspace, $selection->siteAggregateId, $selection->documentAggregateIds);
        if ($scope instanceof UnprocessableContent) {
            return $scope;
        }
        $published = $this->publishOrDiscard($workspace, $scope, true);
        return is_int($published) ? new PublishingResult($published, $this->workspaceOf($workspace)) : $published;
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/discard',
        method: 'POST',
        summary: 'Discard the changes of a workspace',
        description: 'Discards the workspace\'s unpublished changes with Neos\' WorkspacePublishingService, as the Neos UI and workspace module do, they are lost then: all of them if the body is left out or empty, the ones in a site with siteAggregateId, or the ones in some documents with documentAggregateIds (each document with its content, not its child documents). Documents are discarded one after the other, the content repository has no command for several: descendants first, as discarding a new page first would leave its new child pages without a parent. If one of them conflicts, the ones before it stay discarded, as in Neos\' workspace module: the 409 lists them in completedDocumentAggregateIds and counts their changes in numberOfCompletedChanges. Discarding some changes replays the others on the base workspace: if they conflict with it (an outdated workspace) or depend on the discarded ones, it is a 409 that lists the conflicting changes of nodes the account may read and counts the others (inaccessibleConflicts). A selected site or document removed meanwhile by another request is a 409 as well (title removed). The workspace must be one the account may read (else a 404 as an unknown one) and write to (else a 403). A root workspace like live has no changes to discard: a 422, whoever asks. Both siteAggregateId and documentAggregateIds, an empty documentAggregateIds, and a site or document that is no such node in the workspace the account may read are 422s. The response counts the discarded changes as Neos does, 0 if there were none, and is the workspace afterwards.',
        operationId: 'discardWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_PUBLISH],
        ],
    )]
    public function discard(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'Which changes to discard, all of them if left out')] WorkspaceDiscard $selection = new WorkspaceDiscard(),
    ): DiscardingResult|NotFound|Forbidden|WorkspaceConflict|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never, whoever asks: changes are discarded by forking the base again
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, it has no changes to discard', $workspaceName->value));
        }
        if (!$workspace->permissions->write) {
            return Forbidden::because(sprintf('You may not discard the changes of the workspace %s', $workspaceName->value));
        }
        $scope = $this->changeScopeOf($workspace, $selection->siteAggregateId, $selection->documentAggregateIds);
        if ($scope instanceof UnprocessableContent) {
            return $scope;
        }
        $discarded = $this->publishOrDiscard($workspace, $scope, false);
        return is_int($discarded) ? new DiscardingResult($discarded, $this->workspaceOf($workspace)) : $discarded;
    }

    /**
     * Publishes or discards the changes of the scope (changeScopeOf()) and counts them as Neos does. Documents go one
     * by one: ancestors first for publishing (a new page needs its new parent in the base workspace), descendants
     * first for discarding (a new page must not lose its new parent while it is kept). The documents done before a
     * conflict stay so, the 409 names them
     *
     * @param SharedModel\Node\NodeAggregateId|list<SharedModel\Node\NodeAggregateId>|null $scope
     */
    private function publishOrDiscard(ReadableWorkspace $workspace, SharedModel\Node\NodeAggregateId|array|null $scope, bool $publish): int|Forbidden|WorkspaceConflict
    {
        $crId = $workspace->contentRepository->id;
        $name = $workspace->workspace->workspaceName;
        $baseName = $workspace->workspace->baseWorkspaceName?->value;
        $service = $this->workspacePublishingService;
        $count = 0;
        $completedDocuments = [];
        try {
            if ($scope === null) {
                $count = self::countUnlessSkipped(fn () => $publish
                    ? $service->publishWorkspace($crId, $name)->numberOfPublishedChanges
                    : $service->discardAllWorkspaceChanges($crId, $name)->numberOfDiscardedChanges);
            } elseif ($scope instanceof SharedModel\Node\NodeAggregateId) {
                $count = self::countUnlessSkipped(fn () => $publish
                    ? $service->publishChangesInSite($crId, $name, $scope)->numberOfPublishedChanges
                    : $service->discardChangesInSite($crId, $name, $scope)->numberOfDiscardedChanges);
            } else {
                $ancestry = DocumentAncestry::in($workspace->contentGraph());
                foreach ($publish ? $ancestry->ancestorsFirst($scope) : $ancestry->descendantsFirst($scope) as $documentId) {
                    $count += self::countUnlessSkipped(fn () => $publish
                        ? $service->publishChangesInDocument($crId, $name, $documentId)->numberOfPublishedChanges
                        : $service->discardChangesInDocument($crId, $name, $documentId)->numberOfDiscardedChanges);
                    $completedDocuments[] = $documentId;
                }
            }
        } catch (AccessDenied) {
            // Neos checks with Flow's security context, which may know other roles than the token's account
            return Forbidden::because($publish
                ? sprintf('You may not publish the workspace %s to %s', $name->value, $baseName)
                : sprintf('You may not discard the changes of the workspace %s', $name->value));
        } catch (WorkspaceRebaseFailed|PartialWorkspaceRebaseFailed $exception) {
            $detail = match (true) {
                $publish && $exception instanceof PartialWorkspaceRebaseFailed => sprintf('The selected changes of the workspace %s can\'t be published without others they depend on, publish more of them or all', $name->value),
                $publish => sprintf('The changes of the workspace %s conflict with its base workspace %s, which changed meanwhile', $name->value, $baseName),
                $exception instanceof PartialWorkspaceRebaseFailed => sprintf('The other changes of the workspace %s depend on the ones to discard, discard more of them or all', $name->value),
                default => sprintf('The changes of the workspace %s to keep conflict with its base workspace %s, which changed meanwhile', $name->value, $baseName),
            };
            // the documents done before have given the workspace a new content stream
            return WorkspaceConflict::of($exception, $completedDocuments !== [] ? $workspace->refreshed() : $workspace, $detail . self::completedDocumentsDetail($completedDocuments, $count, $publish), $completedDocuments, $count);
        } catch (NodeAggregateCurrentlyDoesNotExist) {
            // checked before, removed meanwhile
            return WorkspaceConflict::removedMeanwhile(
                sprintf('A selected site or document was removed from the workspace %s meanwhile', $name->value) . self::completedDocumentsDetail($completedDocuments, $count, $publish),
                $completedDocuments,
                $count,
            );
        }
        return $count;
    }

    /**
     * @param list<SharedModel\Node\NodeAggregateId> $completedDocuments
     */
    private static function completedDocumentsDetail(array $completedDocuments, int $count, bool $published): string
    {
        if ($completedDocuments === []) {
            return '';
        }
        return sprintf(
            '. The changes of the documents %s were %s before (%d changes, see completedDocumentAggregateIds)',
            implode(', ', array_map(static fn (SharedModel\Node\NodeAggregateId $id) => $id->value, $completedDocuments)),
            $published ? 'published' : 'discarded',
            $count,
        );
    }

    /**
     * The changes a publish or discard is about: null for all of them, a site, or documents (each once, in the order
     * given), each one a node of that type in the workspace the account may read, else a 422. A removed node is still
     * in the workspace (tagged), so its removal can be published or discarded
     *
     * @return SharedModel\Node\NodeAggregateId|list<SharedModel\Node\NodeAggregateId>|UnprocessableContent|null
     */
    private function changeScopeOf(ReadableWorkspace $workspace, ?NodeAggregateId $siteAggregateId, ?DocumentAggregateIdList $documentAggregateIds): SharedModel\Node\NodeAggregateId|array|UnprocessableContent|null
    {
        if ($siteAggregateId !== null && $documentAggregateIds !== null) {
            return UnprocessableContent::because('Give siteAggregateId or documentAggregateIds, not both');
        }
        $workspaceName = $workspace->workspace->workspaceName->value;
        if ($siteAggregateId !== null) {
            $siteId = $siteAggregateId->toNodeAggregateId();
            if (!$this->isReadableNodeOfType($workspace, $siteId, NodeTypeNameFactory::forSite())) {
                return UnprocessableContent::because(sprintf('There is no site %s in the workspace %s', $siteId->value, $workspaceName));
            }
            return $siteId;
        }
        if ($documentAggregateIds === null) {
            return null;
        }
        if ($documentAggregateIds->documentAggregateIds === []) {
            return UnprocessableContent::because('documentAggregateIds needs at least one document, leave it out for all changes');
        }
        $documentIds = [];
        foreach ($documentAggregateIds as $documentAggregateId) {
            $documentId = $documentAggregateId->toNodeAggregateId();
            if (!$this->isReadableNodeOfType($workspace, $documentId, NodeTypeNameFactory::forDocument())) {
                return UnprocessableContent::because(sprintf('There is no document %s in the workspace %s', $documentId->value, $workspaceName));
            }
            $documentIds[$documentId->value] = $documentId;
        }
        return array_values($documentIds);
    }

    /**
     * Whether the node aggregate exists in the workspace, is of the type, and the account may read it
     */
    private function isReadableNodeOfType(ReadableWorkspace $workspace, SharedModel\Node\NodeAggregateId $nodeAggregateId, NodeTypeName $nodeTypeName): bool
    {
        $nodeAggregate = $workspace->contentRepository->getContentGraph($workspace->workspace->workspaceName)->findNodeAggregateById($nodeAggregateId);
        return $nodeAggregate !== null
            && $workspace->contentRepository->getNodeTypeManager()->getNodeType($nodeAggregate->nodeTypeName)?->isOfType($nodeTypeName) === true
            && $workspace->mayReadNodeAggregate($nodeAggregateId);
    }

    /**
     * The changes a publish or discard counted, 0 if the content repository skipped it as there were none (in the
     * selection)
     *
     * @param \Closure(): int $publishOrDiscard
     */
    private static function countUnlessSkipped(\Closure $publishOrDiscard): int
    {
        try {
            return $publishOrDiscard();
        } catch (WorkspaceCommandSkipped) {
            return 0;
        }
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/rebase',
        method: 'POST',
        summary: 'Rebase a workspace',
        description: 'Brings the workspace up to date with its base workspace (RebaseWorkspace through Neos\' WorkspacePublishingService, as the Neos UI\'s synchronize does): its changes are applied again on top of the base\'s current content, so it sees what was published there since. The body is optional, its strategy is fail if left out: changes that conflict with the base (e.g. creating a node with an ID the base has meanwhile, or changing a node removed there for good; a node deleteNode removed there is only tagged and doesn\'t conflict) fail the rebase with a 409 that lists them (the ones of nodes the account may read, the others are counted in inaccessibleConflicts), and nothing is changed. force rebases anyway and drops the conflicting changes for good, the others are kept. The workspace must be one the account may read (else a 404, as an unknown one) and write to, as a collaborator, manager or owner (else a 403). Root workspaces like live have no base to rebase on, a 422 whoever asks. A workspace that is up to date already is a 200 as well, with fail nothing happens. The response is the workspace as getWorkspace returns it, up to date.',
        operationId: 'rebaseWorkspace',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_PUBLISH],
        ],
    )]
    public function rebase(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'How to rebase, optional: with strategy fail if left out')] WorkspaceRebase $rebase = new WorkspaceRebase(),
    ): Workspace|NotFound|Forbidden|WorkspaceConflict|UnprocessableContent {
        $strategy = $rebase->strategy();
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        // never, whoever asks
        if ($workspace->workspace->isRootWorkspace()) {
            return UnprocessableContent::because(sprintf('The workspace %s is a root workspace, it has no base to rebase on', $workspaceName->value));
        }
        if (!$workspace->permissions->write) {
            return Forbidden::because(sprintf('You may not write to the workspace %s', $workspaceName->value));
        }
        try {
            $this->workspacePublishingService->rebaseWorkspace($workspace->contentRepository->id, $workspace->workspace->workspaceName, $strategy->toRebaseErrorHandlingStrategy());
        } catch (WorkspaceCommandSkipped) {
            // up to date already, only fail skips it: force rebases anyway
            return $this->workspaceOf($workspace);
        } catch (AccessDenied) {
            // the content repository checks Flow's security context, which may know other roles than the token's account
            return Forbidden::because(sprintf('You may not write to the workspace %s', $workspaceName->value));
        } catch (WorkspaceRebaseFailed $exception) {
            // nothing changed, the workspace has its content stream still
            return WorkspaceConflict::of($exception, $workspace, sprintf('The changes of the workspace %s conflict with its base workspace %s, nothing was rebased. Rebase with strategy force to drop the conflicting changes and keep the others.', $workspaceName->value, $workspace->workspace->baseWorkspaceName?->value));
        }
        return $this->workspaceOf($workspace);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/roles',
        method: 'GET',
        summary: 'List the roles of a workspace',
        description: 'Who may do what in the workspace: its role assignments, each a user (by its Neos user ID) or a group (every account with a Flow role, by its identifier) with the role viewer, collaborator or manager, sorted by subject type and ID. The owner of a personal workspace has no assignment, it may do everything anyway. The workspace must be one the account may read (else a 404, as an unknown one) and manage, as its owner, a manager or a Neos administrator (else a 403): the assignments tell who works on what, so only who may change them sees them, as in the Neos workspace module.',
        operationId: 'listWorkspaceRoles',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function listRoles(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceRoleAssignmentListing|NotFound|Forbidden {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s, only its managers see its roles', $workspaceName->value));
        }
        $assignments = array_map(
            WorkspaceRoleAssignment::from(...),
            iterator_to_array($this->workspaceService->getWorkspaceRoleAssignments($workspace->contentRepository->id, $workspace->workspace->workspaceName), false),
        );
        // the repository sorts by its own (uppercase) values, the order is the API's to keep
        usort($assignments, static fn (WorkspaceRoleAssignment $a, WorkspaceRoleAssignment $b) => [$a->subject->type->value, $a->subject->id->value] <=> [$b->subject->type->value, $b->subject->id->value]);
        return WorkspaceRoleAssignmentListing::of(new WorkspaceRoleAssignmentList(...$assignments));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/roles/{subjectType}/{subjectId}',
        method: 'PUT',
        summary: 'Assign a workspace role',
        description: 'Gives the user (subjectType user, subjectId its Neos user ID) or the group (subjectType group, subjectId a Flow role identifier like Neos.Neos:AbstractEditor, every account with that role) the role in the workspace: viewer (may read it), collaborator (may also change its content and publish or discard the changes) or manager (may also change its title, description and roles and delete it). A subject has one role per workspace: assigning another one replaces it (Neos unassigns and assigns, if the assignment fails the previous role is given back), the same one changes nothing. A role changed by another request meanwhile is a 409. An unknown user or Flow role is a 422. The workspace must be one the account may read (else a 404, as an unknown one) and manage, as its owner, a manager or a Neos administrator (else a 403). Personal workspaces are their owner\'s only, their roles can\'t be assigned, as in the Neos workspace module: a 422, whoever asks. Your own user\'s role can\'t be lowered, so you don\'t lock yourself out, ask another manager (a 422). The response is the assignment.',
        operationId: 'assignWorkspaceRole',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_ROLES],
        ],
    )]
    public function assignRole(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        WorkspaceRoleSubjectType $subjectType,
        WorkspaceRoleSubjectId $subjectId,
        #[RequestBody(description: 'The role')] WorkspaceRoleAssign $assignment,
        #[AuthContext] ApiCaller $caller,
    ): WorkspaceRoleAssignment|NotFound|Forbidden|Conflict|UnprocessableContent {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $crId = $workspace->contentRepository->id;
        $name = $workspace->workspace->workspaceName;
        // never, whoever asks: Neos' workspace module offers no role assignments for them, though WorkspaceService would allow it
        if ($this->workspaceService->getWorkspaceMetadata($crId, $name)->classification === Model\WorkspaceClassification::PERSONAL) {
            return UnprocessableContent::because(sprintf('The workspace %s is a personal workspace, only its owner works in it. Share a shared workspace instead', $workspaceName->value));
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        $roleSubject = $this->workspaceRoleSubjects->existingSubjectOf($subjectType, $subjectId);
        if ($roleSubject === null) {
            return UnprocessableContent::because($subjectType === WorkspaceRoleSubjectType::USER
                ? sprintf('There is no Neos user with the ID %s', $subjectId->value)
                : sprintf('There is no Flow role %s', $subjectId->value));
        }
        $role = $assignment->role->toWorkspaceRole();
        $existing = $this->roleAssignmentOf($crId, $name, $roleSubject);
        if ($existing?->role === $role) {
            return WorkspaceRoleAssignment::from($existing);
        }
        if ($existing !== null && !$role->isAtLeast($existing->role) && $this->isCallersUser($roleSubject, $caller)) {
            return UnprocessableContent::because('You can\'t lower your own role in the workspace, ask another manager');
        }
        $newAssignment = Model\WorkspaceRoleAssignment::create($roleSubject, $role);
        // whether the previous role was given back after the new one couldn't be assigned, null if there was none
        $restored = null;
        try {
            // Neos has one role per subject, a change is replacing it
            if ($existing !== null) {
                $this->workspaceService->unassignWorkspaceRole($crId, $name, $roleSubject);
            }
            try {
                $this->workspaceService->assignWorkspaceRole($crId, $name, $newAssignment);
            } catch (AccessDenied|\RuntimeException $exception) {
                // two calls, not atomic: the subject gets its previous role back
                if ($existing !== null) {
                    $restored = $this->reassign($crId, $name, $existing);
                }
                throw $exception;
            }
        } catch (AccessDenied) {
            // the service checks Flow's security context, which may know other roles than the token's account
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        } catch (\RuntimeException $exception) {
            // assigned or unassigned by another request meanwhile
            if (in_array($exception->getCode(), [1728476154, 1728477071], true)) {
                return Conflict::because(sprintf(
                    'The role of %s %s in the workspace %s was changed meanwhile, try again%s',
                    $subjectType->value,
                    $subjectId->value,
                    $workspaceName->value,
                    $restored === false ? sprintf('. Its role %s was unassigned and couldn\'t be given back', strtolower($existing->role->value)) : '',
                ));
            }
            throw $exception;
        }
        return WorkspaceRoleAssignment::from($newAssignment);
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/roles/{subjectType}/{subjectId}',
        method: 'DELETE',
        summary: 'Unassign a workspace role',
        description: 'Takes the role in the workspace from the user (subjectType user, subjectId its Neos user ID) or group (subjectType group, subjectId a Flow role identifier): it may then do in the workspace only what another of its roles allows. A subject without a role in the workspace is a 404. The user or Flow role needn\'t exist anymore, so assignments they left behind can be removed. The workspace must be one the account may read (else a 404, as an unknown one) and manage, as its owner, a manager or a Neos administrator (else a 403). Your own user\'s role can\'t be removed, so you don\'t lock yourself out, as in the Neos workspace module: ask another manager (a 422).',
        operationId: 'unassignWorkspaceRole',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_ROLES],
        ],
    )]
    public function unassignRole(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        WorkspaceRoleSubjectType $subjectType,
        WorkspaceRoleSubjectId $subjectId,
        #[AuthContext] ApiCaller $caller,
    ): NotFound|Forbidden|UnprocessableContent|null {
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        if (!$workspace->permissions->manage) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        }
        $crId = $workspace->contentRepository->id;
        $name = $workspace->workspace->workspaceName;
        $notFound = NotFound::because(sprintf('The %s %s has no role in the workspace %s', $subjectType->value, $subjectId->value, $workspaceName->value));
        $roleSubject = $this->workspaceRoleSubjects->subjectOf($subjectType, $subjectId);
        if ($roleSubject === null || $this->roleAssignmentOf($crId, $name, $roleSubject) === null) {
            return $notFound;
        }
        if ($this->isCallersUser($roleSubject, $caller)) {
            return UnprocessableContent::because('You can\'t remove your own role in the workspace, ask another manager');
        }
        try {
            $this->workspaceService->unassignWorkspaceRole($crId, $name, $roleSubject);
        } catch (AccessDenied) {
            return Forbidden::because(sprintf('You may not manage the workspace %s', $workspaceName->value));
        } catch (\RuntimeException $exception) {
            // unassigned by another request meanwhile
            if ($exception->getCode() === 1728477071) {
                return $notFound;
            }
            throw $exception;
        }
        return null;
    }

    /**
     * Assigns the role again, whether that worked: not if another request assigned one meanwhile
     */
    private function reassign(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, SharedModel\Workspace\WorkspaceName $workspaceName, Model\WorkspaceRoleAssignment $assignment): bool
    {
        try {
            $this->workspaceService->assignWorkspaceRole($contentRepositoryId, $workspaceName, $assignment);
            return true;
        } catch (AccessDenied|\RuntimeException) {
            return false;
        }
    }

    private function roleAssignmentOf(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, SharedModel\Workspace\WorkspaceName $workspaceName, Model\WorkspaceRoleSubject $subject): Model\WorkspaceRoleAssignment|null
    {
        foreach ($this->workspaceService->getWorkspaceRoleAssignments($contentRepositoryId, $workspaceName) as $assignment) {
            if ($assignment->subject->equals($subject)) {
                return $assignment;
            }
        }
        return null;
    }

    private function isCallersUser(Model\WorkspaceRoleSubject $subject, ApiCaller $caller): bool
    {
        $user = $this->workspaceResolver->userOf($caller->account);
        return $user !== null && $subject->equals(Model\WorkspaceRoleSubject::createForUser($user->getId()));
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/changes',
        method: 'GET',
        summary: 'List the changes of a workspace',
        description: 'What changed in the workspace compared to its base workspace and isn\'t published yet, one item per node variant (or per node aggregate for changes to all of its variants, like its name or node type), as Neos\' pending changes projection keeps it, sorted by documentAggregateId (the changes of one document together, the ones without a document last), then by aggregate id and dimension space point. documentAggregateId and siteAggregateId are the closest document (the node itself if it is one) and the site of the node in the workspace, found also for hidden and deleted nodes (in the base workspace for a node removed for good), e.g. to group the changes by document as the Neos workspace module does. filterByDocument keeps the changes in a document (of the document itself and its content, not of its child documents), filterBySite the ones in a site, filterByChangeType the ones with one of the kinds of change: filterByChangeType=deleted is the workspace\'s trash, the nodes deleted in it and not published yet, which restoreNode brings back. filterByNodeType the changes of nodes of these node types, e.g. Neos.Neos:Document with filterByChangeType=deleted for the deleted pages. The filters combine. include=diff adds what each change of the page does to its node compared to the base workspace (diff, null if not included). include=node adds the changed node and include=document the node of documentAggregateId, as getNode returns them: in the change\'s origin dimension space point (one the aggregate covers for a change to the whole aggregate), deleted nodes included, else from the base workspace (removed for good in the workspace, its workspaceName tells), null if there is none the account may read. node.ancestors and document.ancestors add their ancestors as getNode\'s include=ancestors, e.g. for a breadcrumb. The workspace must be one the account may read, by its workspace roles or as the owner. Changes of a node the account may not read are left out. Changes of a node that no longer exists in the workspace are listed: it has no permissions left to check. offset and limit (25 by default, 100 at most) choose the page of the filtered changes, meta.total counts them, links tell about the other pages.',
        operationId: 'listWorkspaceChanges',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function listChanges(
        ServerRequestInterface $request,
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'Only the changes in the document with this aggregate id: of the document itself and its content, not of its child documents (the changes whose documentAggregateId it is)')]
        NodeAggregateId|null $filterByDocument = null,
        #[Parameter(in: 'query', description: 'Only the changes in the site with this aggregate id (the changes whose siteAggregateId it is)')]
        NodeAggregateId|null $filterBySite = null,
        #[Parameter(in: 'query', description: 'Only the changes with one of these kinds of change, comma-separated: created, changed, moved, deleted. deleted is the workspace\'s trash: the nodes deleted in it and not published yet')]
        ChangeTypes|null $filterByChangeType = null,
        #[Parameter(in: 'query', description: 'Only the changes of nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type, as filterByNodeType of listNodes. Unknown node types are a 400')]
        NodeTypeCriteria|null $filterByNodeType = null,
        #[Parameter(in: 'query', description: 'What to include beyond each change\'s own fields, comma-separated: diff, what the change does to its node compared to the base workspace, node, the changed node, document, its document, node.ancestors and document.ancestors, their ancestors')]
        IncludePaths|null $include = null,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
    ): PaginatedChangeListing|BadRequest|NotFound {
        $query = ChangeQuery::create($filterByDocument, $filterBySite, $filterByChangeType, $filterByNodeType, $include ?? IncludePaths::none(), $offset ?? Offset::none(), $limit ?? Limit::default());
        if ($query instanceof BadRequest) {
            return $query;
        }
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        return $query->page(
            $request,
            $this->workspacePublishingService->pendingWorkspaceChanges($workspace->contentRepository->id, $workspace->workspace->workspaceName),
            $workspace,
            $this->changeDiffer,
            IncludedNodes::for($query->include(), $workspace, $this->contentSubgraphs, $this->nodeSerializer, $this->contentRepositoryRegistry),
        );
    }

    #[Operation(
        path: '/cr/{contentRepositoryId}/workspaces/{workspaceName}/events',
        method: 'GET',
        summary: 'List the events of a workspace',
        description: 'The events of the workspace\'s current content stream: what was changed in it since it was created or last published, discarded or rebased, which each start a new content stream. Oldest first, sort=-sequenceNumber for newest first (sequenceNumber is the only field to sort by). filterByEventType keeps the events of these types, filterByNodeAggregateId the ones about these nodes (the payload\'s nodeAggregateId), filterByDocument the ones in a document (about the document itself and its content, not its child documents: the closest document of the payload\'s node now, as documentAggregateId of the changes), filterByTag the SubtreeWasTagged and SubtreeWasUntagged events of these tags. The filters combine, e.g. filterByTag=removed&filterByEventType=SubtreeWasTagged&filterByNodeAggregateId=… and sort=-sequenceNumber for when and by whom (metadata.initiatingUserId) nodes were deleted. A request reads at most 10000 events (Neos.Api.workspaceEvents.maxRead), and a page with include=diff spans at most as many sequence numbers: a page that gets there ends before it is full, filtered ones may even be empty, and links.next continues after the last event read. So a client follows links.next (newest first until it is null), it doesn\'t stop at a short or empty page or take after from the last event listed. The workspace must be one the account may read, by its workspace roles or as the owner. Events about a node the account may not read are left out. Events about a node that no longer exists in the workspace, e.g. its removal, are listed: it has no permissions left to check. In the events listed, the IDs of other nodes the account may not read (parent, siblings, reference targets, tethered children: every value of a payload key ending in nodeAggregateId or nodeAggregateIds) are null. after and limit (25 by default, 100 at most) choose the page: after is a sequenceNumber, the page has the events after it in the listing\'s order (so newer events oldest first, older ones newest first), links.next has the one of the last event read. Newest first, links.next continues after this page and is null on the last one. Oldest first, links.next is always there, after the last event read or, if there was none, with the same after: a client polling for new events follows it. meta.contentStreamId is the content stream the events are from, meta.forkedFrom where it branched off its base workspace\'s (null for one that wasn\'t forked, e.g. live\'s). include=diff adds what each event changed about its node compared to just before it (diff, null if not included): the value before is the one the latest earlier event of the content stream set (looking back 1000 events before the page at most, Neos.Api.workspaceEvents.diffLookback), else the base workspace\'s current one; where neither has it (no base workspace, as for live, or a node created before the events looked back at) it is unknown and diff.complete is false. include=node adds the node of the payload\'s nodeAggregateId and include=document its closest document, as getNode returns them now (not as they were then): in the dimension space point the event is about (a new variant\'s origin, the node\'s origin, else the first it affects, else one the aggregate covers), deleted nodes included, else from the base workspace, null if there is none the account may read; node.ancestors and document.ancestors add their ancestors. An after lower than the sequenceNumber of the current content stream\'s first event is from an earlier one: the workspace was published, discarded or rebased since, the events the client has are no longer the workspace\'s, and it is a 409, the client starts over without after.',
        operationId: 'listWorkspaceEvents',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::WORKSPACES_READ],
        ],
    )]
    public function listEvents(
        ServerRequestInterface $request,
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'The sequenceNumber of the last event of the page before in the listing\'s order (newer events oldest first, older ones newest first), the first page if omitted')]
        SequenceNumber|null $after = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
        #[Parameter(in: 'query', description: 'sequenceNumber (oldest first, the default) or -sequenceNumber (newest first)')]
        Sort|null $sort = null,
        #[Parameter(in: 'query', description: 'What to include beyond each event\'s own fields, comma-separated: diff, what the event changed about its node, node, the node, document, its closest document, node.ancestors and document.ancestors, their ancestors')]
        IncludePaths|null $include = null,
        #[Parameter(in: 'query', description: 'Only the events of these types, comma-separated, the short names of the event classes as an event\'s type, e.g. SubtreeWasTagged,SubtreeWasUntagged')]
        EventTypes|null $filterByEventType = null,
        #[Parameter(in: 'query', description: 'Only the events about the nodes with these aggregate ids (the payload\'s nodeAggregateId), comma-separated, at most 100')]
        NodeAggregateIds|null $filterByNodeAggregateId = null,
        #[Parameter(in: 'query', description: 'Only the events in the document with this aggregate id: about the document itself and its content, not its child documents (the closest document of the payload\'s node now, as filterByDocument of listWorkspaceChanges)')]
        NodeAggregateId|null $filterByDocument = null,
        #[Parameter(in: 'query', description: 'Only the SubtreeWasTagged and SubtreeWasUntagged events of these tags, comma-separated, e.g. removed for deletions and restorations, disabled for hiding and showing')]
        SubtreeTags|null $filterByTag = null,
    ): PaginatedEventListing|BadRequest|NotFound|Conflict {
        $query = EventQuery::create($after, $limit ?? Limit::default(), $sort, $include ?? IncludePaths::none(), $filterByEventType, $filterByNodeAggregateId, $filterByDocument, $filterByTag, $this->maxEventsRead);
        if ($query instanceof BadRequest) {
            return $query;
        }
        $workspace = $this->workspaceResolver->resolve($contentRepositoryId, $workspaceName, $caller->account);
        if ($workspace instanceof NotFound) {
            return $workspace;
        }
        $contentStreamId = $workspace->workspace->currentContentStreamId;
        $contentStreamEvents = $this->contentRepositoryRegistry->buildService($workspace->contentRepository->id, new ContentStreamEventsFactory());
        $firstEvent = $contentStreamEvents->findFirst($contentStreamId);
        if ($query->isBefore($firstEvent)) {
            return Conflict::because(sprintf('The events after %d are from an earlier content stream of the workspace %s: it was published, discarded or rebased since, its events start at %d. Start over without after', $after?->value, $workspaceName->value, $firstEvent?->sequenceNumber->value));
        }
        $fork = $firstEvent !== null ? ContentStreamEvents::forkOf($firstEvent) : null;
        $meta = new EventListingMeta(ContentStreamId::from($contentStreamId), $fork !== null ? ContentStreamPosition::from($fork) : null);
        $eventTypes = $query->eventTypes();
        return $query->page(
            $request,
            $contentStreamEvents->load($contentStreamId),
            $eventTypes !== null ? $contentStreamEvents->load($contentStreamId, $eventTypes) : null,
            $workspace,
            $meta,
            IncludedNodes::for($query->include(), $workspace, $this->contentSubgraphs, $this->nodeSerializer, $this->contentRepositoryRegistry),
            new EventDiffer($workspace, $this->nodeSerializer, $this->eventDiffLookback),
        );
    }

    /**
     * The workspace as it is now (re-read, so it reflects a change made in this request), with its metadata and the
     * account's permissions
     */
    private function workspaceOf(ReadableWorkspace $workspace): Workspace
    {
        $contentRepositoryId = $workspace->contentRepository->id;
        $workspaceName = $workspace->workspace->workspaceName;
        return Workspace::from(
            $workspace->contentRepository->findWorkspaceByName($workspaceName) ?? $workspace->workspace,
            $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName),
            $workspace->permissions,
        );
    }
}
