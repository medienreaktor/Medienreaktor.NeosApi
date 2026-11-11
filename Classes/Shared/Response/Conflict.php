<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * The request conflicts with the current state of the resource, e.g. a name that is taken already
 */
final readonly class Conflict implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Conflict', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(409);
    }

    public static function description(): string
    {
        return 'The request conflicts with the current state';
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
