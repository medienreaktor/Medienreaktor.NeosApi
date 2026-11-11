<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;

/**
 * A read-only remote asset source with fixed assets, as a DAM would be: they are imported into neos when used
 *
 * Not configured in Neos.Media.assetSources, the tests that need it add it to the AssetSourceService (see AssetsTest)
 */
final class FakeAssetSource implements AssetSourceInterface
{
    public const string IDENTIFIER = 'neos-api-test-remote';

    /**
     * The assets by ID: filename, content (null: the source fails to deliver it) and IPTC properties
     */
    public const array ASSETS = [
        'photo' => ['photo.png', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', ['Title' => 'A photo', 'CaptionAbstract' => 'Taken in 2026', 'CopyrightNotice' => 'ACME']],
        'long-title' => ['notes.pdf', 'JVBERi0xLjQgbm90ZXM=', ['Title' => 'long']],
        'broken' => ['broken.pdf', null, []],
    ];

    private function __construct(
        private readonly string $identifier,
    ) {
    }

    /**
     * @param array<mixed> $assetSourceOptions
     */
    public static function createFromConfiguration(string $assetSourceIdentifier, array $assetSourceOptions): AssetSourceInterface
    {
        return new self($assetSourceIdentifier);
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return 'Test DAM';
    }

    public function getIconUri(): string
    {
        return '';
    }

    public function getDescription(): string
    {
        return 'Assets for the tests';
    }

    public function getAssetProxyRepository(): AssetProxyRepositoryInterface
    {
        return new FakeAssetProxyRepository($this);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
