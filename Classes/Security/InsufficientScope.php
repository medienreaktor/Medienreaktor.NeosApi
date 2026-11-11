<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Annotations as Flow;

/**
 * The caller is authenticated, but the token lacks the scopes an operation requires
 *
 * neos/openapi can only answer 401 on its own, the OAuthChallengeMiddleware turns this into a 403
 */
#[Flow\Proxy(false)]
final class InsufficientScope extends \RuntimeException
{
    /**
     * @param list<string> $requiredScopes the scopes of the (first) alternative the caller could satisfy
     */
    public function __construct(
        public readonly array $requiredScopes,
    ) {
        parent::__construct(sprintf('The access token lacks the scope(s) %s', implode(', ', $requiredScopes)), 1790200001);
    }
}
