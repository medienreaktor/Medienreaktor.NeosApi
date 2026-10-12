<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\OAuth;

use Medienreaktor\NeosApi\Domain\Model\OAuthClient;
use Medienreaktor\NeosApi\Domain\Repository\OAuthClientRepository;
use Neos\Flow\Annotations as Flow;

/**
 * Dynamic client registration (RFC 7591), as expected by MCP clients.
 *
 * Clients register either as public (PKCE, no secret) or as confidential with
 * a generated secret, as some MCP clients (claude.ai connectors) do. Both are
 * limited to the authorization_code and refresh_token grants, so a secret never
 * unlocks client_credentials; machine clients are still created deliberately
 * via ./flow neosapi:createclient. A registered client obtains no permissions
 * by itself: every token it acquires is bound to the approving user's account
 * and policies.
 */
class RegistrationController extends AbstractOAuthController
{
    #[Flow\Inject]
    protected OAuthClientRepository $clientRepository;

    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $oauthSettings;

    public function registerAction(): string
    {
        if (($this->oauthSettings['dynamicClientRegistration']['enabled'] ?? false) !== true) {
            return $this->jsonResponse(['error' => 'access_denied', 'error_description' => 'Dynamic client registration is disabled.'], 403);
        }

        $metadata = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        if (!is_array($metadata)) {
            return $this->jsonResponse(['error' => 'invalid_client_metadata', 'error_description' => 'Request body must be a JSON object.'], 400);
        }

        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || array_filter($redirectUris, 'is_string') !== $redirectUris) {
            return $this->jsonResponse(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must be a non-empty array of strings.'], 400);
        }
        foreach ($redirectUris as $uri) {
            $scheme = parse_url($uri, PHP_URL_SCHEME);
            $host = parse_url($uri, PHP_URL_HOST);
            $isLoopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
            if ($scheme === null || ($scheme === 'http' && !$isLoopback)) {
                return $this->jsonResponse(['error' => 'invalid_redirect_uri', 'error_description' => sprintf('Redirect URI "%s" must use https (or http on loopback only).', $uri)], 400);
            }
        }

        $authMethod = $metadata['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($authMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            return $this->jsonResponse(['error' => 'invalid_client_metadata', 'error_description' => 'token_endpoint_auth_method must be "none", "client_secret_basic" or "client_secret_post".'], 400);
        }

        $allowedGrantTypes = ['authorization_code', 'refresh_token'];
        $grantTypes = array_values(array_intersect((array)($metadata['grant_types'] ?? $allowedGrantTypes), $allowedGrantTypes));
        if ($grantTypes === []) {
            $grantTypes = $allowedGrantTypes;
        }

        $configuredScopes = array_keys((array)($this->oauthSettings['scopes'] ?? []));
        $requestedScopes = isset($metadata['scope']) && is_string($metadata['scope'])
            ? array_values(array_intersect(explode(' ', $metadata['scope']), $configuredScopes))
            : $configuredScopes;

        $clientId = bin2hex(random_bytes(16));
        $clientName = is_string($metadata['client_name'] ?? null) && $metadata['client_name'] !== ''
            ? mb_substr($metadata['client_name'], 0, 200)
            : 'Dynamically registered client';

        $secret = $authMethod === 'none' ? null : bin2hex(random_bytes(32));
        $secretHash = $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT);

        $client = new OAuthClient($clientId, $clientName, $secretHash, $redirectUris, $grantTypes, $requestedScopes);
        $this->clientRepository->add($client);

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => implode(' ', $requestedScopes),
        ];
        if ($secret !== null) {
            $response['client_secret'] = $secret;
            $response['client_secret_expires_at'] = 0;
        }

        return $this->jsonResponse($response, 201);
    }
}
