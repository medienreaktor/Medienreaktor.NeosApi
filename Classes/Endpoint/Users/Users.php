<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users;

use Neos\Api\Endpoint\Users\RequestBody\UserCreate;
use Neos\Api\Endpoint\Users\RequestBody\UserPasswordSet;
use Neos\Api\Endpoint\Users\RequestBody\UserUpdate;
use Neos\Api\Endpoint\Users\Schema\PaginatedUserListing;
use Neos\Api\Endpoint\Users\Schema\User;
use Neos\Api\Endpoint\Users\Schema\UserId;
use Neos\Api\Endpoint\Users\Schema\UserList;
use Neos\Api\Endpoint\Users\Response\UserCreated;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Security\OAuthTokens;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Neos users
 *
 * As in Neos' user module, everybody with users.read sees all users, but only administrators may change, delete or
 * give roles to every user: others manage the users whose roles are a subset of their own and give only their own
 * roles (AccountPrivileges::mayManage(), ::assignableRoles())
 */
final readonly class Users
{
    public function __construct(
        private UserService $userService,
        private PolicyService $policyService,
        private AccountPrivileges $accountPrivileges,
        private PrivilegeScopes $privilegeScopes,
        private OAuthTokens $oauthTokens,
    ) {
    }

    #[Operation(
        path: '/users',
        method: 'GET',
        summary: 'List the users',
        description: 'A page of the Neos users with their accounts, ordered by account identifier. offset and limit (25 by default, 100 at most) choose the page, meta.total and links tell about the others. All users are listed, also the ones you may not change, as in Neos\' user module.',
        operationId: 'listUsers',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_READ],
        ],
    )]
    public function list(
        ServerRequestInterface $request,
        #[Parameter(in: 'query', description: 'How many items to skip, 0 if omitted')]
        Offset|null $offset = null,
        #[Parameter(in: 'query', description: 'How many items at most, 25 if omitted, 100 at most')]
        Limit|null $limit = null,
    ): PaginatedUserListing {
        $offset ??= Offset::none();
        $limit ??= Limit::default();
        $users = $this->userService->getUsers();
        $total = $users->count();
        // a copy of the query, paged in the database
        $query = $users->getQuery()->setOffset($offset->value)->setLimit($limit->value);
        return new PaginatedUserListing(
            new UserList(...array_map(User::from(...), $query->execute()->toArray())),
            new ListingMeta($total),
            ListingLinks::for($request, $offset, $limit, $total),
        );
    }

    #[Operation(
        path: '/users',
        method: 'POST',
        summary: 'Create a user',
        description: 'Creates a Neos user with a backend account. Unless you are an administrator, you can only give it roles you have yourself (403), see GET /options/users.',
        operationId: 'createUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_CREATE],
        ],
    )]
    public function create(
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'The user and its account')] UserCreate $newUser,
    ): UserCreated|Conflict|Forbidden|UnprocessableContent {
        if ($this->userService->getUser($newUser->username->value) !== null) {
            return Conflict::because(sprintf('There is an account with the username %s already', $newUser->username->value));
        }
        $roleError = $this->checkAssignable($caller->account, $newUser->roleIdentifiers());
        if ($roleError !== null) {
            return $roleError;
        }
        $user = new Model\User();
        $newUser->applyTo($user);
        $this->userService->addUser(
            $newUser->username->value,
            $newUser->password->value,
            $user,
            $newUser->roleIdentifiers(),
        );
        return new UserCreated(User::from($user));
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'GET',
        summary: 'Get a user',
        operationId: 'getUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_READ],
        ],
    )]
    public function get(UserId $userId): User|NotFound
    {
        $user = $this->findUser($userId);
        return $user !== null ? User::from($user) : self::notFound($userId);
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'PATCH',
        summary: 'Change a user',
        description: 'Changes the given properties and leaves the others as they are. roles replaces the roles of all of the user\'s accounts, active activates or deactivates them. Unless you are an administrator, you can only change users whose roles you have yourself and give them only your own roles (403). You can\'t deactivate yourself, take away your own administrator role or the roles that let you change users (422).',
        operationId: 'updateUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_UPDATE],
        ],
    )]
    public function update(
        #[AuthContext] ApiCaller $caller,
        UserId $userId,
        #[RequestBody(description: 'The properties to change')] UserUpdate $patch,
    ): User|NotFound|Forbidden|UnprocessableContent {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        if (!$this->accountPrivileges->mayManage($caller->account, $user)) {
            return self::mayNotManage();
        }
        $isCaller = $user->getAccounts()->contains($caller->account);
        $roleIdentifiers = $patch->roleIdentifiers();
        if ($roleIdentifiers !== null) {
            if ($roleIdentifiers === []) {
                return UnprocessableContent::because('A user needs at least one role');
            }
            $roleError = $this->checkAssignable($caller->account, $roleIdentifiers);
            if ($roleError !== null) {
                return $roleError;
            }
            if ($isCaller) {
                // as Neos' user module: don't lock yourself out
                $newRoles = $this->accountPrivileges->rolesWith($roleIdentifiers);
                if ($this->accountPrivileges->isAdministrator($caller->account) && !AccountPrivileges::includesAdministrator($newRoles)) {
                    return UnprocessableContent::because(sprintf('You can\'t take away your own role %s', AccountPrivileges::ADMINISTRATOR_ROLE));
                }
                if (!$this->accountPrivileges->isGrantedForRoles($newRoles, $this->privilegeScopes->privilegeTargetOf(ApiScopes::USERS_UPDATE))) {
                    return UnprocessableContent::because('You can\'t take away your own roles that let you change users');
                }
            }
        }
        if ($patch->active === false && $isCaller) {
            return UnprocessableContent::because('You can\'t deactivate yourself');
        }
        $patch->applyTo($user);
        $this->userService->updateUser($user);
        if ($roleIdentifiers !== null) {
            foreach ($user->getAccounts() as $account) {
                $this->userService->setRolesForAccount($account, $roleIdentifiers);
            }
        }
        if ($patch->active === true) {
            $this->userService->activateUser($user);
        } elseif ($patch->active === false) {
            $this->userService->deactivateUser($user);
        }
        return User::from($user);
    }

    #[Operation(
        path: '/users/{userId}/password',
        method: 'PUT',
        summary: 'Set the password of a user',
        description: 'Sets the password of all of the user\'s accounts that log in with one, ends its sessions and revokes its OAuth tokens (your own access token stays valid if it is your own user). Unless you are an administrator, only for users whose roles you have yourself (403).',
        operationId: 'setUserPassword',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_UPDATE],
        ],
    )]
    public function setPassword(
        #[AuthContext] ApiCaller $caller,
        UserId $userId,
        #[RequestBody(description: 'The new password')] UserPasswordSet $password,
    ): NotFound|Forbidden|null {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        if (!$this->accountPrivileges->mayManage($caller->account, $user)) {
            return self::mayNotManage();
        }
        $this->userService->setUserPassword($user, $password->newPassword->value);
        foreach ($user->getAccounts() as $account) {
            $this->oauthTokens->revokeAllOf($account, $caller->accessTokenIdentifier);
        }
        return null;
    }

    #[Operation(
        path: '/users/{userId}',
        method: 'DELETE',
        summary: 'Delete a user',
        description: 'Deletes the user with its accounts and personal workspaces, including their unpublished changes, and ends its sessions. You can\'t delete your own user. Unless you are an administrator, only users whose roles you have yourself (403).',
        operationId: 'deleteUser',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_DELETE],
        ],
    )]
    public function delete(#[AuthContext] ApiCaller $caller, UserId $userId): NotFound|Forbidden|Conflict|null
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            return self::notFound($userId);
        }
        if ($user->getAccounts()->contains($caller->account)) {
            return Conflict::because('You can\'t delete your own user');
        }
        if (!$this->accountPrivileges->mayManage($caller->account, $user)) {
            return self::mayNotManage();
        }
        $this->userService->deleteUser($user);
        return null;
    }

    /**
     * An unknown or abstract role is a 422 (Flow can't assign abstract roles to accounts), one the account may not
     * give is a 403
     *
     * @param list<string> $roleIdentifiers
     */
    private function checkAssignable(Account $account, array $roleIdentifiers): Forbidden|UnprocessableContent|null
    {
        foreach ($roleIdentifiers as $roleIdentifier) {
            if (!$this->policyService->hasRole($roleIdentifier) || $this->policyService->getRole($roleIdentifier)->isAbstract()) {
                return UnprocessableContent::because(sprintf('There is no role %s that can be assigned to an account', $roleIdentifier));
            }
        }
        $notAssignable = array_diff($roleIdentifiers, array_keys($this->accountPrivileges->assignableRoles($account)));
        if ($notAssignable !== []) {
            return Forbidden::because(sprintf('You can only give users roles you have yourself, not %s', implode(', ', $notAssignable)));
        }
        return null;
    }

    private function findUser(UserId $userId): ?Model\User
    {
        // the format check of a UUID ignores the case, Neos' user IDs are lowercase
        // the party repository finds any party, not only Neos users
        $user = $this->userService->findUserById(Model\UserId::fromString(strtolower($userId->value)));
        return $user instanceof Model\User ? $user : null;
    }

    private static function notFound(UserId $userId): NotFound
    {
        return NotFound::because(sprintf('There is no user with the ID %s', $userId->value));
    }

    private static function mayNotManage(): Forbidden
    {
        return Forbidden::because('You can only manage users whose roles you have yourself');
    }
}
