<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Unit\Endpoint\Nodes\Schema;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DimensionSpacePointTest extends TestCase
{
    #[Test]
    public function thePointWithoutDimensionsIsTheEmptyObject(): void
    {
        $point = DimensionSpacePoint::from(DimensionSpace\DimensionSpacePoint::createWithoutDimensions());

        self::assertSame('{}', $point->value);
        self::assertTrue($point->toDimensionSpacePoint()->equals(DimensionSpace\DimensionSpacePoint::createWithoutDimensions()));
    }

    #[Test]
    public function aPointWithDimensionsKeepsItsCoordinates(): void
    {
        $point = DimensionSpacePoint::from(DimensionSpace\DimensionSpacePoint::fromArray(['language' => 'de']));

        self::assertSame('{"language":"de"}', $point->value);
    }
}
