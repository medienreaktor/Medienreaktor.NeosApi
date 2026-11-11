<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A tag of an asset, the whole tag is getAssetTag's
 */
final readonly class AssetTagReference implements ProvidesSchema
{
    public function __construct(
        public AssetTagId $id,
        public AssetTagLabel $label,
    ) {
    }

    public static function from(Model\Tag $tag, PersistenceManagerInterface $persistenceManager): self
    {
        return new self(
            AssetTagId::fromString($persistenceManager->getIdentifierByObject($tag)),
            AssetTagLabel::fromString($tag->getLabel()),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
