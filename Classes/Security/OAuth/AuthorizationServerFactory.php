<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Medienreaktor\NeosApi\Security\OAuth\Repository\AccessTokenRepository;
use Medienreaktor\NeosApi\Security\OAuth\Repository\AuthCodeRepository;
use Medienreaktor\NeosApi\Security\OAuth\Repository\ClientRepository;
use Medienreaktor\NeosApi\Security\OAuth\Repository\RefreshTokenRepository;
use Medienreaktor\NeosApi\Security\OAuth\Repository\ScopeRepository;
use Neos\Flow\Annotations as Flow;

/**
 * Builds the league/oauth2-server authorization and resource servers.
 *
 * OAuth 2.1 profile: authorization_code (PKCE required - league enforces the
 * code challenge for public clients and we register no grant that would allow
 * password or implicit flows), refresh_token (with rotation, the league
 * default) and client_credentials for machine-to-machine access.
 */
#[Flow\Scope('singleton')]
class AuthorizationServerFactory
{
    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $settings;

    #[Flow\Inject]
    protected KeyManager $keyManager;

    #[Flow\Inject]
    protected ClientRepository $clientRepository;

    #[Flow\Inject]
    protected AccessTokenRepository $accessTokenRepository;

    #[Flow\Inject]
    protected ScopeRepository $scopeRepository;

    #[Flow\Inject]
    protected AuthCodeRepository $authCodeRepository;

    #[Flow\Inject]
    protected RefreshTokenRepository $refreshTokenRepository;

    private ?AuthorizationServer $authorizationServer = null;

    private ?ResourceServer $resourceServer = null;

    public function getAuthorizationServer(): AuthorizationServer
    {
        if ($this->authorizationServer !== null) {
            return $this->authorizationServer;
        }

        $server = new AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $this->scopeRepository,
            $this->keyManager->getPrivateKeyPath(),
            $this->keyManager->getEncryptionKey()
        );

        $accessTokenTtl = new \DateInterval($this->settings['accessTokenTtl']);

        $authCodeGrant = new AuthCodeGrant(
            $this->authCodeRepository,
            $this->refreshTokenRepository,
            new \DateInterval($this->settings['authCodeTtl'])
        );
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval($this->settings['refreshTokenTtl']));
        $server->enableGrantType($authCodeGrant, $accessTokenTtl);

        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL(new \DateInterval($this->settings['refreshTokenTtl']));
        $server->enableGrantType($refreshTokenGrant, $accessTokenTtl);

        $server->enableGrantType(new ClientCredentialsGrant(), $accessTokenTtl);

        return $this->authorizationServer = $server;
    }

    public function getResourceServer(): ResourceServer
    {
        if ($this->resourceServer !== null) {
            return $this->resourceServer;
        }

        return $this->resourceServer = new ResourceServer(
            $this->accessTokenRepository,
            $this->keyManager->getPublicKeyPath()
        );
    }
}
