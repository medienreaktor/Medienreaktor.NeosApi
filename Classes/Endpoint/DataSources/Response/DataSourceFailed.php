<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A data source threw or returned something that isn't JSON. Its message stays in the log: data sources are code of
 * other packages, their messages may tell about internals
 */
final readonly class DataSourceFailed implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Data source failed', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(500);
    }

    public static function description(): string
    {
        return 'The data source failed, the details are in the log';
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
