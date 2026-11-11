<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\ContentRepositoryFinder;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Neos\Domain\Model;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Party\Domain\Service\PartyService;

/**
 * The content repository and workspace of a request → the workspace as the token's account may read it, or the 404
 *
 * Also what the token's account may do in a workspace: always its own roles and Neos user, as for its privileges, not
 * Flow's security context
 */
#[Flow\Scope('singleton')]
final readonly class WorkspaceResolver
{
    public function __construct(
        private ContentRepositoryFinder $contentRepositoryFinder,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private AccountPrivileges $accountPrivileges,
        private PartyService $partyService,
    ) {
    }

    /**
     * The same 404 for a workspace the account may not read as for an unknown one, so workspaces can't be probed
     */
    public function resolve(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, Account $account): ReadableWorkspace|NotFound
    {
        $contentRepository = $this->contentRepositoryFinder->find($contentRepositoryId->toContentRepositoryId());
        if ($contentRepository === null) {
            return NotFound::contentRepository($contentRepositoryId->value);
        }
        $notFound = NotFound::because(sprintf('There is no workspace %s in the content repository %s', $workspaceName->value, $contentRepositoryId->value));
        $workspace = $contentRepository->findWorkspaceByName($workspaceName->toWorkspaceName());
        if ($workspace === null) {
            return $notFound;
        }
        $permissions = $this->permissionsOf($contentRepository->id, $workspace->workspaceName, $account);
        if (!$permissions->read) {
            return $notFound;
        }
        try {
            $contentGraph = $contentRepository->getContentGraph($workspace->workspaceName);
        } catch (AccessDenied) {
            return $notFound;
        }
        return new ReadableWorkspace($contentRepository, $workspace, $permissions, $contentGraph, $this->accountPrivileges->rolesOf($account), $this->contentRepositoryAuthorizationService);
    }

    /**
     * What the token's account may do in the workspace, by its workspace roles or as the owner (whether the
     * workspace exists or not)
     */
    public function permissionsOf(SharedModel\ContentRepository\ContentRepositoryId $contentRepositoryId, SharedModel\Workspace\WorkspaceName $workspaceName, Account $account): Model\WorkspacePermissions
    {
        return $this->contentRepositoryAuthorizationService->getWorkspacePermissions(
            $contentRepositoryId,
            $workspaceName,
            $this->accountPrivileges->rolesOf($account),
            $this->userOf($account)?->getId(),
        );
    }

    /**
     * The account's Neos user, null for an account without one (PartyService caches it for the request)
     */
    public function userOf(Account $account): Model\User|null
    {
        $user = $this->partyService->getAssignedPartyOfAccount($account);
        return $user instanceof Model\User ? $user : null;
    }
}
