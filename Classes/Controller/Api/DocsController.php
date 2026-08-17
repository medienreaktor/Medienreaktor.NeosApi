<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the API description: the OpenAPI 3.1 document (hand-maintained in
 * Resources/Private/OpenApi/openapi.yaml) and an interactive reference page
 * rendering it with a self-hosted Scalar bundle (no CDN, works offline).
 *
 * Both endpoints are public (see Policy.yaml): like the OAuth discovery
 * documents they describe the API surface but expose no content, and the
 * reference page must be reachable by developers who do not have a token yet.
 *
 * Deliberately NOT extending AbstractApiController: these responses are the
 * same for every account, so the per-account no-store caching policy and the
 * content repository plumbing do not apply here.
 */
class DocsController extends ActionController
{
    private const SPEC_RESOURCE = 'resource://Medienreaktor.NeosApi/Private/OpenApi/openapi.yaml';
    private const SCALAR_RESOURCE = 'resource://Medienreaktor.NeosApi/Public/Docs/scalar-api-reference-1.65.1.min.js';

    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $oauthSettings;

    /**
     * The OpenAPI document as JSON. The YAML source is host-agnostic; the
     * request-dependent parts (server URL, OAuth endpoint URLs and the
     * configured scope catalog) are stamped in at serve time so the document
     * is immediately usable by "try it" clients and code generators.
     */
    public function specAction(): string
    {
        $spec = Yaml::parse((string)file_get_contents(self::SPEC_RESOURCE));

        $spec['servers'] = [['url' => $this->getBaseUri()]];

        $issuer = $this->getIssuer();
        $scopes = (array)($this->oauthSettings['scopes'] ?? []);
        foreach ($spec['components']['securitySchemes'] ?? [] as $schemeName => $scheme) {
            if (($scheme['type'] ?? '') !== 'oauth2') {
                continue;
            }
            foreach ($scheme['flows'] ?? [] as $flowName => $flow) {
                $patched = &$spec['components']['securitySchemes'][$schemeName]['flows'][$flowName];
                if (array_key_exists('authorizationUrl', $flow)) {
                    $patched['authorizationUrl'] = $issuer . '/oauth/authorize';
                }
                if (array_key_exists('tokenUrl', $flow)) {
                    $patched['tokenUrl'] = $issuer . '/oauth/token';
                }
                if (array_key_exists('refreshUrl', $flow)) {
                    $patched['refreshUrl'] = $issuer . '/oauth/token';
                }
                $patched['scopes'] = $scopes;
                unset($patched);
            }
        }

        $this->response->setContentType('application/json');
        $this->response->setHttpHeader('Cache-Control', 'public, max-age=300');

        return json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The interactive API reference (Scalar) rendering the spec above.
     * Scalar mounts itself declaratively on the data-url/data-configuration
     * attributes, so the Fluid template contains no inline script.
     */
    public function docsAction(): void
    {
        $this->response->setContentType('text/html');
        $this->response->setHttpHeader('Cache-Control', 'public, max-age=300');

        $this->view->assign('specUrl', $this->getBaseUri() . '/api/openapi.json');
        $this->view->assign('scalarScriptUri', $this->resourceManager->getPublicPackageResourceUriByPath(self::SCALAR_RESOURCE));
        $this->view->assign('configuration', json_encode([
            'hideClientButton' => true,
            'authentication' => ['preferredSecurityScheme' => 'oauth2'],
        ], JSON_THROW_ON_ERROR));
    }

    private function getBaseUri(): string
    {
        $uri = $this->request->getHttpRequest()->getUri();
        $baseUri = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $baseUri .= ':' . $uri->getPort();
        }

        return $baseUri;
    }

    private function getIssuer(): string
    {
        $issuer = (string)($this->oauthSettings['issuer'] ?? '');

        return $issuer !== '' ? rtrim($issuer, '/') : $this->getBaseUri();
    }
}
