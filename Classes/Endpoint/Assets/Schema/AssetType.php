<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;

/**
 * The kind of an asset, the asset types of Neos lowercased
 */
enum AssetType: string implements ProvidesSchema
{
    case IMAGE = 'image';
    case DOCUMENT = 'document';
    case VIDEO = 'video';
    case AUDIO = 'audio';

    /**
     * The type of the asset in Neos, else (a remote asset) of its media type
     */
    public static function of(Model\AssetInterface|null $asset, string $mediaType): self
    {
        return match (true) {
            $asset instanceof Model\ImageInterface => self::IMAGE,
            $asset instanceof Model\Document => self::DOCUMENT,
            $asset instanceof Model\Video => self::VIDEO,
            $asset instanceof Model\Audio => self::AUDIO,
            str_starts_with($mediaType, 'image/') => self::IMAGE,
            str_starts_with($mediaType, 'video/') => self::VIDEO,
            str_starts_with($mediaType, 'audio/') => self::AUDIO,
            default => self::DOCUMENT,
        };
    }

    public function toAssetTypeFilter(): AssetTypeFilter
    {
        return new AssetTypeFilter(ucfirst($this->value));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'image, document, video or audio',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
