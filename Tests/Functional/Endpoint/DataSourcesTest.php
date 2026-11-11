<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * The data sources are the fixtures in Tests/Functional/Fixtures, which the Testing context registers. Reading a node
 * needs a content repository, which the Testing context (SQLite) doesn't have: these tests cover the node context up
 * to the content repository lookup
 */
class DataSourcesTest extends EndpointTestCase
{
    private const string ECHO = 'neos-api-test-echo';

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:RestrictedEditor']);
        $userService->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsTheDataSources(): void
    {
        $response = $this->get('/api/datasources', $this->token('editor-machine', 'datasources.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $ids = array_column(self::json($response)['data'], 'id');
        // other packages may add theirs
        self::assertContains(self::ECHO, $ids);
        self::assertContains('neos-api-test-failing', $ids);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        self::assertSame(['id' => self::ECHO], self::json($response)['data'][array_search(self::ECHO, $ids, true)]);
        self::assertSame(['total' => count($ids)], self::json($response)['meta']);
    }

    #[Test]
    public function returnsTheDataWithoutANode(): void
    {
        $response = $this->get(self::path(self::ECHO, []), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['data' => ['node' => null, 'arguments' => []]], self::json($response));
    }

    #[Test]
    public function passesTheArguments(): void
    {
        $response = $this->get(self::path(self::ECHO, ['arguments' => ['parentCategory' => 'news', 'tags' => ['a', 'b']]]), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['parentCategory' => 'news', 'tags' => ['a', 'b']], self::json($response)['data']['arguments']);

        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/datasources/{dataSourceId}']['get']['parameters'], null, 'name');
        self::assertSame('deepObject', $parameters['arguments']['style'] ?? null);
        // the shared component, described by the operation
        self::assertContains(['$ref' => '#/components/schemas/Arguments'], $parameters['arguments']['schema']['anyOf'] ?? []);
        self::assertStringContainsString('dataSourceAdditionalData', $parameters['arguments']['description']);
    }

    #[Test]
    public function unknownDataSourcesAreNotFound(): void
    {
        $response = $this->get(self::path('unknown', []), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no data source unknown', self::json($response)['detail']);
    }

    #[Test]
    public function failingDataSourcesDontTellWhy(): void
    {
        $response = $this->get(self::path('neos-api-test-failing', []), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(500, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The data source neos-api-test-failing failed', self::json($response)['detail']);
        self::assertStringNotContainsString('secret internals', (string)$response->getBody());
    }

    #[Test]
    public function whereToReadTheNodeNeedsTheNode(): void
    {
        $token = $this->token('editor-machine', 'datasources.read');

        foreach ([['contentRepositoryId' => 'default'], ['workspaceName' => 'live'], ['dimensionSpacePoint' => '{}']] as $query) {
            $response = $this->get(self::path(self::ECHO, $query), $token);
            self::assertSame(400, $response->getStatusCode(), json_encode($query) . ': ' . $response->getBody());
            self::assertSame('contentRepositoryId, workspaceName and dimensionSpacePoint are where to read the node, give nodeAggregateId as well', self::json($response)['detail']);
        }
    }

    #[Test]
    public function rejectsInvalidParameters(): void
    {
        $token = $this->token('editor-machine', 'datasources.read');
        $node = ['contentRepositoryId' => 'unknown', 'nodeAggregateId' => 'some-node'];

        // their schemas reject them
        foreach ([['nodeAggregateId' => 'Not_An_Id'], ['contentRepositoryId' => 'Not-An-Id'], ['workspaceName' => 'Not A Workspace'], ['arguments' => 'not-an-object']] as $query) {
            self::assertSame(400, $this->get(self::path(self::ECHO, $query + $node), $token)->getStatusCode(), json_encode($query));
        }

        // a syntax error before the content repository is looked up
        $response = $this->get(self::path(self::ECHO, ['dimensionSpacePoint' => '{not json}'] + $node), $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringStartsWith('dimensionSpacePoint is invalid', self::json($response)['detail']);
    }

    #[Test]
    public function nodesOfUnknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get(self::path(self::ECHO, ['contentRepositoryId' => 'unknown', 'nodeAggregateId' => 'some-node']), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function withoutAContentRepositoryTheDefaultSiteIsNeeded(): void
    {
        // there is no site in the Testing context
        $response = $this->get(self::path(self::ECHO, ['nodeAggregateId' => 'some-node']), $this->token('editor-machine', 'datasources.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no default site to read the node in, give contentRepositoryId', self::json($response)['detail']);
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/datasources', $this->token('nobody-machine', 'datasources.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:DataSources.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $token = $this->token('editor-machine', 'me.read');

        foreach (['/api/datasources', self::path(self::ECHO, [])] as $path) {
            $response = $this->get($path, $token);
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('datasources.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/datasources', null)->getStatusCode());
        self::assertSame(401, $this->get(self::path(self::ECHO, []), null)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function path(string $dataSourceId, array $query): string
    {
        return sprintf('/api/datasources/%s', $dataSourceId) . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
