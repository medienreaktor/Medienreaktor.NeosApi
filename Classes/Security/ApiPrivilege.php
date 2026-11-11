<?php

declare(strict_types=1);

namespace Neos\Api\Security;

use Neos\Flow\Security\Authorization\Privilege\AbstractPrivilege;
use Neos\Flow\Security\Authorization\Privilege\PrivilegeSubjectInterface;

/**
 * A capability of the API that roles are granted or denied, e.g. listing users
 *
 * Its matcher is the OAuth scope a token needs to use it, its label describes that scope, see PrivilegeScopes. It
 * guards no method or entity, so it matches nothing: the ApiAuthContextProvider checks it for the caller's roles
 */
class ApiPrivilege extends AbstractPrivilege
{
    public function matchesSubject(PrivilegeSubjectInterface $subject): bool
    {
        return false;
    }
}
