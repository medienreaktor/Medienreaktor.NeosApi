<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\OAuth;

use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use Medienreaktor\NeosApi\Security\OAuth\AuthorizationServerFactory;
use Neos\Flow\Annotations as Flow;

/**
 * The OAuth 2.1 token endpoint: exchanges authorization codes, refresh tokens
 * and client credentials for access tokens.
 */
class TokenController extends AbstractOAuthController
{
    #[Flow\Inject]
    protected AuthorizationServerFactory $serverFactory;

    public function tokenAction(): string
    {
        try {
            $psrResponse = $this->serverFactory->getAuthorizationServer()->respondToAccessTokenRequest(
                $this->request->getHttpRequest(),
                new Response()
            );

            return $this->applyPsr7Response($psrResponse);
        } catch (OAuthServerException $exception) {
            return $this->applyPsr7Response($exception->generateHttpResponse(new Response()));
        } catch (\Throwable $exception) {
            $this->logger->error('OAuth token endpoint failure: ' . $exception->getMessage(), ['exception' => $exception]);

            return $this->jsonResponse(['error' => 'server_error', 'error_description' => 'An internal error occurred.'], 500);
        }
    }
}
