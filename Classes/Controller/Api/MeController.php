<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Security\Authentication\Token\ApiBearerToken;
use Neos\Flow\Security\Policy\Role;

/**
 * The walking-skeleton endpoint: who am I, which roles and scopes does this
 * request act with? Useful for clients to introspect their effective access.
 */
class MeController extends AbstractApiController
{
    public function indexAction(): string
    {
        $this->requireScope('neos.read');

        $account = $this->securityContext->getAccount();
        $scopes = [];
        $clientIdentifier = null;
        foreach ($this->securityContext->getAuthenticationTokensOfType(ApiBearerToken::class) as $token) {
            if ($token->isAuthenticated()) {
                $scopes = $token->getScopes();
                $clientIdentifier = $token->getClientIdentifier();
            }
        }

        return $this->json([
            'account' => $account?->getAccountIdentifier(),
            'roles' => array_map(static fn (Role $role) => $role->getIdentifier(), array_values($this->securityContext->getRoles())),
            'scopes' => $scopes,
            'client' => $clientIdentifier,
            'contentRepository' => $this->contentRepositoryId,
        ]);
    }
}
