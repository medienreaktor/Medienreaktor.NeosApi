<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;

/**
 * Backend users as a read resource - the listing behind the Studio's user
 * administration.
 *
 * Authorization is deliberately NOT granted in Policy.yaml: this controller is
 * matched only by the Api.CatchAll privilege target (granted to
 * Neos.Neos:Administrator), never by Api.Read (granted to AbstractEditor). So
 * editors get a 403 here even though they read nodes and sites freely - user
 * administration is administrators only, matching the classic backend module.
 * The Studio mirrors that with the accountPermissions "users" flag from /me.
 */
class UsersController extends AbstractApiController
{
    #[Flow\Inject]
    protected UserService $userService;

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
            // Lets the client mark "you" and guard against self-deactivation
            // once write operations land.
            'isCurrentUser' => $currentUserId !== null && $id === $currentUserId,
        ];
    }
}
