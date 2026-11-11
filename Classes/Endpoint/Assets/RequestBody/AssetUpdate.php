<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\RequestBody;

use Neos\Api\Endpoint\Assets\Schema\AssetCollectionIdList;
use Neos\Api\Endpoint\Assets\Schema\AssetTagIdList;
use Neos\Api\Endpoint\Assets\Schema\AssetTitle;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to an asset's metadata: what is left out or null stays as it is
 */
final readonly class AssetUpdate implements ProvidesSchema
{
    /**
     * @param AssetTagIdList|null $tagIds all tags of the asset, replacing the ones it has, [] for none
     * @param AssetCollectionIdList|null $collectionIds all collections of the asset, replacing the ones it is in, [] for none
     */
    public function __construct(
        public AssetTitle|null $title = null,
        public string|null $caption = null,
        public string|null $copyrightNotice = null,
        public AssetTagIdList|null $tagIds = null,
        public AssetCollectionIdList|null $collectionIds = null,
    ) {
    }

    /**
     * Applies the texts, the tags and collections are looked up by the endpoint
     */
    public function applyTo(Model\Asset $asset): void
    {
        if ($this->title !== null) {
            $asset->setTitle($this->title->value);
        }
        if ($this->caption !== null) {
            $asset->setCaption($this->caption);
        }
        if ($this->copyrightNotice !== null) {
            $asset->setCopyrightNotice($this->copyrightNotice);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
