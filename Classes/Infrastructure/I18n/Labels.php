<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\I18n;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;

/**
 * Translates labels to one locale, see LabelTranslator
 */
#[Flow\Proxy(false)]
final readonly class Labels
{
    public function __construct(
        private Translator $translator,
        public Locale $locale,
        private Locale $defaultLocale,
    ) {
    }

    /**
     * A label of the configuration, which is a text or the ID of a translation ("Vendor.Package:Source:id")
     *
     * @return string|null in the default locale if the locale has no translation, as it is if neither has one, null if there is no label
     */
    public function label(mixed $label): string|null
    {
        if (!is_string($label) || $label === '') {
            return null;
        }
        if (preg_match(TranslationHelper::I18N_LABEL_ID_PATTERN, $label) !== 1) {
            return $label;
        }
        [$packageKey, $sourceName, $id] = explode(':', $label, 3);
        $sourceName = str_replace('.', '/', $sourceName);
        // an empty target counts as missing, Flow only falls back for missing ones
        foreach ([$this->locale, $this->defaultLocale] as $locale) {
            $translation = $this->translator->translateById($id, [], null, $locale, $sourceName, $packageKey);
            if ($translation !== null && $translation !== '') {
                return $translation;
            }
        }
        return $label;
    }
}
