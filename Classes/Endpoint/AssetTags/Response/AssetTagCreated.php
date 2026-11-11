<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Response;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTag;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new asset tag and where to find it
 */
final readonly class AssetTagCreated implements ApiResponseWithHeaders
{
    use Created;

    public function __construct(
        private AssetTag $assetTag,
    ) {
    }

    public static function description(): string
    {
        return 'The asset tag was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new asset tag, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(AssetTag::class);
    }

    public function body(): AssetTag
    {
        return $this->assetTag;
    }

    public function headers(): array
    {
        // resolved against POST …/assettags, this is …/assettags/{id}, whatever the API's URI prefix is
        return ['Location' => 'assettags/' . $this->assetTag->id->value];
    }
}
