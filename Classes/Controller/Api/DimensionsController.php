<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;

/**
 * Content dimension configuration and the set of allowed dimension space
 * points - clients need this to construct valid node addresses and to offer
 * a dimension switcher.
 */
class DimensionsController extends AbstractApiController
{
    #[Flow\Inject]
    protected TranslationHelper $translationHelper;

    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $contentRepository = $this->getContentRepository();

        // Dimensions ordered by priority, values in configuration order with
        // specializations directly after their generalization (depth-first),
        // so clients can render the hierarchy from specializationDepth alone.
        $dimensions = [];
        foreach ($contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority() as $dimension) {
            $values = [];
            foreach ($dimension->values->getIterator() as $value) {
                $values[] = [
                    'value' => $value->value,
                    'label' => $this->translateLabel($value->getConfigurationValue('label')) ?? $value->value,
                    'specializationDepth' => $value->specializationDepth->value,
                ];
            }
            $dimensions[] = [
                'id' => $dimension->id->value,
                'label' => $this->translateLabel($dimension->getConfigurationValue('label')) ?? $dimension->id->value,
                'icon' => $dimension->getConfigurationValue('icon'),
                'values' => $values,
            ];
        }

        $allowedDimensionSpacePoints = array_map(
            static fn (DimensionSpacePoint $point) => $point->coordinates,
            iterator_to_array($contentRepository->getVariationGraph()->getDimensionSpacePoints(), false)
        );

        return $this->json([
            'dimensions' => $dimensions,
            'allowedDimensionSpacePoints' => array_values($allowedDimensionSpacePoints),
        ]);
    }

    /**
     * Labels may be plain strings or XLIFF ids ("Vendor.Package:Source:key");
     * the helper resolves the latter and passes plain strings through.
     */
    private function translateLabel(mixed $label): ?string
    {
        if (!is_string($label) || $label === '') {
            return null;
        }

        return $this->translationHelper->translate($label) ?? $label;
    }
}
