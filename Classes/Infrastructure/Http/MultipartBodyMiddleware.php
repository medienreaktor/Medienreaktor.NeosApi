<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Neos\OpenApi\Problem\ProblemDocument;
use Neos\OpenApi\Support\HttpStatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Parses multipart/form-data bodies of other methods than POST (PUT …/file) as PHP parses them for POST
 *
 * PHP only parses POST bodies itself. request_parse_body() does the same for any method, with PHP's limits
 * (post_max_size, upload_max_filesize, max_file_uploads) and the files in temporary files, but only before anything
 * else reads php://input, so this runs before Flow's RequestBodyParsingMiddleware ("parseBody"), which would parse the
 * body as a query string. Not in the CLI: the functional tests' requests have no php://input, MultipartForm parses
 * their bodies itself.
 */
final class MultipartBodyMiddleware implements MiddlewareInterface
{
    /**
     * A request attribute, true if this middleware parsed the body, so MultipartForm doesn't parse it again
     */
    public const string PARSED = 'Neos.Api:multipartBodyParsed';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (PHP_SAPI === 'cli' || $request->getMethod() === 'POST' || stripos($request->getHeaderLine('Content-Type'), 'multipart/form-data') !== 0) {
            return $handler->handle($request);
        }
        try {
            [$fields, $files] = request_parse_body();
        } catch (\RequestParseBodyException $exception) {
            $problem = ProblemDocument::create(HttpStatusCode::fromInteger(400), 'Bad request', sprintf('The multipart body can\'t be parsed: %s', $exception->getMessage()));
            return new Response(400, ['Content-Type' => ProblemDocument::CONTENT_TYPE], json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        return $handler->handle($request
            ->withParsedBody($fields)
            ->withUploadedFiles(ServerRequest::normalizeFiles($files))
            // php://input is read
            ->withBody(Utils::streamFor(''))
            ->withAttribute(self::PARSED, true));
    }
}
