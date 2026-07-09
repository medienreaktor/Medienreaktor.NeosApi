<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\OAuth;

use Neos\Flow\Annotations as Flow;

/**
 * OAuth server metadata discovery: RFC 8414 (authorization server) and
 * RFC 9728 (protected resource). MCP clients use these documents to find the
 * authorization endpoints and register themselves.
 */
class DiscoveryController extends AbstractOAuthController
{
    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $oauthSettings;

    public function authorizationServerAction(): string
    {
        $issuer = $this->getIssuer();

        return $this->jsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => ($this->oauthSettings['dynamicClientRegistration']['enabled'] ?? false) === true
                ? $issuer . '/oauth/register'
                : null,
            'scopes_supported' => array_keys((array)($this->oauthSettings['scopes'] ?? [])),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    public function protectedResourceAction(): string
    {
        $issuer = $this->getIssuer();

        return $this->jsonResponse([
            'resource' => $issuer . '/api',
            'authorization_servers' => [$issuer],
            'scopes_supported' => array_keys((array)($this->oauthSettings['scopes'] ?? [])),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    private function getIssuer(): string
    {
        $issuer = (string)($this->oauthSettings['issuer'] ?? '');

        return $issuer !== '' ? rtrim($issuer, '/') : $this->getBaseUri();
    }
}
