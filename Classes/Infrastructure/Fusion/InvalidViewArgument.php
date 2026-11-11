<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

/**
 * Thrown while rendering a view when one of its arguments can't be used, e.g. a Fusion path that doesn't exist: the
 * request is at fault, not the view, so renderView answers with a 400
 */
final class InvalidViewArgument extends \InvalidArgumentException
{
    /**
     * The exception itself or the one it caused, Fusion wraps the exceptions of nested paths
     */
    public static function in(\Throwable $exception): ?self
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return $cause;
            }
        }
        return null;
    }
}
