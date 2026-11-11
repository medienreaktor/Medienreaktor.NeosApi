<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleSubjectId;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRoleSubjectType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\UserService;

/**
 * The subject of a workspace role in a request (type and ID in the path) → Neos' subject
 */
#[Flow\Scope('singleton')]
final readonly class WorkspaceRoleSubjects
{
    public function __construct(
        private UserService $userService,
        private PolicyService $policyService,
    ) {
    }

    /**
     * The subject as Neos has it, whether the user or Flow role exists or not (an assignment may outlive it), null if
     * it can't be one
     */
    public function subjectOf(WorkspaceRoleSubjectType $type, WorkspaceRoleSubjectId $id): Model\WorkspaceRoleSubject|null
    {
        try {
            return Model\WorkspaceRoleSubject::create(Model\WorkspaceRoleSubjectType::from(strtoupper($type->value)), $id->value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The subject if its Neos user or Flow role exists (abstract roles like Neos.Neos:AbstractEditor included, Neos
     * assigns them itself), null else
     */
    public function existingSubjectOf(WorkspaceRoleSubjectType $type, WorkspaceRoleSubjectId $id): Model\WorkspaceRoleSubject|null
    {
        $exists = match ($type) {
            WorkspaceRoleSubjectType::USER => $this->userExists($id->value),
            WorkspaceRoleSubjectType::GROUP => $this->policyService->hasRole($id->value),
        };
        return $exists ? $this->subjectOf($type, $id) : null;
    }

    private function userExists(string $userId): bool
    {
        try {
            return $this->userService->findUserById(Model\UserId::fromString($userId)) !== null;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
