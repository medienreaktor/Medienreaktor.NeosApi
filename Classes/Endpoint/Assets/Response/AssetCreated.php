<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Response;

use Neos\Api\Endpoint\Assets\Schema\Asset;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new asset of neos (uploaded, imported or a variant) and where to find it. The operations answer an
 * asset that existed already (the same file, imported before, the same crop) with a plain Asset, a 200
 */
final readonly class AssetCreated implements ApiResponseWithHeaders
{
    use Created;

    /**
     * @param string $location the asset's URI relative to the request URI, which differs per operation
     */
    private function __construct(
        private Asset $asset,
        private string $location,
    ) {
    }

    /**
     * For POST …/assets/{assetSourceId}, relative to …/assets/
     */
    public static function uploaded(Asset $asset): self
    {
        return new self($asset, $asset->assetSourceId->value . '/' . $asset->id->value);
    }

    /**
     * For POST …/assets/{assetSourceId}/{assetId}/import of a remote asset, relative to …/assets/{assetSourceId}/{assetId}/
     */
    public static function imported(Asset $asset): self
    {
        return new self($asset, '../../' . $asset->assetSourceId->value . '/' . $asset->id->value);
    }

    /**
     * For POST …/assets/{assetSourceId}/{assetId}/variants, relative to …/assets/{assetSourceId}/{assetId}/
     */
    public static function variant(Asset $asset): self
    {
        return new self($asset, '../' . $asset->id->value);
    }

    public static function description(): string
    {
        return 'The asset was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new asset, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Asset::class);
    }

    public function body(): Asset
    {
        return $this->asset;
    }

    public function headers(): array
    {
        return ['Location' => $this->location];
    }
}
