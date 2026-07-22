<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\NodeAddressCodec;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeNameIsAlreadyCovered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeNotFound;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Exception\SiteNodeNameIsAlreadyInUseByAnotherSite;
use Neos\Neos\Domain\Exception\SiteNodeTypeIsInvalid;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\SiteService;

/**
 * The configured sites: entry points into the content (each site carries the
 * encoded node address of its site node, the starting point for traversal)
 * AND the administration resource behind the Studio's sites administration,
 * replacing the classic Sites backend module.
 *
 * Authorization is split by operation in Policy.yaml: the read side
 * (Api.Sites.Read: index) is granted to every editor - it feeds the Studio's
 * site switcher and trees. The write side plus the creation options catalog
 * (Api.Sites.Write: create, update, delete, options, createDomain,
 * updateDomain, deleteDomain) is administrators only, matching the classic
 * backend module. The Studio mirrors that with the accountPermissions
 * "sites" flag from /me.
 */
class SitesController extends AbstractApiController
{
    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected DomainRepository $domainRepository;

    #[Flow\Inject]
    protected SiteService $siteService;

    #[Flow\Inject]
    protected PackageManager $packageManager;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * All sites (including offline ones - clients that only want reachable
     * entry points, like the site switcher, filter on state) with their
     * administration metadata and the site node address in the requested
     * workspace and dimension space point.
     */
    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $contentRepository = $this->getContentRepository();

        $workspaceName = WorkspaceName::fromString($this->getStringQueryParam('workspace') ?? WorkspaceName::WORKSPACE_NAME_LIVE);
        $dimensionsParam = $this->getStringQueryParam('dimensions');
        $dimensionSpacePoint = $dimensionsParam !== null
            ? DimensionSpacePoint::fromJsonString($dimensionsParam)
            : (array_values($contentRepository->getVariationGraph()->getRootGeneralizations())[0] ?? DimensionSpacePoint::createWithoutDimensions());

        $subgraph = $this->getContentRepository()->getContentSubgraph($workspaceName, $dimensionSpacePoint);
        $sitesRootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));

        $sites = [];
        foreach ($this->siteRepository->findAll() as $site) {
            $siteNode = $sitesRootNode === null
                ? null
                : $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesRootNode->aggregateId);

            $sites[] = $this->serializeSite($site) + [
                // The site node's aggregate id, used to scope workspace
                // publish/discard to a single site in multi-site setups. null
                // when the site node is absent from the current subgraph.
                'aggregateId' => $siteNode?->aggregateId->value,
                'nodeAddress' => $siteNode === null ? null : NodeAddressCodec::encode(NodeAddress::fromNode($siteNode)),
            ];
        }

        return $this->json([
            'workspace' => $workspaceName->value,
            'dimensionSpacePoint' => $dimensionSpacePoint->coordinates,
            'sites' => $sites,
        ]);
    }

    /**
     * The options for the site creation dialog: installed site packages and
     * the node types a site node may use (non-abstract subtypes of
     * Neos.Neos:Site).
     */
    public function optionsAction(): string
    {
        $this->requireScope('neos.read');

        $packages = [];
        foreach ($this->packageManager->getFilteredPackages('available', 'neos-site') as $packageKey => $package) {
            $packages[] = ['packageKey' => $packageKey];
        }

        $nodeTypes = [];
        foreach ($this->getContentRepository()->getNodeTypeManager()->getSubNodeTypes(NodeTypeNameFactory::forSite(), false) as $nodeType) {
            $nodeTypes[] = [
                'name' => $nodeType->name->value,
                'label' => $nodeType->getLabel(),
            ];
        }

        return $this->json(['packages' => $packages, 'nodeTypes' => $nodeTypes]);
    }

    /**
     * Create a site with a fresh site node in the live workspace. JSON body:
     * packageKey, name, nodeTypeName, nodeName? (derived from the name when
     * omitted), inactive?.
     */
    #[Flow\SkipCsrfProtection]
    public function createAction(
        string $packageKey,
        string $name,
        string $nodeTypeName,
        ?string $nodeName = null,
        bool $inactive = false
    ): string {
        $this->requireScope('neos.write');

        if (trim($name) === '') {
            $this->throwJsonStatus(400, 'invalid_name', 'The site name must not be empty.');
        }
        if (!$this->packageManager->isPackageAvailable($packageKey)) {
            $this->throwJsonStatus(400, 'invalid_package', sprintf('The package "%s" is not available.', $packageKey));
        }
        // Validate the node type BEFORE calling SiteService::createSite: the
        // service adds the Site record to the repository before it validates
        // the node type, and Flow persists that record at request end even
        // when we abort with a 400 - a failed create must not leak a site.
        $nodeType = $this->getContentRepository()->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            $this->throwJsonStatus(400, 'invalid_node_type', sprintf('The node type "%s" does not exist.', $nodeTypeName));
        }
        if (!$nodeType->isOfType(NodeTypeNameFactory::forSite())) {
            $this->throwJsonStatus(400, 'invalid_node_type', sprintf('The node type "%s" is not a document node type based on Neos.Neos:Site.', $nodeTypeName));
        }

        try {
            $site = $this->siteService->createSite($packageKey, trim($name), $nodeTypeName, $nodeName, $inactive);
        } catch (NodeTypeNotFound | SiteNodeTypeIsInvalid) {
            $this->throwJsonStatus(400, 'invalid_node_type', sprintf('The node type "%s" cannot be used for a site node.', $nodeTypeName));
        } catch (SiteNodeNameIsAlreadyInUseByAnotherSite) {
            $this->throwJsonStatus(409, 'site_exists', 'A site with this site node name already exists.');
        } catch (NodeNameIsAlreadyCovered) {
            // Thrown by the content repository AFTER the Site record was
            // added - remove it again so the failed create leaves nothing.
            $leaked = $this->siteRepository->findOneByNodeName(
                \Neos\ContentRepository\Core\SharedModel\Node\NodeName::transliterateFromString($nodeName ?: trim($name))->value
            );
            if ($leaked !== null) {
                $this->siteRepository->remove($leaked);
            }
            $this->throwJsonStatus(409, 'site_exists', 'A site with this site node name already exists.');
        }

        $this->persistenceManager->persistAll();

        return $this->json(['site' => $this->serializeSite($site)], 201);
    }

    /**
     * Partial update of a site. JSON body: name, state ("online"/"offline"),
     * primaryDomainId (a domain id, or an empty string to fall back to the
     * first active domain) - absent keys are left as-is.
     */
    #[Flow\SkipCsrfProtection]
    public function updateAction(
        string $siteNodeName,
        ?string $name = null,
        ?string $state = null,
        ?string $primaryDomainId = null
    ): string {
        $this->requireScope('neos.write');

        $site = $this->requireSite($siteNodeName);

        // Validate everything BEFORE mutating (Flow persists in-memory
        // changes even when the action aborts via throwStatus).
        if ($name !== null && trim($name) === '') {
            $this->throwJsonStatus(400, 'invalid_name', 'The site name must not be empty.');
        }
        if ($state !== null && !in_array($state, ['online', 'offline'], true)) {
            $this->throwJsonStatus(400, 'invalid_state', 'The state must be "online" or "offline".');
        }
        $primaryDomain = null;
        if ($primaryDomainId !== null && $primaryDomainId !== '') {
            $primaryDomain = $this->requireDomain($primaryDomainId);
            if ($primaryDomain->getSite() !== $site) {
                $this->throwJsonStatus(400, 'invalid_domain', 'The domain does not belong to this site.');
            }
        }

        if ($name !== null) {
            $site->setName(trim($name));
        }
        if ($state !== null) {
            $site->setState($state === 'online' ? Site::STATE_ONLINE : Site::STATE_OFFLINE);
        }
        if ($primaryDomainId !== null) {
            $site->setPrimaryDomain($primaryDomain);
        }

        $this->siteRepository->update($site);
        $this->persistenceManager->persistAll();

        return $this->json(['site' => $this->serializeSite($site)]);
    }

    /**
     * Delete a site including its content (site node in all workspaces),
     * domains and asset collection - the classic module's "prune".
     */
    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $siteNodeName): string
    {
        $this->requireScope('neos.write');

        $site = $this->requireSite($siteNodeName);
        $this->siteService->pruneSite($site);
        $this->persistenceManager->persistAll();

        return $this->json(['success' => true]);
    }

    /**
     * Add a domain to a site. JSON body: hostname, scheme? ("http"/"https"),
     * port?, active? (default true).
     */
    #[Flow\SkipCsrfProtection]
    public function createDomainAction(
        string $siteNodeName,
        string $hostname,
        ?string $scheme = null,
        ?int $port = null,
        bool $active = true
    ): string {
        $this->requireScope('neos.write');

        $site = $this->requireSite($siteNodeName);
        $hostname = strtolower(trim($hostname));
        $this->validateDomainProperties($hostname, $scheme, $port);
        // Exact-match uniqueness via the magic property finder. NOT
        // findOneByHost(): that one runs the domain MATCHING strategy, where
        // "example.com" matches the host "sub.example.com" - subdomains of an
        // existing domain would be rejected as duplicates.
        if ($this->domainRepository->findOneByHostname($hostname) !== null) {
            $this->throwJsonStatus(409, 'domain_exists', sprintf('The domain "%s" is already configured.', $hostname));
        }

        $domain = new Domain();
        $domain->setSite($site);
        $domain->setHostname($hostname);
        $domain->setScheme($scheme !== null && $scheme !== '' ? $scheme : null);
        $domain->setPort($port);
        $domain->setActive($active);
        $this->domainRepository->add($domain);
        $this->persistenceManager->persistAll();

        return $this->json(['site' => $this->serializeSite($site)], 201);
    }

    /**
     * Partial update of a domain. JSON body: hostname, scheme (empty string
     * clears it), port (0 clears it), active - absent keys are left as-is.
     */
    #[Flow\SkipCsrfProtection]
    public function updateDomainAction(
        string $siteNodeName,
        string $domainId,
        ?string $hostname = null,
        ?string $scheme = null,
        ?int $port = null,
        ?bool $active = null
    ): string {
        $this->requireScope('neos.write');

        $site = $this->requireSite($siteNodeName);
        $domain = $this->requireDomain($domainId);
        if ($domain->getSite() !== $site) {
            $this->throwJsonStatus(400, 'invalid_domain', 'The domain does not belong to this site.');
        }

        $hostname = $hostname !== null ? strtolower(trim($hostname)) : null;
        $this->validateDomainProperties($hostname ?? 'valid.example', $scheme, $port);
        if ($hostname !== null) {
            // Exact match, not findOneByHost - see createDomainAction.
            $existing = $this->domainRepository->findOneByHostname($hostname);
            if ($existing !== null && $existing !== $domain) {
                $this->throwJsonStatus(409, 'domain_exists', sprintf('The domain "%s" is already configured.', $hostname));
            }
            $domain->setHostname($hostname);
        }
        if ($scheme !== null) {
            $domain->setScheme($scheme !== '' ? $scheme : null);
        }
        if ($port !== null) {
            $domain->setPort($port > 0 ? $port : null);
        }
        if ($active !== null) {
            $domain->setActive($active);
        }

        $this->domainRepository->update($domain);
        $this->persistenceManager->persistAll();

        return $this->json(['site' => $this->serializeSite($site)]);
    }

    /**
     * Remove a domain. If it was the site's explicit primary domain, the
     * primary falls back to the first active domain.
     */
    #[Flow\SkipCsrfProtection]
    public function deleteDomainAction(string $siteNodeName, string $domainId): string
    {
        $this->requireScope('neos.write');

        $site = $this->requireSite($siteNodeName);
        $domain = $this->requireDomain($domainId);
        if ($domain->getSite() !== $site) {
            $this->throwJsonStatus(400, 'invalid_domain', 'The domain does not belong to this site.');
        }

        if ($site->getPrimaryDomain(false) === $domain) {
            $site->setPrimaryDomain(null);
            $this->siteRepository->update($site);
        }
        $this->domainRepository->remove($domain);
        $this->persistenceManager->persistAll();

        return $this->json(['site' => $this->serializeSite($site)]);
    }

    private function requireSite(string $siteNodeName): Site
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);
        if ($site === null) {
            $this->throwJsonStatus(404, 'site_not_found', sprintf('No site with the node name "%s" exists.', $siteNodeName));
        }

        return $site;
    }

    private function requireDomain(string $domainId): Domain
    {
        $domain = $this->persistenceManager->getObjectByIdentifier($domainId, Domain::class);
        if ($domain === null) {
            $this->throwJsonStatus(404, 'domain_not_found', sprintf('No domain with the id "%s" exists.', $domainId));
        }

        return $domain;
    }

    private function validateDomainProperties(string $hostname, ?string $scheme, ?int $port): void
    {
        if ($hostname === '' || str_contains($hostname, '/') || str_contains($hostname, ':') || str_contains($hostname, ' ')) {
            $this->throwJsonStatus(400, 'invalid_hostname', 'The hostname must not be empty and must not contain a scheme, port or path.');
        }
        if ($scheme !== null && $scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            $this->throwJsonStatus(400, 'invalid_scheme', 'The scheme must be "http" or "https".');
        }
        if ($port !== null && $port !== 0 && ($port < 1 || $port > 65535)) {
            $this->throwJsonStatus(400, 'invalid_port', 'The port must be between 1 and 65535.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSite(Site $site): array
    {
        $explicitPrimary = $site->getPrimaryDomain(false);
        $effectivePrimary = $site->getPrimaryDomain();

        $domains = [];
        // Queried through the repository, NOT $site->getDomains(): the
        // in-memory inverse-side collection does not see domains added or
        // removed in the same request, even after persistAll.
        foreach ($this->domainRepository->findBySite($site) as $domain) {
            /** @var Domain $domain */
            $domains[] = [
                'id' => $this->persistenceManager->getIdentifierByObject($domain),
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'port' => $domain->getPort(),
                'active' => (bool)$domain->getActive(),
                // The explicitly configured primary domain; the site falls
                // back to its first active domain when none is set.
                'isPrimary' => $domain === $explicitPrimary,
                'url' => (string)$domain,
            ];
        }

        return [
            'name' => $site->getName(),
            'nodeName' => (string)$site->getNodeName(),
            'state' => $site->isOnline() ? 'online' : 'offline',
            'siteResourcesPackageKey' => $site->getSiteResourcesPackageKey(),
            'domains' => $domains,
            'primaryDomain' => $effectivePrimary === null ? null : (string)$effectivePrimary,
        ];
    }

    private function getStringQueryParam(string $name): ?string
    {
        $value = $this->request->getHttpRequest()->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
