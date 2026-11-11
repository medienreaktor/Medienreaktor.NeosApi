<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Options;

use Neos\Api\Endpoint\Sites\Schema\PackageKeyList;
use Neos\Api\Endpoint\Sites\Schema\SiteCreationOptions;
use Neos\Api\Endpoint\Sites\Schema\SiteNodeType;
use Neos\Api\Endpoint\Sites\Schema\SiteNodeTypeList;
use Neos\Api\Endpoint\Users\Schema\AccountRole;
use Neos\Api\Endpoint\Users\Schema\AccountRoleList;
use Neos\Api\Endpoint\Users\Schema\UserOptions;
use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\Api\Shared\Schema\PackageKey;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;

/**
 * What resources can be created with: the choices a form needs, one path per resource
 *
 * Each one needs the scope of the create it is for (sites.create, users.create), not one of its own. The schemas
 * belong to the resources' endpoints
 */
final readonly class Options
{
    private ContentRepositoryId $contentRepositoryForNewSites;

    public function __construct(
        private PackageManager $packageManager,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private LabelTranslator $labelTranslator,
        private AccountPrivileges $accountPrivileges,
        #[Flow\InjectConfiguration(path: 'sitePresets.default.contentRepository', package: 'Neos.Neos')]
        string $defaultContentRepository,
    ) {
        // the one the Neos sites module offers the node types of, sites without settings of their own use it as well
        $this->contentRepositoryForNewSites = ContentRepositoryId::fromString($defaultContentRepository);
    }

    #[Operation(
        path: '/options/sites',
        method: 'GET',
        summary: 'Get the site creation options',
        description: 'The site packages and site node types a site can be created with (POST /sites). The labels are translated to the Accept-Language.',
        operationId: 'getSiteCreationOptions',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_CREATE],
        ],
    )]
    public function sites(
        #[Parameter(in: 'header', name: 'Accept-Language')] AcceptLanguage|null $acceptLanguage = null,
    ): SiteCreationOptions {
        $nodeTypeManager = $this->contentRepositoryRegistry->get($this->contentRepositoryForNewSites)->getNodeTypeManager();
        $labels = $this->labelTranslator->forAcceptLanguage($acceptLanguage);
        return new SiteCreationOptions(
            new PackageKeyList(...array_map(
                PackageKey::fromString(...),
                array_keys($this->packageManager->getFilteredPackages('available', 'neos-site')),
            )),
            new SiteNodeTypeList(...array_map(
                static fn (NodeType $nodeType) => SiteNodeType::from($nodeType, $labels),
                array_values($nodeTypeManager->getSubNodeTypes(NodeTypeNameFactory::forSite(), false)),
            )),
        );
    }

    #[Operation(
        path: '/options/users',
        method: 'GET',
        summary: 'Get the user options',
        description: 'The roles you can give a user when you create or change it (POST /users, PATCH /users/{userId}), ordered by label: for administrators all roles that aren\'t abstract, for others the ones they have themselves, as in Neos\' user module.',
        operationId: 'getUserOptions',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::USERS_CREATE],
        ],
    )]
    public function users(#[AuthContext] ApiCaller $caller): UserOptions
    {
        $roles = array_values($this->accountPrivileges->assignableRoles($caller->account));
        usort($roles, static fn (Role $a, Role $b) => strcasecmp($a->getLabel(), $b->getLabel()) ?: strcmp($a->getIdentifier(), $b->getIdentifier()));
        return new UserOptions(new AccountRoleList(...array_map(AccountRole::from(...), $roles)));
    }
}
