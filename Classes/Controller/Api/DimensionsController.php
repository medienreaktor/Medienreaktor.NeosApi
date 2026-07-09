<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;

/**
 * Content dimension configuration and the set of allowed dimension space
 * points - clients need this to construct valid node addresses.
 */
class DimensionsController extends AbstractApiController
{
    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $contentRepository = $this->getContentRepository();

        $dimensions = [];
        foreach ($contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority() as $dimension) {
            $values = [];
            foreach ($dimension->values->getIterator() as $value) {
                $values[] = $value->value;
            }
            $dimensions[$dimension->id->value] = [
                'values' => $values,
                'configuration' => $dimension->configuration,
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
}
