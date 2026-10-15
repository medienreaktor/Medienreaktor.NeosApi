<?php
declare(strict_types=1);

namespace Neos\Api\Security;

/**
 * The OAuth scopes of the API, to be used in the operations' `security` requirements
 *
 * Each one is the matcher of an ApiPrivilege target in Policy.yaml, which the account must hold as well, see
 * PrivilegeScopes
 */
final class ApiScopes
{
    public const string ME_READ = 'me.read';
    public const string ME_UPDATE = 'me.update';
    public const string ME_PASSWORD = 'me.password';
    public const string USERS_READ = 'users.read';
    public const string USERS_UPDATE = 'users.update';
    public const string USERS_CREATE = 'users.create';
    public const string USERS_DELETE = 'users.delete';
    public const string SITES_READ = 'sites.read';
    public const string SITES_UPDATE = 'sites.update';
    public const string SITES_CREATE = 'sites.create';
    public const string SITES_DELETE = 'sites.delete';
    public const string CONTENTREPOSITORIES_READ = 'contentrepositories.read';
    public const string NODETYPES_READ = 'nodetypes.read';
    public const string VIEWS_READ = 'views.read';
    public const string NODES_READ = 'nodes.read';
    public const string NODES_UPDATE = 'nodes.update';
    public const string NODES_CREATE = 'nodes.create';
    public const string NODES_DELETE = 'nodes.delete';
    public const string WORKSPACES_READ = 'workspaces.read';
    public const string WORKSPACES_UPDATE = 'workspaces.update';
    public const string WORKSPACES_CREATE = 'workspaces.create';
    public const string WORKSPACES_DELETE = 'workspaces.delete';
    public const string WORKSPACES_PUBLISH = 'workspaces.publish';
    public const string WORKSPACES_ROLES = 'workspaces.roles';
    public const string DATASOURCES_READ = 'datasources.read';
    public const string ASSETS_READ = 'assets.read';
    public const string ASSETS_UPDATE = 'assets.update';
    public const string ASSETS_CREATE = 'assets.create';
    public const string ASSETS_DELETE = 'assets.delete';
    public const string ASSETTAGS_READ = 'assettags.read';
    public const string ASSETTAGS_UPDATE = 'assettags.update';
    public const string ASSETTAGS_CREATE = 'assettags.create';
    public const string ASSETTAGS_DELETE = 'assettags.delete';
    public const string ASSETCOLLECTIONS_READ = 'assetcollections.read';
    public const string ASSETCOLLECTIONS_UPDATE = 'assetcollections.update';
    public const string ASSETCOLLECTIONS_CREATE = 'assetcollections.create';
    public const string ASSETCOLLECTIONS_DELETE = 'assetcollections.delete';
    public const string CACHES_READ = 'caches.read';
    public const string CACHES_FLUSH = 'caches.flush';
}
