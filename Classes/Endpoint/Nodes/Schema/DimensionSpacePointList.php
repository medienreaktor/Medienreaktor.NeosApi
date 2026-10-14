<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\ContentRepository\Core\DimensionSpace;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * Dimension space points as JSON, e.g. the ones a node covers or all of a content repository
 *
 * The schema is written out to describe the list, the items are DimensionSpacePoint's
 *
 * @implements \IteratorAggregate<DimensionSpacePoint>
 */
final readonly class DimensionSpacePointList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<DimensionSpacePoint>
     */
    public array $dimensionSpacePoints;

    public function __construct(DimensionSpacePoint ...$dimensionSpacePoints)
    {
        $this->dimensionSpacePoints = array_values($dimensionSpacePoints);
    }

    /**
     * @param iterable<DimensionSpace\AbstractDimensionSpacePoint> $dimensionSpacePoints
     */
    public static function from(iterable $dimensionSpacePoints): self
    {
        $points = [];
        foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
            $points[] = DimensionSpacePoint::from($dimensionSpacePoint);
        }
        return new self(...$points);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ArraySchema::create(
            description: 'Dimension space points as JSON, in the order of the content repository\'s variation graph (the order of the content dimensions\' values)',
            items: DimensionSpacePoint::schema(),
        );
    }

    public function getIterator(): \Traversable
    {
        yield from $this->dimensionSpacePoints;
    }
}
