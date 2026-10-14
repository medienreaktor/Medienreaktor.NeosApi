<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Me;

use Neos\Api\Endpoint\Me\RequestBody\PasswordChange;
use Neos\Api\Endpoint\Me\RequestBody\ProfileUpdate;
use Neos\Api\Endpoint\Me\Schema\AccessTokenGrant;
use Neos\Api\Endpoint\Me\Schema\ClientIdentifier;
use Neos\Api\Endpoint\Me\Schema\CurrentAccount;
use Neos\Api\Endpoint\Me\Schema\Profile;
use Neos\Api\Endpoint\Me\Schema\ScopeList;
use Neos\Api\Endpoint\Users\Schema\Account;
use Neos\Api\Endpoint\Users\Schema\User;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Security\OAuthTokens;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Api\Infrastructure\Http\JsonBody;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\Party\Domain\Service\PartyService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The account of the access token
 */
final readonly class Me
{
    /**
     * @var list<string> the locale identifiers of the interface languages of Neos
     */
    private array $interfaceLanguages;

    /**
     * the interface language of a user that chose none
     */
    private string $defaultLanguage;

    /**
     * @param array<string, string> $availableLanguages locale identifier => label
     */
    public function __construct(
        private PartyService $partyService,
        private UserService $userService,
        private HashService $hashService,
        private OAuthTokens $oauthTokens,
        private PrivilegeScopes $privilegeScopes,
        private AccountPrivileges $accountPrivileges,
        #[Flow\InjectConfiguration(path: 'userInterface.availableLanguages', package: 'Neos.Neos')]
        array $availableLanguages,
        #[Flow\InjectConfiguration(path: 'userInterface.defaultLanguage', package: 'Neos.Neos')]
        string $defaultLanguage,
    ) {
        $this->interfaceLanguages = array_map(strval(...), array_keys($availableLanguages));
        $this->defaultLanguage = $defaultLanguage;
    }

    #[Operation(
        path: '/me',
        method: 'GET',
        summary: 'Get the current account',
        description: 'The account the access token acts as with its roles, the Neos user it belongs to, the client and scopes of the token, and the scopes whose privileges the account holds (privileges), whatever scopes the token has.',
        operationId: 'getMe',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ME_READ],
        ],
    )]
    public function get(#[AuthContext] ApiCaller $caller): CurrentAccount
    {
        $user = $this->findUser($caller);
        return new CurrentAccount(
            Account::from($caller->account),
            $user !== null ? User::from($user) : null,
            new AccessTokenGrant(ClientIdentifier::fromString($caller->clientIdentifier), ScopeList::fromStrings(...$caller->scopes)),
            ScopeList::fromStrings(...$this->privilegesOf($caller)),
        );
    }

    /**
     * The scopes whose ApiPrivilege targets the account's roles grant, sorted
     *
     * @return list<string>
     */
    private function privilegesOf(ApiCaller $caller): array
    {
        $roles = $this->accountPrivileges->rolesOf($caller->account);
        $scopes = array_keys(array_filter(
            $this->privilegeScopes->privilegeTargets(),
            fn (string $privilegeTarget) => $this->accountPrivileges->isGrantedForRoles($roles, $privilegeTarget),
        ));
        sort($scopes);
        return $scopes;
    }

    #[Operation(
        path: '/me/profile',
        method: 'GET',
        summary: 'Get your profile',
        description: 'The name, email address, interface language and preferences of the Neos user the access token acts as. 404 for an account without a Neos user.',
        operationId: 'getProfile',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ME_READ],
        ],
    )]
    public function getProfile(#[AuthContext] ApiCaller $caller): Profile|NotFound
    {
        $user = $this->findUser($caller);
        return $user !== null ? Profile::from($user, $this->defaultLanguage) : self::noUser($caller);
    }

    #[Operation(
        path: '/me/profile',
        method: 'PATCH',
        summary: 'Change your profile',
        description: 'Changes the given fields and preferences and leaves the others as they are. interfaceLanguage null resets it to Neos.Neos.userInterface.defaultLanguage.',
        operationId: 'updateProfile',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ME_UPDATE],
        ],
    )]
    public function updateProfile(
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'The fields and preferences to change')] ProfileUpdate $patch,
        ServerRequestInterface $request,
    ): Profile|NotFound|UnprocessableContent {
        $user = $this->findUser($caller);
        if ($user === null) {
            return self::noUser($caller);
        }
        if ($patch->interfaceLanguage !== null && !in_array($patch->interfaceLanguage, $this->interfaceLanguages, true)) {
            return UnprocessableContent::because(sprintf('There is no interface language %s, available are %s', $patch->interfaceLanguage, implode(', ', $this->interfaceLanguages)));
        }
        if ($patch->preferences !== null && array_key_exists(Profile::INTERFACE_LANGUAGE_PREFERENCE, $patch->preferences)) {
            return UnprocessableContent::because(sprintf('%s is no preference, set interfaceLanguage instead', Profile::INTERFACE_LANGUAGE_PREFERENCE));
        }
        $patch->applyTo($user);
        // ProfileUpdate can't tell an interfaceLanguage null from one left out
        if ($patch->interfaceLanguage === null && JsonBody::has($request, 'interfaceLanguage')) {
            // no preference: Neos uses its default language
            $preferences = $user->getPreferences()->getPreferences();
            unset($preferences[Profile::INTERFACE_LANGUAGE_PREFERENCE]);
            $user->getPreferences()->setPreferences($preferences);
        }
        $this->userService->updateUser($user);
        return Profile::from($user, $this->defaultLanguage);
    }

    #[Operation(
        path: '/me/password',
        method: 'PUT',
        summary: 'Change your password',
        description: 'Sets the password of the Neos user the access token acts as, for all of its accounts that log in with one, ends its sessions and revokes the OAuth tokens of its accounts (access and refresh tokens, authorization codes) but the access token of this request. 404 for an account without a Neos user.',
        operationId: 'changePassword',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::ME_PASSWORD],
        ],
    )]
    public function changePassword(
        #[AuthContext] ApiCaller $caller,
        #[RequestBody(description: 'The current password and the new one')] PasswordChange $change,
    ): NotFound|UnprocessableContent|null {
        $user = $this->findUser($caller);
        if ($user === null) {
            return self::noUser($caller);
        }
        if (!$this->isPasswordOf($user, $change->currentPassword)) {
            return UnprocessableContent::because('The current password is wrong');
        }
        $this->userService->setUserPassword($user, $change->newPassword->value);
        foreach ($user->getAccounts() as $account) {
            $this->oauthTokens->revokeAllOf($account, $caller->accessTokenIdentifier);
        }
        return null;
    }

    private function findUser(ApiCaller $caller): ?Model\User
    {
        // not UserService::getUser(), which fails for accounts without a party
        $user = $this->partyService->getAssignedPartyOfAccount($caller->account);
        return $user instanceof Model\User ? $user : null;
    }

    /**
     * Whether one of the user's accounts logs in with the password
     */
    private function isPasswordOf(Model\User $user, string $password): bool
    {
        foreach ($user->getAccounts() as $account) {
            /** @var Security\Account $account */
            $credentialsSource = $account->getCredentialsSource();
            if (!is_string($credentialsSource) || $credentialsSource === '') {
                continue;
            }
            try {
                if ($this->hashService->validatePassword($password, $credentialsSource)) {
                    return true;
                }
            } catch (\Throwable) {
                // no password hash, e.g. of an account with another kind of credentials
            }
        }
        return false;
    }

    private static function noUser(ApiCaller $caller): NotFound
    {
        return NotFound::because(sprintf('The account %s belongs to no Neos user', $caller->account->getAccountIdentifier()));
    }
}
