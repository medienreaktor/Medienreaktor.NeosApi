<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\I18n;

use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as LocalizationService;
use Neos\Flow\I18n\Translator;
use Neos\Flow\I18n\Utility;

/**
 * Translates the labels of the Neos configuration to the language of a request
 *
 * It remembers the locale it translated to, for ApiCacheHeadersMiddleware to send it as the Content-Language
 */
#[Flow\Scope('singleton')]
final class LabelTranslator
{
    private Locale|null $usedLocale = null;

    public function __construct(
        private readonly LocalizationService $localizationService,
        private readonly Translator $translator,
    ) {
    }

    /**
     * The labels in the best matching language of the header, in the default locale without one
     */
    public function forAcceptLanguage(AcceptLanguage|null $acceptLanguage): Labels
    {
        $defaultLocale = $this->localizationService->getConfiguration()->getDefaultLocale();
        $this->usedLocale = ($acceptLanguage !== null ? $this->bestMatchingLocale($acceptLanguage) : null) ?? $defaultLocale;
        return new Labels($this->translator, $this->usedLocale, $defaultLocale);
    }

    /**
     * The locale labels were translated to since forget() was called, null if none were
     */
    public function usedLocale(): Locale|null
    {
        return $this->usedLocale;
    }

    /**
     * Forgets the used locale, at the start of a request
     */
    public function forget(): void
    {
        $this->usedLocale = null;
    }

    /**
     * Like Detector::detectLocaleFromHttpHeader(), whose locale collection is empty once the service's comes from the cache
     */
    private function bestMatchingLocale(AcceptLanguage $acceptLanguage): Locale|null
    {
        // ordered by quality, false if there is no valid language
        foreach (Utility::parseAcceptLanguageHeader($acceptLanguage->value) ?: [] as $languageTag) {
            if ($languageTag === '*') {
                return null;
            }
            try {
                $locale = $this->localizationService->findBestMatchingLocale(new Locale($languageTag));
            } catch (InvalidLocaleIdentifierException) {
                continue;
            }
            if ($locale !== null) {
                return $locale;
            }
        }
        return null;
    }
}
