<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * A workspace the account may read, what else it may do in it, and which of its node aggregates it may read
 *
 * Its content graphs are the ones of the workspace's content stream when it was resolved: after a publish, discard
 * or rebase in the same request, refreshed() reads the new one
 */
#[Flow\Proxy(false)]
final class ReadableWorkspace
{
    /**
     * @var array<string, bool> whether each node aggregate is readable, by id, filled while asked
     */
    private array $readableNodeAggregates = [];

    /**
     * The base workspace's content graph once looked up, null for a root workspace or a base the content repository
     * won't let read
     */
    private ContentGraphInterface|null|false $baseContentGraph = false;

    /**
     * @param Model\WorkspacePermissions $permissions the token's account's, by its workspace roles or as the owner
     * @param array<Role> $roles the token's account's
     */
    public function __construct(
        public readonly ContentRepository $contentRepository,
        public readonly SharedModel\Workspace\Workspace $workspace,
        public readonly Model\WorkspacePermissions $permissions,
        private readonly ContentGraphInterface $contentGraph,
        private readonly array $roles,
        private readonly ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
    ) {
    }

    /**
     * The workspace's content graph, or with $base its base workspace's: null for a root workspace, which has no
     * base, or a base the content repository won't let read (the changes are compared to nothing then). Looked up
     * once per request, all changes of a page share them
     */
    public function contentGraph(bool $base = false): ContentGraphInterface|null
    {
        if (!$base) {
            return $this->contentGraph;
        }
        if ($this->baseContentGraph === false) {
            $baseWorkspaceName = $this->workspace->baseWorkspaceName;
            try {
                $this->baseContentGraph = $baseWorkspaceName !== null ? $this->contentRepository->getContentGraph($baseWorkspaceName) : null;
            } catch (AccessDenied) {
                $this->baseContentGraph = null;
            }
        }
        return $this->baseContentGraph;
    }

    /**
     * The workspace as it is now, with the same permissions: a publish, discard or rebase gives it a new content
     * stream, which the content graphs of this one don't see. Itself if the workspace is gone
     */
    public function refreshed(): self
    {
        $workspace = $this->contentRepository->findWorkspaceByName($this->workspace->workspaceName);
        if ($workspace === null) {
            return $this;
        }
        return new self($this->contentRepository, $workspace, $this->permissions, $this->contentRepository->getContentGraph($workspace->workspaceName), $this->roles, $this->contentRepositoryAuthorizationService);
    }

    /**
     * Whether the account may read one of the aggregate's nodes in the workspace: what happened to an aggregate is
     * about all of its nodes, so one readable node is enough. An aggregate that no longer exists in the workspace has
     * no permissions left, so whoever may read the workspace may read it
     */
    public function mayReadNodeAggregate(SharedModel\Node\NodeAggregateId $nodeAggregateId): bool
    {
        return $this->readableNodeAggregates[$nodeAggregateId->value] ??= (function () use ($nodeAggregateId): bool {
            $nodeAggregate = $this->contentGraph->findNodeAggregateById($nodeAggregateId);
            if ($nodeAggregate === null) {
                return true;
            }
            foreach ($nodeAggregate->getNodes() as $node) {
                if ($this->contentRepositoryAuthorizationService->getNodePermissions($node, $this->roles)->read) {
                    return true;
                }
            }
            return false;
        })();
    }
}
