<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\RequestBody;

use Neos\Api\Endpoint\Assets\Schema\CropRectangle;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An image variant to create: a crop of the image
 */
final readonly class AssetVariantCreate implements ProvidesSchema
{
    public function __construct(
        public CropRectangle $crop,
    ) {
    }

    public function applyTo(Model\ImageVariant $variant): void
    {
        $variant->addAdjustment($this->crop->toAdjustment());
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
