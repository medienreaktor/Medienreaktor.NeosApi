<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Neos\Api\Tests\Functional\Fixtures\FakeAssetSource;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\Test;

class AssetsTest extends MediaTestCase
{
    private string $reportId;

    private string $flyerId;

    private string $logoId;

    private string $pressId;

    private string $financeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');

        $finance = new Tag('Finance');
        $this->objectManager->get(TagRepository::class)->add($finance);
        $press = new AssetCollection('Press');
        $this->objectManager->get(AssetCollectionRepository::class)->add($press);

        $report = $this->addDocument('annual-report.pdf');
        $report->setTitle('Annual report');
        $report->setCaption('The figures of 2025');
        $report->addTag($finance);
        $press->addAsset($report);
        $flyer = $this->addDocument('flyer.pdf');
        $logo = $this->addImage('logo.png');
        $logo->setCopyrightNotice('Neos');
        foreach ([[$report, '2026-01-01'], [$flyer, '2026-03-01'], [$logo, '2026-02-01']] as [$asset, $lastModified]) {
            ObjectAccess::setProperty($asset, 'lastModified', new \DateTime($lastModified . 'T12:00:00+00:00'), true);
        }
        $this->persistenceManager->persistAll();

        $this->reportId = $this->persistenceManager->getIdentifierByObject($report);
        $this->flyerId = $this->persistenceManager->getIdentifierByObject($flyer);
        $this->logoId = $this->persistenceManager->getIdentifierByObject($logo);
        $this->pressId = $this->persistenceManager->getIdentifierByObject($press);
        $this->financeId = $this->persistenceManager->getIdentifierByObject($finance);
    }

    #[Test]
    public function listsTheAssetSources(): void
    {
        $response = $this->get('/api/assets', $this->token('editor-machine', 'assets.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $neos = self::json($response)['data'][0];
        self::assertSame('neos', $neos['id']);
        self::assertFalse($neos['isReadOnly']);
        self::assertTrue($neos['supportsTagging']);
        self::assertTrue($neos['supportsCollections']);
        self::assertTrue($neos['supportsSorting']);
        self::assertSame(count(self::json($response)['data']), self::json($response)['meta']['total']);
    }

    #[Test]
    public function listsTheAssetsNewestFirst(): void
    {
        $response = $this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['total' => 3], self::json($response)['meta']);
        self::assertSame([$this->flyerId, $this->logoId, $this->reportId], array_column(self::json($response)['data'], 'id'));
        $report = self::json($response)['data'][2];
        self::assertSame([
            'assetSourceId' => 'neos',
            'id' => $this->reportId,
            'localAssetId' => $this->reportId,
            'label' => 'Annual report',
            'filename' => 'annual-report.pdf',
            'mediaType' => 'application/pdf',
            'assetType' => 'document',
            'width' => null,
            'height' => null,
            'isReadOnly' => false,
            'title' => 'Annual report',
            'caption' => 'The figures of 2025',
            'copyrightNotice' => '',
            'tags' => [['id' => $this->financeId, 'label' => 'Finance']],
            'collections' => [['id' => $this->pressId, 'title' => 'Press']],
            'originalAssetId' => null,
            'crop' => null,
            'iptc' => null,
        ], array_diff_key($report, ['fileSize' => true, 'lastModified' => true, 'thumbnailUri' => true, 'previewUri' => true]));
        // stored without its time zone, read in the server's
        self::assertStringStartsWith('2026-01-01T12:00:00', $report['lastModified']);
        $logo = self::json($response)['data'][1];
        self::assertSame(['image', 1, 1], [$logo['assetType'], $logo['width'], $logo['height']]);
    }

    #[Test]
    public function filtersTheAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.read');
        $ids = fn (string $query) => array_column(self::json($this->get('/api/assets/neos?' . $query, $token))['data'], 'id');

        self::assertSame([$this->logoId], $ids('filterByAssetType=image'));
        self::assertSame([$this->flyerId, $this->reportId], $ids('filterByAssetType=document'));
        self::assertSame([$this->reportId], $ids('filterByCollection=' . $this->pressId));
        self::assertSame([$this->reportId], $ids('filterByTag=' . $this->financeId));
        self::assertSame([$this->flyerId, $this->logoId], $ids('filterByUntagged=true'));
        self::assertSame([$this->reportId], $ids('search=figures'));
        self::assertSame([$this->flyerId], $ids('search=flyer&filterByAssetType=document'));
    }

    #[Test]
    public function sortsAndPagesTheAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        $byName = self::json($this->get('/api/assets/neos?sort=name', $token));
        self::assertSame([$this->reportId, $this->flyerId, $this->logoId], array_column($byName['data'], 'id'));

        $page = self::json($this->get('/api/assets/neos?sort=lastModified&offset=1&limit=1', $token));
        self::assertSame([$this->logoId], array_column($page['data'], 'id'));
        self::assertSame(['total' => 3], $page['meta']);
        self::assertNotNull($page['links']['next']);
    }

    #[Test]
    public function rejectsInvalidListings(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        self::assertSame(400, $this->get('/api/assets/neos?search=x&filterByTag=' . $this->financeId, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByUntagged=true&filterByTag=' . $this->financeId, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?sort=size', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByTag=' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByCollection=' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByAssetType=pdf', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/assets/unknown', $token)->getStatusCode());
        // the path first
        self::assertSame(404, $this->get('/api/assets/unknown?sort=size', $token)->getStatusCode());
    }

    #[Test]
    public function getsAnAsset(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        // after an image filter, the neos source would find no documents if its filters weren't reset
        $this->get('/api/assets/neos?filterByAssetType=image', $token);
        $response = $this->get('/api/assets/neos/' . $this->reportId, $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Annual report', self::json($response)['label']);

        self::assertSame(404, $this->get('/api/assets/neos/' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/assets/unknown/' . $this->reportId, $token)->getStatusCode());
    }

    #[Test]
    public function needsTheScope(): void
    {
        self::assertSame(403, $this->get('/api/assets/neos', $this->token('editor-machine', 'assettags.read'))->getStatusCode());
    }

    #[Test]
    public function listsTheUsagesTheAccountMayRead(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.read nodes.read');

        $none = $this->get('/api/assets/neos/' . $this->reportId . '/usages', $token);
        self::assertSame(200, $none->getStatusCode(), (string)$none->getBody());
        self::assertSame(['data' => [], 'meta' => ['total' => 0, 'inaccessible' => 0]], self::json($none));

        // a usage in a workspace that doesn't exist (or the account may not read) is one it can't see
        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->insert('neos_asset_usage', [
            'contentrepositoryid' => 'default',
            'assetid' => $this->reportId,
            'workspacename' => 'someone-elses',
            'nodeaggregateid' => 'some-node',
            'origindimensionspacepoint' => '{}',
            'origindimensionspacepointhash' => '',
            'propertyname' => 'document',
        ]);
        $hidden = $this->get('/api/assets/neos/' . $this->reportId . '/usages', $token);
        self::assertSame(200, $hidden->getStatusCode(), (string)$hidden->getBody());
        self::assertSame(['data' => [], 'meta' => ['total' => 0, 'inaccessible' => 1]], self::json($hidden));

        self::assertSame(404, $this->get('/api/assets/neos/' . self::UNKNOWN_ID . '/usages', $token)->getStatusCode());
        // the usages are node content
        self::assertSame(403, $this->get('/api/assets/neos/' . $this->reportId . '/usages', $this->token('editor-machine', 'assets.read'))->getStatusCode());
    }

    #[Test]
    public function updatesTheMetadataOfAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');

        $response = $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['title' => 'Flyer', 'copyrightNotice' => 'ACME', 'tagIds' => [$this->financeId, $this->financeId], 'collectionIds' => [$this->pressId]]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $flyer = self::json($response);
        self::assertSame(['Flyer', 'Flyer', '', 'ACME'], [$flyer['label'], $flyer['title'], $flyer['caption'], $flyer['copyrightNotice']]);
        self::assertSame([['id' => $this->financeId, 'label' => 'Finance']], $flyer['tags']);
        self::assertSame([['id' => $this->pressId, 'title' => 'Press']], $flyer['collections']);

        $cleared = self::json($this->patch('/api/assets/neos/' . $this->flyerId, $token, ['tagIds' => [], 'collectionIds' => []]));
        self::assertSame([[], [], 'Flyer'], [$cleared['tags'], $cleared['collections'], $cleared['title']]);

        $this->persistenceManager->clearState();
        $read = self::json($this->get('/api/assets/neos/' . $this->flyerId, $this->token('editor-machine', 'assets.read')));
        self::assertSame([[], [], 'ACME'], [$read['tags'], $read['collections'], $read['copyrightNotice']]);
        self::assertSame(1, self::json($this->get('/api/assetcollections/' . $this->pressId, $this->token('editor-machine', 'assetcollections.read')))['assetCount']);
    }

    #[Test]
    public function rejectsInvalidUpdates(): void
    {
        $token = $this->token('editor-machine', 'assets.update');

        self::assertSame(422, $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['tagIds' => [self::UNKNOWN_ID]])->getStatusCode());
        self::assertSame(422, $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['collectionIds' => [self::UNKNOWN_ID]])->getStatusCode());
        self::assertSame(400, $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['title' => str_repeat('x', 256)])->getStatusCode());
        self::assertSame(404, $this->patch('/api/assets/neos/' . self::UNKNOWN_ID, $token, ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->patch('/api/assets/unknown/' . $this->flyerId, $token, ['title' => 'x'])->getStatusCode());
    }

    #[Test]
    public function replacesTheTagsOfAnAssetOneByOne(): void
    {
        $this->requireContentRepository();
        $nature = new Tag('Nature');
        $this->objectManager->get(TagRepository::class)->add($nature);
        $this->persistenceManager->persistAll();
        $natureId = $this->persistenceManager->getIdentifierByObject($nature);
        $token = $this->token('editor-machine', 'assets.update');

        // the report has Finance: Nature is added, Finance stays
        $both = $this->patch('/api/assets/neos/' . $this->reportId, $token, ['tagIds' => [$natureId, $this->financeId]]);
        self::assertSame(200, $both->getStatusCode(), (string)$both->getBody());
        self::assertSame(['Finance', 'Nature'], array_column(self::json($both)['tags'], 'label'));

        $nature = self::json($this->patch('/api/assets/neos/' . $this->reportId, $token, ['tagIds' => [$natureId]]));
        self::assertSame([$natureId], array_column($nature['tags'], 'id'));

        $this->persistenceManager->clearState();
        $read = self::json($this->get('/api/assets/neos/' . $this->reportId, $this->token('editor-machine', 'assets.read')));
        self::assertSame([$natureId], array_column($read['tags'], 'id'));
    }

    #[Test]
    public function tagsAndUntagsAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->flyerId . '/tags/' . $this->financeId;

        $tagged = $this->put($path, $token, []);
        self::assertSame(200, $tagged->getStatusCode(), (string)$tagged->getBody());
        self::assertSame([$this->financeId], array_column(self::json($tagged)['tags'], 'id'));
        self::assertSame(200, $this->put($path, $token, [])->getStatusCode());

        $untagged = $this->delete($path, $token);
        self::assertSame(200, $untagged->getStatusCode(), (string)$untagged->getBody());
        self::assertSame([], self::json($untagged)['tags']);
        self::assertSame(404, $this->delete($path, $token)->getStatusCode());
        self::assertSame(404, $this->put('/api/assets/neos/' . $this->flyerId . '/tags/' . self::UNKNOWN_ID, $token, [])->getStatusCode());
    }

    #[Test]
    public function addsAndRemovesAnAssetFromACollection(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->flyerId . '/collections/' . $this->pressId;

        $added = $this->put($path, $token, []);
        self::assertSame(200, $added->getStatusCode(), (string)$added->getBody());
        self::assertSame([$this->pressId], array_column(self::json($added)['collections'], 'id'));
        self::assertSame(200, $this->put($path, $token, [])->getStatusCode());

        $removed = $this->delete($path, $token);
        self::assertSame(200, $removed->getStatusCode(), (string)$removed->getBody());
        self::assertSame([], self::json($removed)['collections']);
        self::assertSame(404, $this->delete($path, $token)->getStatusCode());
        self::assertSame(404, $this->put('/api/assets/neos/' . $this->flyerId . '/collections/' . self::UNKNOWN_ID, $token, [])->getStatusCode());

        $this->persistenceManager->clearState();
        self::assertSame([$this->reportId], array_column(self::json($this->get('/api/assets/neos?filterByCollection=' . $this->pressId, $this->token('editor-machine', 'assets.read')))['data'], 'id'));
    }

    #[Test]
    public function deletesAnAssetThatIsNotInUse(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.delete');

        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->insert('neos_asset_usage', [
            'contentrepositoryid' => 'default',
            'assetid' => $this->reportId,
            'workspacename' => 'live',
            'nodeaggregateid' => 'some-node',
            'origindimensionspacepoint' => '{}',
            'origindimensionspacepointhash' => '',
            'propertyname' => 'document',
        ]);
        self::assertSame(409, $this->delete('/api/assets/neos/' . $this->reportId, $token)->getStatusCode());

        $response = $this->delete('/api/assets/neos/' . $this->flyerId, $token);
        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(404, $this->delete('/api/assets/neos/' . $this->flyerId, $token)->getStatusCode());
    }

    #[Test]
    public function createsAnImageVariant(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        $response = $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $variant = self::json($response);
        self::assertSame('../' . $variant['id'], $response->getHeaderLine('Location'));
        self::assertSame(['neos', 'image', $this->logoId, ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]], [$variant['assetSourceId'], $variant['assetType'], $variant['originalAssetId'], $variant['crop']]);

        self::assertSame(422, $this->post('/api/assets/neos/' . $variant['id'] . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(422, $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 1, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(422, $this->post('/api/assets/neos/' . $this->flyerId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(400, $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1]])->getStatusCode());

        // the same crop again is the variant there is
        $again = $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]);
        self::assertSame(200, $again->getStatusCode(), (string)$again->getBody());
        self::assertSame($variant['id'], self::json($again)['id']);

        // variants aren't listed, they belong to their image
        $readToken = $this->token('editor-machine', 'assets.read');
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $readToken))['meta']);
        $variants = $this->get('/api/assets/neos/' . $this->logoId . '/variants', $readToken);
        self::assertSame(200, $variants->getStatusCode(), (string)$variants->getBody());
        self::assertSame([$variant['id']], array_column(self::json($variants)['data'], 'id'));
        self::assertSame(['total' => 1], self::json($variants)['meta']);
        self::assertSame(['data' => [], 'meta' => ['total' => 0]], self::json($this->get('/api/assets/neos/' . $this->flyerId . '/variants', $readToken)));
        self::assertSame(404, $this->get('/api/assets/neos/' . self::UNKNOWN_ID . '/variants', $readToken)->getStatusCode());
    }

    #[Test]
    public function importsOnlyRemoteAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        self::assertSame(422, $this->post('/api/assets/neos/' . $this->flyerId . '/import', $token, [])->getStatusCode());
        self::assertSame(404, $this->post('/api/assets/unknown/' . $this->flyerId . '/import', $token, [])->getStatusCode());
    }

    #[Test]
    public function uploadsAnAsset(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        $response = $this->postMultipart('/api/assets/neos', $token, ['title' => 'Price list', 'collectionIds' => [$this->pressId], 'tagIds' => [$this->financeId]], ['file' => self::file('%PDF-1.4 prices', 'prices.pdf')]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $asset = self::json($response);
        self::assertSame('neos/' . $asset['id'], $response->getHeaderLine('Location'));
        self::assertSame(['neos', 'document', 'prices.pdf', 'application/pdf', 'Price list'], [$asset['assetSourceId'], $asset['assetType'], $asset['filename'], $asset['mediaType'], $asset['title']]);
        self::assertSame([$this->financeId], array_column($asset['tags'], 'id'));
        self::assertSame([$this->pressId], array_column($asset['collections'], 'id'));
        self::assertSame(['total' => 4], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function uploadingAFileTwiceAddsItOnceAndLeavesIt(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        // the content of the flyer of setUp(): it isn't changed, that needs assets.update
        $response = $this->postMultipart('/api/assets/neos', $token, ['title' => 'Copy', 'tagIds' => [$this->financeId], 'collectionIds' => [$this->pressId]], ['file' => self::file('%PDF-1.4 flyer.pdf', 'copy.pdf')]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($this->flyerId, self::json($response)['id']);
        self::assertSame(['', [], []], [self::json($response)['title'], self::json($response)['tags'], self::json($response)['collections']]);
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function rejectsInvalidUploads(): void
    {
        $token = $this->token('editor-machine', 'assets.create');
        $file = static fn () => ['file' => self::file('%PDF-1.4 new', 'new.pdf')];

        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['title' => 'No file'], [])->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, [], ['file' => new UploadedFile(Utils::streamFor(''), 0, UPLOAD_ERR_INI_SIZE, 'big.pdf')])->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['tagIds' => 'not-a-list'], $file())->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['collectionIds' => ['not-an-id']], $file())->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['title' => ['x']], $file())->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['title' => str_repeat('x', 256)], $file())->getStatusCode());
        self::assertSame(422, $this->postMultipart('/api/assets/neos', $token, ['tagIds' => [self::UNKNOWN_ID]], $file())->getStatusCode());
        self::assertSame(422, $this->postMultipart('/api/assets/neos', $token, ['collectionIds' => [self::UNKNOWN_ID]], $file())->getStatusCode());
        // Flow takes the media type from the name: an image that isn't one
        $notAnImage = $this->postMultipart('/api/assets/neos', $token, [], ['file' => self::file('%PDF-1.4 not an image', 'photo.png')]);
        self::assertSame(422, $notAnImage->getStatusCode(), (string)$notAnImage->getBody());
        self::assertSame(404, $this->postMultipart('/api/assets/unknown', $token, [], $file())->getStatusCode());
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function replacesTheFileOfAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->reportId . '/file';

        $response = $this->sendMultipart('PUT', $path, $token, [], ['file' => ['%PDF-1.4 the new report', 'report-2026.pdf']]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([$this->reportId, 'report-2026.pdf', 'Annual report'], [self::json($response)['id'], self::json($response)['filename'], self::json($response)['title']]);

        $kept = $this->sendMultipart('PUT', $path, $token, ['keepOriginalFilename' => 'true'], ['file' => ['%PDF-1.4 once more', 'other.pdf']]);
        self::assertSame(200, $kept->getStatusCode(), (string)$kept->getBody());
        self::assertSame('report-2026.pdf', self::json($kept)['filename']);

        self::assertSame(422, $this->sendMultipart('PUT', '/api/assets/neos/' . $this->logoId . '/file', $token, [], ['file' => ['%PDF-1.4', 'logo.pdf']])->getStatusCode());
        $notAnImage = $this->sendMultipart('PUT', '/api/assets/neos/' . $this->logoId . '/file', $token, [], ['file' => ['%PDF-1.4', 'logo.png']]);
        self::assertSame(422, $notAnImage->getStatusCode(), (string)$notAnImage->getBody());
        self::assertSame(400, $this->sendMultipart('PUT', $path, $token, ['keepOriginalFilename' => 'maybe'], ['file' => ['%PDF-1.4', 'x.pdf']])->getStatusCode());
        self::assertSame(400, $this->sendMultipart('PUT', $path, $token, [], [])->getStatusCode());
        // the path first
        self::assertSame(404, $this->sendMultipart('PUT', '/api/assets/neos/' . self::UNKNOWN_ID . '/file', $token, [], [])->getStatusCode());

        $this->persistenceManager->clearState();
        $logo = self::json($this->get('/api/assets/neos/' . $this->logoId, $this->token('editor-machine', 'assets.read')));
        self::assertSame(['logo.png', 1], [$logo['filename'], $logo['width']]);
    }

    #[Test]
    public function readsAndImportsTheAssetsOfARemoteSource(): void
    {
        $this->addFakeAssetSource();
        $readToken = $this->token('editor-machine', 'assets.read');
        $token = $this->token('editor-machine', 'assets.create');
        $remote = '/api/assets/' . FakeAssetSource::IDENTIFIER;

        $list = $this->get($remote, $readToken);
        self::assertSame(200, $list->getStatusCode(), (string)$list->getBody());
        self::assertSame(['photo', 'long-title', 'broken'], array_column(self::json($list)['data'], 'id'));
        $photo = self::json($list)['data'][0];
        self::assertSame([FakeAssetSource::IDENTIFIER, null, true, null, null], [$photo['assetSourceId'], $photo['localAssetId'], $photo['isReadOnly'], $photo['title'], $photo['tags']]);
        self::assertSame(['title' => 'A photo', 'caption' => 'Taken in 2026', 'copyrightNotice' => 'ACME'], $photo['iptc']);
        self::assertSame(400, $this->get($remote . '?filterByTag=' . $this->financeId, $readToken)->getStatusCode());
        self::assertSame(404, $this->get($remote . '/unknown', $readToken)->getStatusCode());

        // read-only: import first
        self::assertSame(422, $this->patch($remote . '/photo', $this->token('editor-machine', 'assets.update'), ['title' => 'x'])->getStatusCode());
        self::assertSame(422, $this->postMultipart($remote, $token, [], ['file' => self::file('%PDF-1.4 new', 'new.pdf')])->getStatusCode());

        $imported = $this->post($remote . '/photo/import', $token, []);
        self::assertSame(201, $imported->getStatusCode(), (string)$imported->getBody());
        $asset = self::json($imported);
        self::assertSame('../../neos/' . $asset['id'], $imported->getHeaderLine('Location'));
        self::assertSame(['neos', 'image', 'A photo', 'Taken in 2026', 'ACME'], [$asset['assetSourceId'], $asset['assetType'], $asset['title'], $asset['caption'], $asset['copyrightNotice']]);
        self::assertSame($asset['id'], self::json($this->get($remote . '/photo', $readToken))['localAssetId']);

        $again = $this->post($remote . '/photo/import', $token, []);
        self::assertSame(200, $again->getStatusCode(), (string)$again->getBody());
        self::assertSame($asset['id'], self::json($again)['id']);

        // an IPTC title longer than an asset's is cut
        $longTitle = $this->post($remote . '/long-title/import', $token, []);
        self::assertSame(201, $longTitle->getStatusCode(), (string)$longTitle->getBody());
        self::assertSame(255, mb_strlen(self::json($longTitle)['title']));

        $broken = $this->post($remote . '/broken/import', $token, []);
        self::assertSame(502, $broken->getStatusCode(), (string)$broken->getBody());
    }

    private static function file(string $content, string $filename): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($content), strlen($content), UPLOAD_ERR_OK, $filename);
    }
}
