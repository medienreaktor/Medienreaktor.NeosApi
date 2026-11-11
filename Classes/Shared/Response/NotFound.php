<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * The resource an operation addresses doesn't exist
 */
final readonly class NotFound implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Not found', $detail));
    }

    public static function contentRepository(string $contentRepositoryId): self
    {
        return self::because(sprintf('There is no content repository with the ID %s', $contentRepositoryId));
    }

    public static function cache(string $cacheId): self
    {
        return self::because(sprintf('There is no cache with the ID %s', $cacheId));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(404);
    }

    public static function description(): string
    {
        return 'The resource doesn\'t exist';
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
