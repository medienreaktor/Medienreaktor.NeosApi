<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;

/**
 * The access token has the scopes an operation requires, but its account lacks the privileges
 *
 * neos/openapi can only answer 401 on its own, the OAuthChallengeMiddleware turns this into a 403
 */
#[Flow\Proxy(false)]
final class MissingPrivileges extends \RuntimeException
{
    /**
     * @param list<string> $privilegeTargets the missing ones of the (first) alternative whose scopes the token grants
     */
    public function __construct(
        public readonly array $privilegeTargets,
    ) {
        parent::__construct(sprintf('The account lacks the privilege(s) %s', implode(', ', $privilegeTargets)), 1790200002);
    }
}
