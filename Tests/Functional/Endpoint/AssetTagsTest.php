<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class AssetTagsTest extends MediaTestCase
{
    private string $natureId;

    private string $treesId;

    private string $oaksId;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');

        $tagRepository = $this->objectManager->get(TagRepository::class);
        $nature = new Tag('Nature');
        $trees = new Tag('Trees');
        $trees->setParent($nature);
        $oaks = new Tag('Oaks');
        $oaks->setParent($trees);
        foreach ([$nature, $trees, $oaks, new Tag('Animals')] as $tag) {
            $tagRepository->add($tag);
        }

        $asset = $this->addDocument('oak.pdf');
        $asset->addTag($trees);
        $asset->addTag($oaks);
        $this->persistenceManager->persistAll();

        $this->natureId = $this->persistenceManager->getIdentifierByObject($nature);
        $this->treesId = $this->persistenceManager->getIdentifierByObject($trees);
        $this->oaksId = $this->persistenceManager->getIdentifierByObject($oaks);
        $this->assetId = $this->persistenceManager->getIdentifierByObject($asset);
    }

    #[Test]
    public function listsTheTagsAsATree(): void
    {
        $response = $this->get('/api/assettags', $this->token('editor-machine', 'assettags.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['total' => 4], self::json($response)['meta']);
        $roots = self::json($response)['data'];
        self::assertSame(['Animals', 'Nature'], array_column($roots, 'label'));
        self::assertSame([
            'id' => $this->natureId,
            'label' => 'Nature',
            'parentId' => null,
            'assetCount' => 0,
            'children' => [[
                'id' => $this->treesId,
                'label' => 'Trees',
                'parentId' => $this->natureId,
                'assetCount' => 1,
                'children' => [[
                    'id' => $this->oaksId,
                    'label' => 'Oaks',
                    'parentId' => $this->treesId,
                    'assetCount' => 1,
                    'children' => [],
                ]],
            ]],
        ], $roots[1]);
    }

    #[Test]
    public function getsATag(): void
    {
        $token = $this->token('editor-machine', 'assettags.read');

        $response = $this->get('/api/assettags/' . $this->treesId, $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Trees', self::json($response)['label']);
        self::assertSame(['Oaks'], array_column(self::json($response)['children'], 'label'));

        self::assertSame(404, $this->get('/api/assettags/' . self::UNKNOWN_ID, $token)->getStatusCode());
    }

    #[Test]
    public function needsTheScope(): void
    {
        self::assertSame(403, $this->get('/api/assettags', $this->token('editor-machine', 'assetcollections.read'))->getStatusCode());
    }

    #[Test]
    public function createsATag(): void
    {
        $token = $this->token('editor-machine', 'assettags.create assettags.read');

        $response = $this->post('/api/assettags', $token, ['label' => 'Beeches', 'parentId' => $this->treesId]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $tag = self::json($response);
        self::assertSame(['label' => 'Beeches', 'parentId' => $this->treesId, 'assetCount' => 0, 'children' => []], array_diff_key($tag, ['id' => true]));
        self::assertSame('assettags/' . $tag['id'], $response->getHeaderLine('Location'));
        self::assertSame(['Beeches', 'Oaks'], array_column(self::json($this->get('/api/assettags/' . $this->treesId, $token))['children'], 'label'));

        $atTheRoot = $this->post('/api/assettags', $token, ['label' => 'Cities']);
        self::assertSame(201, $atTheRoot->getStatusCode(), (string)$atTheRoot->getBody());
        self::assertNull(self::json($atTheRoot)['parentId']);
    }

    #[Test]
    public function rejectsInvalidTags(): void
    {
        $token = $this->token('editor-machine', 'assettags.create');

        self::assertSame(400, $this->post('/api/assettags', $token, ['label' => ''])->getStatusCode());
        self::assertSame(422, $this->post('/api/assettags', $token, ['label' => 'Beeches', 'parentId' => self::UNKNOWN_ID])->getStatusCode());
    }

    #[Test]
    public function renamesAndMovesATag(): void
    {
        $token = $this->token('editor-machine', 'assettags.update assettags.read');

        $response = $this->patch('/api/assettags/' . $this->oaksId, $token, ['label' => 'Oak trees']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Oak trees', self::json($response)['label']);
        self::assertSame($this->treesId, self::json($response)['parentId']);

        $moved = $this->patch('/api/assettags/' . $this->oaksId, $token, ['parentId' => $this->natureId]);
        self::assertSame(200, $moved->getStatusCode(), (string)$moved->getBody());
        self::assertSame($this->natureId, self::json($moved)['parentId']);
        self::assertSame('Oak trees', self::json($moved)['label']);

        $toTheRoot = $this->patch('/api/assettags/' . $this->treesId, $token, ['parentId' => null]);
        self::assertSame(200, $toTheRoot->getStatusCode(), (string)$toTheRoot->getBody());
        self::assertNull(self::json($toTheRoot)['parentId']);

        $this->persistenceManager->clearState();
        $list = self::json($this->get('/api/assettags', $token));
        self::assertSame(['total' => 4], $list['meta']);
        self::assertSame(['Animals', 'Nature', 'Trees'], array_column($list['data'], 'label'));
        self::assertSame(['Oak trees'], array_column($list['data'][1]['children'], 'label'));
        self::assertSame([], $list['data'][2]['children']);
    }

    #[Test]
    public function rejectsMovingATagIntoItself(): void
    {
        $token = $this->token('editor-machine', 'assettags.update');

        self::assertSame(422, $this->patch('/api/assettags/' . $this->natureId, $token, ['parentId' => $this->natureId])->getStatusCode());
        self::assertSame(422, $this->patch('/api/assettags/' . $this->natureId, $token, ['parentId' => $this->oaksId])->getStatusCode());
        self::assertSame(422, $this->patch('/api/assettags/' . $this->natureId, $token, ['parentId' => self::UNKNOWN_ID])->getStatusCode());
        self::assertSame(404, $this->patch('/api/assettags/' . self::UNKNOWN_ID, $token, ['label' => 'x'])->getStatusCode());
    }

    #[Test]
    public function deletesATagWithItsDescendants(): void
    {
        // changing an asset flushes the content caches of its usages, which needs the content repository
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assettags.delete assettags.read');

        $response = $this->delete('/api/assettags/' . $this->treesId, $token);
        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());

        $this->persistenceManager->clearState();
        $list = self::json($this->get('/api/assettags', $token));
        self::assertSame(['total' => 2], $list['meta']);
        self::assertSame([], $list['data'][1]['children']);
        $asset = $this->objectManager->get(AssetRepository::class)->findByIdentifier($this->assetId);
        self::assertNotNull($asset);
        self::assertCount(0, $asset->getTags());

        self::assertSame(404, $this->delete('/api/assettags/' . $this->treesId, $token)->getStatusCode());
    }
}
