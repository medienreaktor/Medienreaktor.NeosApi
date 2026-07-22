<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Exception\NoSuchRoleException;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;

/**
 * Backend user administration - the resource behind the Studio's user
 * administration, replacing the classic Users backend module.
 *
 * Authorization is split by operation in Policy.yaml: the read side
 * (Api.Users.Read: index, show) is granted to every editor - the listing
 * doubles as the name roster for collaboration presence. The write side plus
 * the assignable-role catalog (Api.Users.Write: create, update, delete,
 * roles) is granted to administrators only, matching the classic backend
 * module. The Studio mirrors that with the accountPermissions "users" flag
 * from /me.
 *
 * Lockout guards: the acting administrator cannot delete or deactivate their
 * own user, nor drop their own Administrator role - otherwise a single
 * mis-click locks the last admin out of user administration entirely.
 */
class UsersController extends AbstractApiController
{
    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PolicyService $policyService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $currentUserId = $this->userService->getCurrentUser()?->getId()->value;

        $users = [];
        foreach ($this->userService->getUsers() as $user) {
            $users[] = $this->serializeUser($user, $currentUserId);
        }

        return $this->json(['users' => $users]);
    }

    public function showAction(string $userId): string
    {
        $this->requireScope('neos.read');

        $user = $this->requireUser($userId);
        $currentUserId = $this->userService->getCurrentUser()?->getId()->value;

        return $this->json(['user' => $this->serializeUser($user, $currentUserId)]);
    }

    /**
     * The roles an administrator can assign to a user: every non-abstract
     * role known to the policy framework, sorted by label. Part of the
     * administration feature (Api.Users.Write), not the editor-facing read
     * side - the catalog only matters for the role picker.
     */
    public function rolesAction(): string
    {
        $this->requireScope('neos.read');

        $roles = array_filter(
            $this->policyService->getRoles(),
            static fn (Role $role) => !$role->isAbstract()
        );
        usort($roles, static fn (Role $a, Role $b) => strcasecmp($a->getLabel(), $b->getLabel()));

        return $this->json([
            'roles' => array_map(static fn (Role $role) => [
                'identifier' => $role->getIdentifier(),
                'label' => $role->getLabel(),
                'packageKey' => $role->getPackageKey(),
            ], $roles),
        ]);
    }

    /**
     * Create a user with one account on the default authentication provider.
     * JSON body: username, password, firstName, lastName, roles?, email?.
     *
     * @param array<int, string>|null $roles
     */
    #[Flow\SkipCsrfProtection]
    public function createAction(
        string $username,
        string $password,
        string $firstName,
        string $lastName,
        ?array $roles = null,
        ?string $email = null
    ): string {
        $this->requireScope('neos.write');

        $username = trim($username);
        if ($username === '') {
            $this->throwJsonStatus(400, 'invalid_username', 'The username must not be empty.');
        }
        if ($password === '') {
            $this->throwJsonStatus(400, 'invalid_password', 'The password must not be empty.');
        }
        if (trim($firstName) === '' || trim($lastName) === '') {
            $this->throwJsonStatus(400, 'invalid_name', 'First name and last name must not be empty.');
        }
        if ($this->userService->getUser($username) !== null) {
            $this->throwJsonStatus(409, 'user_exists', sprintf('A user with the username "%s" already exists.', $username));
        }

        // Validate everything BEFORE mutating: Flow flushes in-memory entity
        // changes at the end of the request even when an action aborts via
        // throwStatus, so a late validation error must not leave partial
        // writes behind.
        $roleIdentifiers = $roles !== null ? $this->validateRoles($roles) : null;
        $email = $email !== null ? trim($email) : null;
        $this->validateEmail($email);

        $user = $this->userService->createUser($username, $password, trim($firstName), trim($lastName), $roleIdentifiers);

        if ($email !== null && $email !== '') {
            $this->setPrimaryEmail($user, $email);
            $this->userService->updateUser($user);
        }

        $this->persistenceManager->persistAll();

        $currentUserId = $this->userService->getCurrentUser()?->getId()->value;

        return $this->json(['user' => $this->serializeUser($user, $currentUserId)], 201);
    }

    /**
     * Partial update of a user. JSON body: firstName, lastName, email, roles,
     * active, password - absent keys are left as-is. An empty email string
     * removes the address; "password" sets a new one without requiring the
     * old (administrative reset); "roles" replaces the assigned roles on all
     * of the user's accounts.
     *
     * @param array<int, string>|null $roles
     */
    #[Flow\SkipCsrfProtection]
    public function updateAction(
        string $userId,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $email = null,
        ?array $roles = null,
        ?bool $active = null,
        ?string $password = null
    ): string {
        $this->requireScope('neos.write');

        $user = $this->requireUser($userId);
        $currentUserId = $this->userService->getCurrentUser()?->getId()->value;
        $isCurrentUser = $currentUserId !== null && $user->getId()->value === $currentUserId;

        // Validate everything BEFORE mutating: Flow flushes in-memory entity
        // changes at the end of the request even when an action aborts via
        // throwStatus, so a late validation error must not leave partial
        // writes behind.
        if (($firstName !== null && trim($firstName) === '') || ($lastName !== null && trim($lastName) === '')) {
            $this->throwJsonStatus(400, 'invalid_name', 'First name and last name must not be empty.');
        }
        $email = $email !== null ? trim($email) : null;
        $this->validateEmail($email);
        $roleIdentifiers = $roles !== null ? $this->validateRoles($roles) : null;
        if ($roleIdentifiers !== null && $isCurrentUser && !in_array('Neos.Neos:Administrator', $roleIdentifiers, true) && $this->userService->currentUserIsAdministrator()) {
            $this->throwJsonStatus(400, 'cannot_demote_self', 'You cannot remove your own Administrator role.');
        }
        if ($password !== null && $password === '') {
            $this->throwJsonStatus(400, 'invalid_password', 'The password must not be empty.');
        }
        if ($active === false && $isCurrentUser) {
            $this->throwJsonStatus(400, 'cannot_deactivate_self', 'You cannot deactivate your own user.');
        }

        if ($firstName !== null) {
            $user->getName()->setFirstName(trim($firstName));
        }
        if ($lastName !== null) {
            $user->getName()->setLastName(trim($lastName));
        }

        if ($email !== null) {
            if ($email === '') {
                $primaryAddress = $user->getPrimaryElectronicAddress();
                if ($primaryAddress !== null) {
                    $user->removeElectronicAddress($primaryAddress);
                }
            } else {
                $this->setPrimaryEmail($user, $email);
            }
        }

        if ($roleIdentifiers !== null) {
            foreach ($user->getAccounts() as $account) {
                /** @var Account $account */
                $this->userService->setRolesForAccount($account, $roleIdentifiers);
            }
        }

        if ($password !== null) {
            $this->userService->setUserPassword($user, $password);
        }

        if ($active !== null && $active !== $user->isActive()) {
            if ($active) {
                $this->userService->activateUser($user);
            } else {
                $this->userService->deactivateUser($user);
            }
        }

        $this->userService->updateUser($user);
        $this->persistenceManager->persistAll();

        return $this->json(['user' => $this->serializeUser($user, $currentUserId)]);
    }

    /**
     * Delete a user, their accounts and their personal workspaces (including
     * any pending changes in them).
     */
    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $userId): string
    {
        $this->requireScope('neos.write');

        $user = $this->requireUser($userId);
        $currentUserId = $this->userService->getCurrentUser()?->getId()->value;
        if ($currentUserId !== null && $user->getId()->value === $currentUserId) {
            $this->throwJsonStatus(400, 'cannot_delete_self', 'You cannot delete your own user.');
        }

        $this->userService->deleteUser($user);
        $this->persistenceManager->persistAll();

        return $this->json(['success' => true]);
    }

    private function requireUser(string $userId): User
    {
        try {
            $id = UserId::fromString($userId);
        } catch (\InvalidArgumentException) {
            $this->throwJsonStatus(400, 'invalid_user_id', 'The given user id is not valid.');
        }
        $user = $this->userService->findUserById($id);
        if ($user === null) {
            $this->throwJsonStatus(404, 'user_not_found', sprintf('No user with the id "%s" exists.', $userId));
        }

        return $user;
    }

    /**
     * Normalize (Neos.Neos-relative short names allowed, duplicates removed)
     * and validate assignable role identifiers: each role must exist and must
     * not be abstract.
     *
     * @param array<int, mixed> $roleIdentifiers
     * @return array<int, string>
     */
    private function validateRoles(array $roleIdentifiers): array
    {
        $normalized = [];
        foreach ($roleIdentifiers as $roleIdentifier) {
            if (!is_string($roleIdentifier) || trim($roleIdentifier) === '') {
                $this->throwJsonStatus(400, 'invalid_role', 'Roles must be given as a list of role identifiers.');
            }
            $roleIdentifier = trim($roleIdentifier);
            if (!str_contains($roleIdentifier, ':')) {
                $roleIdentifier = 'Neos.Neos:' . $roleIdentifier;
            }
            try {
                $role = $this->policyService->getRole($roleIdentifier);
            } catch (NoSuchRoleException) {
                $this->throwJsonStatus(400, 'invalid_role', sprintf('The role "%s" does not exist.', $roleIdentifier));
            }
            if ($role->isAbstract()) {
                $this->throwJsonStatus(400, 'invalid_role', sprintf('The role "%s" is abstract and cannot be assigned.', $roleIdentifier));
            }
            $normalized[$role->getIdentifier()] = true;
        }

        return array_keys($normalized);
    }

    /** An empty string is valid here - it means "remove the address". */
    private function validateEmail(?string $email): void
    {
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->throwJsonStatus(400, 'invalid_email', 'The given email address is not valid.');
        }
    }

    private function setPrimaryEmail(User $user, string $email): void
    {
        $primaryAddress = $user->getPrimaryElectronicAddress();
        if ($primaryAddress !== null) {
            $primaryAddress->setIdentifier($email);
        } else {
            $electronicAddress = new ElectronicAddress();
            $electronicAddress->setIdentifier($email);
            $electronicAddress->setType('Email');
            $user->addElectronicAddress($electronicAddress);
            $user->setPrimaryElectronicAddress($electronicAddress);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user, ?string $currentUserId): array
    {
        $id = $user->getId()->value;
        $name = $user->getName();

        // The explicitly assigned roles of every account, de-duplicated. The
        // implicit Everybody / AuthenticatedUser roles are omitted as noise -
        // a listing wants "Administrator", "Editor", not framework internals.
        $roles = [];
        $accounts = [];
        foreach ($user->getAccounts() as $account) {
            /** @var Account $account */
            $accounts[] = [
                'accountIdentifier' => $account->getAccountIdentifier(),
                'authenticationProvider' => $account->getAuthenticationProviderName(),
                'active' => $account->isActive(),
            ];
            foreach ($account->getRoles() as $role) {
                $roles[$role->getIdentifier()] = true;
            }
        }

        return [
            'id' => $id,
            'label' => $user->getLabel(),
            'firstName' => $name?->getFirstName(),
            'lastName' => $name?->getLastName(),
            'fullName' => (string)$name,
            'email' => $user->getPrimaryElectronicAddress()?->getIdentifier(),
            'active' => $user->isActive(),
            'roles' => array_keys($roles),
            'accounts' => $accounts,
            // Lets the client mark "you" and disable the self-lockout
            // operations (deactivate, delete, admin-role removal) up front.
            'isCurrentUser' => $currentUserId !== null && $id === $currentUserId,
        ];
    }
}
