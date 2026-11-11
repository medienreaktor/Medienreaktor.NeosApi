<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Response;

use Neos\OpenApi\Binding\BuiltinType;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ResponseHeader;
use Neos\OpenApi\Response\ResponseHeaders;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * What every 201 with a JSON body and a `Location` has in common, for an `ApiResponseWithHeaders`
 *
 * The response class adds its body (`description()`, `bodyType()`, `body()`), `headers()` with the `Location`, and
 * `locationDescription()`. A `Location` is relative to the request URI, so it depends on the operation's path:
 * `users/{id}` for `POST …/users`, `../{id}` for `POST …/{originalId}/variants`.
 */
trait Created
{
    abstract public static function locationDescription(): string;

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(201);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    public static function headerTypes(): ResponseHeaders
    {
        return ResponseHeaders::create(
            ResponseHeader::create('Location', TypeReference::builtin(BuiltinType::string), description: static::locationDescription()),
        );
    }
}
