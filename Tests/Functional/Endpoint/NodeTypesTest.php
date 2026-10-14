<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class NodeTypesTest extends EndpointTestCase
{
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
    public function listsTheNodeTypesWithoutTheirConfiguration(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeTypes = array_column(self::json($response)['data'], null, 'name');
        $names = array_keys($nodeTypes);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);

        $document = $nodeTypes['Neos.Neos:Document'];
        self::assertTrue($document['isAbstract']);
        self::assertNull($document['properties']);
        self::assertNull($document['references']);
        self::assertNull($document['configuration']);
        self::assertNull($document['allowedChildNodeTypes']);
        self::assertNull($document['tetheredChildNodes']);
        self::assertContains('Neos.Neos:Node', $nodeTypes['Neos.Neos:Document']['superTypes']);
    }

    #[Test]
    public function listsTheGroupsInTheMeta(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes', $this->token('editor-machine', 'nodetypes.read'), ['Accept-Language' => 'de']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $meta = self::json($response)['meta'];
        self::assertSame(count(self::json($response)['data']), $meta['total']);
        $groups = array_column($meta['groups'], null, 'name');
        // Neos.Neos' groups, in the order of their positions, translated
        self::assertSame(['general', 'structure', 'plugins'], array_values(array_intersect(array_keys($groups), ['general', 'structure', 'plugins'])));
        self::assertSame('general', $meta['groups'][0]['name']);
        self::assertSame(['name' => 'plugins', 'label' => 'Plugins', 'position' => 200, 'collapsed' => true], $groups['plugins']);
        self::assertSame('start', $groups['general']['position']);
        self::assertFalse($groups['general']['collapsed']);
        self::assertSame('Struktur', $groups['structure']['label']);
    }

    #[Test]
    public function includesPropertiesReferencesAndConfigurationInTheList(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes?include=properties,references,configuration', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $document = array_column(self::json($response)['data'], null, 'name')['Neos.Neos:Document'];
        $properties = array_column($document['properties'], null, 'name');
        self::assertSame('string', $properties['title']['type']);
        self::assertArrayNotHasKey('_hidden', $properties);
        self::assertIsArray($document['references']);
        self::assertArrayHasKey('title', $document['configuration']['properties']);
    }

    #[Test]
    public function includesTheAllowedChildNodeTypesInTheList(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes?include=allowedChildNodeTypes,tetheredChildNodes', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeTypes = array_column(self::json($response)['data'], null, 'name');
        $page = $nodeTypes['Neos.Demo:Document.Page'];
        // Neos.Demo allows only its sub pages below documents
        self::assertContains('Neos.Demo:Document.Page', $page['allowedChildNodeTypes']);
        self::assertNotContains('Neos.Neos:Shortcut', $page['allowedChildNodeTypes']);
        self::assertNotContains('Neos.Demo:Content.Text', $page['allowedChildNodeTypes']);
        self::assertSame(['main'], array_column($page['tetheredChildNodes'], 'name'));
        self::assertSame([], $nodeTypes['Neos.Demo:Content.Text']['tetheredChildNodes']);
    }

    #[Test]
    public function getsTheConstraintsOfTetheredChildNodes(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes/Neos.Demo:Document.Page', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeType = self::json($response);
        $main = array_column($nodeType['tetheredChildNodes'], null, 'name')['main'];
        self::assertSame('Neos.Demo:Collection.Content.Main', $main['nodeType']);
        self::assertContains('Neos.Demo:Content.Text', $main['allowedChildNodeTypes']);
        self::assertNotContains('Neos.Demo:Document.Page', $main['allowedChildNodeTypes']);
        // neither abstract nor root node types
        self::assertNotContains('Neos.Neos:Content', $main['allowedChildNodeTypes']);
        self::assertNotContains('Neos.Neos:Sites', $nodeType['allowedChildNodeTypes']);
        $sorted = $main['allowedChildNodeTypes'];
        sort($sorted);
        self::assertSame($sorted, $main['allowedChildNodeTypes']);
    }

    #[Test]
    public function rejectsUnknownIncludes(): void
    {
        $response = $this->get('/api/cr/default/nodetypes?include=properties,children', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('children', self::json($response)['detail']);
    }

    #[Test]
    public function filtersBySuperType(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes?filterBySuperType=Neos.Neos:Document', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $names = array_column(self::json($response)['data'], 'name');
        self::assertContains('Neos.Neos:Document', $names);
        self::assertContains('Neos.Neos:Shortcut', $names);
        self::assertNotContains('Neos.Neos:Content', $names);

        self::assertSame(400, $this->get('/api/cr/default/nodetypes?filterBySuperType=Vendor.Unknown:Type', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function documentsTheFilterAsPlainParameter(): void
    {
        $parameters = array_column(self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/nodetypes']['get']['parameters'], null, 'name');
        self::assertArrayNotHasKey('style', $parameters['filterBySuperType']);
        self::assertArrayNotHasKey('filter', $parameters);

        // not a node type name, its schema rejects it before any content repository is asked
        self::assertSame(400, $this->get('/api/cr/default/nodetypes?filterBySuperType=NoColon', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function getsANodeTypeWithEverything(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes/Neos.Neos:Document', $this->token('editor-machine', 'nodetypes.read'), ['Accept-Language' => 'de']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $nodeType = self::json($response);
        self::assertSame('Neos.Neos:Document', $nodeType['name']);
        self::assertContains('title', array_column($nodeType['properties'], 'name'));
        self::assertIsArray($nodeType['references']);
        self::assertSame('de', $response->getHeaderLine('Content-Language'));
        // translated, not the translation ID Neos.Neos:NodeTypes.Document:properties.title
        self::assertSame('Titel', $nodeType['configuration']['properties']['title']['ui']['label']);
    }

    #[Test]
    public function unknownNodeTypesAreNotFound(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default/nodetypes/Vendor.Unknown:Type', $this->token('editor-machine', 'nodetypes.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function unknownContentRepositoriesAreNotFound(): void
    {
        self::assertSame(404, $this->get('/api/cr/unknown/nodetypes', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
        self::assertSame(404, $this->get('/api/cr/unknown/nodetypes/Neos.Neos:Document', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidNames(): void
    {
        self::assertSame(400, $this->get('/api/cr/default/nodetypes/NoColon', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/Not-An-Id/nodetypes', $this->token('editor-machine', 'nodetypes.read'))->getStatusCode());
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/cr/default/nodetypes', $this->token('nobody-machine', 'nodetypes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:NodeTypes.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/cr/default/nodetypes', $this->token('editor-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('nodetypes.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/nodetypes', null)->getStatusCode());
    }

    private function requireContentRepository(): void
    {
        if (!$this->objectManager->get(EntityManagerInterface::class)->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
    }
}
