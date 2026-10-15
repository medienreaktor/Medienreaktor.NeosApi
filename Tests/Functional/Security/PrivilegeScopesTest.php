<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Security;

use Neos\Api\Security\ApiScopes;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\OAuth\Domain\ScopeRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * Scopes and privileges are one list: the ApiPrivilege targets of Policy.yaml
 */
class PrivilegeScopesTest extends FunctionalTestCase
{
    #[Test]
    public function eachApiPrivilegeIsAScope(): void
    {
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        self::assertSame([
            'me.read' => 'Read your account',
            'me.update' => 'Change your name, email address, interface language and preferences',
            'me.password' => 'Change your password',
            'users.read' => 'List and read the Neos users',
            'users.update' => 'Change the Neos users',
            'users.create' => 'Create Neos users',
            'users.delete' => 'Delete Neos users',
            'sites.read' => 'List and read the Neos sites',
            'sites.update' => 'Change the Neos sites and their domains',
            'sites.create' => 'Create Neos sites',
            'sites.delete' => 'Delete Neos sites with their content',
            'contentrepositories.read' => 'List and read the content repositories and their dimensions',
            'nodetypes.read' => 'List and read the node types and their configuration',
            'views.read' => 'Render views of the nodes you can read',
            'nodes.read' => 'Read the nodes you can read',
            'nodes.update' => 'Change the properties of the nodes you can edit',
            'nodes.create' => 'Create nodes where you can edit',
            'nodes.delete' => 'Delete the nodes you can edit and restore them',
            'workspaces.read' => 'List the workspaces you can read and the changes in them',
            'workspaces.update' => 'Change the workspaces you manage, their title, description and base workspace',
            'workspaces.create' => 'Create workspaces, your personal one and shared ones',
            'workspaces.delete' => 'Delete the workspaces you manage',
            'workspaces.publish' => 'Publish, discard and rebase the changes in the workspaces you can write to',
            'workspaces.roles' => 'Assign and remove the roles in the workspaces you manage',
            'datasources.read' => 'Get the data of the data sources, e.g. the options of select boxes',
            'assets.read' => 'List and read the assets of the media library and remote asset sources, and where they are used',
            'assets.update' => 'Change the assets of the media library, their metadata, tags, collections and files',
            'assets.create' => 'Upload and import assets into the media library and create image variants',
            'assets.delete' => 'Delete assets of the media library that aren\'t in use',
            'assettags.read' => 'List and read the tags of the media library',
            'assettags.update' => 'Rename and move the tags of the media library',
            'assettags.create' => 'Create tags in the media library',
            'assettags.delete' => 'Delete tags of the media library',
            'assetcollections.read' => 'List and read the collections of the media library',
            'assetcollections.update' => 'Rename the collections of the media library',
            'assetcollections.create' => 'Create collections in the media library',
            'assetcollections.delete' => 'Delete collections of the media library',
            'caches.read' => 'List the caches of Flow and Neos',
            'caches.flush' => 'Flush the caches of Flow and Neos',
        ], $privilegeScopes->scopes());
        self::assertSame('Neos.Api:Users.Update', $privilegeScopes->privilegeTargetOf('users.update'));
    }

    #[Test]
    public function theScopesAreRegisteredWithNeosOAuth(): void
    {
        $scopeRegistry = $this->objectManager->get(ScopeRegistry::class);

        self::assertTrue($scopeRegistry->isKnown('users.update'));
        self::assertSame('Change the Neos users', $scopeRegistry->describe('users.update'));

        $response = $this->browser->request('http://localhost/.well-known/oauth-protected-resource/api');
        self::assertSame(['me.read', 'me.update', 'me.password', 'users.read', 'users.update', 'users.create', 'users.delete', 'sites.read', 'sites.update', 'sites.create', 'sites.delete', 'contentrepositories.read', 'nodetypes.read', 'views.read', 'nodes.read', 'nodes.update', 'nodes.create', 'nodes.delete', 'workspaces.read', 'workspaces.update', 'workspaces.create', 'workspaces.delete', 'workspaces.publish', 'workspaces.roles', 'datasources.read', 'assets.read', 'assets.update', 'assets.create', 'assets.delete', 'assettags.read', 'assettags.update', 'assettags.create', 'assettags.delete', 'assetcollections.read', 'assetcollections.update', 'assetcollections.create', 'assetcollections.delete', 'caches.read', 'caches.flush'], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['scopes_supported']);
    }

    #[Test]
    public function eachApiScopesConstantHasAPrivilege(): void
    {
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        foreach ((new \ReflectionClass(ApiScopes::class))->getConstants() as $name => $scope) {
            self::assertNotEmpty($privilegeScopes->privilegeTargetOf($scope), $name);
        }
    }

    #[Test]
    public function eachScopeOfTheOperationsHasAPrivilege(): void
    {
        $document = json_decode((string)$this->browser->request('http://localhost/api/openapi.json')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $privilegeScopes = $this->objectManager->get(PrivilegeScopes::class);

        $scopes = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                // an operation without a security requirement would be public
                self::assertNotEmpty($operation['security'] ?? [], $method . ' ' . $path . ' has no security requirement');
                foreach ($operation['security'] as $alternative) {
                    self::assertNotEmpty($alternative['oauth2'] ?? [], $method . ' ' . $path . ' has an alternative without scopes');
                    foreach ($alternative['oauth2'] ?? [] as $scope) {
                        $scopes[] = $scope;
                        self::assertNotEmpty($privilegeScopes->privilegeTargetOf($scope), $method . ' ' . $path);
                    }
                }
            }
        }
        self::assertNotEmpty($scopes);
    }

    #[Test]
    public function unknownScopesHaveNoPrivilege(): void
    {
        $this->expectExceptionCode(1790200010);
        $this->objectManager->get(PrivilegeScopes::class)->privilegeTargetOf('users.purge');
    }
}
