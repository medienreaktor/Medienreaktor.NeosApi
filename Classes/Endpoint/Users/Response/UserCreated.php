<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Response;

use Neos\Api\Endpoint\Users\Schema\User;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new user and where to find it
 */
final readonly class UserCreated implements ApiResponseWithHeaders
{
    use Created;

    public function __construct(
        private User $user,
    ) {
    }

    public static function description(): string
    {
        return 'The user was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new user, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(User::class);
    }

    public function body(): User
    {
        return $this->user;
    }

    public function headers(): array
    {
        // resolved against POST …/users, this is …/users/{userId}, whatever the API's URI prefix is
        return ['Location' => 'users/' . $this->user->id->value];
    }
}
