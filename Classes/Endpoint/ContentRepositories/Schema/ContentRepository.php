<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePointList;
use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core;
use Neos\ContentRepository\Core\Dimension;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A content repository with its content dimensions
 */
final readonly class ContentRepository implements ProvidesSchema
{
    /**
     * @param ContentDimensionList $dimensions ordered by priority, empty if the content repository has none
     * @param DimensionSpacePointList $dimensionSpacePoints all points of its dimensions\' values that may be combined (its variation graph), [{}] without dimensions
     */
    public function __construct(
        public ContentRepositoryId $id,
        public ContentDimensionList $dimensions,
        public DimensionSpacePointList $dimensionSpacePoints,
    ) {
    }

    public static function from(Core\ContentRepository $contentRepository, Labels $labels, NodeTypeUi $nodeTypeUi): self
    {
        return new self(
            ContentRepositoryId::fromString($contentRepository->id->value),
            new ContentDimensionList(...array_map(
                static fn (Dimension\ContentDimension $dimension) => ContentDimension::from($dimension, $labels, $nodeTypeUi),
                array_values($contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority()),
            )),
            DimensionSpacePointList::from($contentRepository->getVariationGraph()->getDimensionSpacePoints()),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
