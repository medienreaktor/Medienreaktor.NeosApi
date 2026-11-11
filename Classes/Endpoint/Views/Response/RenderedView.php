<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Response;

use Neos\Api\Endpoint\Views\Schema\ViewData;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 200 with the data of a view
 *
 * Not a plain return type: the data is free-form, so there is no class to instantiate, the decoded JSON passes the
 * ViewData schema as it is
 */
final readonly class RenderedView implements ApiResponse
{
    /**
     * @param array<string, mixed>|\stdClass $data an empty object as \stdClass, so it is encoded as {}
     */
    public function __construct(
        private array|\stdClass $data,
    ) {
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(200);
    }

    public static function description(): string
    {
        return 'The view was rendered';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ViewData::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public function body(): array|\stdClass
    {
        return $this->data;
    }
}
