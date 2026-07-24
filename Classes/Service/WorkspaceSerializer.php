<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Model\WorkspaceRoleSubjectType;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\Model\WorkspacePermissions;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * Serializes workspaces (and their role assignments) into the API's JSON
 * representation, always from the acting account's point of view: a workspace
 * the account may not read serializes to null.
 *
 * Workspace permissions are computed once per workspace and remembered for the
 * duration of the request - they recur constantly (every serialization needs
 * them, every base workspace's write permission feeds the publish flag, and
 * read guards check them before the body is built).
 */
#[Flow\Scope('singleton')]
class WorkspaceSerializer
{
    #[Flow\Inject]
    protected ContentRepositoryAuthorizationService $authorizationService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * @var array<string, WorkspacePermissions>
     */
    private array $permissionsCache = [];

    public function permissions(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): WorkspacePermissions
    {
        return $this->permissionsCache[$workspaceName->value] ??= $this->authorizationService->getWorkspacePermissions(
            $contentRepositoryId,
            $workspaceName,
            $this->securityContext->getRoles(),
            $this->userService->getCurrentUser()?->getId()
        );
    }

    public function canRead(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): bool
    {
        return $this->permissions($contentRepositoryId, $workspaceName)->read;
    }

    /**
     * @return array<string, mixed>|null null if the account may not read the workspace
     */
    public function serialize(ContentRepositoryId $contentRepositoryId, Workspace $workspace): ?array
    {
        $permissions = $this->permissions($contentRepositoryId, $workspace->workspaceName);
        if (!$permissions->read) {
            return null;
        }

        $metadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);

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
                    && $this->permissions($contentRepositoryId, $workspace->baseWorkspaceName)->write,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializeRoleAssignments(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): array
    {
        $assignments = [];
        foreach ($this->workspaceService->getWorkspaceRoleAssignments($contentRepositoryId, $workspaceName) as $assignment) {
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
}
