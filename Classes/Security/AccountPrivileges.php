<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model\User;

/**
 * Whether the account of an access token holds a privilege, from its own roles only
 *
 * Not Flow's security context, which may hold the roles of other tokens as well, e.g. of a backend session
 */
#[Flow\Scope('singleton')]
final readonly class AccountPrivileges
{
    public const string ADMINISTRATOR_ROLE = 'Neos.Neos:Administrator';

    public function __construct(
        private PolicyService $policyService,
        private PrivilegeManagerInterface $privilegeManager,
    ) {
    }

    public function isGranted(Account $account, string $privilegeTarget): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGrantedForRoles($this->rolesOf($account), $privilegeTarget);
    }

    /**
     * Whether the roles grant the privilege, e.g. the ones an account is about to get (see rolesWith())
     *
     * @param array<string, Role> $roles
     */
    public function isGrantedForRoles(array $roles, string $privilegeTarget): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGrantedForRoles($roles, $privilegeTarget);
    }

    /**
     * What Flow's security context would hold for the account alone, e.g. for Neos' workspace permissions
     *
     * @return array<string, Role>
     */
    public function rolesOf(Account $account): array
    {
        return $this->rolesWith(array_keys($account->getRoles()));
    }

    public function isAdministrator(Account $account): bool
    {
        return self::includesAdministrator($this->rolesOf($account));
    }

    /**
     * @param array<string, Role> $roles
     */
    public static function includesAdministrator(array $roles): bool
    {
        return isset($roles[self::ADMINISTRATOR_ROLE]);
    }

    /**
     * The roles the account may give users, as in Neos' user module: all roles that aren't abstract (Flow can't
     * assign those) for administrators, else the account's own ones, parent roles included
     *
     * @return array<string, Role> by identifier
     */
    public function assignableRoles(Account $account): array
    {
        $roles = $this->isAdministrator($account) ? $this->policyService->getRoles() : $this->rolesOf($account);
        $assignable = [];
        foreach ($roles as $role) {
            if (!$role->isAbstract()) {
                $assignable[$role->getIdentifier()] = $role;
            }
        }
        return $assignable;
    }

    /**
     * Whether the account may change, delete or set the password of the user, as in Neos' user module:
     * administrators may manage everybody, other accounts the users whose roles are a subset of their own
     */
    public function mayManage(Account $account, User $user): bool
    {
        if ($this->isAdministrator($account)) {
            return true;
        }
        $roles = $this->rolesOf($account);
        foreach ($user->getAccounts() as $userAccount) {
            /** @var Account $userAccount */
            if (array_diff_key($this->rolesOf($userAccount), $roles) !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * What Flow's security context would hold for an account with these roles: the roles, their parent roles,
     * Neos.Flow:Everybody and Neos.Flow:AuthenticatedUser
     *
     * @param list<string> $roleIdentifiers existing roles
     * @return array<string, Role>
     */
    public function rolesWith(array $roleIdentifiers): array
    {
        $roles = [];
        foreach (['Neos.Flow:Everybody', 'Neos.Flow:AuthenticatedUser', ...$roleIdentifiers] as $identifier) {
            $role = $this->policyService->getRole($identifier);
            $roles[$role->getIdentifier()] = $role;
            $roles += $role->getAllParentRoles();
        }
        return $roles;
    }
}
