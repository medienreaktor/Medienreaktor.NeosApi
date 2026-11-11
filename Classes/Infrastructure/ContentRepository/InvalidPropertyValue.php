<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

/**
 * A property value PropertyValues can't write: an undeclared property, a value that doesn't fit its type or a type it
 * can't convert to yet. The message is for the caller of the API
 */
final class InvalidPropertyValue extends \DomainException
{
}
