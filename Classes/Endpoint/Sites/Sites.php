<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites;

use Doctrine\DBAL\Exception as DBALException;
use Neos\Api\Endpoint\Sites\RequestBody\DomainCreate;
use Neos\Api\Endpoint\Sites\RequestBody\DomainUpdate;
use Neos\Api\Endpoint\Sites\RequestBody\SiteCreate;
use Neos\Api\Endpoint\Sites\RequestBody\SiteUpdate;
use Neos\Api\Endpoint\Sites\Schema\Domain;
use Neos\Api\Endpoint\Sites\Schema\DomainId;
use Neos\Api\Endpoint\Sites\Schema\Hostname;
use Neos\Api\Endpoint\Sites\Schema\Site;
use Neos\Api\Endpoint\Sites\Schema\SiteList;
use Neos\Api\Endpoint\Sites\Schema\SiteListing;
use Neos\Api\Endpoint\Sites\Schema\SiteNodeName;
use Neos\Api\Endpoint\Sites\Response\DomainCreated;
use Neos\Api\Endpoint\Sites\Response\SiteCreated;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\Http\JsonBody;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\Conflict;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Exception\InvalidConfigurationException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\SiteService;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Neos sites and their domains
 */
final readonly class Sites
{
    /**
     * The code of the exception WorkspaceMetadataAndRoleRepository throws if it can't read the metadata
     */
    private const int WORKSPACE_METADATA_MISSING = 1727782164;

    public function __construct(
        private SiteRepository $siteRepository,
        private DomainRepository $domainRepository,
        private SiteService $siteService,
        private PackageManager $packageManager,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private PersistenceManagerInterface $persistenceManager,
        private ContentSubgraphs $contentSubgraphs,
    ) {
    }

    #[Operation(
        path: '/sites',
        method: 'GET',
        summary: 'List all sites',
        description: 'All Neos sites, including the offline ones, ordered by name. nodeAggregateId is the site node, the entry point for GET /cr/{contentRepositoryId}/nodes/{nodeAggregateId} and filterByHierarchy[nodeAggregateId] of GET /cr/{contentRepositoryId}/nodes, defaultDimensionSpacePoint the dimension space point the frontend renders the home page in: give it as dimensionSpacePoint there, the default is the one of the content repository\'s default site.',
        operationId: 'listSites',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_READ],
        ],
    )]
    public function list(): SiteListing
    {
        return SiteListing::of(new SiteList(...array_map(
            $this->site(...),
            $this->siteRepository->findAll()->toArray(),
        )));
    }

    #[Operation(
        path: '/sites',
        method: 'POST',
        summary: 'Create a site',
        description: 'Creates a Neos site with a new site node in the live workspace. GET /options/sites lists the packages and node types it can be created with.',
        operationId: 'createSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_CREATE],
        ],
    )]
    public function create(
        #[RequestBody(description: 'The site')] SiteCreate $newSite,
    ): SiteCreated|Conflict|UnprocessableContent {
        $nodeName = $newSite->siteNodeName();
        if ($this->siteRepository->findOneByNodeName($nodeName->value) !== null) {
            return Conflict::because(sprintf('There is a site with the node name %s already', $nodeName->value));
        }
        if (!$this->packageManager->isPackageAvailable($newSite->packageKey->value)) {
            return UnprocessableContent::because(sprintf('There is no package %s', $newSite->packageKey->value));
        }
        // checked up front: SiteService::createSite() adds the site before it checks the node type
        $unsavedSite = new Model\Site($nodeName->value);
        $nodeType = $this->nodeTypeManager($unsavedSite->getConfiguration()->contentRepositoryId)->getNodeType($newSite->nodeTypeName->value);
        if ($nodeType === null || $nodeType->isAbstract() || !$nodeType->isOfType(NodeTypeNameFactory::NAME_SITE)) {
            return UnprocessableContent::because(sprintf('There is no node type %s that a site node can have', $newSite->nodeTypeName->value));
        }
        $site = $this->siteService->createSite(
            $newSite->packageKey->value,
            $newSite->name->value,
            $newSite->nodeTypeName->value,
            $nodeName->value,
            !($newSite->online ?? true),
        );
        return new SiteCreated($this->site($site));
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'GET',
        summary: 'Get a site',
        operationId: 'getSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_READ],
        ],
    )]
    public function get(SiteNodeName $siteNodeName): Site|NotFound
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        return $site !== null ? $this->site($site) : self::siteNotFound($siteNodeName);
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'PATCH',
        summary: 'Change a site',
        description: 'Changes the given properties and leaves the others as they are.',
        operationId: 'updateSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function update(
        SiteNodeName $siteNodeName,
        #[RequestBody(description: 'The properties to change')] SiteUpdate $patch,
    ): Site|NotFound|UnprocessableContent {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $primaryDomain = null;
        if ($patch->primaryDomainId !== null) {
            $primaryDomain = $this->findDomain($site, $patch->primaryDomainId);
            if ($primaryDomain === null || !$primaryDomain->getActive()) {
                return UnprocessableContent::because(sprintf('The site has no active domain with the ID %s', $patch->primaryDomainId->value));
            }
        }
        $patch->applyTo($site);
        if ($primaryDomain !== null) {
            $site->setPrimaryDomain($primaryDomain);
        }
        $this->siteRepository->update($site);
        return $this->site($site);
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'DELETE',
        summary: 'Delete a site',
        description: 'Deletes the site with its domains and its site node in all workspaces, including unpublished changes.',
        operationId: 'deleteSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_DELETE],
        ],
    )]
    public function delete(SiteNodeName $siteNodeName): NotFound|null
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $this->siteService->pruneSite($site);
        return null;
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains',
        method: 'POST',
        summary: 'Add a domain to a site',
        operationId: 'createDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function createDomain(
        SiteNodeName $siteNodeName,
        #[RequestBody(description: 'The domain')] DomainCreate $newDomain,
    ): DomainCreated|NotFound|Conflict {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        if ($this->hostnameIsTaken($newDomain->hostname)) {
            return self::hostnameTaken($newDomain->hostname);
        }
        $domain = new Model\Domain();
        $newDomain->applyTo($domain);
        $domain->setSite($site);
        // the inverse side, which Site::getPrimaryDomain() falls back to
        $site->getDomains()->add($domain);
        $this->domainRepository->add($domain);
        return new DomainCreated(Domain::from($domain, $this->persistenceManager));
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains/{domainId}',
        method: 'GET',
        summary: 'Get a domain of a site',
        operationId: 'getDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_READ],
        ],
    )]
    public function getDomain(SiteNodeName $siteNodeName, DomainId $domainId): Domain|NotFound
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $domain = $this->findDomain($site, $domainId);
        return $domain !== null ? Domain::from($domain, $this->persistenceManager) : self::domainNotFound($domainId);
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains/{domainId}',
        method: 'PATCH',
        summary: 'Change a domain of a site',
        description: 'Changes the given properties and leaves the others as they are, scheme or port null removes it (the domain works with the request\'s then). Deactivating the primary domain unsets it, the site is linked with its first active domain then.',
        operationId: 'updateDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function updateDomain(
        SiteNodeName $siteNodeName,
        DomainId $domainId,
        #[RequestBody(description: 'The properties to change')] DomainUpdate $patch,
        ServerRequestInterface $request,
    ): Domain|NotFound|Conflict {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $domain = $this->findDomain($site, $domainId);
        if ($domain === null) {
            return self::domainNotFound($domainId);
        }
        if ($patch->hostname !== null && $patch->hostname->value !== $domain->getHostname() && $this->hostnameIsTaken($patch->hostname)) {
            return self::hostnameTaken($patch->hostname);
        }
        $patch->applyTo($domain);
        // DomainUpdate can't tell a scheme or port null from one left out
        if ($patch->scheme === null && JsonBody::has($request, 'scheme')) {
            $domain->setScheme(null);
        }
        if ($patch->port === null && JsonBody::has($request, 'port')) {
            $domain->setPort(null);
        }
        $this->domainRepository->update($domain);
        if (!$domain->getActive() && $site->getPrimaryDomain(false) === $domain) {
            // updateSite only takes an active primary domain
            $site->setPrimaryDomain(null);
            $this->siteRepository->update($site);
        }
        return Domain::from($domain, $this->persistenceManager);
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains/{domainId}',
        method: 'DELETE',
        summary: 'Remove a domain from a site',
        description: 'If it was the primary domain, the site is linked with its first active domain.',
        operationId: 'deleteDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function deleteDomain(SiteNodeName $siteNodeName, DomainId $domainId): NotFound|null
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $domain = $this->findDomain($site, $domainId);
        if ($domain === null) {
            return self::domainNotFound($domainId);
        }
        if ($site->getPrimaryDomain(false) === $domain) {
            $site->setPrimaryDomain(null);
            $this->siteRepository->update($site);
        }
        $site->getDomains()->removeElement($domain);
        $this->domainRepository->remove($domain);
        return null;
    }

    private function site(Model\Site $site): Site
    {
        return Site::from($site, $this->siteNode($site), $this->persistenceManager);
    }

    /**
     * The site node in the live workspace and the site's default dimension space point, null if there is none the
     * account may read, or the content repository can't be built or isn't set up (also Neos' migrations): sites and
     * their domains are managed without it, e.g. before ./flow cr:setup
     */
    private function siteNode(Model\Site $site): ?Node
    {
        try {
            return $this->contentSubgraphs->findSiteNode($site, excludeDisabled: false);
        } catch (InvalidConfigurationException | DBALException) {
            return null;
        } catch (\RuntimeException $exception) {
            // Neos' workspace metadata table is missing (its migrations didn't run): not set up either
            if ($exception->getCode() === self::WORKSPACE_METADATA_MISSING) {
                return null;
            }
            throw $exception;
        }
    }

    private function nodeTypeManager(ContentRepositoryId $contentRepositoryId): NodeTypeManager
    {
        return $this->contentRepositoryRegistry->get($contentRepositoryId)->getNodeTypeManager();
    }

    /**
     * The domain of the site with the ID, null if there is none, or it belongs to another site
     */
    private function findDomain(Model\Site $site, DomainId $domainId): ?Model\Domain
    {
        $domain = $this->domainRepository->findByIdentifier($domainId->value);
        return $domain instanceof Model\Domain && $domain->getSite() === $site ? $domain : null;
    }

    private function hostnameIsTaken(Hostname $hostname): bool
    {
        // not findOneByHost(), which matches subdomains as well
        $query = $this->domainRepository->createQuery();
        return $query->matching($query->equals('hostname', $hostname->value))->count() > 0;
    }

    private static function siteNotFound(SiteNodeName $siteNodeName): NotFound
    {
        return NotFound::because(sprintf('There is no site with the node name %s', $siteNodeName->value));
    }

    private static function domainNotFound(DomainId $domainId): NotFound
    {
        return NotFound::because(sprintf('The site has no domain with the ID %s', $domainId->value));
    }

    private static function hostnameTaken(Hostname $hostname): Conflict
    {
        return Conflict::because(sprintf('There is a domain with the host name %s already', $hostname->value));
    }
}
