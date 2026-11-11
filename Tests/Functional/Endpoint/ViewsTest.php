<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Rendering needs a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the content repository lookup
 */
class ViewsTest extends EndpointTestCase
{
    private const array NODE = ['contentRepositoryId' => 'unknown', 'nodeAggregateId' => 'some-node'];

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $userService->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsTheViewsOfTheSettings(): void
    {
        $response = $this->get('/api/views', $this->token('editor-machine', 'views.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        // the package's own first, the removed one is left out
        self::assertSame(['documentNodesTree', 'contentNodesTree', 'document', 'fusionPathHtml', 'navigation'], array_column(self::json($response)['data'], 'name'));
        self::assertSame(['name' => 'navigation', 'description' => 'A test view'], self::json($response)['data'][4]);
        self::assertSame(['total' => 5], self::json($response)['meta']);
    }

    #[Test]
    public function unknownViewsAreNotFound(): void
    {
        $response = $this->get(self::viewPath('unknown', self::NODE), $this->token('editor-machine', 'views.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no view unknown', self::json($response)['detail']);
        self::assertSame(404, $this->get(self::viewPath('removed', self::NODE), $this->token('editor-machine', 'views.read'))->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidParameters(): void
    {
        $token = $this->token('editor-machine', 'views.read');
        // their schemas reject them
        foreach ([['nodeAggregateId' => 'Not_An_Id'], ['contentRepositoryId' => 'Not-An-Id'], ['workspaceName' => 'Not A Workspace'], ['dimensionSpacePoint' => 'de']] as $query) {
            self::assertSame(400, $this->get(self::viewPath('navigation', $query + self::NODE), $token)->getStatusCode(), json_encode($query));
        }

        // a syntax error before the content repository is looked up
        $response = $this->get(self::viewPath('navigation', ['dimensionSpacePoint' => '{not json}'] + self::NODE), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('dimensionSpacePoint is invalid', self::json($response)['detail']);
    }

    #[Test]
    public function takesArgumentsAsDeepObject(): void
    {
        $token = $this->token('editor-machine', 'views.read');

        // up to the content repository lookup, arrays included
        $response = $this->get(self::viewPath('fusionPathHtml', ['arguments' => ['fusionPath' => 'root/main', 'tags' => ['a', 'b']]] + self::NODE), $token);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('unknown', self::json($response)['detail']);

        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/views/{viewName}']['get']['parameters'], null, 'name');
        self::assertSame('deepObject', $parameters['arguments']['style'] ?? null);
        // the shared component, described by the operation
        self::assertContains(['$ref' => '#/components/schemas/Arguments'], $parameters['arguments']['schema']['anyOf'] ?? []);
        self::assertStringContainsString('arguments[fusionPath]', $parameters['arguments']['description']);
    }

    #[Test]
    public function nodesOfUnknownContentRepositoriesAreNotFound(): void
    {
        $token = $this->token('editor-machine', 'views.read');

        foreach ([self::NODE, ['contentRepositoryId' => 'unknown'], self::NODE + ['workspaceName' => 'user-editor', 'dimensionSpacePoint' => '{"language":"de"}']] as $query) {
            $response = $this->get(self::viewPath('navigation', $query), $token);
            self::assertSame(404, $response->getStatusCode(), json_encode($query) . ': ' . $response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }
    }

    #[Test]
    public function withoutAContentRepositoryTheDefaultSiteIsNeeded(): void
    {
        // there is no site in the Testing context
        foreach ([[], ['nodeAggregateId' => 'some-node']] as $query) {
            $response = $this->get(self::viewPath('navigation', $query), $this->token('editor-machine', 'views.read'));

            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no default site to render the view for, give a contentRepositoryId', self::json($response)['detail']);
        }
    }

    #[Test]
    public function rejectsUnknownRenderingModes(): void
    {
        $response = $this->get(self::viewPath('navigation', self::NODE + ['renderingMode' => 'unknown']), $this->token('editor-machine', 'views.read'));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        // site packages may add modes
        self::assertStringStartsWith('There is no rendering mode unknown, there are: frontend, inPlace, rawContent, desktop', self::json($response)['detail']);
    }

    #[Test]
    public function backendRenderingModesNeedBackendAccess(): void
    {
        // every account may render views in the frontend mode: the request gets as far as the content repository lookup
        $response = $this->get(self::viewPath('navigation', self::NODE + ['renderingMode' => 'frontend']), $this->token('nobody-machine', 'views.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());

        $response = $this->get(self::viewPath('navigation', self::NODE + ['renderingMode' => 'inPlace']), $this->token('nobody-machine', 'views.read'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The rendering mode inPlace needs the privilege Neos.Neos:Backend.GeneralAccess', self::json($response)['detail']);

        // editors have it
        $response = $this->get(self::viewPath('navigation', self::NODE + ['renderingMode' => 'inPlace']), $this->token('editor-machine', 'views.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $token = $this->token('editor-machine', 'me.read');

        foreach (['/api/views', self::viewPath('navigation', self::NODE)] as $path) {
            $response = $this->get($path, $token);
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('views.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/views', null)->getStatusCode());
        self::assertSame(401, $this->get(self::viewPath('navigation', self::NODE), null)->getStatusCode());
    }

    /**
     * @param array<string, string> $query
     */
    private static function viewPath(string $viewName, array $query): string
    {
        return sprintf('/api/views/%s', $viewName) . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
