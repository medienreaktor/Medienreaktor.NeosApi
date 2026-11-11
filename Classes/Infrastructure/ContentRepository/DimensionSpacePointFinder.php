<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\Flow\Annotations as Flow;

/**
 * Finds the dimension space point that applies where a request names none
 */
#[Flow\Scope('singleton')]
final readonly class DimensionSpacePointFinder
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * The default dimension space point of the content repository's default site (SiteFinder::findDefault()), else
     * the content repository's only one (e.g. {} without dimensions), null if it has no site and several points
     */
    public function findDefault(ContentRepository $contentRepository): ?DimensionSpacePoint
    {
        $site = $this->siteFinder->findDefault($contentRepository->id);
        if ($site !== null) {
            return $site->getConfiguration()->defaultDimensionSpacePoint;
        }
        $points = $contentRepository->getVariationGraph()->getDimensionSpacePoints();
        return count($points) === 1 ? array_values($points->points)[0] : null;
    }
}
