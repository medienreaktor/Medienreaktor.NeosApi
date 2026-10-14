<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\People\Schema;

use Neos\Api\Endpoint\Users\Schema\UserId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos user as others see them, e.g. as the owner of a workspace or the author of a change: their name, nothing else
 */
final readonly class Person implements ProvidesSchema
{
    /**
     * @param string $label the name as Neos shows it, e.g. "Edith Editor"
     */
    public function __construct(
        public UserId $id,
        public string $label,
    ) {
    }

    public static function from(Model\User $user): self
    {
        return new self(UserId::fromString($user->getId()->value), $user->getLabel());
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
