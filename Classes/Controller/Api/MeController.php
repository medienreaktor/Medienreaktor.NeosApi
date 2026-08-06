<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Security\Authentication\Token\ApiBearerToken;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;

/**
 * The walking-skeleton endpoint: who am I, which roles and scopes does this
 * request act with? Useful for clients to introspect their effective access.
 *
 * Additionally the self-service profile: every authenticated user (not just
 * administrators) may read and edit their OWN name, email, interface language,
 * password and free-form client preferences here - the Api.Me privilege target
 * covers all actions of this controller and is granted to every editor.
 */
class MeController extends AbstractApiController
{
    /**
     * Preference keys that are NOT part of the generic `preferences` object
     * because they are managed through dedicated, validated profile fields.
     */
    private const RESERVED_PREFERENCES = ['interfaceLanguage'];

    #[Flow\Inject]
    protected PrivilegeManagerInterface $privilegeManager;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected HashService $hashService;

    /**
     * @var array<string, string> permission name => privilege target identifier
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'accountPermissions')]
    protected array $accountPermissions;

    /**
     * @var array<string, string> locale identifier => label, the classic backend's language picker options
     */
    #[Flow\InjectConfiguration(package: 'Neos.Neos', path: 'userInterface.availableLanguages')]
    protected array $availableLanguages;

    #[Flow\InjectConfiguration(package: 'Neos.Neos', path: 'userInterface.defaultLanguage')]
    protected string $defaultLanguage;

    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $account = $this->securityContext->getAccount();
        $scopes = [];
        $clientIdentifier = null;
        foreach ($this->securityContext->getAuthenticationTokensOfType(ApiBearerToken::class) as $token) {
            if ($token->isAuthenticated()) {
                $scopes = $token->getScopes();
                $clientIdentifier = $token->getClientIdentifier();
            }
        }

        $user = $this->userService->getCurrentUser();

        return $this->json([
            'account' => $account?->getAccountIdentifier(),
            // The Neos user behind the account - the same id the content
            // repository stamps into events as initiatingUserId and the
            // presence roster reports, so clients can tell "me" from others.
            'user' => $user !== null
                ? ['id' => $user->getId()->value, 'label' => $user->getLabel()]
                : null,
            'roles' => array_map(static fn (Role $role) => $role->getIdentifier(), array_values($this->securityContext->getRoles())),
            'scopes' => $scopes,
            'client' => $clientIdentifier,
            'contentRepository' => $this->contentRepositoryId,
            // Same privilege-target checks the classic backend menu uses to
            // show/hide modules (see accountPermissions in Settings.yaml).
            'permissions' => array_map(
                fn (string $privilegeTarget): bool => $this->privilegeManager->isPrivilegeTargetGranted($privilegeTarget),
                $this->accountPermissions
            ),
        ]);
    }

    /**
     * The authenticated user's own profile: name, email and interface
     * language, plus the options for the language picker.
     */
    public function profileAction(): string
    {
        $this->requireScope('neos.read');
        $user = $this->requireCurrentUser();

        return $this->json(['profile' => $this->serializeProfile($user)]);
    }

    /**
     * Partial update of the own profile. JSON body: firstName, lastName,
     * email, interfaceLanguage, preferences - absent keys are left as-is.
     * `preferences` is itself partial: only the keys present are written,
     * a null value deletes its key (see mergePreferences for the contract).
     *
     * @param array<string, mixed>|null $preferences
     */
    #[Flow\SkipCsrfProtection]
    public function updateProfileAction(
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $email = null,
        ?string $interfaceLanguage = null,
        ?array $preferences = null
    ): string {
        $this->requireScope('neos.write');
        $user = $this->requireCurrentUser();

        if ($firstName !== null || $lastName !== null) {
            $name = $user->getName();
            if ($firstName !== null) {
                if (trim($firstName) === '') {
                    $this->throwJsonStatus(400, 'invalid_name', 'The first name must not be empty.');
                }
                $name->setFirstName(trim($firstName));
            }
            if ($lastName !== null) {
                if (trim($lastName) === '') {
                    $this->throwJsonStatus(400, 'invalid_name', 'The last name must not be empty.');
                }
                $name->setLastName(trim($lastName));
            }
        }

        if ($email !== null) {
            $email = trim($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->throwJsonStatus(400, 'invalid_email', 'The given email address is not valid.');
            }
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

        if ($interfaceLanguage !== null) {
            if (!isset($this->availableLanguages[$interfaceLanguage])) {
                $this->throwJsonStatus(400, 'invalid_interface_language', sprintf('"%s" is not an available interface language.', $interfaceLanguage));
            }
            $user->getPreferences()->setInterfaceLanguage($interfaceLanguage);
        }

        if ($preferences !== null) {
            $this->mergePreferences($user, $preferences);
        }

        $this->userService->updateUser($user);
        $this->persistenceManager->persistAll();

        return $this->json(['profile' => $this->serializeProfile($user)]);
    }

    /**
     * Change the own password. The current password must be supplied and is
     * verified against the user's accounts before the new one is set.
     */
    #[Flow\SkipCsrfProtection]
    public function changePasswordAction(string $currentPassword, string $newPassword): string
    {
        $this->requireScope('neos.write');
        $user = $this->requireCurrentUser();

        if ($newPassword === '') {
            $this->throwJsonStatus(400, 'invalid_password', 'The new password must not be empty.');
        }
        if (!$this->currentPasswordMatches($user, $currentPassword)) {
            $this->throwJsonStatus(400, 'invalid_current_password', 'The current password is not correct.');
        }

        $this->userService->setUserPassword($user, $newPassword);
        $this->persistenceManager->persistAll();

        return $this->json(['success' => true]);
    }

    private function requireCurrentUser(): User
    {
        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            $this->throwJsonStatus(404, 'no_user', 'The authenticated account is not associated with a Neos user.');
        }

        return $user;
    }

    private function currentPasswordMatches(User $user, string $currentPassword): bool
    {
        foreach ($user->getAccounts() as $account) {
            /** @var Account $account */
            $credentialsSource = $account->getCredentialsSource();
            if (!is_string($credentialsSource) || $credentialsSource === '') {
                continue;
            }
            try {
                if ($this->hashService->validatePassword($currentPassword, $credentialsSource)) {
                    return true;
                }
            } catch (\Throwable) {
                // Not a password hash (e.g. a token-based account) - skip it.
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProfile(User $user): array
    {
        $name = $user->getName();

        return [
            'firstName' => $name?->getFirstName() ?? '',
            'lastName' => $name?->getLastName() ?? '',
            'fullName' => (string)$name,
            'email' => $user->getPrimaryElectronicAddress()?->getIdentifier(),
            'interfaceLanguage' => $user->getPreferences()->getInterfaceLanguage() ?: $this->defaultLanguage,
            'availableLanguages' => $this->availableLanguages,
            'preferences' => (object)array_diff_key(
                $user->getPreferences()->getPreferences(),
                array_flip(self::RESERVED_PREFERENCES)
            ),
        ];
    }

    /**
     * Merge a partial preferences update into the user's schema-less
     * preferences array: keys are arbitrary (clients bring their own,
     * namespaced like "studio.uiMode"), values are stored verbatim, null
     * deletes a key. Only the reserved keys with dedicated profile fields
     * are refused so their validation cannot be bypassed.
     *
     * @param array<string, mixed> $preferences
     */
    private function mergePreferences(User $user, array $preferences): void
    {
        $reserved = array_intersect(array_keys($preferences), self::RESERVED_PREFERENCES);
        if ($reserved !== []) {
            $this->throwJsonStatus(400, 'reserved_preference', sprintf('The preference key(s) %s are managed through their dedicated profile fields.', implode(', ', $reserved)));
        }

        $container = $user->getPreferences();
        $merged = $container->getPreferences();
        foreach ($preferences as $key => $value) {
            if (trim((string)$key) === '') {
                $this->throwJsonStatus(400, 'invalid_preference_key', 'Preference keys must be non-empty strings.');
            }
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }
        $container->setPreferences($merged);
    }
}
