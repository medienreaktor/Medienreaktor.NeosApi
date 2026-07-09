<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\OAuth;

use Neos\Flow\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared plumbing for the OAuth protocol endpoints: bridging between the
 * PSR-7 responses produced by league/oauth2-server and Flow's action response.
 */
abstract class AbstractOAuthController extends ActionController
{
    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html', 'application/x-www-form-urlencoded'];

    protected function applyPsr7Response(ResponseInterface $psrResponse): string
    {
        $this->response->setStatusCode($psrResponse->getStatusCode());
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $this->response->setHttpHeader($name, implode(', ', $values));
        }

        return (string)$psrResponse->getBody();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonResponse(array $data, int $statusCode = 200): string
    {
        $this->response->setStatusCode($statusCode);
        $this->response->setContentType('application/json');

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function getBaseUri(): string
    {
        $uri = $this->request->getHttpRequest()->getUri();
        $baseUri = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $baseUri .= ':' . $uri->getPort();
        }

        return $baseUri;
    }
}
