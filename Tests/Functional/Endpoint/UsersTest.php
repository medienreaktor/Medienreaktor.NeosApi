<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;
use PHPUnit\Framework\Attributes\Test;

class UsersTest extends EndpointTestCase
{
    private const string UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private string $adminId;

    private string $editorId;

    private string $chiefId;

    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $admin = $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $email = new ElectronicAddress();
        $email->setIdentifier('ada@example.com');
        $email->setType(EmailAddress::ELECTRONIC_ADDRESS_TYPE);
        $admin->addElectronicAddress($email);
        $admin->setPrimaryElectronicAddress($email);
        $userService->updateUser($admin);
        $this->adminId = $admin->getId()->value;

        $this->editorId = $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor'])->getId()->value;
        $userService->createUser('manager', 'password', 'Manu', 'Manager', ['Neos.Neos:UserManager']);
        // manages editors, not administrators
        $this->chiefId = $userService->createUser('chief', 'password', 'Chris', 'Chief', ['Neos.Neos:Editor', 'Neos.Neos:UserManager'])->getId()->value;

        $this->addMachineClient('admin-machine', 'admin');
        $this->addMachineClient('editor-machine', 'editor');
        $this->addMachineClient('manager-machine', 'manager');
        $this->addMachineClient('chief-machine', 'chief');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsAllUsersForUserManagers(): void
    {
        $response = $this->get('/api/users', $this->token('manager-machine', 'users.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $users = self::json($response)['data'];
        self::assertSame(['total' => 4], self::json($response)['meta']);
        // also the ones the manager may not change, as in Neos' user module
        self::assertSame(['Ada Admin', 'Chris Chief', 'Edith Editor', 'Manu Manager'], array_column($users, 'label'));
        self::assertSame([
            'id' => $this->adminId,
            'label' => 'Ada Admin',
            'firstName' => 'Ada',
            'lastName' => 'Admin',
            'email' => 'ada@example.com',
            'active' => true,
            'accounts' => [['identifier' => 'admin', 'roles' => ['Neos.Neos:Administrator']]],
        ], $users[0]);
        self::assertNull($users[1]['email']);
    }

    #[Test]
    public function pagesTheUsers(): void
    {
        $token = $this->token('manager-machine', 'users.read');

        $response = $this->get('/api/users?offset=1&limit=1', $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Chris Chief'], array_column(self::json($response)['data'], 'label'));
        self::assertSame(['total' => 4], self::json($response)['meta']);
        $links = array_map(static fn (?string $link) => $link !== null ? urldecode(parse_url($link, PHP_URL_QUERY)) : null, self::json($response)['links']);
        self::assertSame([
            'self' => 'offset=1&limit=1',
            'first' => 'offset=0&limit=1',
            'prev' => 'offset=0&limit=1',
            'next' => 'offset=2&limit=1',
            'last' => 'offset=3&limit=1',
        ], $links);

        $response = $this->get('/api/users', $token);
        self::assertNull(self::json($response)['links']['prev']);
        self::assertNull(self::json($response)['links']['next']);

        foreach (['limit=101', 'limit=0', 'offset=-1', 'limit=ten', 'offset=1.5'] as $query) {
            self::assertSame(400, $this->get('/api/users?' . $query, $token)->getStatusCode(), $query);
        }
    }

    #[Test]
    public function getsAUserForUserManagers(): void
    {
        $response = $this->get('/api/users/' . $this->adminId, $this->token('manager-machine', 'users.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Ada Admin', self::json($response)['label']);
    }

    #[Test]
    public function findsUsersByUppercaseIds(): void
    {
        // a UUID's format ignores the case, Neos' user IDs are lowercase
        $response = $this->get('/api/users/' . strtoupper($this->adminId), $this->token('manager-machine', 'users.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($this->adminId, self::json($response)['id']);
        self::assertSame(404, $this->get('/api/users/' . strtoupper(self::UNKNOWN_ID), $this->token('manager-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function readingIsDeniedToEditors(): void
    {
        $token = $this->token('editor-machine', 'users.read');
        foreach (['/api/users', '/api/users/' . $this->adminId] as $path) {
            $response = $this->get($path, $token);

            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('Neos.Api:Users.Read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function unknownUsersAreNotFound(): void
    {
        $response = $this->get('/api/users/' . self::UNKNOWN_ID, $this->token('manager-machine', 'users.read'));
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $response = $this->patch('/api/users/' . self::UNKNOWN_ID, $this->token('admin-machine', 'users.update'), ['firstName' => 'Nobody']);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(404, $this->put('/api/users/' . self::UNKNOWN_ID . '/password', $this->token('admin-machine', 'users.update'), ['newPassword' => 'new-password'])->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidUserIds(): void
    {
        self::assertSame(400, $this->get('/api/users/not-a-uuid', $this->token('manager-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function administratorsChangeUsers(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.update'), ['firstName' => 'Edda', 'email' => 'edda@example.com']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $user = self::json($response);
        self::assertSame(['Edda Editor', 'Edda', 'Editor', 'edda@example.com'], [$user['label'], $user['firstName'], $user['lastName'], $user['email']]);

        // persisted: a new request reads it back
        $this->persistenceManager->clearState();
        $user = self::json($this->get('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.read')));
        self::assertSame(['Edda Editor', 'edda@example.com'], [$user['label'], $user['email']]);
    }

    #[Test]
    public function changesTheExistingEmailAddress(): void
    {
        $response = $this->patch('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.update'), ['email' => 'ada@example.org']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Ada Admin', 'ada@example.org'], [self::json($response)['label'], self::json($response)['email']]);
    }

    #[Test]
    public function changingIsDeniedToEditors(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('editor-machine', 'users.update'), ['firstName' => 'Evil']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Update', self::json($response)['detail']);
    }

    #[Test]
    public function changingRequiresTheUpdateScope(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.read'), ['firstName' => 'Edda']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.update', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidChanges(): void
    {
        $token = $this->token('admin-machine', 'users.update');
        foreach ([['email' => 'not-an-email'], ['firstName' => 42], ['firstName' => ''], ['lastName' => str_repeat('a', 101)], ['password' => 'secret'], ['roles' => ['Editor']], ['active' => 'no']] as $body) {
            $response = $this->patch('/api/users/' . $this->editorId, $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
    }

    #[Test]
    public function administratorsCreateUsers(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), [
            'username' => 'writer',
            'password' => 'writer-password',
            'firstName' => 'Wanda',
            'lastName' => 'Writer',
            'email' => 'wanda@example.com',
            'roles' => ['Neos.Neos:RestrictedEditor'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $user = self::json($response);
        self::assertSame(['Wanda Writer', 'wanda@example.com', [['identifier' => 'writer', 'roles' => ['Neos.Neos:RestrictedEditor']]]], [$user['label'], $user['email'], $user['accounts']]);
        self::assertArrayNotHasKey('password', $user);
        self::assertSame('users/' . $user['id'], $response->getHeaderLine('Location'));

        $this->persistenceManager->clearState();
        $response = $this->get('/api/users/' . $user['id'], $this->token('admin-machine', 'users.read'));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue($this->objectManager->get(UserService::class)->getUser('writer')->isActive());
    }

    #[Test]
    public function newUsersAreEditorsByDefault(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), ['username' => 'writer', 'password' => 'writer-password', 'firstName' => 'Wanda', 'lastName' => 'Writer']);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['Neos.Neos:Editor'], self::json($response)['accounts'][0]['roles']);
        self::assertNull(self::json($response)['email']);
    }

    #[Test]
    public function usernamesAreUnique(): void
    {
        $response = $this->post('/api/users', $this->token('admin-machine', 'users.create'), ['username' => 'editor', 'password' => 'password', 'firstName' => 'Eve', 'lastName' => 'Editor']);

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function rejectsUnknownAndAbstractRoles(): void
    {
        $token = $this->token('admin-machine', 'users.create');
        foreach (['Neos.Neos:Nonexistent', 'Neos.Flow:Everybody'] as $role) {
            $response = $this->post('/api/users', $token, ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer', 'roles' => [$role]]);

            self::assertSame(422, $response->getStatusCode(), $role . ': ' . $response->getBody());
            self::assertStringContainsString($role, self::json($response)['detail']);
        }
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(UserService::class)->getUser('writer'));
    }

    #[Test]
    public function rejectsInvalidNewUsers(): void
    {
        $token = $this->token('admin-machine', 'users.create');
        $valid = ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'];
        foreach ([['password' => ''], ['username' => ''], ['email' => 'not-an-email'], ['roles' => ['Editor']], ['active' => false], ['firstName' => ''], ['firstName' => str_repeat('a', 101)], ['lastName' => str_repeat('ä', 101)]] as $change) {
            $response = $this->post('/api/users', $token, array_merge($valid, $change));

            self::assertSame(400, $response->getStatusCode(), json_encode($change) . ': ' . $response->getBody());
        }
        $body = $valid;
        unset($body['password']);
        self::assertSame(400, $this->post('/api/users', $token, $body)->getStatusCode());
    }

    #[Test]
    public function creatingIsDeniedToEditorsAndRequiresTheCreateScope(): void
    {
        $body = ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'];

        $response = $this->post('/api/users', $this->token('editor-machine', 'users.create'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Create', self::json($response)['detail']);

        $response = $this->post('/api/users', $this->token('admin-machine', 'users.update'), $body);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.create', self::json($response)['detail']);
    }

    #[Test]
    public function administratorsDeleteUsers(): void
    {
        $this->createWorkspaceMetadataTable();
        $response = $this->delete('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.delete'));

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('', (string)$response->getBody());
        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(UserService::class)->getUser('editor'));
        self::assertSame(404, $this->get('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function usersCantDeleteThemselves(): void
    {
        $response = $this->delete('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.delete'));

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotNull($this->objectManager->get(UserService::class)->getUser('admin'));
    }

    #[Test]
    public function deletingIsDeniedToEditorsAndRequiresTheDeleteScope(): void
    {
        $response = $this->delete('/api/users/' . $this->adminId, $this->token('editor-machine', 'users.delete'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Users.Delete', self::json($response)['detail']);

        $response = $this->delete('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.update'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.delete', self::json($response)['detail']);
    }

    #[Test]
    public function deletingUnknownUsersIsNotFound(): void
    {
        self::assertSame(404, $this->delete('/api/users/' . self::UNKNOWN_ID, $this->token('admin-machine', 'users.delete'))->getStatusCode());
    }

    /**
     * UserService::deleteUser() looks up the personal workspaces in a table of a Neos migration, which the testing
     * schema lacks: a stand-in with the columns it reads
     */
    private function createWorkspaceMetadataTable(): void
    {
        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS neos_neos_workspace_metadata (content_repository_id VARCHAR(16), workspace_name VARCHAR(255), classification VARCHAR(255), owner_user_id VARCHAR(255))',
        );
    }

    #[Test]
    public function readingRequiresTheReadScope(): void
    {
        foreach (['/api/users', '/api/users/' . $this->editorId] as $path) {
            $response = $this->get($path, $this->token('admin-machine', null));

            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
            self::assertStringContainsString('users.read', self::json($response)['detail']);
        }
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/users', null)->getStatusCode());
        self::assertSame(401, $this->patch('/api/users/' . $this->editorId, null, ['firstName' => 'Edda'])->getStatusCode());
        self::assertSame(401, $this->post('/api/users', null, ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer'])->getStatusCode());
        self::assertSame(401, $this->delete('/api/users/' . $this->editorId, null)->getStatusCode());
        self::assertSame(401, $this->put('/api/users/' . $this->editorId . '/password', null, ['newPassword' => 'new-password'])->getStatusCode());
    }

    #[Test]
    public function isDescribedInTheSpecification(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));

        self::assertSame([['oauth2' => ['users.read']]], $document['paths']['/users']['get']['security']);
        self::assertArrayNotHasKey('/users/options', $document['paths']);
        self::assertSame([['oauth2' => ['users.update']]], $document['paths']['/users/{userId}']['patch']['security']);
        self::assertSame([['oauth2' => ['users.create']]], $document['paths']['/users']['post']['security']);
        self::assertSame([['oauth2' => ['users.delete']]], $document['paths']['/users/{userId}']['delete']['security']);
        self::assertSame([['oauth2' => ['users.update']]], $document['paths']['/users/{userId}/password']['put']['security']);
        self::assertSame(['oauth2'], array_keys($document['components']['securitySchemes']));
        self::assertSame(['listUsers', 'createUser', 'getUser', 'updateUser', 'deleteUser', 'setUserPassword'], [
            $document['paths']['/users']['get']['operationId'],
            $document['paths']['/users']['post']['operationId'],
            $document['paths']['/users/{userId}']['get']['operationId'],
            $document['paths']['/users/{userId}']['patch']['operationId'],
            $document['paths']['/users/{userId}']['delete']['operationId'],
            $document['paths']['/users/{userId}/password']['put']['operationId'],
        ]);
        // the 403 of a user the caller may not manage, neos/openapi doesn't know about the one of missing scopes or privileges yet
        self::assertEqualsCanonicalizing([200, 400, 401, 403, 404, 422], array_keys($document['paths']['/users/{userId}']['patch']['responses']));
        $created = $document['paths']['/users']['post']['responses'];
        self::assertEqualsCanonicalizing([201, 400, 401, 403, 409, 422], array_keys($created));
        self::assertArrayHasKey('Location', $created[201]['headers']);
        self::assertEqualsCanonicalizing([204, 400, 401, 403, 404, 409], array_keys($document['paths']['/users/{userId}']['delete']['responses']));
        self::assertEqualsCanonicalizing([204, 400, 401, 403, 404], array_keys($document['paths']['/users/{userId}/password']['put']['responses']));
        self::assertSame(100, $document['components']['schemas']['PersonNamePart']['maxLength']);
        self::assertTrue($document['components']['schemas']['Password']['writeOnly']);
        self::assertArrayHasKey('UserUpdate', $document['components']['schemas']);
        self::assertSame('Change the Neos users (privilege Neos.Api:Users.Update)', $document['components']['securitySchemes']['oauth2']['flows']['clientCredentials']['scopes']['users.update']);
    }

    #[Test]
    public function administratorsChangeRoles(): void
    {
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('admin-machine', 'users.update'), ['roles' => ['Neos.Neos:RestrictedEditor', 'Neos.Neos:UserManager']]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertEqualsCanonicalizing(['Neos.Neos:RestrictedEditor', 'Neos.Neos:UserManager'], self::json($response)['accounts'][0]['roles']);

        $this->persistenceManager->clearState();
        self::assertEqualsCanonicalizing(['Neos.Neos:RestrictedEditor', 'Neos.Neos:UserManager'], $this->user($this->editorId)['accounts'][0]['roles']);
    }

    #[Test]
    public function rejectsInvalidRoles(): void
    {
        $token = $this->token('admin-machine', 'users.update');
        foreach ([[], ['Neos.Neos:Nonexistent'], ['Neos.Neos:AbstractEditor']] as $roles) {
            $response = $this->patch('/api/users/' . $this->editorId, $token, ['roles' => $roles]);

            self::assertSame(422, $response->getStatusCode(), json_encode($roles) . ': ' . $response->getBody());
        }
        $this->persistenceManager->clearState();
        self::assertSame(['Neos.Neos:Editor'], $this->user($this->editorId)['accounts'][0]['roles']);
    }

    #[Test]
    public function userManagersManageUsersWithASubsetOfTheirRoles(): void
    {
        $this->createWorkspaceMetadataTable();
        // the chief (editor and user manager) has all roles of the editor
        $response = $this->patch('/api/users/' . $this->editorId, $this->token('chief-machine', 'users.update'), ['firstName' => 'Edda', 'roles' => ['Neos.Neos:Editor', 'Neos.Neos:UserManager']]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $response = $this->post('/api/users', $this->token('chief-machine', 'users.create'), ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(204, $this->put('/api/users/' . self::json($response)['id'] . '/password', $this->token('chief-machine', 'users.update'), ['newPassword' => 'new-password'])->getStatusCode());
        self::assertSame(204, $this->delete('/api/users/' . self::json($response)['id'], $this->token('chief-machine', 'users.delete'))->getStatusCode());
    }

    #[Test]
    public function userManagersDontManageUsersWithOtherRoles(): void
    {
        foreach (
            [
            'change' => fn () => $this->patch('/api/users/' . $this->adminId, $this->token('chief-machine', 'users.update'), ['firstName' => 'Evil']),
            'set the password' => fn () => $this->put('/api/users/' . $this->adminId . '/password', $this->token('chief-machine', 'users.update'), ['newPassword' => 'evil-password']),
            'delete' => fn () => $this->delete('/api/users/' . $this->adminId, $this->token('chief-machine', 'users.delete')),
            'give other roles' => fn () => $this->patch('/api/users/' . $this->editorId, $this->token('chief-machine', 'users.update'), ['roles' => ['Neos.Neos:Administrator']]),
            'create with other roles' => fn () => $this->post('/api/users', $this->token('chief-machine', 'users.create'), ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer', 'roles' => ['Neos.Neos:Administrator']]),
            // the default role, Neos.Neos:Editor, isn't the manager's
            'create with the default role' => fn () => $this->post('/api/users', $this->token('manager-machine', 'users.create'), ['username' => 'writer', 'password' => 'password', 'firstName' => 'Wanda', 'lastName' => 'Writer']),
            'change an editor' => fn () => $this->patch('/api/users/' . $this->editorId, $this->token('manager-machine', 'users.update'), ['firstName' => 'Evil']),
            ] as $what => $request
        ) {
            $response = $request();

            self::assertSame(403, $response->getStatusCode(), $what . ': ' . $response->getBody());
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        }
        $this->persistenceManager->clearState();
        self::assertSame('Ada', $this->user($this->adminId)['firstName']);
        self::assertSame(['Neos.Neos:Editor'], $this->user($this->editorId)['accounts'][0]['roles']);
        self::assertSame(4, self::json($this->get('/api/users', $this->token('admin-machine', 'users.read')))['meta']['total']);
        // reading them is fine
        self::assertSame(200, $this->get('/api/users/' . $this->adminId, $this->token('chief-machine', 'users.read'))->getStatusCode());
    }

    #[Test]
    public function usersDontLockThemselvesOut(): void
    {
        foreach (
            [
            fn () => $this->patch('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.update'), ['active' => false]),
            fn () => $this->patch('/api/users/' . $this->adminId, $this->token('admin-machine', 'users.update'), ['roles' => ['Neos.Neos:Editor', 'Neos.Neos:UserManager']]),
            // no longer allowed to change users
            fn () => $this->patch('/api/users/' . $this->chiefId, $this->token('chief-machine', 'users.update'), ['roles' => ['Neos.Neos:Editor']]),
            ] as $i => $request
        ) {
            $response = $request();

            self::assertSame(422, $response->getStatusCode(), $i . ': ' . $response->getBody());
        }
        $this->persistenceManager->clearState();
        self::assertTrue($this->user($this->adminId)['active']);

        $response = $this->patch('/api/users/' . $this->chiefId, $this->token('chief-machine', 'users.update'), ['roles' => ['Neos.Neos:UserManager', 'Neos.Neos:Editor'], 'active' => true]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function deactivatesAndActivatesUsers(): void
    {
        $editorToken = $this->token('editor-machine', 'me.read');
        $token = $this->token('admin-machine', 'users.update');

        $response = $this->patch('/api/users/' . $this->editorId, $token, ['active' => false]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertFalse(self::json($response)['active']);
        $this->persistenceManager->persistAll();
        // a deactivated account's tokens stop working
        self::assertSame(401, $this->get('/api/me', $editorToken)->getStatusCode());

        $response = $this->patch('/api/users/' . $this->editorId, $token, ['active' => true]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue(self::json($response)['active']);
        $this->persistenceManager->persistAll();
        self::assertSame(200, $this->get('/api/me', $editorToken)->getStatusCode());
    }

    #[Test]
    public function setsThePasswordAndRevokesTheTokens(): void
    {
        $editorToken = $this->token('editor-machine', 'me.read');
        $adminToken = $this->token('admin-machine', 'users.update me.read');

        $response = $this->put('/api/users/' . $this->editorId . '/password', $adminToken, ['newPassword' => 'new-password']);

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        $this->persistenceManager->clearState();
        $account = $this->objectManager->get(AccountRepository::class)->findActiveByAccountIdentifierAndAuthenticationProviderName('editor', 'Neos.Neos:Backend');
        self::assertTrue($this->objectManager->get(HashService::class)->validatePassword('new-password', $account->getCredentialsSource()));
        self::assertSame(401, $this->get('/api/me', $editorToken)->getStatusCode());
        self::assertSame(200, $this->get('/api/me', $adminToken)->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidPasswords(): void
    {
        $token = $this->token('admin-machine', 'users.update');
        foreach ([['newPassword' => ''], [], ['newPassword' => 'new-password', 'currentPassword' => 'password']] as $body) {
            $response = $this->put('/api/users/' . $this->editorId . '/password', $token, $body);

            self::assertSame(400, $response->getStatusCode(), json_encode($body) . ': ' . $response->getBody());
        }
        $response = $this->put('/api/users/' . $this->editorId . '/password', $this->token('admin-machine', 'users.read'), ['newPassword' => 'new-password']);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('users.update', self::json($response)['detail']);
    }

    /**
     * @return array<mixed> the user as the API returns it
     */
    private function user(string $userId): array
    {
        return self::json($this->get('/api/users/' . $userId, $this->token('admin-machine', 'users.read')));
    }
}
