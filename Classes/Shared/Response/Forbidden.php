<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Response;

use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * The caller may use the operation, but not on this resource or with these arguments, e.g. a workspace the account
 * may not manage, or a rendering mode of the backend without backend access
 *
 * Only for rules an operation's security requirement can't express because they depend on an argument
 */
final readonly class Forbidden implements ApiResponse
{
    private function __construct(
        private ProblemDocument $problem,
    ) {
    }

    public static function because(string $detail): self
    {
        return new self(ProblemDocument::create(self::statusCode(), 'Forbidden', $detail));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(403);
    }

    public static function description(): string
    {
        return 'The caller may not do this';
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
