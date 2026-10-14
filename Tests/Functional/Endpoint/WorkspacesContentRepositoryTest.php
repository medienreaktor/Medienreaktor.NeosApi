<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Workspaces against a real content repository (MariaDB, FLOW_CONTEXT=Testing/Ddev): live with a site and a page,
 * workspaces created through the API, changes made with content repository commands
 */
class WorkspacesContentRepositoryTest extends ContentRepositoryTestCase
{
    private const string SITE = 'site';
    private const string PAGE = 'page';
    private const string PAGE_TYPE = 'Neos.Demo:Document.Page';

    private string $editorId;
    private string $otherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireLiveWorkspace();
        $userService = $this->objectManager->get(UserService::class);
        $this->editorId = $userService->createUser('editor', 'password', 'Edda', 'Editor', ['Neos.Neos:Editor'])->getId()->value;
        $this->otherId = $userService->createUser('other', 'password', 'Otto', 'Other', ['Neos.Neos:Editor'])->getId()->value;
        $this->addMachineClient('editor-machine', 'editor');
        $this->persistenceManager->persistAll();

        $this->handle(CreateRootNodeAggregateWithNode::create(WorkspaceName::forLive(), NodeAggregateId::fromString('sites'), NodeTypeName::fromString('Neos.Neos:Sites')));
        $this->handle(CreateNodeAggregateWithNode::create(WorkspaceName::forLive(), NodeAggregateId::fromString(self::SITE), NodeTypeName::fromString('Neos.Demo:Document.Homepage'), self::origin(), NodeAggregateId::fromString('sites')));
        $this->createPage('live', self::PAGE, self::SITE);
    }

    #[Test]
    public function createsPublicPrivateAndPersonalWorkspaces(): void
    {
        $token = $this->token('editor-machine', 'workspaces.create workspaces.read');

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'visibility' => 'public']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('workspaces/team', $response->getHeaderLine('Location'));
        $workspace = self::json($response);
        self::assertSame(['shared', 'live', true], [$workspace['classification'], $workspace['baseWorkspaceName'], $workspace['permissions']['manage']]);
        self::assertSame([['group', 'Neos.Neos:AbstractEditor', 'collaborator'], ['user', $this->editorId, 'manager']], $this->roles('team'));

        // the classification's name, no longer a visibility
        self::assertSame(400, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Old', 'visibility' => 'shared'])->getStatusCode());

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Secret', 'visibility' => 'private']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([['user', $this->editorId, 'manager']], $this->roles('secret'));

        $response = $this->post('/api/cr/default/workspaces', $token, ['classification' => 'personal', 'name' => 'mine']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['personal', 'Edda Editor'], [self::json($response)['classification'], self::json($response)['title']]);
        $response = $this->post('/api/cr/default/workspaces', $token, ['classification' => 'personal']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The user has a personal workspace mine in the content repository default already', self::json($response)['detail']);

        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('workspaces/team-1', $response->getHeaderLine('Location'));
        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'name' => 'team']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        $response = $this->post('/api/cr/default/workspaces', $token, ['title' => 'Team', 'baseWorkspaceName' => 'unknown']);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function publishesAndDiscardsAllChanges(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.publish workspaces.read');

        $this->createPage('team', 'new-page', self::SITE);
        $response = $this->post('/api/cr/default/workspaces/team/discard', $token, []);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertGreaterThan(0, self::json($response)['numberOfDiscardedChanges']);
        self::assertFalse(self::json($response)['workspace']['hasPublishableChanges']);
        self::assertNull($this->nodeAggregate('team', 'new-page'));

        $this->createPage('team', 'new-page', self::SITE);
        $response = $this->post('/api/cr/default/workspaces/team/publish', $token, []);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertGreaterThan(0, self::json($response)['numberOfPublishedChanges']);
        self::assertNotNull($this->nodeAggregate('live', 'new-page'));

        // nothing left
        $response = $this->post('/api/cr/default/workspaces/team/publish', $token, []);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(0, self::json($response)['numberOfPublishedChanges']);

        $response = $this->post('/api/cr/default/workspaces/live/publish', $token, []);
        self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function publishesBySiteAndDocumentsAncestorsFirst(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.publish');

        // the child given first: it needs its new parent in live first
        $this->createPage('team', 'parent-page', self::SITE);
        $this->createPage('team', 'child-page', 'parent-page');
        $response = $this->post('/api/cr/default/workspaces/team/publish', $token, ['documentAggregateIds' => ['child-page', 'parent-page']]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotNull($this->nodeAggregate('live', 'child-page'));

        // the parent given first: it must not lose its new child while that is kept
        $this->createPage('team', 'other-parent', self::SITE);
        $this->createPage('team', 'other-child', 'other-parent');
        $response = $this->post('/api/cr/default/workspaces/team/discard', $token, ['documentAggregateIds' => ['other-parent', 'other-child']]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertNull($this->nodeAggregate('team', 'other-parent'));

        $this->createPage('team', 'site-page', self::SITE);
        $response = $this->post('/api/cr/default/workspaces/team/publish', $token, ['siteAggregateId' => self::SITE]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotNull($this->nodeAggregate('live', 'site-page'));

        foreach ([['siteAggregateId' => self::PAGE], ['documentAggregateIds' => ['unknown']], ['documentAggregateIds' => []]] as $invalid) {
            self::assertSame(422, $this->post('/api/cr/default/workspaces/team/publish', $token, $invalid)->getStatusCode(), json_encode($invalid));
        }
    }

    #[Test]
    public function aConflictTellsWhichDocumentsWerePublishedBefore(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.publish');
        $this->createPage('team', 'unrelated-page', self::SITE);
        $this->createPage('team', 'new-parent', self::SITE);
        $this->createPage('team', 'new-child', 'new-parent');

        // the child can't be published without its new parent, the unrelated page before it can
        $response = $this->post('/api/cr/default/workspaces/team/publish', $token, ['documentAggregateIds' => ['unrelated-page', 'new-child']]);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        $problem = self::json($response);
        self::assertSame('partial', $problem['title']);
        self::assertSame(['unrelated-page'], $problem['completedDocumentAggregateIds']);
        self::assertGreaterThan(0, $problem['numberOfCompletedChanges']);
        self::assertSame(0, $problem['inaccessibleConflicts']);
        self::assertContains('new-child', array_column($problem['conflicts'], 'nodeAggregateId'));
        self::assertContains('node_has_been_created', array_column($problem['conflicts'], 'typeOfChange'));
        self::assertNotNull($this->nodeAggregate('live', 'unrelated-page'));
    }

    #[Test]
    public function rebasesWithFailOrForce(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.publish workspaces.read');
        $this->handle(SetNodeProperties::create(WorkspaceName::fromString('team'), NodeAggregateId::fromString(self::PAGE), self::origin(), PropertyValuesToWrite::fromArray(['title' => 'Changed'])));
        $this->handle(RemoveNodeAggregate::create(WorkspaceName::forLive(), NodeAggregateId::fromString(self::PAGE), DimensionSpacePoint::fromArray(['language' => 'en_US']), NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS));
        self::assertSame('outdated', self::json($this->get('/api/cr/default/workspaces/team', $token))['status']);

        foreach ([$this->post('/api/cr/default/workspaces/team/rebase', $token, []), $this->post('/api/cr/default/workspaces/team/publish', $token, [])] as $response) {
            self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
            $problem = self::json($response);
            self::assertSame('rebase', $problem['title']);
            self::assertSame([self::PAGE, 'node_has_been_deleted'], [$problem['conflicts'][0]['nodeAggregateId'], $problem['conflicts'][0]['reason']]);
            // the title set in the workspace
            self::assertSame('node_has_been_changed', $problem['conflicts'][0]['typeOfChange']);
            self::assertSame([[], 0], [$problem['completedDocumentAggregateIds'], $problem['numberOfCompletedChanges']]);
        }

        $response = $this->post('/api/cr/default/workspaces/team/rebase', $token, ['strategy' => 'force']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['up_to_date', false], [self::json($response)['status'], self::json($response)['hasPublishableChanges']]);
    }

    #[Test]
    public function changesTheBaseOnlyWithoutChanges(): void
    {
        $this->createTeamWorkspace();
        $this->createTeamWorkspace('Other');
        $token = $this->token('editor-machine', 'workspaces.update');

        $response = $this->put('/api/cr/default/workspaces/other/baseworkspace', $token, ['baseWorkspaceName' => 'team']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('team', self::json($response)['baseWorkspaceName']);

        $this->createPage('other', 'new-page', self::SITE);
        $response = $this->put('/api/cr/default/workspaces/other/baseworkspace', $token, ['baseWorkspaceName' => 'live']);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        // the current base sends nothing
        self::assertSame(200, $this->put('/api/cr/default/workspaces/other/baseworkspace', $token, ['baseWorkspaceName' => 'team'])->getStatusCode());
        // based on it
        self::assertSame(422, $this->put('/api/cr/default/workspaces/team/baseworkspace', $token, ['baseWorkspaceName' => 'other'])->getStatusCode());
        self::assertSame(422, $this->put('/api/cr/default/workspaces/live/baseworkspace', $token, ['baseWorkspaceName' => 'team'])->getStatusCode());
    }

    #[Test]
    public function deletesWorkspacesWithoutDependantsAndChangesUnlessForced(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.create workspaces.delete workspaces.read');
        self::assertSame(201, $this->post('/api/cr/default/workspaces', $token, ['title' => 'Sub', 'baseWorkspaceName' => 'team'])->getStatusCode());

        $response = $this->delete('/api/cr/default/workspaces/team', $token);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('The workspace team can\'t be deleted, other workspaces are based on it: sub', self::json($response)['detail']);
        self::assertSame(204, $this->delete('/api/cr/default/workspaces/sub', $token)->getStatusCode());

        $this->createPage('team', 'new-page', self::SITE);
        self::assertSame(409, $this->delete('/api/cr/default/workspaces/team', $token)->getStatusCode());
        self::assertSame(204, $this->delete('/api/cr/default/workspaces/team?force=true', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/cr/default/workspaces/team', $token)->getStatusCode());
        self::assertSame(422, $this->delete('/api/cr/default/workspaces/live', $token)->getStatusCode());
    }

    #[Test]
    public function assignsReplacesAndUnassignsRolesButNotOnesOwn(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.roles workspaces.read');
        $other = '/api/cr/default/workspaces/team/roles/user/' . $this->otherId;

        $response = $this->put($other, $token, ['role' => 'viewer']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['subject' => ['type' => 'user', 'id' => $this->otherId], 'role' => 'viewer'], self::json($response));
        self::assertSame(200, $this->put($other, $token, ['role' => 'collaborator'])->getStatusCode());
        self::assertContains(['user', $this->otherId, 'collaborator'], $this->roles('team'));
        self::assertSame(204, $this->delete($other, $token)->getStatusCode());
        self::assertSame(404, $this->delete($other, $token)->getStatusCode());
        self::assertNotContains(['user', $this->otherId, 'collaborator'], $this->roles('team'));

        $own = '/api/cr/default/workspaces/team/roles/user/' . $this->editorId;
        self::assertSame(422, $this->put($own, $token, ['role' => 'viewer'])->getStatusCode());
        self::assertSame(422, $this->delete($own, $token)->getStatusCode());
        self::assertSame(200, $this->put($own, $token, ['role' => 'manager'])->getStatusCode());
        self::assertSame(422, $this->put('/api/cr/default/workspaces/team/roles/user/7f0e5f1c-0000-4000-8000-000000000000', $token, ['role' => 'viewer'])->getStatusCode());
    }

    #[Test]
    public function listsChangesByDocumentWithFiltersTotalAndDiff(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.read');
        $this->createPage('team', 'b-page', self::SITE);
        $this->createPage('team', 'a-page', self::SITE);
        $this->handle(SetNodeProperties::create(WorkspaceName::fromString('team'), NodeAggregateId::fromString(self::PAGE), self::origin(), PropertyValuesToWrite::fromArray(['title' => 'Changed'])));

        $listing = self::json($this->get('/api/cr/default/workspaces/team/changes', $token));
        // grouped by document: each page with its tethered content collection
        self::assertSame(['a-page', 'a-page', 'b-page', 'b-page', self::PAGE], array_column($listing['data'], 'documentAggregateId'), json_encode($listing['data']));
        self::assertSame(5, $listing['meta']['total']);
        self::assertSame([self::SITE], array_values(array_unique(array_column($listing['data'], 'siteAggregateId'))));
        self::assertNull($listing['data'][0]['diff']);

        $listing = self::json($this->get('/api/cr/default/workspaces/team/changes?filterByDocument=a-page&limit=1', $token));
        self::assertSame([2, 1], [$listing['meta']['total'], count($listing['data'])]);
        self::assertNotNull($listing['links']['next']);
        // the projection marks created nodes as changed as well
        self::assertSame(4, self::json($this->get('/api/cr/default/workspaces/team/changes?filterByChangeType=created', $token))['meta']['total']);
        self::assertSame(5, self::json($this->get('/api/cr/default/workspaces/team/changes?filterByChangeType=changed', $token))['meta']['total']);
        $listing = self::json($this->get('/api/cr/default/workspaces/team/changes?filterByDocument=' . self::PAGE . '&include=diff', $token));
        self::assertSame([self::PAGE], array_column($listing['data'], 'nodeAggregateId'));
        self::assertContains(['name' => 'title', 'old' => self::PAGE, 'new' => 'Changed'], $listing['data'][0]['diff']['properties']);
        self::assertSame(0, self::json($this->get('/api/cr/default/workspaces/team/changes?filterBySite=other-site', $token))['meta']['total']);
    }

    #[Test]
    public function pagesEventsInBothDirectionsAndRejectsCursorsOfEarlierContentStreams(): void
    {
        $this->createTeamWorkspace();
        $token = $this->token('editor-machine', 'workspaces.read workspaces.publish');
        $this->createPage('team', 'one', self::SITE);
        $this->createPage('team', 'two', self::SITE);

        $all = self::json($this->get('/api/cr/default/workspaces/team/events?limit=100', $token));
        self::assertSame('ContentStreamWasForked', $all['data'][0]['type']);
        self::assertNotNull($all['meta']['forkedFrom']);
        self::assertNull(self::json($this->get('/api/cr/default/workspaces/live/events', $token))['meta']['forkedFrom']);
        $sequenceNumbers = array_column($all['data'], 'sequenceNumber');
        self::assertGreaterThan(2, count($sequenceNumbers));

        // oldest first, next is always there: the last page has one to poll
        $seen = [];
        $next = '/api/cr/default/workspaces/team/events?limit=2';
        for ($pages = 0; $pages < 20; $pages++) {
            $page = self::json($this->get($next, $token));
            if ($page['data'] === []) {
                break;
            }
            array_push($seen, ...array_column($page['data'], 'sequenceNumber'));
            self::assertNotNull($page['links']['next']);
            $next = substr($page['links']['next'], strlen('http://localhost'));
        }
        self::assertSame($sequenceNumbers, $seen);
        self::assertStringContainsString('after=' . end($sequenceNumbers), $page['links']['next']);

        // newest first, it ends
        $seen = [];
        $next = '/api/cr/default/workspaces/team/events?limit=2&sort=-sequenceNumber';
        do {
            $page = self::json($this->get($next, $token));
            array_push($seen, ...array_column($page['data'], 'sequenceNumber'));
            $next = $page['links']['next'] !== null ? substr($page['links']['next'], strlen('http://localhost')) : null;
        } while ($next !== null);
        self::assertSame(array_reverse($sequenceNumbers), $seen);

        // publishing starts a new content stream: the old cursor is from the one before
        self::assertSame(200, $this->post('/api/cr/default/workspaces/team/publish', $token, [])->getStatusCode());
        $response = $this->get('/api/cr/default/workspaces/team/events?after=' . end($sequenceNumbers), $token);
        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Start over without after', self::json($response)['detail']);
        $current = self::json($this->get('/api/cr/default/workspaces/team/events', $token));
        self::assertNotSame($all['meta']['contentStreamId'], $current['meta']['contentStreamId']);
    }

    private function createTeamWorkspace(string $title = 'Team'): void
    {
        $response = $this->post('/api/cr/default/workspaces', $this->token('editor-machine', 'workspaces.create'), ['title' => $title]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    private function createPage(string $workspaceName, string $nodeAggregateId, string $parentNodeAggregateId): void
    {
        $this->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::fromString($workspaceName),
            NodeAggregateId::fromString($nodeAggregateId),
            NodeTypeName::fromString(self::PAGE_TYPE),
            self::origin(),
            NodeAggregateId::fromString($parentNodeAggregateId),
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => $nodeAggregateId, 'uriPathSegment' => $nodeAggregateId]),
        ));
    }

    /**
     * @return list<array{string, string, string}> type, ID and role of each assignment
     */
    private function roles(string $workspaceName): array
    {
        $listing = self::json($this->get('/api/cr/default/workspaces/' . $workspaceName . '/roles', $this->token('editor-machine', 'workspaces.read')));
        return array_map(static fn (array $assignment) => [$assignment['subject']['type'], $assignment['subject']['id'], $assignment['role']], $listing['data']);
    }

    private function nodeAggregate(string $workspaceName, string $nodeAggregateId): ?object
    {
        return $this->withoutAuthorizationChecks(fn () => $this->contentRepository()->getContentGraph(WorkspaceName::fromString($workspaceName))->findNodeAggregateById(NodeAggregateId::fromString($nodeAggregateId)));
    }

    private static function origin(): OriginDimensionSpacePoint
    {
        return OriginDimensionSpacePoint::fromArray(['language' => 'en_US']);
    }
}
