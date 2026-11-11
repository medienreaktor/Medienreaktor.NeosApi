<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\ContentRepository;

use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\Test;

class SiteFinderTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    private mixed $defaultSiteNodeName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultSiteNodeName = ObjectAccess::getProperty($this->objectManager->get(SiteRepository::class), 'defaultSiteNodeName', true);
    }

    protected function tearDown(): void
    {
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        ObjectAccess::setProperty($siteRepository, 'defaultSiteNodeName', $this->defaultSiteNodeName, true);
        parent::tearDown();
    }

    #[Test]
    public function skipsAConfiguredDefaultSiteThatIsMissingOrOffline(): void
    {
        $this->addSite('offline', 'Offline', Site::STATE_OFFLINE);
        $online = $this->addSite('online', 'Online', Site::STATE_ONLINE);
        $this->persistenceManager->persistAll();
        $siteFinder = $this->objectManager->get(SiteFinder::class);
        $siteRepository = $this->objectManager->get(SiteRepository::class);

        foreach (['missing', 'offline'] as $defaultSiteNodeName) {
            // SiteRepository::findDefault() throws for these
            ObjectAccess::setProperty($siteRepository, 'defaultSiteNodeName', $defaultSiteNodeName, true);

            self::assertSame($online, $siteFinder->findDefault(), $defaultSiteNodeName);
            self::assertSame($online, $siteFinder->findDefault(ContentRepositoryId::fromString('default')), $defaultSiteNodeName);
        }
    }

    private function addSite(string $nodeName, string $name, int $state): Site
    {
        $site = new Site($nodeName);
        $site->setName($name);
        $site->setState($state);
        $site->setSiteResourcesPackageKey('Neos.Demo');
        $this->objectManager->get(SiteRepository::class)->add($site);
        return $site;
    }
}
