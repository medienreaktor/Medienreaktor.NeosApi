<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain;
use Neos\Neos\Domain\Repository\SiteRepository;

/**
 * Finds the site whose defaults apply where a request names none: its site node and its default dimension space point
 */
#[Flow\Scope('singleton')]
final readonly class SiteFinder
{
    public function __construct(
        private SiteRepository $siteRepository,
    ) {
    }

    /**
     * The default site (Neos.Neos.defaultSiteNodeName, else the first online site) if it is in the content
     * repository, else the first online site of the content repository by name, null if it has none. Without a
     * content repository, the default site. A configured default site that is missing or offline is skipped, as if
     * none were configured
     */
    public function findDefault(?ContentRepositoryId $contentRepositoryId = null): ?Site
    {
        try {
            $default = $this->siteRepository->findDefault();
        } catch (Domain\Exception) {
            // SiteRepository throws for a configured default site that is missing or offline
            $default = $contentRepositoryId === null ? $this->siteRepository->findFirstOnline() : null;
        }
        if ($contentRepositoryId === null || $default?->getConfiguration()->contentRepositoryId->equals($contentRepositoryId) === true) {
            return $default;
        }
        $sites = array_values(array_filter(
            $this->siteRepository->findOnline()->toArray(),
            static fn (Site $site) => $site->getConfiguration()->contentRepositoryId->equals($contentRepositoryId),
        ));
        usort($sites, static fn (Site $a, Site $b) => strcmp($a->getName(), $b->getName()));
        return $sites[0] ?? null;
    }
}
