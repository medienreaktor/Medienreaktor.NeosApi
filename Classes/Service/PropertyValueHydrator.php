<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Turns serialized object references in a command payload back into the actual
 * persisted objects.
 *
 * The read API emits object-typed properties (assets, images, and any other
 * Flow/Doctrine entity) in the content repository's serialized form,
 * {"__flow_object_type": "<concrete FQCN>", "__identifier": "<uuid>"}. The
 * write side cannot take that form as-is: SetNodeProperties (and friends)
 * validate every value against the node type's declared property type with a
 * plain `instanceof` check (ConstraintChecks / PropertyType::isMatchedBy), and
 * a decoded JSON reference is an array, not an ImageInterface/Asset - so it is
 * rejected before the command handler ever serializes it back.
 *
 * This mirrors what the classic UI's property mapper does: resolve the
 * reference to the real object up front. The command handler then re-serializes
 * it to the exact same shape, so the value round-trips losslessly. Works for
 * single values and for arrays of them (array<Asset>), at any depth, because
 * the walk is recursive; anything without the reference marker passes through
 * untouched.
 */
final class PropertyValueHydrator
{
    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Recursively replace every serialized object reference in $value with its
     * persisted object. Scalars and plain arrays are returned unchanged.
     */
    public function hydrate(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($this->isObjectReference($value)) {
            return $this->resolve($value['__flow_object_type'], $value['__identifier']);
        }
        return array_map(fn (mixed $item): mixed => $this->hydrate($item), $value);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function isObjectReference(array $value): bool
    {
        return is_string($value['__flow_object_type'] ?? null)
            && is_string($value['__identifier'] ?? null);
    }

    private function resolve(string $type, string $identifier): object
    {
        $object = $this->persistenceManager->getObjectByIdentifier($identifier, $type);
        if (!is_object($object)) {
            throw new \InvalidArgumentException(sprintf(
                'Could not resolve %s with identifier "%s".',
                $type,
                $identifier
            ), 1752570000);
        }
        return $object;
    }
}
