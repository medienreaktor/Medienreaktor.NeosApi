<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class PeopleTest extends EndpointTestCase
{
    private string $editorId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $this->editorId = $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor'])->getId()->value;
        $this->adminId = $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator'])->getId()->value;
        $userService->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('nobody-machine', 'nobody');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsAllUsersByNameForEditors(): void
    {
        $response = $this->get('/api/people', $this->token('editor-machine', 'people.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $listing = self::json($response);
        // sorted by label, nothing but id and label, not their accounts, roles or email addresses
        self::assertSame([['id' => $this->adminId, 'label' => 'Ada Admin'], ['id' => $this->editorId, 'label' => 'Edith Editor']], array_slice($listing['data'], 0, 2));
        self::assertSame(['Ada Admin', 'Edith Editor', 'Nora Nobody'], array_column($listing['data'], 'label'));
        self::assertSame(['total' => 3], $listing['meta']);
        self::assertArrayNotHasKey('links', $listing);
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/people', $this->token('nobody-machine', 'people.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:People.Read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        // another scope isn't enough, scopes are explicit
        $response = $this->get('/api/people', $this->token('editor-machine', 'me.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('people.read', self::json($response)['detail']);
        self::assertSame(401, $this->get('/api/people', null)->getStatusCode());
    }

    #[Test]
    public function isTaggedPeople(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/people']['get'];

        self::assertSame(['listPeople', ['People']], [$operation['operationId'], $operation['tags']]);
    }
}
