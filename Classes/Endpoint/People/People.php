<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\People;

use Neos\Api\Endpoint\People\Schema\Person;
use Neos\Api\Endpoint\People\Schema\PersonList;
use Neos\Api\Endpoint\People\Schema\PersonListing;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Neos\Domain\Model;
use Neos\Neos\Domain\Service\UserService;
use Neos\OpenApi\Attributes\Operation;

/**
 * The Neos users by name, for everybody who works with them: editors see who owns a workspace or made a change,
 * without users.read, which tells their accounts, roles and email addresses and is for managing them
 */
final readonly class People
{
    public function __construct(
        private UserService $userService,
    ) {
    }

    #[Operation(
        path: '/people',
        method: 'GET',
        summary: 'List the people',
        description: 'All Neos users with their ID and name (label) only, not paged, sorted by name, e.g. to show the names of workspace owners or of the users in workspace roles and events. listUsers has more about them.',
        operationId: 'listPeople',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::PEOPLE_READ],
        ],
    )]
    public function list(): PersonListing
    {
        $people = array_map(static fn (Model\User $user) => Person::from($user), $this->userService->getUsers()->toArray());
        usort($people, static fn (Person $a, Person $b) => strcasecmp($a->label, $b->label) ?: strcmp($a->id->value, $b->id->value));
        return PersonListing::of(new PersonList(...$people));
    }
}
