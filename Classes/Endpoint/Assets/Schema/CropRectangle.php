<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Media\Domain\Model\Adjustment\CropImageAdjustment;
use Neos\Media\Domain\Model\ImageVariant;

/**
 * The part of an image a variant shows, in pixels of the original image
 */
final readonly class CropRectangle implements ProvidesSchema
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * The crop of the variant, null if it has none
     */
    public static function of(ImageVariant $variant): ?self
    {
        foreach ($variant->getAdjustments() as $adjustment) {
            if ($adjustment instanceof CropImageAdjustment) {
                return new self((int)$adjustment->getX(), (int)$adjustment->getY(), (int)$adjustment->getWidth(), (int)$adjustment->getHeight());
            }
        }
        return null;
    }

    public function toAdjustment(): CropImageAdjustment
    {
        return new CropImageAdjustment(['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height]);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The part of an image a variant shows, in pixels of the original image',
            examples: [['x' => 0, 'y' => 120, 'width' => 1600, 'height' => 900]],
            properties: ObjectProperties::create(
                x: IntegerSchema::create(description: 'The left edge', minimum: 0),
                y: IntegerSchema::create(description: 'The top edge', minimum: 0),
                width: IntegerSchema::create(minimum: 1),
                height: IntegerSchema::create(minimum: 1),
            ),
            additionalProperties: false,
            required: ['x', 'y', 'width', 'height'],
        );
    }
}
