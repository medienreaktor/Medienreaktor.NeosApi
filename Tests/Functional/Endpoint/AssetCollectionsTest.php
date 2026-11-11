<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class AssetCollectionsTest extends MediaTestCase
{
    private string $pressId;

    private string $assetId;

    private string $eventsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');

        $press = new AssetCollection('Press');
        $collectionRepository = $this->objectManager->get(AssetCollectionRepository::class);
        $collectionRepository->add($press);
        $collectionRepository->add(new AssetCollection('Archive'));

        $events = new Tag('Events');
        $this->objectManager->get(TagRepository::class)->add($events);
        $press->addTag($events);

        $asset = $this->addDocument('release.pdf');
        $press->addAsset($asset);
        // variants aren't counted, as in Neos' lists
        $image = $this->addImage('photo.png');
        $press->addAsset($image);
        $variant = new ImageVariant($image);
        $this->objectManager->get(AssetRepository::class)->add($variant);
        $this->persistenceManager->persistAll();
        $this->eventsId = $this->persistenceManager->getIdentifierByObject($events);

        $this->pressId = $this->persistenceManager->getIdentifierByObject($press);
        $this->assetId = $this->persistenceManager->getIdentifierByObject($asset);
    }

    #[Test]
    public function listsTheCollections(): void
    {
        $response = $this->get('/api/assetcollections', $this->token('editor-machine', 'assetcollections.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['total' => 2], self::json($response)['meta']);
        self::assertSame(['Archive', 'Press'], array_column(self::json($response)['data'], 'title'));
        self::assertSame(['id' => $this->pressId, 'title' => 'Press', 'assetCount' => 2, 'tags' => [['id' => $this->eventsId, 'label' => 'Events']]], self::json($response)['data'][1]);
        self::assertSame(0, self::json($response)['data'][0]['assetCount']);
    }

    #[Test]
    public function getsACollection(): void
    {
        $token = $this->token('editor-machine', 'assetcollections.read');

        $response = $this->get('/api/assetcollections/' . $this->pressId, $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Press', self::json($response)['title']);
        self::assertSame(404, $this->get('/api/assetcollections/' . self::UNKNOWN_ID, $token)->getStatusCode());
    }

    #[Test]
    public function needsTheScope(): void
    {
        self::assertSame(403, $this->get('/api/assetcollections', $this->token('editor-machine', 'assettags.read'))->getStatusCode());
    }

    #[Test]
    public function createsACollection(): void
    {
        $token = $this->token('editor-machine', 'assetcollections.create');

        $response = $this->post('/api/assetcollections', $token, ['title' => 'Events']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Events', self::json($response)['title']);
        self::assertSame([0, []], [self::json($response)['assetCount'], self::json($response)['tags']]);
        self::assertSame('assetcollections/' . self::json($response)['id'], $response->getHeaderLine('Location'));

        self::assertSame(400, $this->post('/api/assetcollections', $token, ['title' => ''])->getStatusCode());
    }

    #[Test]
    public function renamesACollection(): void
    {
        $token = $this->token('editor-machine', 'assetcollections.update');

        $response = $this->patch('/api/assetcollections/' . $this->pressId, $token, ['title' => 'Press photos']);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['id' => $this->pressId, 'title' => 'Press photos', 'assetCount' => 2], array_diff_key(self::json($response), ['tags' => true]));

        $unchanged = $this->patch('/api/assetcollections/' . $this->pressId, $token, []);
        self::assertSame('Press photos', self::json($unchanged)['title']);
        self::assertSame(404, $this->patch('/api/assetcollections/' . self::UNKNOWN_ID, $token, ['title' => 'x'])->getStatusCode());
    }

    #[Test]
    public function deletesACollectionButNotItsAssets(): void
    {
        $token = $this->token('editor-machine', 'assetcollections.delete assetcollections.read');

        $response = $this->delete('/api/assetcollections/' . $this->pressId, $token);
        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());

        $this->persistenceManager->clearState();
        self::assertSame(['Archive'], array_column(self::json($this->get('/api/assetcollections', $token))['data'], 'title'));
        self::assertNotNull($this->objectManager->get(AssetRepository::class)->findByIdentifier($this->assetId));
        self::assertSame(404, $this->delete('/api/assetcollections/' . $this->pressId, $token)->getStatusCode());
    }
}
