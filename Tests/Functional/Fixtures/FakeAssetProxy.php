<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ResourceManagement\Exception as ResourceException;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Utility\MediaTypes;
use Psr\Http\Message\UriInterface;

/**
 * An asset of FakeAssetSource. The title of long-title is longer than an asset's can be
 */
final class FakeAssetProxy implements AssetProxyInterface, HasRemoteOriginalInterface, SupportsIptcMetadataInterface
{
    public function __construct(
        private readonly FakeAssetSource $assetSource,
        private readonly string $identifier,
    ) {
    }

    public function getAssetSource(): AssetSourceInterface
    {
        return $this->assetSource;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->getFilename();
    }

    public function getFilename(): string
    {
        return FakeAssetSource::ASSETS[$this->identifier][0];
    }

    public function getLastModified(): \DateTimeInterface
    {
        return new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }

    public function getFileSize(): int
    {
        return strlen((string)base64_decode(FakeAssetSource::ASSETS[$this->identifier][1] ?? '', true));
    }

    public function getMediaType(): string
    {
        return MediaTypes::getMediaTypeFromFilename($this->getFilename());
    }

    public function getWidthInPixels(): ?int
    {
        return null;
    }

    public function getHeightInPixels(): ?int
    {
        return null;
    }

    public function getThumbnailUri(): ?UriInterface
    {
        return null;
    }

    public function getPreviewUri(): ?UriInterface
    {
        return null;
    }

    /**
     * @return resource
     * @throws ResourceException for broken, as a remote source that can't be reached
     */
    public function getImportStream()
    {
        $content = FakeAssetSource::ASSETS[$this->identifier][1];
        if ($content === null) {
            throw new ResourceException(sprintf('The asset %s can\'t be fetched', $this->identifier), 1791900104);
        }
        $stream = fopen('php://memory', 'r+b');
        assert(is_resource($stream));
        fwrite($stream, (string)base64_decode($content, true));
        rewind($stream);
        return $stream;
    }

    public function getLocalAssetIdentifier(): ?string
    {
        $importedAsset = Bootstrap::$staticObjectManager->get(ImportedAssetRepository::class)->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return $importedAsset instanceof ImportedAsset ? $importedAsset->getLocalAssetIdentifier() : null;
    }

    public function isImported(): bool
    {
        return $this->getLocalAssetIdentifier() !== null;
    }

    public function hasIptcProperty(string $propertyName): bool
    {
        return isset($this->getIptcProperties()[$propertyName]);
    }

    public function getIptcProperty(string $propertyName): string
    {
        return $this->getIptcProperties()[$propertyName] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public function getIptcProperties(): array
    {
        $properties = FakeAssetSource::ASSETS[$this->identifier][2];
        if ($this->identifier === 'long-title') {
            $properties['Title'] = str_repeat('Long title ', 30);
        }
        return $properties;
    }
}
