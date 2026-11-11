<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The IPTC metadata of a remote asset that importing it copies to the asset's title, caption and copyright notice
 */
final readonly class AssetIptc implements ProvidesSchema
{
    public function __construct(
        public string|null $title,
        public string|null $caption,
        public string|null $copyrightNotice,
    ) {
    }

    public static function from(SupportsIptcMetadataInterface $proxy): self
    {
        $property = static fn (string $name) => $proxy->hasIptcProperty($name) ? $proxy->getIptcProperty($name) : null;
        return new self($property('Title'), $property('CaptionAbstract'), $property('CopyrightNotice'));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
