<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Flow\Http\ServerRequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps the API's responses out of shared caches, and says what they depend on
 *
 * - `Cache-Control: private, no-store` and `Vary: Authorization`: a response depends on the token's account and scopes
 * - `Content-Language` and `Vary: Accept-Language` if the operation translated labels, see LabelTranslator
 *
 * Only for the operations, not for anything else under the API's path like the OpenAPI document or the docs of
 * Neos.Api.Docs, which are public and the same for everybody. It runs around OAuthChallengeMiddleware, whose 401 and
 * 403 responses get the headers as well.
 */
final class ApiCacheHeadersMiddleware implements MiddlewareInterface
{
    /**
     * The name of the API in Neos.OpenApi.FlowAdapter.apis, see Settings.yaml
     */
    private const string API_NAME = 'neos';

    public function __construct(
        private readonly LabelTranslator $labelTranslator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isOperation($request)) {
            return $handler->handle($request);
        }
        $this->labelTranslator->forget();
        $response = $handler->handle($request);
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'private, no-store');
        }
        $response = $response->withAddedHeader('Vary', 'Authorization');
        $locale = $this->labelTranslator->usedLocale();
        if ($locale !== null) {
            $response = $response
                // Flow's locale identifiers separate the region with an underscore, language tags with a hyphen
                ->withHeader('Content-Language', str_replace('_', '-', (string)$locale))
                ->withAddedHeader('Vary', 'Accept-Language');
        }
        return $response;
    }

    /**
     * Whether the request was routed to one of the API's operations, see the FlowAdapter's OpenApiRoutesProvider
     */
    private function isOperation(ServerRequestInterface $request): bool
    {
        $routingResults = $request->getAttribute(ServerRequestAttributes::ROUTING_RESULTS);
        return ($routingResults['__api'] ?? null) === self::API_NAME && isset($routingResults['__operation']);
    }
}
