<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\ContentRepository;

use Neos\Api\Infrastructure\ContentRepository\UriPathSegments;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class UriPathSegmentsTest extends FunctionalTestCase
{
    #[Test]
    public function transliteratesInTheLanguageOfTheDimensionSpacePoint(): void
    {
        $uriPathSegments = $this->objectManager->get(UriPathSegments::class);

        self::assertSame('ueber-uns-strasse', $uriPathSegments->fromText('Über uns: Straße', DimensionSpacePoint::fromArray(['language' => 'de'])));
        self::assertSame('ueber-uns', $uriPathSegments->fromText('Über uns', DimensionSpacePoint::fromArray(['language' => 'de_CH'])));
    }

    #[Test]
    public function transliteratesWithoutALanguage(): void
    {
        $uriPathSegments = $this->objectManager->get(UriPathSegments::class);

        self::assertSame('about-us', $uriPathSegments->fromText('About us', DimensionSpacePoint::createWithoutDimensions()));
        self::assertSame('about-us', $uriPathSegments->fromText('About us', DimensionSpacePoint::fromArray(['language' => 'not a locale'])));
    }

    #[Test]
    public function givesNewDocumentsTheirTitle(): void
    {
        $uriPathSegments = $this->objectManager->get(UriPathSegments::class);

        self::assertSame('ueber-uns', $uriPathSegments->forNewDocument(NodeTypeName::fromString('Neos.Demo:Document.Page'), 'Über uns', DimensionSpacePoint::fromArray(['language' => 'de'])));
    }

    #[Test]
    public function givesNewDocumentsWithoutAUsableTitleARandomOne(): void
    {
        $uriPathSegments = $this->objectManager->get(UriPathSegments::class);

        foreach ([null, '', '!!!', '   ', '🙂'] as $title) {
            self::assertMatchesRegularExpression('/^document-page-\d{3}$/', $uriPathSegments->forNewDocument(NodeTypeName::fromString('Neos.Demo:Document.Page'), $title, DimensionSpacePoint::createWithoutDimensions()), var_export($title, true));
        }
    }
}
