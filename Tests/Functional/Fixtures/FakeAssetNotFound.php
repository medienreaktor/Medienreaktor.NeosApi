<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Fixtures;

use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;

final class FakeAssetNotFound extends \Exception implements AssetNotFoundExceptionInterface
{
}
