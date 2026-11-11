<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class OptionsTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $userService->createUser('chief', 'password', 'Chris', 'Chief', ['Neos.Neos:Editor', 'Neos.Neos:UserManager']);
        $this->addMachineClient('admin-machine', 'admin');
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('chief-machine', 'chief');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsAllAssignableRolesForAdministrators(): void
    {
        $response = $this->get('/api/options/users', $this->token('admin-machine', 'users.create'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $roles = self::json($response)['roles'];
        $identifiers = array_column($roles, 'identifier');
        self::assertContains('Neos.Neos:Administrator', $identifiers);
        self::assertContains('Neos.Neos:Editor', $identifiers);
        foreach (['Neos.Flow:Everybody', 'Neos.Flow:AuthenticatedUser', 'Neos.Neos:AbstractEditor'] as $abstractRole) {
            self::assertNotContains($abstractRole, $identifiers);
        }
        $labels = array_column($roles, 'label');
        $sorted = $labels;
        usort($sorted, strcasecmp(...));
        self::assertSame($sorted, $labels);
        $editor = $roles[array_search('Neos.Neos:Editor', $identifiers, true)];
        self::assertSame(['identifier', 'label', 'description', 'packageKey'], array_keys($editor));
        self::assertSame('Neos.Neos', $editor['packageKey']);

        // the roles a client gets here are the ones it can create users with
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer', 'roles' => $identifiers]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function listsTheirOwnRolesForUserManagers(): void
    {
        $response = $this->get('/api/options/users', $this->token('chief-machine', 'users.create'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $identifiers = array_column(self::json($response)['roles'], 'identifier');
        self::assertContains('Neos.Neos:Editor', $identifiers);
        self::assertContains('Neos.Neos:UserManager', $identifiers);
        self::assertNotContains('Neos.Neos:Administrator', $identifiers);
        self::assertNotContains('Neos.Neos:AbstractEditor', $identifiers);

        $response = $this->post('/api/users', $this->token('chief-machine', 'users.create'), ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer', 'roles' => $identifiers]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function listsTheSiteCreationOptions(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/options/sites', $this->token('admin-machine', 'sites.create'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $options = self::json($response);
        self::assertContains('Neos.Demo', $options['packages']);
        self::assertContains('Neos.Demo:Document.Homepage', array_column($options['nodeTypes'], 'name'));
        self::assertNotContains('Neos.Neos:Site', array_column($options['nodeTypes'], 'name'));
        self::assertSame('en', $response->getHeaderLine('Content-Language'));
        self::assertSame(['Authorization', 'Accept-Language'], $response->getHeader('Vary'));

        $response = $this->get('/api/options/sites', $this->token('admin-machine', 'sites.create'), ['Accept-Language' => 'de-DE, en;q=0.5']);
        self::assertSame('de', $response->getHeaderLine('Content-Language'));
    }

    #[Test]
    public function requireTheScopeAndPrivilegeOfTheCreate(): void
    {
        foreach (
            [
            ['/api/options/users', 'users.create', 'Neos.Api:Users.Create'],
            ['/api/options/sites', 'sites.create', 'Neos.Api:Sites.Create'],
            ] as [$path, $scope, $privilege]
        ) {
            $response = $this->get($path, $this->token('editor-machine', $scope));
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertStringContainsString($privilege, self::json($response)['detail']);

            $response = $this->get($path, $this->token('admin-machine', 'users.read sites.read users.update sites.update'));
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertStringContainsString($scope, self::json($response)['detail']);

            self::assertSame(401, $this->get($path, null)->getStatusCode());
        }
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));

        self::assertSame('getSiteCreationOptions', $document['paths']['/options/sites']['get']['operationId']);
        self::assertSame('getUserOptions', $document['paths']['/options/users']['get']['operationId']);
        self::assertSame([['oauth2' => ['sites.create']]], $document['paths']['/options/sites']['get']['security']);
        self::assertSame([['oauth2' => ['users.create']]], $document['paths']['/options/users']['get']['security']);
        self::assertSame(['Options'], $document['paths']['/options/users']['get']['tags']);
        self::assertArrayNotHasKey('/sites/options', $document['paths']);
        self::assertArrayNotHasKey('/users/options', $document['paths']);
    }

    /**
     * The content repository can't be built on the SQLite database the Testing context uses by default
     */
    private function requireContentRepository(): void
    {
        if (!$this->objectManager->get(EntityManagerInterface::class)->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
        $this->objectManager->get(ContentRepositoryRegistry::class)->buildService(ContentRepositoryId::fromString('default'), new ContentRepositoryMaintainerFactory())->setUp();
    }
}
