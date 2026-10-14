<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Service\UserService;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\Test;

class ContentRepositoriesTest extends EndpointTestCase
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
    public function listsTheContentRepositoriesForEditors(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $contentRepositories = self::json($response)['data'];
        self::assertSame(count($contentRepositories), self::json($response)['meta']['total']);
        self::assertContains('default', array_column($contentRepositories, 'id'));
        foreach ($contentRepositories as $contentRepository) {
            self::assertIsArray($contentRepository['dimensions']);
        }
    }

    #[Test]
    public function getsAContentRepository(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('default', self::json($response)['id']);

        $response = $this->get('/api/cr/default', $this->token('editor-machine', 'contentrepositories.read'), ['Accept-Language' => 'de']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('de', $response->getHeaderLine('Content-Language'));
        self::assertSame(['Authorization', 'Accept-Language'], $response->getHeader('Vary'));
    }

    #[Test]
    public function tellsTheDimensionsWithIconsDepthsAndAllDimensionSpacePoints(): void
    {
        $this->requireContentRepository();
        $response = $this->get('/api/cr/default', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $contentRepository = self::json($response);
        // Neos.Demo's language dimension: icon: language, en_UK below en_US
        $language = $contentRepository['dimensions'][0];
        self::assertSame(['language', 'fas fa-language'], [$language['id'], $language['icon']]);
        self::assertSame(
            [['en_US', null, 0], ['en_UK', 'en_US', 1], ['de', null, 0]],
            array_map(static fn (array $value) => [$value['value'], $value['generalization'], $value['specializationDepth']], $language['values']),
        );
        self::assertEqualsCanonicalizing(['{"language":"en_US"}', '{"language":"en_UK"}', '{"language":"de"}'], $contentRepository['dimensionSpacePoints']);
        self::assertCount(3, $contentRepository['dimensionSpacePoints']);
    }

    #[Test]
    public function unknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get('/api/cr/unknown', $this->token('editor-machine', 'contentrepositories.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        // with the header, which is an optional parameter
        self::assertSame(404, $this->get('/api/cr/unknown', $this->token('editor-machine', 'contentrepositories.read'), ['Accept-Language' => 'de'])->getStatusCode());
    }

    #[Test]
    public function contentRepositoriesThatCantBeBuiltAreLeftOut(): void
    {
        $registry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $settings = ObjectAccess::getProperty($registry, 'settings', true);
        $broken = $settings;
        $broken['contentRepositories']['broken'] = ['preset' => 'nonexistent'];
        $registry->injectSettings($broken);
        try {
            $response = $this->get('/api/cr/broken', $this->token('editor-machine', 'contentrepositories.read'));
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID broken', self::json($response)['detail']);

            $this->requireContentRepository();
            $response = $this->get('/api/cr', $this->token('editor-machine', 'contentrepositories.read'));
            self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
            self::assertNotContains('broken', array_column(self::json($response)['data'], 'id'));
            self::assertContains('default', array_column(self::json($response)['data'], 'id'));
        } finally {
            $registry->injectSettings($settings);
        }
    }

    #[Test]
    public function rejectsInvalidIds(): void
    {
        self::assertSame(400, $this->get('/api/cr/Not-An-Id', $this->token('editor-machine', 'contentrepositories.read'))->getStatusCode());
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/cr', $this->token('nobody-machine', 'contentrepositories.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:ContentRepositories.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/cr', $this->token('editor-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('contentrepositories.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr', null)->getStatusCode());
    }

    private function requireContentRepository(): void
    {
        if (!$this->objectManager->get(EntityManagerInterface::class)->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
    }
}
