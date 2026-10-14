<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class MeTest extends EndpointTestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->userId = $user->getId()->value;

        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('service', 'password', ['Neos.Neos:Editor'], 'Neos.Neos:Backend'));

        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('service-machine', 'service');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function returnsTheAccountOfTheToken(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'me.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $account = ['identifier' => 'editor', 'roles' => ['Neos.Neos:Editor']];
        self::assertSame([
            'account' => $account,
            'user' => [
                'id' => $this->userId,
                'label' => 'Edith Editor',
                'firstName' => 'Edith',
                'lastName' => 'Editor',
                'email' => null,
                'active' => true,
                'accounts' => [$account],
            ],
            'token' => ['client' => 'editor-machine', 'scopes' => ['me.read']],
            // whatever the token's scopes, sorted
            'privileges' => [
                'assetcollections.create', 'assetcollections.delete', 'assetcollections.read', 'assetcollections.update',
                'assets.create', 'assets.delete', 'assets.read', 'assets.update',
                'assettags.create', 'assettags.delete', 'assettags.read', 'assettags.update',
                'contentrepositories.read', 'datasources.read',
                'me.password', 'me.read', 'me.update',
                'nodes.create', 'nodes.delete', 'nodes.read', 'nodes.update',
                'nodetypes.read', 'people.read', 'sites.read', 'views.read',
                'workspaces.create', 'workspaces.delete', 'workspaces.publish', 'workspaces.read', 'workspaces.roles', 'workspaces.update',
            ],
        ], self::json($response));
    }

    #[Test]
    public function privilegesAreTheOnesOfTheAccountsRoles(): void
    {
        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('plain', 'password', [], 'Neos.Neos:Backend'));
        $this->addMachineClient('plain-machine', 'plain');
        $this->persistenceManager->persistAll();

        $response = $this->get('/api/me', $this->token('plain-machine', 'me.read'));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        // Neos.Flow:AuthenticatedUser's only
        self::assertSame(['me.password', 'me.read', 'me.update', 'nodes.read', 'views.read', 'workspaces.read'], self::json($response)['privileges']);
    }

    #[Test]
    public function userIsNullForAccountsWithoutUser(): void
    {
        $response = $this->get('/api/me', $this->token('service-machine', 'me.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = self::json($response);
        self::assertSame('service', $body['account']['identifier']);
        self::assertNull($body['user']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        $response = $this->get('/api/me', null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/api"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        self::assertSame(401, $this->get('/api/me', 'not-a-token')->getStatusCode());
    }

    #[Test]
    public function requiresTheReadScope(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', null));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.read', self::json($response)['detail']);
    }

    #[Test]
    public function otherScopesDoNotGrantIt(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'users.read users.update'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.read', self::json($response)['detail']);
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $response = $this->get('/api/openapi.json', null);

        self::assertSame(200, $response->getStatusCode());
        $document = self::json($response);
        self::assertSame([['oauth2' => ['me.read']]], $document['paths']['/me']['get']['security']);
        self::assertSame('oauth2', $document['components']['securitySchemes']['oauth2']['type']);
        self::assertArrayHasKey('CurrentAccount', $document['components']['schemas']);
        self::assertSame([['oauth2' => ['me.read']]], $document['paths']['/me/profile']['get']['security']);
        self::assertSame([['oauth2' => ['me.update']]], $document['paths']['/me/profile']['patch']['security']);
        self::assertSame([['oauth2' => ['me.password']]], $document['paths']['/me/password']['put']['security']);
        self::assertArrayHasKey('204', $document['paths']['/me/password']['put']['responses']);
    }

    #[Test]
    public function returnsTheProfile(): void
    {
        $response = $this->get('/api/me/profile', $this->token('editor-machine', 'me.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([
            'firstName' => 'Edith',
            'lastName' => 'Editor',
            'email' => null,
            'interfaceLanguage' => 'en',
            'preferences' => [],
        ], self::json($response));
        self::assertStringContainsString('"preferences":{}', (string)$response->getBody());
    }

    #[Test]
    public function profileIsNotFoundForAccountsWithoutUser(): void
    {
        self::assertSame(404, $this->get('/api/me/profile', $this->token('service-machine', 'me.read'))->getStatusCode());
        self::assertSame(404, $this->patch('/api/me/profile', $this->token('service-machine', 'me.update'), ['firstName' => 'Sam'])->getStatusCode());
        self::assertSame(404, $this->put('/api/me/password', $this->token('service-machine', 'me.password'), ['currentPassword' => 'password', 'newPassword' => 'new-password'])->getStatusCode());
    }

    #[Test]
    public function changesTheProfile(): void
    {
        $token = $this->token('editor-machine', 'me.read me.update');
        $response = $this->patch('/api/me/profile', $token, [
            'firstName' => 'Edda',
            'email' => 'edda@example.com',
            'interfaceLanguage' => 'de',
            'preferences' => ['studio.uiMode' => 'dark', 'studio.sidebar' => ['width' => 320]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $expected = [
            'firstName' => 'Edda',
            'lastName' => 'Editor',
            'email' => 'edda@example.com',
            'interfaceLanguage' => 'de',
            'preferences' => ['studio.uiMode' => 'dark', 'studio.sidebar' => ['width' => 320]],
        ];
        self::assertSame($expected, self::json($response));
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
        self::assertSame($expected, self::json($this->get('/api/me/profile', $token)));

        // preferences are merged, null removes one
        $response = $this->patch('/api/me/profile', $token, ['preferences' => ['studio.uiMode' => null, 'studio.zoom' => 1.5]]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['studio.sidebar' => ['width' => 320], 'studio.zoom' => 1.5], self::json($response)['preferences']);
        self::assertSame('de', self::json($response)['interfaceLanguage']);
    }

    #[Test]
    public function nullResetsTheInterfaceLanguage(): void
    {
        $token = $this->token('editor-machine', 'me.update');
        self::assertSame('de', self::json($this->patch('/api/me/profile', $token, ['interfaceLanguage' => 'de']))['interfaceLanguage']);

        // left out, it stays
        self::assertSame('de', self::json($this->patch('/api/me/profile', $token, ['firstName' => 'Edda']))['interfaceLanguage']);

        $response = $this->patch('/api/me/profile', $token, ['interfaceLanguage' => null]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('en', self::json($response)['interfaceLanguage']);
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(UserService::class)->getUser('editor')->getPreferences()->getInterfaceLanguage());
    }

    #[Test]
    public function rejectsInvalidProfileChanges(): void
    {
        $token = $this->token('editor-machine', 'me.update');
        foreach ([['email' => 'not-an-email'], ['firstName' => ''], ['firstName' => 42], ['lastName' => str_repeat('a', 101)], ['preferences' => ['a', 'b']], ['label' => 'Edith']] as $body) {
            $response = $this->patch('/api/me/profile', $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
        foreach ([['interfaceLanguage' => 'xx'], ['preferences' => ['interfaceLanguage' => 'de']]] as $body) {
            $response = $this->patch('/api/me/profile', $token, $body);

            self::assertSame(422, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function changingTheProfileRequiresTheUpdateScope(): void
    {
        $response = $this->patch('/api/me/profile', $this->token('editor-machine', 'me.read me.password'), ['firstName' => 'Edda']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.update', self::json($response)['detail']);
    }

    #[Test]
    public function changesThePassword(): void
    {
        $response = $this->put('/api/me/password', $this->token('editor-machine', 'me.password'), ['currentPassword' => 'password', 'newPassword' => 'new-password']);

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
        $account = $this->objectManager->get(AccountRepository::class)->findActiveByAccountIdentifierAndAuthenticationProviderName('editor', 'Neos.Neos:Backend');
        $hashService = $this->objectManager->get(HashService::class);
        self::assertTrue($hashService->validatePassword('new-password', $account->getCredentialsSource()));
        self::assertFalse($hashService->validatePassword('password', $account->getCredentialsSource()));
    }

    #[Test]
    public function changingThePasswordRevokesTheOtherTokens(): void
    {
        $otherToken = $this->token('editor-machine', 'me.read');
        $token = $this->token('editor-machine', 'me.read me.password');

        $response = $this->put('/api/me/password', $token, ['currentPassword' => 'password', 'newPassword' => 'new-password']);

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        $this->persistenceManager->clearState();
        self::assertSame(401, $this->get('/api/me', $otherToken)->getStatusCode());
        // the token of the request stays valid
        self::assertSame(200, $this->get('/api/me', $token)->getStatusCode());
    }

    #[Test]
    public function rejectsAWrongCurrentPassword(): void
    {
        $response = $this->put('/api/me/password', $this->token('editor-machine', 'me.password'), ['currentPassword' => 'wrong', 'newPassword' => 'new-password']);

        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        $this->persistenceManager->clearState();
        $account = $this->objectManager->get(AccountRepository::class)->findActiveByAccountIdentifierAndAuthenticationProviderName('editor', 'Neos.Neos:Backend');
        self::assertTrue($this->objectManager->get(HashService::class)->validatePassword('password', $account->getCredentialsSource()));
    }

    #[Test]
    public function rejectsInvalidPasswordChanges(): void
    {
        $token = $this->token('editor-machine', 'me.password');
        foreach ([['currentPassword' => 'password', 'newPassword' => ''], ['currentPassword' => 'password'], ['newPassword' => 'new-password']] as $body) {
            $response = $this->put('/api/me/password', $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function changingThePasswordRequiresThePasswordScope(): void
    {
        $response = $this->put('/api/me/password', $this->token('editor-machine', 'me.read me.update'), ['currentPassword' => 'password', 'newPassword' => 'new-password']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('me.password', self::json($response)['detail']);
    }
}
