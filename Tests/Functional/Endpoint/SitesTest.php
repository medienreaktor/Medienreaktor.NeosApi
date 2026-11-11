<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class SitesTest extends ContentRepositoryTestCase
{
    private const string UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private string $primaryDomainId;

    private string $secondDomainId;

    private string $otherSitesDomainId;

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('admin-machine', 'admin');
        $this->addMachineClient('editor-machine', 'editor');

        $demo = $this->addSite('demo', 'Demo Site', Site::STATE_ONLINE);
        $primaryDomain = $this->addDomain($demo, 'www.example.com', 'https', null);
        $demo->setPrimaryDomain($primaryDomain);
        $this->primaryDomainId = $this->persistenceManager->getIdentifierByObject($primaryDomain);
        $this->secondDomainId = $this->persistenceManager->getIdentifierByObject($this->addDomain($demo, 'example.com', null, 8080));
        $other = $this->addSite('other', 'Another Site', Site::STATE_OFFLINE);
        $this->otherSitesDomainId = $this->persistenceManager->getIdentifierByObject($this->addDomain($other, 'other.example.com', null, null));
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
    }

    #[Test]
    public function listsAllSitesForAdministrators(): void
    {
        $response = $this->get('/api/sites', $this->token('admin-machine', 'sites.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        // not paged, so no links
        self::assertSame(['data', 'meta'], array_keys(self::json($response)));
        self::assertSame(['total' => 2], self::json($response)['meta']);
        $sites = self::json($response)['data'];
        self::assertSame(['Another Site', 'Demo Site'], array_column($sites, 'name'));
        self::assertSame([
            'nodeName' => 'demo',
            'name' => 'Demo Site',
            'online' => true,
            'contentRepositoryId' => 'default',
            'siteResourcesPackageKey' => 'Neos.Demo',
            'domains' => [
                ['id' => $this->secondDomainId, 'hostname' => 'example.com', 'scheme' => null, 'port' => 8080, 'active' => true, 'isPrimary' => false, 'url' => 'example.com:8080'],
                ['id' => $this->primaryDomainId, 'hostname' => 'www.example.com', 'scheme' => 'https', 'port' => null, 'active' => true, 'isPrimary' => true, 'url' => 'https://www.example.com'],
            ],
            'primaryDomain' => 'https://www.example.com',
            // there is no content repository in the Testing context
            'nodeAggregateId' => null,
            'defaultDimensionSpacePoint' => '{"language":"en_US"}',
        ], $sites[1]);
        self::assertFalse($sites[0]['online']);
    }

    #[Test]
    public function getsASite(): void
    {
        $response = $this->get('/api/sites/other', $this->token('admin-machine', 'sites.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Another Site', 'other.example.com'], [self::json($response)['name'], self::json($response)['primaryDomain']]);
    }

    #[Test]
    public function unknownSitesAndDomainsAreNotFound(): void
    {
        $response = $this->get('/api/sites/unknown', $this->token('admin-machine', 'sites.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $token = $this->token('admin-machine', 'sites.update');
        self::assertSame(404, $this->patch('/api/sites/unknown', $token, ['name' => 'Nobody'])->getStatusCode());
        self::assertSame(404, $this->post('/api/sites/unknown/domains', $token, ['hostname' => 'new.example.com'])->getStatusCode());
        self::assertSame(404, $this->patch('/api/sites/demo/domains/' . self::UNKNOWN_ID, $token, ['active' => false])->getStatusCode());
        self::assertSame(404, $this->get('/api/sites/demo/domains/' . self::UNKNOWN_ID, $this->token('admin-machine', 'sites.read'))->getStatusCode());
        self::assertSame(404, $this->get('/api/sites/demo/domains/' . $this->otherSitesDomainId, $this->token('admin-machine', 'sites.read'))->getStatusCode());
        self::assertSame(404, $this->get('/api/sites/unknown/domains/' . $this->primaryDomainId, $this->token('admin-machine', 'sites.read'))->getStatusCode());
        // a domain of another site isn't one of this site
        self::assertSame(404, $this->patch('/api/sites/demo/domains/' . $this->otherSitesDomainId, $token, ['active' => false])->getStatusCode());
        self::assertSame(404, $this->delete('/api/sites/demo/domains/' . $this->otherSitesDomainId, $token)->getStatusCode());
        self::assertSame(404, $this->delete('/api/sites/unknown', $this->token('admin-machine', 'sites.delete'))->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidPathParameters(): void
    {
        self::assertSame(400, $this->get('/api/sites/Not_A_Node_Name', $this->token('admin-machine', 'sites.read'))->getStatusCode());
        self::assertSame(400, $this->delete('/api/sites/demo/domains/not-a-uuid', $this->token('admin-machine', 'sites.update'))->getStatusCode());
    }

    #[Test]
    public function editorsReadSites(): void
    {
        $token = $this->token('editor-machine', 'sites.read');
        self::assertSame(200, $this->get('/api/sites', $token)->getStatusCode());
        self::assertSame(200, $this->get('/api/sites/demo', $token)->getStatusCode());
        self::assertSame(200, $this->get('/api/sites/demo/domains/' . $this->primaryDomainId, $token)->getStatusCode());
    }

    #[Test]
    public function changingIsDeniedToEditors(): void
    {
        foreach (
            [
            ['sites.create', 'Neos.Api:Sites.Create', fn (string $token) => $this->post('/api/sites', $token, ['packageKey' => 'Neos.Demo', 'name' => 'New Site', 'nodeTypeName' => 'Neos.Demo:Document.Homepage'])],
            ['sites.update', 'Neos.Api:Sites.Update', fn (string $token) => $this->patch('/api/sites/demo', $token, ['name' => 'Evil'])],
            ['sites.update', 'Neos.Api:Sites.Update', fn (string $token) => $this->post('/api/sites/demo/domains', $token, ['hostname' => 'evil.example.com'])],
            ['sites.update', 'Neos.Api:Sites.Update', fn (string $token) => $this->patch('/api/sites/demo/domains/' . $this->primaryDomainId, $token, ['active' => false])],
            ['sites.update', 'Neos.Api:Sites.Update', fn (string $token) => $this->delete('/api/sites/demo/domains/' . $this->primaryDomainId, $token)],
            ['sites.delete', 'Neos.Api:Sites.Delete', fn (string $token) => $this->delete('/api/sites/demo', $token)],
            ] as [$scope, $privilege, $request]
        ) {
            $response = $request($this->token('editor-machine', $scope));

            self::assertSame(403, $response->getStatusCode(), $privilege . ': ' . $response->getBody());
            self::assertStringContainsString($privilege, self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresTheScopes(): void
    {
        $token = $this->token('admin-machine', 'sites.read');
        foreach (
            [
            ['sites.update', $this->patch('/api/sites/demo', $token, ['name' => 'Renamed'])],
            ['sites.update', $this->post('/api/sites/demo/domains', $token, ['hostname' => 'new.example.com'])],
            ['sites.delete', $this->delete('/api/sites/demo', $token)],
            ] as [$scope, $response]
        ) {
            self::assertSame(403, $response->getStatusCode(), $scope . ': ' . $response->getBody());
            self::assertStringContainsString($scope, self::json($response)['detail']);
        }
        self::assertSame(403, $this->get('/api/sites', $this->token('admin-machine', 'sites.update'))->getStatusCode());
        self::assertSame(403, $this->get('/api/sites/demo/domains/' . $this->primaryDomainId, $this->token('admin-machine', 'sites.update'))->getStatusCode());
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/sites', null)->getStatusCode());
        self::assertSame(401, $this->patch('/api/sites/demo', null, ['name' => 'Renamed'])->getStatusCode());
        self::assertSame(401, $this->delete('/api/sites/demo', null)->getStatusCode());
    }

    #[Test]
    public function administratorsChangeSites(): void
    {
        $response = $this->patch('/api/sites/demo', $this->token('admin-machine', 'sites.update'), ['name' => 'Renamed Site', 'online' => false, 'primaryDomainId' => $this->secondDomainId]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $site = self::json($response);
        self::assertSame(['Renamed Site', false, 'example.com:8080'], [$site['name'], $site['online'], $site['primaryDomain']]);
        self::assertSame([true, false], array_column($site['domains'], 'isPrimary'));

        $this->persistenceManager->clearState();
        $site = self::json($this->get('/api/sites/demo', $this->token('admin-machine', 'sites.read')));
        self::assertSame(['Renamed Site', false, 'example.com:8080'], [$site['name'], $site['online'], $site['primaryDomain']]);
    }

    #[Test]
    public function thePrimaryDomainIsAnActiveDomainOfTheSite(): void
    {
        $token = $this->token('admin-machine', 'sites.update');
        self::assertSame(200, $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $token, ['active' => false])->getStatusCode());

        foreach ([$this->otherSitesDomainId, $this->secondDomainId, self::UNKNOWN_ID] as $domainId) {
            $response = $this->patch('/api/sites/demo', $token, ['primaryDomainId' => $domainId]);

            self::assertSame(422, $response->getStatusCode(), $domainId . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function rejectsInvalidSiteChanges(): void
    {
        $token = $this->token('admin-machine', 'sites.update');
        foreach ([['name' => ''], ['name' => 'No <tags>'], ['online' => 'offline'], ['nodeName' => 'renamed'], ['primaryDomainId' => 'www.example.com']] as $body) {
            $response = $this->patch('/api/sites/demo', $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function administratorsAddDomains(): void
    {
        $response = $this->post('/api/sites/other/domains', $this->token('admin-machine', 'sites.update'), ['hostname' => 'www.other.example.com', 'scheme' => 'https', 'port' => 8443, 'active' => false]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $domain = self::json($response);
        self::assertSame(['www.other.example.com', 'https', 8443, false, false, 'https://www.other.example.com:8443'], [$domain['hostname'], $domain['scheme'], $domain['port'], $domain['active'], $domain['isPrimary'], $domain['url']]);
        self::assertSame('domains/' . $domain['id'], $response->getHeaderLine('Location'));

        $this->persistenceManager->clearState();
        $site = self::json($this->get('/api/sites/other', $this->token('admin-machine', 'sites.read')));
        self::assertSame(['other.example.com', 'www.other.example.com'], array_column($site['domains'], 'hostname'));
        self::assertSame($domain, $site['domains'][1]);
        self::assertSame($domain, self::json($this->get('/api/sites/other/domains/' . $domain['id'], $this->token('admin-machine', 'sites.read'))));
    }

    #[Test]
    public function newDomainsAreActiveByDefault(): void
    {
        $response = $this->post('/api/sites/other/domains', $this->token('admin-machine', 'sites.update'), ['hostname' => 'localhost']);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([true, null, null], [self::json($response)['active'], self::json($response)['scheme'], self::json($response)['port']]);
    }

    #[Test]
    public function getsADomain(): void
    {
        $response = $this->get('/api/sites/demo/domains/' . $this->primaryDomainId, $this->token('admin-machine', 'sites.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['id' => $this->primaryDomainId, 'hostname' => 'www.example.com', 'scheme' => 'https', 'port' => null, 'active' => true, 'isPrimary' => true, 'url' => 'https://www.example.com'], self::json($response));
    }

    #[Test]
    public function hostnamesAreUnique(): void
    {
        $token = $this->token('admin-machine', 'sites.update');

        $response = $this->post('/api/sites/other/domains', $token, ['hostname' => 'www.example.com']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        self::assertSame(409, $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $token, ['hostname' => 'other.example.com'])->getStatusCode());
        // a domain keeps its own host name
        self::assertSame(200, $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $token, ['hostname' => 'example.com'])->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidDomains(): void
    {
        $token = $this->token('admin-machine', 'sites.update');
        foreach ([['hostname' => 'https://new.example.com'], ['hostname' => 'new.example.com:80'], ['hostname' => 'new.example.com/path'], ['hostname' => '-new.example.com'], ['hostname' => 'new.example.com', 'scheme' => 'ftp'], ['hostname' => 'new.example.com', 'port' => 0], ['hostname' => 'new.example.com', 'port' => 50000], ['scheme' => 'https']] as $body) {
            $response = $this->post('/api/sites/demo/domains', $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function administratorsChangeDomains(): void
    {
        $response = $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $this->token('admin-machine', 'sites.update'), ['hostname' => 'example.org', 'scheme' => 'http', 'port' => 8081]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([$this->secondDomainId, 'http://example.org:8081'], [self::json($response)['id'], self::json($response)['url']]);
    }

    #[Test]
    public function nullRemovesTheSchemeAndPort(): void
    {
        $token = $this->token('admin-machine', 'sites.update');

        // left out, they stay
        $response = $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $token, ['scheme' => 'https']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['https', 8080], [self::json($response)['scheme'], self::json($response)['port']]);

        $response = $this->patch('/api/sites/demo/domains/' . $this->secondDomainId, $token, ['scheme' => null, 'port' => null]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([null, null, 'example.com'], [self::json($response)['scheme'], self::json($response)['port'], self::json($response)['url']]);

        $this->persistenceManager->clearState();
        $domain = $this->objectManager->get(DomainRepository::class)->findByIdentifier($this->secondDomainId);
        self::assertNotNull($domain);
        self::assertSame([null, null], [$domain->getScheme(), $domain->getPort()]);
    }

    #[Test]
    public function deactivatingThePrimaryDomainUnsetsIt(): void
    {
        $response = $this->patch('/api/sites/demo/domains/' . $this->primaryDomainId, $this->token('admin-machine', 'sites.update'), ['active' => false]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([false, false], [self::json($response)['active'], self::json($response)['isPrimary']]);

        $this->persistenceManager->clearState();
        $site = $this->objectManager->get(SiteRepository::class)->findOneByNodeName('demo');
        self::assertNotNull($site);
        self::assertNull($site->getPrimaryDomain(false));
        self::assertSame('example.com:8080', self::json($this->get('/api/sites/demo', $this->token('admin-machine', 'sites.read')))['primaryDomain']);
    }

    #[Test]
    public function removingThePrimaryDomainFallsBackToAnActiveOne(): void
    {
        $response = $this->delete('/api/sites/demo/domains/' . $this->primaryDomainId, $this->token('admin-machine', 'sites.update'));

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('', (string)$response->getBody());

        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(DomainRepository::class)->findByIdentifier($this->primaryDomainId));
        $site = $this->objectManager->get(SiteRepository::class)->findOneByNodeName('demo');
        self::assertNotNull($site);
        self::assertNull($site->getPrimaryDomain(false));
        $site = self::json($this->get('/api/sites/demo', $this->token('admin-machine', 'sites.read')));
        self::assertSame(['example.com'], array_column($site['domains'], 'hostname'));
        self::assertSame('example.com:8080', $site['primaryDomain']);
    }

    #[Test]
    public function administratorsCreateSites(): void
    {
        $this->requireContentRepository();
        $response = $this->post('/api/sites', $this->token('admin-machine', 'sites.create'), ['packageKey' => 'Neos.Demo', 'name' => 'Neue Seite', 'nodeTypeName' => 'Neos.Demo:Document.Homepage', 'online' => false]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $site = self::json($response);
        self::assertSame(['neue-seite', 'Neue Seite', false, 'Neos.Demo', [], null], [$site['nodeName'], $site['name'], $site['online'], $site['siteResourcesPackageKey'], $site['domains'], $site['primaryDomain']]);
        self::assertSame('sites/neue-seite', $response->getHeaderLine('Location'));

        $this->persistenceManager->clearState();
        self::assertSame(200, $this->get('/api/sites/neue-seite', $this->token('admin-machine', 'sites.read'))->getStatusCode());
    }

    #[Test]
    public function administratorsDeleteSites(): void
    {
        $this->requireContentRepository();
        $response = $this->delete('/api/sites/demo', $this->token('admin-machine', 'sites.delete'));

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(SiteRepository::class)->findOneByNodeName('demo'));
        self::assertNull($this->objectManager->get(DomainRepository::class)->findByIdentifier($this->primaryDomainId));
    }

    #[Test]
    public function siteNodeNamesAreUnique(): void
    {
        $token = $this->token('admin-machine', 'sites.create');

        $response = $this->post('/api/sites', $token, ['packageKey' => 'Neos.Demo', 'name' => 'Demo', 'nodeTypeName' => 'Neos.Demo:Document.Homepage']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('demo', self::json($response)['detail']);

        $response = $this->post('/api/sites', $token, ['packageKey' => 'Neos.Demo', 'name' => 'Something else', 'nodeTypeName' => 'Neos.Demo:Document.Homepage', 'nodeName' => 'other']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function derivedSiteNodeNamesAreCutToTheirMaximumLength(): void
    {
        // transliterated, each character becomes three or four
        $name = str_repeat('北', 200);
        $nodeName = substr(NodeName::transliterateFromString($name)->value, 0, 250);
        $this->addSite($nodeName, 'Long', Site::STATE_ONLINE);
        $this->persistenceManager->persistAll();

        $response = $this->post('/api/sites', $this->token('admin-machine', 'sites.create'), ['packageKey' => 'Neos.Demo', 'name' => $name, 'nodeTypeName' => 'Neos.Demo:Document.Homepage']);

        // the site exists already: the derived node name is the cut one
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString($nodeName, self::json($response)['detail']);
    }

    #[Test]
    public function rejectsUnknownPackages(): void
    {
        $response = $this->post('/api/sites', $this->token('admin-machine', 'sites.create'), ['packageKey' => 'Neos.Nonexistent', 'name' => 'New Site', 'nodeTypeName' => 'Neos.Demo:Document.Homepage']);

        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Nonexistent', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsNodeTypesThatArentSites(): void
    {
        $this->requireContentRepository();
        $token = $this->token('admin-machine', 'sites.create');
        foreach (['Neos.Demo:Nonexistent', 'Neos.Neos:Site', 'Neos.Demo:Document.Page'] as $nodeTypeName) {
            $response = $this->post('/api/sites', $token, ['packageKey' => 'Neos.Demo', 'name' => 'New Site', 'nodeTypeName' => $nodeTypeName]);

            self::assertSame(422, $response->getStatusCode(), $nodeTypeName . ': ' . $response->getBody());
        }
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(SiteRepository::class)->findOneByNodeName('new-site'));
    }

    #[Test]
    public function rejectsInvalidNewSites(): void
    {
        $token = $this->token('admin-machine', 'sites.create');
        $valid = ['packageKey' => 'Neos.Demo', 'name' => 'New Site', 'nodeTypeName' => 'Neos.Demo:Document.Homepage'];
        foreach ([['packageKey' => 'Demo'], ['name' => ''], ['nodeTypeName' => 'Homepage'], ['nodeName' => 'New Site'], ['online' => 'offline'], ['domains' => []]] as $change) {
            $response = $this->post('/api/sites', $token, array_merge($valid, $change));

            self::assertSame(400, $response->getStatusCode(), json_encode($change) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));

        self::assertSame(['listSites', 'createSite', 'getSite', 'updateSite', 'deleteSite', 'createDomain', 'getDomain', 'updateDomain', 'deleteDomain'], [
            $document['paths']['/sites']['get']['operationId'],
            $document['paths']['/sites']['post']['operationId'],
            $document['paths']['/sites/{siteNodeName}']['get']['operationId'],
            $document['paths']['/sites/{siteNodeName}']['patch']['operationId'],
            $document['paths']['/sites/{siteNodeName}']['delete']['operationId'],
            $document['paths']['/sites/{siteNodeName}/domains']['post']['operationId'],
            $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['get']['operationId'],
            $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['patch']['operationId'],
            $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['delete']['operationId'],
        ]);
        self::assertArrayNotHasKey('/sites/options', $document['paths']);
        self::assertSame('#/components/schemas/Domain', $document['paths']['/sites/{siteNodeName}/domains']['post']['responses'][201]['content']['application/json']['schema']['$ref']);
        self::assertArrayHasKey('Location', $document['paths']['/sites/{siteNodeName}/domains']['post']['responses'][201]['headers']);
        self::assertSame('#/components/schemas/Domain', $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['patch']['responses'][200]['content']['application/json']['schema']['$ref']);
        // no 204 yet: neos/openapi reads NotFound|null as a nullable NotFound, not as a union with null
        self::assertArrayHasKey(404, $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['delete']['responses']);
        self::assertSame([['oauth2' => ['sites.read']]], $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['get']['security']);
        self::assertSame([['oauth2' => ['sites.read']]], $document['paths']['/sites']['get']['security']);
        self::assertSame([['oauth2' => ['sites.update']]], $document['paths']['/sites/{siteNodeName}/domains/{domainId}']['delete']['security']);
        self::assertSame([['oauth2' => ['sites.delete']]], $document['paths']['/sites/{siteNodeName}']['delete']['security']);
        self::assertEqualsCanonicalizing([201, 400, 401, 409, 422], array_keys($document['paths']['/sites']['post']['responses']));
        self::assertArrayHasKey('Location', $document['paths']['/sites']['post']['responses'][201]['headers']);
        self::assertSame('boolean', $document['components']['schemas']['Site']['properties']['online']['type']);
        self::assertArrayHasKey('SiteUpdate', $document['components']['schemas']);
        self::assertSame('Delete Neos sites with their content (privilege Neos.Api:Sites.Delete)', $document['components']['securitySchemes']['oauth2']['flows']['clientCredentials']['scopes']['sites.delete']);
    }

    private function addSite(string $nodeName, string $name, int $state): Site
    {
        $site = new Site($nodeName);
        $site->setName($name);
        $site->setState($state);
        $site->setSiteResourcesPackageKey('Neos.Demo');
        $this->objectManager->get(SiteRepository::class)->add($site);
        return $site;
    }

    private function addDomain(Site $site, string $hostname, ?string $scheme, ?int $port): Domain
    {
        $domain = new Domain();
        $domain->setHostname($hostname);
        $domain->setScheme($scheme);
        $domain->setPort($port);
        $domain->setSite($site);
        $site->getDomains()->add($domain);
        $this->objectManager->get(DomainRepository::class)->add($domain);
        return $domain;
    }
}
