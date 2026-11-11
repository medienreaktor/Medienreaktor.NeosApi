<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Api\Tests\Functional\Fixtures\FakeAssetSource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Authentication\Token\BearerToken;
use Neos\Flow\Security\Authentication\TokenAndProviderFactoryInterface;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\AssetSourceService;
use Psr\Http\Message\ResponseInterface;

/**
 * Media tests: assets to work with. Changing or deleting an asset makes Neos look up its usages and flush their content
 * caches, which needs the content repository (requireContentRepository())
 */
abstract class MediaTestCase extends ContentRepositoryTestCase
{
    protected const string UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    protected function tearDown(): void
    {
        $this->removeFakeAssetSource();
        parent::tearDown();
    }

    /**
     * Adds FakeAssetSource, a remote asset source, to the asset sources of Neos.Media (removed after the test)
     */
    protected function addFakeAssetSource(): void
    {
        $sources = $this->objectManager->get(AssetSourceService::class)->getAssetSources();
        $sources[FakeAssetSource::IDENTIFIER] = FakeAssetSource::createFromConfiguration(FakeAssetSource::IDENTIFIER, []);
        $this->setAssetSources($sources);
    }

    private function removeFakeAssetSource(): void
    {
        $sources = $this->objectManager->get(AssetSourceService::class)->getAssetSources();
        if (isset($sources[FakeAssetSource::IDENTIFIER])) {
            unset($sources[FakeAssetSource::IDENTIFIER]);
            $this->setAssetSources($sources);
        }
    }

    /**
     * @param array<string, mixed> $sources
     */
    private function setAssetSources(array $sources): void
    {
        $service = $this->objectManager->get(AssetSourceService::class);
        // the property is private to the class Flow's proxy extends
        $parent = get_parent_class($service);
        $scope = $parent !== false && str_ends_with($parent, '_Original') ? $parent : $service::class;
        \Closure::bind(function () use ($sources): void {
            $this->assetSources = $sources;
        }, $service, $scope)();
    }

    /**
     * A multipart/form-data request with a real body, as a client sends it: PHP doesn't parse it for other methods
     * than POST, MultipartForm does
     *
     * @param array<string, string|list<string>> $fields lists as name[]
     * @param array<string, array{string, string}> $files content and filename by field name
     */
    protected function sendMultipart(string $method, string $path, ?string $bearer, array $fields, array $files): ResponseInterface
    {
        // as EndpointTestCase does for every request: the browser runs them all in one process
        $this->inject($this->objectManager->get(AuthenticationManagerInterface::class), 'isAuthenticated', null);
        foreach ($this->objectManager->get(TokenAndProviderFactoryInterface::class)->getTokens() as $token) {
            if ($token instanceof BearerToken) {
                $this->inject($token, 'credentials', ['bearer' => '']);
            }
        }
        $elements = [];
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $elements[] = ['name' => is_array($value) ? $name . '[]' : $name, 'contents' => $item];
            }
        }
        foreach ($files as $name => [$content, $filename]) {
            $elements[] = ['name' => $name, 'contents' => $content, 'filename' => $filename];
        }
        $headers = ['Content-Type' => 'multipart/form-data; boundary=neos-api-test'];
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        return $this->browser->sendRequest(new ServerRequest($method, 'http://localhost' . $path, $headers, new MultipartStream($elements, 'neos-api-test')));
    }

    /**
     * A PDF document asset, added to the repository, not persisted yet
     */
    protected function addDocument(string $filename): Document
    {
        $resource = $this->objectManager->get(ResourceManager::class)->importResourceFromContent('%PDF-1.4 ' . $filename, $filename);
        $document = new Document($resource);
        $this->objectManager->get(AssetRepository::class)->add($document);
        return $document;
    }

    /**
     * A 1×1 PNG image asset, added to the repository, not persisted yet
     */
    protected function addImage(string $filename): Image
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
        $resource = $this->objectManager->get(ResourceManager::class)->importResourceFromContent($png, $filename);
        $image = new Image($resource);
        $this->objectManager->get(AssetRepository::class)->add($image);
        return $image;
    }
}
