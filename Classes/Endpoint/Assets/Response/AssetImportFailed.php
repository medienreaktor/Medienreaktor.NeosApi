<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * The remote asset source didn't deliver the file of the asset to import. Neos logs the details, they may tell about
 * internals (URIs with credentials, paths)
 */
final readonly class AssetImportFailed implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Asset import failed', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(502);
    }

    public static function description(): string
    {
        return 'The remote asset source failed to deliver the file, the details are in the log';
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
