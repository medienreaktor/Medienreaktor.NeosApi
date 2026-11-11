<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\ContentRepository;

use Neos\Api\Infrastructure\ContentRepository\InvalidPropertyValue;
use Neos\Api\Infrastructure\ContentRepository\PropertyValues;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class PropertyValuesTest extends FunctionalTestCase
{
    #[Test]
    public function writesDatesAsRfc3339(): void
    {
        $values = $this->objectManager->get(PropertyValues::class)->toWrite(self::nodeType(), [
            'date' => '2026-02-28T09:30:00+01:00',
            'other' => '2026-02-28T09:30:00.123+01:00',
        ])->values;

        self::assertEquals(new \DateTimeImmutable('2026-02-28T09:30:00+01:00'), $values['date']);
        self::assertEquals(new \DateTimeImmutable('2026-02-28T09:30:00.123+01:00'), $values['other']);
    }

    #[Test]
    public function rejectsImpossibleDates(): void
    {
        foreach (['2026-02-31T09:30:00+01:00', '2026-02-28T25:30:00+01:00', '2026-13-01T09:30:00.000+01:00', '28.02.2026'] as $date) {
            try {
                $this->objectManager->get(PropertyValues::class)->toWrite(self::nodeType(), ['date' => $date]);
                self::fail($date . ' was written');
            } catch (InvalidPropertyValue $exception) {
                self::assertSame('The value of the property date doesn\'t fit its type DateTime', $exception->getMessage(), $date);
            }
        }
    }

    private static function nodeType(): NodeType
    {
        return new NodeType(NodeTypeName::fromString('Neos.Api.Test:Dated'), [], ['properties' => ['date' => ['type' => 'DateTime'], 'other' => ['type' => 'DateTimeImmutable']]]);
    }
}
