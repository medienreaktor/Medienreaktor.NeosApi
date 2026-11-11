<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\RequestBody;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagLabel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to an asset tag: what is left out stays as it is
 *
 * parentId null moves the tag to the root, which schematic can't tell from a parentId left out: the endpoint reads
 * that from the body itself
 */
final readonly class AssetTagUpdate implements ProvidesSchema
{
    /**
     * @param AssetTagId|null $parentId the tag to move it into, null to move it to the root
     */
    public function __construct(
        public AssetTagLabel|null $label = null,
        public AssetTagId|null $parentId = null,
    ) {
    }

    /**
     * Applies the label, the parent is up to the endpoint (see above)
     */
    public function applyTo(Model\Tag $tag): void
    {
        if ($this->label !== null) {
            $tag->setLabel($this->label->value);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
