<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\RequestBody;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionTitle;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to an asset collection: what is left out or null stays as it is
 */
final readonly class AssetCollectionUpdate implements ProvidesSchema
{
    public function __construct(
        public AssetCollectionTitle|null $title = null,
    ) {
    }

    public function applyTo(Model\AssetCollection $assetCollection): void
    {
        if ($this->title !== null) {
            $assetCollection->setTitle($this->title->value);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
