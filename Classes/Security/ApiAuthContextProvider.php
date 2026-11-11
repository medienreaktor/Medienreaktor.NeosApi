<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;
use Neos\OAuth\Security\AuthorizationServerMetadata;
use Neos\OAuth\Security\OAuthContext;
use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Spec\OAuthFlowObject;
use Neos\OpenApi\Spec\OAuthFlowsObject;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bridges Neos.OAuth to neos/openapi: operations declare `security: [self::SCOPES => [ApiScopes::USERS_UPDATE]]`, this
 * hands them the caller if the request's access token grants those scopes and its account holds the ApiPrivileges
 * they stand for (see PrivilegeScopes). The privileges come from the account's roles, the scopes only narrow them down
 */
#[Flow\Scope('singleton')]
final class ApiAuthContextProvider implements AuthContextProviderWithSchemes
{
    /**
     * The name of the OAuth 2 security scheme, named after what operations list for it: the scopes the token must grant
     */
    public const string SCOPES = 'oauth2';

    public function __construct(
        private readonly OAuthContext $oauthContext,
        private readonly PrivilegeScopes $privilegeScopes,
        private readonly AuthorizationServerMetadata $authorizationServer,
        private readonly AccountPrivileges $accountPrivileges,
    ) {
    }

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        $scopes = [];
        foreach ($this->privilegeScopes->scopes() as $scope => $description) {
            $scopes[$scope] = sprintf('%s (privilege %s)', $description, $this->privilegeScopes->privilegeTargetOf($scope));
        }
        $tokenUrl = $this->authorizationServer->tokenUrl();
        return SecuritySchemeOrReferenceObjectMap::create()
            ->with(self::SCOPES, SecuritySchemeObject::oauth2(
                new OAuthFlowsObject(
                    clientCredentials: new OAuthFlowObject(scopes: $scopes, tokenUrl: $tokenUrl),
                    authorizationCode: new OAuthFlowObject(scopes: $scopes, authorizationUrl: $this->authorizationServer->authorizationUrl(), tokenUrl: $tokenUrl, refreshUrl: $tokenUrl),
                ),
                description: sprintf(
                    'OAuth 2 access token of a Neos account. Each scope stands for a Neos privilege (see its description), which the roles of the account must grant as well: a scope can only narrow down what the account may do. The authorization code flow requires PKCE (%s).',
                    implode(', ', $this->authorizationServer->codeChallengeMethods()),
                ),
            ));
    }

    /**
     * @throws InsufficientScope if the caller is authenticated but the token lacks the scopes
     * @throws MissingPrivileges if the token has the scopes but its account lacks the privileges
     */
    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): ?ApiCaller
    {
        $grant = $this->oauthContext->authenticatedGrant();
        if ($grant === null) {
            return null;
        }
        $requiredScopes = null;
        $missingPrivileges = null;
        foreach ($requirement as $alternative) {
            // anonymous alternatives are handled by neos/openapi, other schemes are unknown to this provider
            if (array_keys($alternative) !== [self::SCOPES]) {
                continue;
            }
            if ($grant->missingScopes(...$alternative[self::SCOPES]) !== []) {
                $requiredScopes ??= $alternative[self::SCOPES];
                continue;
            }
            $missing = array_values(array_filter(
                array_map($this->privilegeScopes->privilegeTargetOf(...), $alternative[self::SCOPES]),
                fn (string $privilegeTarget) => !$this->accountPrivileges->isGranted($grant->account, $privilegeTarget),
            ));
            if ($missing === []) {
                return ApiCaller::fromGrant($grant);
            }
            $missingPrivileges ??= $missing;
        }
        if ($requirement->anonymousAccessAllowed) {
            return null;
        }
        if ($missingPrivileges !== null) {
            throw new MissingPrivileges($missingPrivileges);
        }
        if ($requiredScopes !== null) {
            throw new InsufficientScope($requiredScopes);
        }
        return null;
    }
}
