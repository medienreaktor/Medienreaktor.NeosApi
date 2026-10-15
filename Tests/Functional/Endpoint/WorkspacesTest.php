<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Parameter\ChangeTypes;
use Neos\Api\Endpoint\Workspaces\Schema;
use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Schematic\Schematic;
use PHPUnit\Framework\Attributes\Test;

/**
 * Workspaces, their changes and events need a content repository, which the Testing context (SQLite) doesn't have: these tests cover everything
 * up to the content repository lookup, WorkspacesContentRepositoryTest the rest (Testing/Ddev)
 */
class WorkspacesTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('nobody', 'password', 'Nora', 'Nobody', []);
        $this->addMachineClient('nobody-machine', 'nobody');
        // editors may create workspaces and change or delete the ones they manage
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edda', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function workspacesOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may list workspaces, which ones is up to its workspace roles
        $response = $this->get('/api/cr/unknown/workspaces', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidContentRepositoryIds(): void
    {
        self::assertSame(400, $this->get('/api/cr/Not-An-Id/workspaces', $this->token('nobody-machine', 'workspaces.read'))->getStatusCode());
    }

    #[Test]
    public function documentsTheOperationUnderItsOwnTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces']['get'];
        self::assertSame('listWorkspaces', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces', null)->getStatusCode());
    }

    #[Test]
    public function eventsOfUnknownContentRepositoriesAreNotFound(): void
    {
        // every account may list events, which ones is up to its workspace roles and node privileges
        $response = $this->get('/api/cr/unknown/workspaces/live/events', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidEventParameters(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not-A-Name/events', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?after=0', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?limit=101', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheEventsUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/events']['get'];
        self::assertSame('listWorkspaceEvents', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName', 'after', 'limit', 'sort', 'include', 'filterByEventType', 'filterByNodeAggregateId', 'filterByDocument', 'filterByTag'], array_column($operation['parameters'], 'name'));
    }

    #[Test]
    public function eventsRequireTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live/events', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function eventsRequireAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/live/events', null)->getStatusCode());
    }

    #[Test]
    public function changesOfUnknownContentRepositoriesAreNotFound(): void
    {
        $response = $this->get('/api/cr/unknown/workspaces/live/changes', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidChangeParameters(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not-A-Name/changes', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/changes?offset=-1', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/changes?limit=101', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheChangesUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/changes']['get'];
        self::assertSame('listWorkspaceChanges', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName', 'filterByDocument', 'filterBySite', 'filterByChangeType', 'filterByNodeType', 'include', 'offset', 'limit'], array_column($operation['parameters'], 'name'));
    }

    #[Test]
    public function changesRequireTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live/changes', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function changesRequireAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/live/changes', null)->getStatusCode());
    }

    #[Test]
    public function aWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->get('/api/cr/unknown/workspaces/live', $this->token('nobody-machine', 'workspaces.read'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidWorkspaceNames(): void
    {
        self::assertSame(400, $this->get('/api/cr/default/workspaces/Not_A_Name', $this->token('nobody-machine', 'workspaces.read'))->getStatusCode());
    }

    #[Test]
    public function documentsGetWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['get'];
        self::assertSame('getWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
    }

    #[Test]
    public function getWorkspaceRequiresTheWorkspacesScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/live', $this->token('nobody-machine', 'nodes.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function creatingAWorkspaceInAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->post('/api/cr/unknown/workspaces', $this->token('editor-machine', 'workspaces.create'), ['title' => 'Team']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidNewWorkspaces(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => 'team'])->getStatusCode());
        // public or private, shared is the classification
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'visibility' => 'shared'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'name' => 'Not_A_Name'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'baseWorkspaceName' => 'Not_A_Name'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'owner' => 'someone'])->getStatusCode());
    }

    #[Test]
    public function onlySharedAndPersonalWorkspacesCanBeCreated(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        foreach (['root', 'unknown'] as $classification) {
            $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => $classification]);
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame(sprintf('Only shared and personal workspaces can be created, not %s ones', $classification), self::json($response)['detail']);
        }
    }

    #[Test]
    public function aSharedWorkspaceNeedsATitle(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create');

        $response = $this->post('/api/cr/default/workspaces', $token, []);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('A shared workspace needs a title', self::json($response)['detail']);

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => '   ']);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The title must be 1 to 200 letters, digits, spaces and punctuation', self::json($response)['detail']);

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'description' => str_repeat('a', 501)]);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The description must be at most 500 letters, digits, spaces and punctuation', self::json($response)['detail']);
    }

    #[Test]
    public function aPersonalWorkspaceHasNoVisibility(): void
    {
        $response = $this->post('/api/cr/default/workspaces', $this->token('editor-machine', 'workspaces.create'), ['classification' => 'personal', 'visibility' => 'private']);

        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('A personal workspace has no visibility, only its owner may work in it', self::json($response)['detail']);
    }

    #[Test]
    public function creatingAWorkspaceNeedsANeosUser(): void
    {
        $accountFactory = $this->objectManager->get(AccountFactory::class);
        $this->objectManager->get(AccountRepository::class)->add($accountFactory->createAccountWithPassword('workspace-service', 'password', ['Neos.Neos:Editor'], 'Neos.Neos:Backend'));
        $this->addMachineClient('workspace-service-machine', 'workspace-service');
        $this->persistenceManager->persistAll();
        $token = $this->token('workspace-service-machine', 'workspaces.create');

        foreach (['shared', 'personal'] as $classification) {
            $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'classification' => $classification]);
            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('The account has no Neos user to create the workspace for', self::json($response)['detail']);
        }
    }

    #[Test]
    public function documentsCreateWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces']['post'];
        self::assertSame('createWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['201', '400', '401', '403', '404', '409', '422'], array_map('strval', array_keys($operation['responses'])));
        self::assertArrayHasKey('Location', $operation['responses']['201']['headers']);
    }

    #[Test]
    public function createWorkspaceRequiresTheScope(): void
    {
        $response = $this->post('/api/cr/default/workspaces', $this->token('editor-machine', 'workspaces.read'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.create', self::json($response)['detail']);
    }

    #[Test]
    public function createWorkspaceRequiresThePrivilege(): void
    {
        // nobody has no roles, Neos grants creating workspaces to editors
        $response = $this->post('/api/cr/default/workspaces', $this->token('nobody-machine', 'workspaces.create'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function createWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->post('/api/cr/default/workspaces', null, ['title' => 'Team'])->getStatusCode());
    }

    #[Test]
    public function updatingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->patch('/api/cr/unknown/workspaces/team', $this->token('editor-machine', 'workspaces.update'), ['title' => 'Team']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidWorkspaceUpdates(): void
    {
        $token = $this->token('editor-machine', 'workspaces.update');

        self::assertSame(400, $this->patch('/api/cr/default/workspaces/Not_A_Name', $token, ['title' => 'Team'])->getStatusCode());
        self::assertSame(400, $this->patch('/api/cr/default/workspaces/team', $token, ['title' => 42])->getStatusCode());
        self::assertSame(400, $this->patch('/api/cr/default/workspaces/team', $token, ['description' => ['a']])->getStatusCode());
    }

    #[Test]
    public function documentsUpdateWorkspaceUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['patch'];

        self::assertSame('updateWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        foreach (['200', '403', '404', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
        // the base workspace is changed by changeBaseWorkspace, not here
        self::assertSame(['title', 'description'], array_keys($document['components']['schemas']['WorkspaceUpdate']['properties']));
    }

    #[Test]
    public function updateWorkspaceRequiresTheScope(): void
    {
        $response = $this->patch('/api/cr/default/workspaces/team', $this->token('editor-machine', 'workspaces.read'), ['title' => 'Team']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.update', self::json($response)['detail']);
    }

    #[Test]
    public function updateWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->patch('/api/cr/default/workspaces/team', null, ['title' => 'Team'])->getStatusCode());
    }

    #[Test]
    public function deletingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->delete('/api/cr/unknown/workspaces/shared-one?force=true', $this->token('editor-machine', 'workspaces.delete'));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidDeleteWorkspaceParameters(): void
    {
        $token = $this->token('editor-machine', 'workspaces.delete');

        self::assertSame(400, $this->delete('/api/cr/default/workspaces/Not_A_Name', $token)->getStatusCode());
        self::assertSame(400, $this->delete('/api/cr/default/workspaces/shared-one?force=yes', $token)->getStatusCode());
    }

    #[Test]
    public function documentsDeleteWorkspaceUnderTheWorkspacesTag(): void
    {
        $operation = self::json($this->get('/api/openapi.json', null))['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}']['delete'];
        self::assertSame('deleteWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        foreach (['204', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
        $force = array_values(array_filter($operation['parameters'], static fn (array $parameter) => $parameter['name'] === 'force'))[0];
        self::assertSame('query', $force['in']);
        self::assertFalse($force['required'] ?? false);
        self::assertSame('boolean', $force['schema']['type']);
    }

    #[Test]
    public function deleteWorkspaceRequiresTheWorkspacesDeleteScope(): void
    {
        $response = $this->delete('/api/cr/default/workspaces/shared-one', $this->token('editor-machine', 'workspaces.read'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.delete', self::json($response)['detail']);
    }

    #[Test]
    public function deleteWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->delete('/api/cr/default/workspaces/shared-one', null)->getStatusCode());
    }

    #[Test]
    public function changingTheBaseOfAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->put('/api/cr/unknown/workspaces/team/baseworkspace', $this->token('editor-machine', 'workspaces.update'), ['baseWorkspaceName' => 'live']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidBaseWorkspaceChanges(): void
    {
        $token = $this->token('editor-machine', 'workspaces.update');

        self::assertSame(400, $this->put('/api/cr/default/workspaces/Not_A_Name/baseworkspace', $token, ['baseWorkspaceName' => 'live'])->getStatusCode());
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/baseworkspace', $token, [])->getStatusCode());
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/baseworkspace', $token, ['baseWorkspaceName' => 'Not_A_Name'])->getStatusCode());
    }

    #[Test]
    public function documentsChangeBaseWorkspaceUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/baseworkspace']['put'];
        self::assertSame('changeBaseWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName'], array_column($operation['parameters'], 'name'));
        self::assertSame('#/components/schemas/BaseWorkspaceChange', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        self::assertSame(['baseWorkspaceName'], $document['components']['schemas']['BaseWorkspaceChange']['required']);
        foreach (['200', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
    }

    #[Test]
    public function changeBaseWorkspaceRequiresTheUpdateScope(): void
    {
        $response = $this->put('/api/cr/default/workspaces/team/baseworkspace', $this->token('editor-machine', 'workspaces.read'), ['baseWorkspaceName' => 'live']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.update', self::json($response)['detail']);
    }

    #[Test]
    public function changeBaseWorkspaceRequiresAToken(): void
    {
        self::assertSame(401, $this->put('/api/cr/default/workspaces/team/baseworkspace', null, ['baseWorkspaceName' => 'live'])->getStatusCode());
    }

    #[Test]
    public function publishingOrDiscardingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $token = $this->token('editor-machine', 'workspaces.publish');
        foreach (['publish', 'discard'] as $action) {
            // [] is {}: all changes
            $response = $this->post('/api/cr/unknown/workspaces/user-editor/' . $action, $token, []);
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);

            $response = $this->post('/api/cr/unknown/workspaces/user-editor/' . $action, $token, ['documentAggregateIds' => ['a3474e1d-dd60-4a84-82b1-18d2f21891a3']]);
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        }
    }

    #[Test]
    public function rejectsInvalidPublishAndDiscardSelections(): void
    {
        $token = $this->token('editor-machine', 'workspaces.publish');
        foreach (['publish', 'discard'] as $action) {
            $path = '/api/cr/default/workspaces/user-editor/' . $action;
            self::assertSame(400, $this->post('/api/cr/default/workspaces/Not_A_Name/' . $action, $token, [])->getStatusCode());
            self::assertSame(400, $this->post($path, $token, ['siteAggregateId' => 'Not An Id'])->getStatusCode());
            self::assertSame(400, $this->post($path, $token, ['documentAggregateIds' => ['Not An Id']])->getStatusCode());
            self::assertSame(400, $this->post($path, $token, ['documentAggregateIds' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3'])->getStatusCode());
            self::assertSame(400, $this->post($path, $token, ['baseWorkspaceName' => 'live'])->getStatusCode());
        }
    }

    #[Test]
    public function documentsPublishAndDiscardUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        foreach (['publish' => ['publishWorkspace', 'WorkspacePublish', 'PublishingResult'], 'discard' => ['discardWorkspace', 'WorkspaceDiscard', 'DiscardingResult']] as $action => [$operationId, $body, $result]) {
            $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/' . $action]['post'];
            self::assertSame($operationId, $operation['operationId']);
            self::assertSame(['Workspaces'], $operation['tags']);
            self::assertSame(['contentRepositoryId', 'workspaceName'], array_column($operation['parameters'], 'name'));
            // optional: without a body, all changes
            self::assertFalse($operation['requestBody']['required']);
            self::assertSame('#/components/schemas/' . $body, $operation['requestBody']['content']['application/json']['schema']['$ref']);
            self::assertArrayNotHasKey('required', $document['components']['schemas'][$body]);
            self::assertSame(['siteAggregateId', 'documentAggregateIds'], array_keys($document['components']['schemas'][$body]['properties']));
            self::assertSame('#/components/schemas/' . $result, $operation['responses']['200']['content']['application/json']['schema']['$ref']);
            foreach (['200', '400', '401', '403', '404', '409', '422'] as $status) {
                self::assertArrayHasKey($status, $operation['responses']);
            }
            self::assertSame('#/components/schemas/WorkspaceConflictProblem', $operation['responses']['409']['content']['application/problem+json']['schema']['$ref']);
        }
        self::assertSame(['numberOfPublishedChanges', 'workspace'], $document['components']['schemas']['PublishingResult']['required']);
        self::assertSame(['numberOfDiscardedChanges', 'workspace'], $document['components']['schemas']['DiscardingResult']['required']);
    }

    #[Test]
    public function publishAndDiscardRequireThePublishScope(): void
    {
        $token = $this->token('editor-machine', 'workspaces.read workspaces.update');
        foreach (['publish', 'discard'] as $action) {
            $response = $this->post('/api/cr/default/workspaces/user-editor/' . $action, $token, []);
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('workspaces.publish', self::json($response)['detail']);
        }
    }

    #[Test]
    public function publishAndDiscardRequireThePrivilege(): void
    {
        // nobody has no role that grants workspaces.publish
        $response = $this->post('/api/cr/default/workspaces/user-nobody/publish', $this->token('nobody-machine', 'workspaces.publish'), []);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function publishAndDiscardRequireAToken(): void
    {
        self::assertSame(401, $this->post('/api/cr/default/workspaces/user-editor/publish', null, [])->getStatusCode());
        self::assertSame(401, $this->post('/api/cr/default/workspaces/user-editor/discard', null, [])->getStatusCode());
    }

    #[Test]
    public function rebasingAWorkspaceOfAnUnknownContentRepositoryIsNotFound(): void
    {
        $response = $this->post('/api/cr/unknown/workspaces/user-rebase/rebase', $this->token('editor-machine', 'workspaces.publish'), ['strategy' => 'force']);

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
    }

    #[Test]
    public function rejectsInvalidRebases(): void
    {
        $token = $this->token('editor-machine', 'workspaces.publish');

        self::assertSame(400, $this->post('/api/cr/default/workspaces/Not_A_Name/rebase', $token, ['strategy' => 'fail'])->getStatusCode());
        // lowercase only
        self::assertSame(400, $this->post('/api/cr/default/workspaces/user-rebase/rebase', $token, ['strategy' => 'FORCE'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces/user-rebase/rebase', $token, ['strategy' => 'skip'])->getStatusCode());
        self::assertSame(400, $this->post('/api/cr/default/workspaces/user-rebase/rebase', $token, ['baseWorkspaceName' => 'live'])->getStatusCode());
    }

    #[Test]
    public function documentsTheRebaseUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/rebase']['post'];

        self::assertSame('rebaseWorkspace', $operation['operationId']);
        self::assertSame(['Workspaces'], $operation['tags']);
        self::assertSame(['contentRepositoryId', 'workspaceName'], array_column($operation['parameters'], 'name'));
        // the body is optional, the strategy is fail without it
        self::assertFalse($operation['requestBody']['required']);
        self::assertSame(['strategy'], array_keys($document['components']['schemas']['WorkspaceRebase']['properties']));
        self::assertSame(['fail', 'force'], $document['components']['schemas']['RebaseStrategy']['enum']);
        foreach (['200', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
        self::assertSame('#/components/schemas/WorkspaceConflictProblem', $operation['responses']['409']['content']['application/problem+json']['schema']['$ref']);
    }

    #[Test]
    public function rebaseRequiresThePublishScope(): void
    {
        $response = $this->post('/api/cr/default/workspaces/user-rebase/rebase', $this->token('editor-machine', 'workspaces.update'), ['strategy' => 'fail']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.publish', self::json($response)['detail']);
    }

    #[Test]
    public function rebaseRequiresThePrivilege(): void
    {
        // nobody has no roles, Neos grants publishing to editors
        $response = $this->post('/api/cr/default/workspaces/user-rebase/rebase', $this->token('nobody-machine', 'workspaces.publish'), ['strategy' => 'fail']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function rebaseRequiresAToken(): void
    {
        self::assertSame(401, $this->post('/api/cr/default/workspaces/user-rebase/rebase', null, ['strategy' => 'fail'])->getStatusCode());
    }

    #[Test]
    public function rolesOfAWorkspaceOfAnUnknownContentRepositoryAreNotFound(): void
    {
        $readToken = $this->token('editor-machine', 'workspaces.read');
        $rolesToken = $this->token('editor-machine', 'workspaces.roles');

        foreach ([
            $this->get('/api/cr/unknown/workspaces/team/roles', $readToken),
            $this->put('/api/cr/unknown/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $rolesToken, ['role' => 'viewer']),
            $this->delete('/api/cr/unknown/workspaces/team/roles/user/0fd65ee4-9e85-4b4a-9f2c-4b7b2f3b9e44', $rolesToken),
        ] as $response) {
            self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
            self::assertSame('There is no content repository with the ID unknown', self::json($response)['detail']);
        }
    }

    #[Test]
    public function rejectsInvalidRoleAssignments(): void
    {
        $token = $this->token('editor-machine', 'workspaces.roles');

        self::assertSame(400, $this->put('/api/cr/default/workspaces/Not_A_Name/roles/group/Neos.Neos:AbstractEditor', $token, ['role' => 'viewer'])->getStatusCode());
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/roles/team/Neos.Neos:AbstractEditor', $token, ['role' => 'viewer'])->getStatusCode());
        // the values are the API's lowercase ones, not Neos'
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/roles/GROUP/Neos.Neos:AbstractEditor', $token, ['role' => 'viewer'])->getStatusCode());
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $token, ['role' => 'VIEWER'])->getStatusCode());
        self::assertSame(400, $this->put('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $token, [])->getStatusCode());
        self::assertSame(400, $this->delete('/api/cr/default/workspaces/team/roles/everyone/Neos.Neos:AbstractEditor', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheRolesUnderTheWorkspacesTag(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $list = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/roles']['get'];
        $subject = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/roles/{subjectType}/{subjectId}'];

        self::assertSame('listWorkspaceRoles', $list['operationId']);
        self::assertSame('assignWorkspaceRole', $subject['put']['operationId']);
        self::assertSame('unassignWorkspaceRole', $subject['delete']['operationId']);
        foreach ([$list, $subject['put'], $subject['delete']] as $operation) {
            self::assertSame(['Workspaces'], $operation['tags']);
        }
        self::assertSame(['contentRepositoryId', 'workspaceName', 'subjectType', 'subjectId'], array_column($subject['put']['parameters'], 'name'));
        self::assertSame('#/components/schemas/WorkspaceRoleAssign', $subject['put']['requestBody']['content']['application/json']['schema']['$ref']);
        foreach (['200', '403', '404'] as $status) {
            self::assertArrayHasKey($status, $list['responses']);
        }
        foreach (['200', '403', '404', '422'] as $status) {
            self::assertArrayHasKey($status, $subject['put']['responses']);
        }
        foreach (['204', '403', '404', '422'] as $status) {
            self::assertArrayHasKey($status, $subject['delete']['responses']);
        }
        self::assertSame(['user', 'group'], $document['components']['schemas']['WorkspaceRoleSubjectType']['enum']);
        self::assertSame(['viewer', 'collaborator', 'manager'], $document['components']['schemas']['WorkspaceRole']['enum']);
        self::assertSame(['data', 'meta'], array_keys($document['components']['schemas']['WorkspaceRoleAssignmentListing']['properties']));
    }

    #[Test]
    public function listingTheRolesRequiresTheReadScope(): void
    {
        $response = $this->get('/api/cr/default/workspaces/team/roles', $this->token('editor-machine', 'workspaces.roles'));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('workspaces.read', self::json($response)['detail']);
    }

    #[Test]
    public function changingTheRolesRequiresTheRolesScope(): void
    {
        $token = $this->token('editor-machine', 'workspaces.read workspaces.update');

        foreach ([
            $this->put('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $token, ['role' => 'viewer']),
            $this->delete('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $token),
        ] as $response) {
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
            self::assertStringContainsString('workspaces.roles', self::json($response)['detail']);
        }
    }

    #[Test]
    public function changingTheRolesRequiresThePrivilege(): void
    {
        // nobody has no Neos role, so no workspaces.roles privilege
        $response = $this->put('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', $this->token('nobody-machine', 'workspaces.roles'), ['role' => 'viewer']);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function theRolesRequireAToken(): void
    {
        self::assertSame(401, $this->get('/api/cr/default/workspaces/team/roles', null)->getStatusCode());
        self::assertSame(401, $this->put('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', null, ['role' => 'viewer'])->getStatusCode());
        self::assertSame(401, $this->delete('/api/cr/default/workspaces/team/roles/group/Neos.Neos:AbstractEditor', null)->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidChangeFilters(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        // checked before the content repository is looked up (else a 404 here)
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?filterByDocument=Not_An_Id', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?filterBySite=Not_An_Id', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?filterByChangeType=removed', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?filterByChangeType=created,', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?filterByChangeType=', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/cr/unknown/workspaces/live/changes?filterByChangeType=created,deleted&filterByDocument=a-b&filterBySite=c', $token)->getStatusCode());
    }

    #[Test]
    public function rejectsUnknownChangeIncludes(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        $response = $this->get('/api/cr/unknown/workspaces/live/changes?include=diff,parent', $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t include parent, only: diff, node, node.ancestors, document, document.ancestors', self::json($response)['detail']);
        self::assertSame(400, $this->get('/api/cr/unknown/workspaces/live/changes?include=diff.properties', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/cr/unknown/workspaces/live/changes?include=diff', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheChangeFiltersAndTheDiff(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/changes']['get'];
        $parameters = array_column($operation['parameters'], null, 'name');

        self::assertSame('query', $parameters['filterByChangeType']['in']);
        self::assertStringContainsString('trash', $operation['description']);
        self::assertContains('400', array_map('strval', array_keys($operation['responses'])));
        $change = $document['components']['schemas']['Change'];
        foreach (['documentAggregateId', 'siteAggregateId', 'diff'] as $member) {
            self::assertArrayHasKey($member, $change['properties']);
        }
    }

    #[Test]
    public function documentsTheDiffOfAChange(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $schemas = $document['components']['schemas'];

        self::assertSame(['createdVariant', 'nodeType', 'name', 'parentAggregateId', 'properties', 'references', 'tags'], $schemas['ChangeDiff']['required']);
        self::assertSame(['name', 'old', 'new'], $schemas['PropertyDiff']['required']);
        // any JSON value, as the node's properties
        self::assertArrayNotHasKey('type', $schemas['PropertyDiff']['properties']['old']);
        self::assertSame('#/components/schemas/PropertyDiff', $schemas['PropertyDiffList']['items']['$ref']);
        self::assertSame('#/components/schemas/ReferenceDiff', $schemas['ReferenceDiffList']['items']['$ref']);
        self::assertSame('array', $schemas['ReferenceDiff']['properties']['old']['type']);
        self::assertSame(['added', 'removed'], $schemas['TagDiff']['required']);
        self::assertSame(['old', 'new'], $schemas['NodeAggregateIdDiff']['required']);
        self::assertSame(['old', 'new'], $schemas['NodeTypeDiff']['required']);
    }

    #[Test]
    public function serializesTheDiffOfAChange(): void
    {
        $diff = new Schema\ChangeDiff(
            true,
            null,
            null,
            new Schema\NodeAggregateIdDiff(NodeAggregateId::fromString('old-parent'), NodeAggregateId::fromString('new-parent')),
            new Schema\PropertyDiffList(
                new Schema\PropertyDiff('image', null, ['id' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3', 'url' => null]),
                new Schema\PropertyDiff('tags', [], ['a', 'b']),
                new Schema\PropertyDiff('title', 'Old', 'New'),
            ),
            new Schema\ReferenceDiffList(new Schema\ReferenceDiff('relatedPages', ['a'], [])),
            new Schema\TagDiff(['disabled'], []),
        );

        self::assertSame(
            '{"createdVariant":true,"nodeType":null,"name":null,"parentAggregateId":{"old":"old-parent","new":"new-parent"},'
            . '"properties":[{"name":"image","old":null,"new":{"id":"a3474e1d-dd60-4a84-82b1-18d2f21891a3","url":null}},{"name":"tags","old":[],"new":["a","b"]},{"name":"title","old":"Old","new":"New"}],'
            . '"references":[{"name":"relatedPages","old":["a"],"new":[]}],"tags":{"added":["disabled"],"removed":[]}}',
            json_encode(Schematic::serialize($diff)),
        );
    }

    #[Test]
    public function eventsAreSortedBySequenceNumberOnly(): void
    {
        $token = $this->token('nobody-machine', 'workspaces.read');

        $response = $this->get('/api/cr/default/workspaces/live/events?sort=recordedAt', $token);
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Can\'t sort by recordedAt, only by sequenceNumber or -sequenceNumber', self::json($response)['detail']);
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?sort=-sequenceNumber,version', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/cr/default/workspaces/live/events?sort=', $token)->getStatusCode());
        // both orders get as far as the content repository, which the Testing context doesn't have
        self::assertSame(404, $this->get('/api/cr/unknown/workspaces/live/events?sort=sequenceNumber', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/cr/unknown/workspaces/live/events?sort=-sequenceNumber&after=1', $token)->getStatusCode());
    }

    #[Test]
    public function documentsTheContentStreamOfTheEvents(): void
    {
        $document = self::json($this->get('/api/openapi.json', null));
        $operation = $document['paths']['/cr/{contentRepositoryId}/workspaces/{workspaceName}/events']['get'];
        self::assertStringContainsString('-sequenceNumber', $operation['description']);
        self::assertStringContainsString('meta.contentStreamId', $operation['description']);
        $listing = $document['components']['schemas']['PaginatedEventListing'];
        self::assertSame(['data', 'meta', 'links'], array_keys($listing['properties']));
        $meta = $document['components']['schemas']['EventListingMeta'];
        self::assertSame(['contentStreamId', 'forkedFrom'], $meta['required']);
        self::assertSame(['contentStreamId', 'version'], $document['components']['schemas']['ContentStreamPosition']['required']);
    }

    #[Test]
    public function aChangeMatchesIfItHasOneOfTheChangeTypes(): void
    {
        $change = static fn (bool $created, bool $changed, bool $moved, bool $deleted) => new Schema\Change(NodeAggregateId::fromString('node'), null, null, null, $created, $changed, $moved, $deleted, null, null, null);

        self::assertTrue(ChangeTypes::fromString('created')->matches($change(true, false, false, false)));
        self::assertTrue(ChangeTypes::fromString('moved,deleted')->matches($change(false, true, false, true)));
        self::assertFalse(ChangeTypes::fromString('created,moved')->matches($change(false, true, false, true)));
        self::assertFalse(ChangeTypes::fromString('deleted')->matches($change(false, false, false, false)));
    }

    #[Test]
    public function documentsTheVisibilitiesAndWhatAConflictCompleted(): void
    {
        $schemas = self::json($this->get('/api/openapi.json', null))['components']['schemas'];

        self::assertSame(['public', 'private'], $schemas['WorkspaceVisibility']['enum']);
        foreach (['conflicts', 'inaccessibleConflicts', 'completedDocumentAggregateIds', 'numberOfCompletedChanges'] as $member) {
            self::assertArrayHasKey($member, $schemas['WorkspaceConflictProblem']['properties']);
        }
    }
}
