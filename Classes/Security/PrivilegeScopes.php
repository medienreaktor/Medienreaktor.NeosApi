<?php
declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\OAuth\Domain\ScopeProvider;

/**
 * The scopes of the API: one per ApiPrivilege target in Policy.yaml, named by its matcher and described by its label
 *
 * So scopes and privileges can't drift apart: a token's scope lets a client use a privilege, the account's roles
 * decide whether it holds it at all
 */
#[Flow\Scope('singleton')]
final class PrivilegeScopes implements ScopeProvider
{
    /**
     * @var array<string, array{privilegeTarget: string, description: string}>|null by scope
     */
    private ?array $scopes = null;

    public function __construct(
        private readonly PolicyService $policyService,
    ) {
    }

    public function scopes(): array
    {
        return array_map(static fn (array $scope) => $scope['description'], $this->all());
    }

    /**
     * The identifiers of the ApiPrivilege targets by the scopes they stand for
     *
     * @return array<string, string>
     */
    public function privilegeTargets(): array
    {
        return array_map(static fn (array $scope) => $scope['privilegeTarget'], $this->all());
    }

    /**
     * The identifier of the ApiPrivilege target the scope stands for
     *
     * @throws \LogicException if there is none, e.g. for a typo in an operation's security
     */
    public function privilegeTargetOf(string $scope): string
    {
        return $this->all()[$scope]['privilegeTarget']
            ?? throw new \LogicException(sprintf('There is no ApiPrivilege target with the matcher "%s" in Policy.yaml', $scope), 1790200010);
    }

    /**
     * @return array<string, array{privilegeTarget: string, description: string}>
     */
    private function all(): array
    {
        if ($this->scopes !== null) {
            return $this->scopes;
        }
        $scopes = [];
        foreach ($this->policyService->getPrivilegeTargets() as $privilegeTarget) {
            if (!is_a($privilegeTarget->getPrivilegeClassName(), ApiPrivilege::class, true)) {
                continue;
            }
            $scope = $privilegeTarget->getMatcher();
            if (isset($scopes[$scope])) {
                throw new \LogicException(sprintf('The ApiPrivilege targets "%s" and "%s" have the same matcher "%s"', $scopes[$scope]['privilegeTarget'], $privilegeTarget->getIdentifier(), $scope), 1790200011);
            }
            $scopes[$scope] = ['privilegeTarget' => $privilegeTarget->getIdentifier(), 'description' => $privilegeTarget->getLabel()];
        }
        return $this->scopes = $scopes;
    }
}
