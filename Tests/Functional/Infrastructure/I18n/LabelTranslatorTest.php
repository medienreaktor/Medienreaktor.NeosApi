<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\I18n;

use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class LabelTranslatorTest extends FunctionalTestCase
{
    #[Test]
    public function translatesToTheBestMatchingLanguageOfTheHeader(): void
    {
        $labels = $this->objectManager->get(LabelTranslator::class)->forAcceptLanguage(AcceptLanguage::fromString('xx, de-AT;q=0.9, en;q=0.8'));

        self::assertSame('de', (string)$labels->locale);
        self::assertSame('Abbrechen', $labels->label('Neos.Neos:Main:cancel'));
    }

    #[Test]
    public function translatesToTheDefaultLocaleWithoutAHeader(): void
    {
        $labels = $this->objectManager->get(LabelTranslator::class)->forAcceptLanguage(null);

        self::assertSame('Cancel', $labels->label('Neos.Neos:Main:cancel'));
    }

    #[Test]
    public function fallsBackToTheDefaultLocaleForEmptyTranslations(): void
    {
        // Neos.Demo's French source only has the English text, without a target
        $labels = $this->objectManager->get(LabelTranslator::class)->forAcceptLanguage(AcceptLanguage::fromString('fr'));

        self::assertSame('Language', $labels->label('Neos.Demo:Main:contentDimensions.language'));
    }

    #[Test]
    public function keepsTextsAndUntranslatedIds(): void
    {
        $labels = $this->objectManager->get(LabelTranslator::class)->forAcceptLanguage(AcceptLanguage::fromString('de'));

        self::assertSame('Deutsch', $labels->label('Deutsch'));
        self::assertSame('Neos.Neos:Main:doesNotExist', $labels->label('Neos.Neos:Main:doesNotExist'));
        self::assertNull($labels->label(''));
        self::assertNull($labels->label(null));
    }
}
