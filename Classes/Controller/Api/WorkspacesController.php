<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\WorkspaceMetadata;
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
            $this->workspacePublishingService->rebaseWorkspace($this->getContentRepositoryId(), $workspace, $strategy);

            return ['rebased' => true];
        });
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
            'permissions' => [
                'read' => $permissions->read,
                'write' => $permissions->write,
                'manage' => $permissions->manage,
            ],
        ];
    }
}
