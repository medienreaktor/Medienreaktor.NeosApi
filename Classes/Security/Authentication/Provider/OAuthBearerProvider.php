<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\Authentication\Provider;

use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Medienreaktor\NeosApi\Security\Authentication\Token\ApiBearerToken;
use Medienreaktor\NeosApi\Security\OAuth\AuthorizationServerFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Psr\Log\LoggerInterface;

/**
 * Authenticates OAuth 2.1 bearer tokens and hydrates a full Flow account into
 * the security context. This is the linchpin of the whole API security model:
 * a token request ends up with the same account, roles and policy enforcement
 * as an interactive backend session - scopes can only narrow, never widen.
 *
 * User-bound tokens (authorization_code grant) resolve to the approving
 * user's account. Client-credentials tokens resolve through the configured
 * clientCredentialsAccounts mapping (client id -> account identifier).
 */
class OAuthBearerProvider extends AbstractProvider
{
    #[Flow\Inject]
    protected AuthorizationServerFactory $serverFactory;

    #[Flow\Inject]
    protected AccountRepository $accountRepository;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'accountAuthenticationProviderName')]
    protected string $accountAuthenticationProviderName;

    /**
     * @var array<string, string>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth.clientCredentialsAccounts')]
    protected array $clientCredentialsAccounts = [];

    public function getTokenClassNames(): array
    {
        return [ApiBearerToken::class];
    }

    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof ApiBearerToken) {
            throw new UnsupportedAuthenticationTokenException('This provider cannot authenticate the given token.', 1751980010);
        }

        $bearer = $authenticationToken->getBearer();
        if ($bearer === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);

            return;
        }

        try {
            $validatedRequest = $this->serverFactory->getResourceServer()->validateAuthenticatedRequest(
                new ServerRequest('GET', '/', ['Authorization' => 'Bearer ' . $bearer])
            );
        } catch (OAuthServerException $exception) {
            $this->logger->info('NeosApi: bearer token rejected: ' . $exception->getMessage());
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

            return;
        }

        $clientId = (string)$validatedRequest->getAttribute('oauth_client_id');
        $userId = $validatedRequest->getAttribute('oauth_user_id');
        $scopes = (array)$validatedRequest->getAttribute('oauth_scopes', []);

        $accountIdentifier = $userId !== null && $userId !== ''
            ? (string)$userId
            : ($this->clientCredentialsAccounts[$clientId] ?? null);

        if ($accountIdentifier === null) {
            $this->logger->info(sprintf('NeosApi: no account mapping for client-credentials client "%s".', $clientId));
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

            return;
        }

        $account = $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName(
            $accountIdentifier,
            $this->accountAuthenticationProviderName
        );
        if ($account === null) {
            $this->logger->info(sprintf('NeosApi: no active account "%s" for provider "%s".', $accountIdentifier, $this->accountAuthenticationProviderName));
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

            return;
        }

        $authenticationToken->setScopes(array_map('strval', $scopes));
        $authenticationToken->setClientIdentifier($clientId);
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}
