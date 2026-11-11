<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\ContentRepository;

use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class NodeTypeUiTest extends FunctionalTestCase
{
    #[Test]
    public function mapsIconsAsTheNeosUi(): void
    {
        $nodeTypeUi = $this->objectManager->get(NodeTypeUi::class);

        // bare legacy names get the icon- prefix IconNameMappingService needs, full classes are kept
        foreach (['file' => 'fas fa-file', 'icon-file' => 'fas fa-file', 'fas fa-file' => 'fas fa-file', 'far fa-file' => 'far fa-file'] as $icon => $expected) {
            self::assertSame($expected, $nodeTypeUi->icon(self::nodeType(['ui' => ['icon' => $icon]])), $icon);
        }
        self::assertNull($nodeTypeUi->icon(self::nodeType([])));
        self::assertNull($nodeTypeUi->icon(self::nodeType(['ui' => ['icon' => '']])));
    }

    #[Test]
    public function labelsNodeTypesWithoutALabelByName(): void
    {
        $nodeTypeUi = $this->objectManager->get(NodeTypeUi::class);
        $labels = $this->objectManager->get(LabelTranslator::class)->forAcceptLanguage(null);

        self::assertSame('Teaser', $nodeTypeUi->label(self::nodeType(['ui' => ['label' => 'Teaser']]), $labels));
        self::assertSame('Neos.Api.Test:Thing', $nodeTypeUi->label(self::nodeType([]), $labels));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function nodeType(array $configuration): NodeType
    {
        return new NodeType(NodeTypeName::fromString('Neos.Api.Test:Thing'), [], $configuration);
    }
}
