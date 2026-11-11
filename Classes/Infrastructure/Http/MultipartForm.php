<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The fields and files of a multipart/form-data request, for the uploads
 *
 * neos/openapi only decodes JSON request bodies, so an operation that takes a file reads it from the request itself
 * and describes it in its description: the OpenAPI document has no request body for it. PHP parses multipart bodies
 * of POST requests only (into the request's parsed body and uploaded files), MultipartBodyMiddleware does it for other
 * methods (PUT) with request_parse_body(). Requests without php://input (the functional tests') are parsed here, in
 * memory
 *
 * A field left out is null (or the default), one sent in the wrong shape (title[]=x for a text, tagIds=x for a list)
 * throws \UnexpectedValueException with the message for the 400
 */
final readonly class MultipartForm
{
    /**
     * @param array<mixed> $fields
     * @param array<mixed> $files
     */
    private function __construct(
        private array $fields,
        private array $files,
    ) {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $fields = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        // for other methods without MultipartBodyMiddleware, Flow's parsed body is the multipart body read as a query string
        $parsed = $request->getMethod() !== 'POST' && $request->getAttribute(MultipartBodyMiddleware::PARSED) !== true ? self::parse($request) : null;
        return $parsed ?? new self(is_array($fields) ? $fields : [], $files);
    }

    /**
     * The file, null if there is none or its upload failed (see uploadError())
     */
    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->files[$name] ?? null;
        return $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK ? $file : null;
    }

    /**
     * Why the file is missing, for the 400
     */
    public function uploadError(string $name): string
    {
        $file = $this->files[$name] ?? null;
        return match (true) {
            !$file instanceof UploadedFileInterface, $file->getError() === UPLOAD_ERR_NO_FILE => sprintf('The request has no file "%s", send it as multipart/form-data', $name),
            in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) => sprintf('The file "%s" is larger than the server accepts', $name),
            default => sprintf('The upload of the file "%s" failed (PHP upload error %d)', $name, $file->getError()),
        };
    }

    /**
     * A text field, null if it is left out
     *
     * @throws \UnexpectedValueException if it isn't a text
     */
    public function string(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a single text field', $name), 1791900001);
        }
        return $value;
    }

    /**
     * A repeated text field (name[]), [] if it is left out
     *
     * @return list<string>
     * @throws \UnexpectedValueException if it isn't a list of texts
     */
    public function strings(string $name): array
    {
        $values = $this->fields[$name] ?? [];
        if (!is_array($values) || !array_is_list($values) || array_filter($values, static fn (mixed $value) => !is_string($value)) !== []) {
            throw new \UnexpectedValueException(sprintf('%s must be repeated %s[] fields', $name, $name), 1791900002);
        }
        return $values;
    }

    /**
     * A boolean field (true, false, 1, 0), the default if it is left out
     *
     * @throws \UnexpectedValueException if it is none of them
     */
    public function bool(string $name, bool $default): bool
    {
        return match ($this->fields[$name] ?? null) {
            null => $default,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new \UnexpectedValueException(sprintf('%s must be true or false', $name), 1791900003),
        };
    }

    /**
     * A multipart body as PHP would parse it for a POST: fields with brackets (name[]) as arrays, files by their field
     * name. The body is read into memory, a file's content as a stream. Null if the request isn't multipart/form-data
     */
    private static function parse(ServerRequestInterface $request): ?self
    {
        if (preg_match('/^multipart\/form-data\s*;.*boundary=(?:"([^"]+)"|([^\s;]+))/i', $request->getHeaderLine('Content-Type'), $matches) !== 1) {
            return null;
        }
        $boundary = $matches[1] !== '' ? $matches[1] : $matches[2];
        $parts = explode('--' . $boundary, (string)$request->getBody());
        // before the first boundary is the preamble, after the closing one (--boundary--) the epilogue
        array_shift($parts);
        $pairs = [];
        $files = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }
            $part = (string)preg_replace('/^\r?\n|\r?\n$/', '', $part);
            [$headers, $content] = array_pad(preg_split('/\r?\n\r?\n/', $part, 2) ?: [], 2, '');
            if (
                preg_match('/^Content-Disposition:\s*form-data\s*;(.*)$/mi', $headers, $disposition) !== 1
                || preg_match('/\bname="([^"]*)"/i', $disposition[1], $name) !== 1
            ) {
                continue;
            }
            if (preg_match('/\bfilename="([^"]*)"/i', $disposition[1], $filename) === 1) {
                $mediaType = preg_match('/^Content-Type:\s*(\S+)/mi', $headers, $type) === 1 ? $type[1] : null;
                $files[$name[1]] = new UploadedFile(Utils::streamFor($content), strlen($content), $filename[1] === '' ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK, $filename[1], $mediaType);
                continue;
            }
            $pairs[] = rawurlencode($name[1]) . '=' . rawurlencode($content);
        }
        // as PHP does: name[] becomes a list, name[key] a map
        parse_str(str_replace(['%5B', '%5D'], ['[', ']'], implode('&', $pairs)), $fields);
        return new self($fields, $files);
    }
}
