<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use GuzzleHttp\Psr7\Response;
use Neos\Api\Security\InsufficientScope;
use Neos\Api\Security\MissingPrivileges;
use Neos\OAuth\Security\IssuerNotConfigured;
use Neos\OAuth\Security\ProtectedResources;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Support\HttpStatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Completes the OAuth challenges of the API (RFC 6750, RFC 9728)
 *
 * - 401: points clients to the resource metadata, where they find the authorization server
 * - 403 if an operation requires scopes the token lacks. RFC 6750 suggests a `WWW-Authenticate: Bearer
 *   error="insufficient_scope"` challenge, but PHP turns every response with that header into a 401 once Flow has
 *   sent the status line. So the required scopes are only in the problem body
 * - 403 if the account of the token lacks the privileges an operation requires
 */
final class OAuthChallengeMiddleware implements MiddlewareInterface
{
    /**
     * The key of the API in Neos.OAuth.protectedResources, see Settings.yaml
     */
    public const string RESOURCE = 'Neos.Api';

    public function __construct(
        private readonly ProtectedResources $protectedResources,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (InsufficientScope $exception) {
            return self::forbidden('Insufficient scope', sprintf('The access token must grant the scope(s): %s', implode(' ', $exception->requiredScopes)));
        } catch (MissingPrivileges $exception) {
            return self::forbidden('Access denied', sprintf('The account of the access token must hold the privilege(s): %s', implode(', ', $exception->privilegeTargets)));
        }
        // neos/openapi answers unauthenticated requests with a bare "Bearer" challenge
        if ($response->getStatusCode() === 401 && $response->getHeaderLine('WWW-Authenticate') === 'Bearer') {
            try {
                return $response->withHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $this->protectedResources->metadataUrl(self::RESOURCE)));
            } catch (IssuerNotConfigured) {
                return $response;
            }
        }
        return $response;
    }

    private static function forbidden(string $title, string $detail): ResponseInterface
    {
        $problem = ProblemDocument::create(HttpStatusCode::fromInteger(403), $title, $detail);
        // ApiCacheHeadersMiddleware adds the cache headers
        return new Response(403, ['Content-Type' => ProblemDocument::CONTENT_TYPE], json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
