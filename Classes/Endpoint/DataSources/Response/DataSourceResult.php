<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Response;

use Neos\Api\Endpoint\DataSources\Schema\DataSourceData;
use Neos\Api\Infrastructure\Json\JsonValues;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponse;
use Neos\OpenApi\Support\HttpStatusCode;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * A 200 with the data a data source provided
 *
 * Not a plain return type: the data is free-form, so there is no class to instantiate, it passes the DataSourceData
 * schema as it is
 */
final readonly class DataSourceResult implements ApiResponse
{
    private function __construct(
        private mixed $data,
    ) {
    }

    /**
     * The return value of a data source, as JSON: it may contain JsonSerializable objects
     *
     * @throws \JsonException if it isn't JSON serializable
     */
    public static function of(mixed $value): self
    {
        return new self(JsonValues::decoded(json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR)));
    }

    public static function statusCode(): HttpStatusCode
    {
        return HttpStatusCode::fromInteger(200);
    }

    public static function description(): string
    {
        return 'The data source provided the data';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(DataSourceData::class);
    }

    public static function contentType(): MediaTypeRange
    {
        return MediaTypeRange::fromString('application/json');
    }

    /**
     * @return array{data: mixed}
     */
    public function body(): array
    {
        return ['data' => $this->data];
    }
}
