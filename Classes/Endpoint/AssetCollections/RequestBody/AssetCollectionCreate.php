<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\RequestBody;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionTitle;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An asset collection to create
 *
 * No applyTo(): the title is all there is, and it's the constructor's
 */
final readonly class AssetCollectionCreate implements ProvidesSchema
{
    public function __construct(
        public AssetCollectionTitle $title,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
