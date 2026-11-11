<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A parameter is malformed in a way its schema can't express, e.g. a node address that isn't valid JSON
 */
final readonly class BadRequest implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Bad request', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(400);
    }

    public static function description(): string
    {
        return 'A parameter is malformed';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(ProblemDocument::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return ProblemDocument::contentType();
    }

    public function body(): ProblemDocument
    {
        return $this->problem;
    }
}
