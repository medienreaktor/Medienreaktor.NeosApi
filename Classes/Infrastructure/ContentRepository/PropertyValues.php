<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferenceToWrite;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * The counterpart of NodeSerializer::properties() and ::references(): property values and references in the form the API
 * reads them, converted to what the content repository writes, by the types the node type declares
 *
 * The content repository's own converters are internal, so the types are converted here: string, integer, float, boolean
 * and array as JSON values, dates as RFC 3339 strings, assets (also in a list) as {"id": "…"}. Other types (value
 * objects, enums, URIs) can't be written yet
 */
#[Flow\Scope('singleton')]
final readonly class PropertyValues
{
    private const array DATE_TYPES = ['DateTime', '\DateTime', 'DateTimeImmutable', '\DateTimeImmutable', 'DateTimeInterface', '\DateTimeInterface'];

    public function __construct(
        private AssetRepository $assetRepository,
    ) {
    }

    /**
     * Null unsets a property
     *
     * @param array<string, mixed> $values
     * @throws InvalidPropertyValue
     */
    public function toWrite(NodeType $nodeType, array $values): PropertyValuesToWrite
    {
        $converted = [];
        foreach ($values as $name => $value) {
            $name = (string)$name;
            // _-prefixed ones are the internal properties of Neos 8 (_hidden, _nodeType, …), not node type properties
            if (str_starts_with($name, '_') || !$nodeType->hasProperty($name)) {
                throw new InvalidPropertyValue(sprintf('The node type %s has no property %s', $nodeType->name->value, $name));
            }
            $converted[$name] = $value !== null ? $this->convert($name, $nodeType->getPropertyType($name), $value) : null;
        }
        return PropertyValuesToWrite::fromArray($converted);
    }

    /**
     * References by name as Node has them, each name replaced by its list, an empty list removes them:
     * {"relatedPages": [{"nodeAggregateId": "…", "properties": {…}}]}, properties optional and converted by the types
     * the reference declares
     *
     * @param array<mixed> $values
     * @throws InvalidPropertyValue
     */
    public function referencesToWrite(NodeType $nodeType, array $values): NodeReferencesToWrite
    {
        $referencesForNames = [];
        foreach ($values as $name => $references) {
            $name = (string)$name;
            if (!$nodeType->hasReference($name)) {
                throw new InvalidPropertyValue(sprintf('The node type %s has no reference %s', $nodeType->name->value, $name));
            }
            if (!is_array($references) || !array_is_list($references)) {
                throw new InvalidPropertyValue(sprintf('The references %s must be a list of {"nodeAggregateId": "…", "properties": {…}}', $name));
            }
            /** @var array<string, array{type?: string}> $declaredProperties */
            $declaredProperties = $nodeType->getReferences()[$name]['properties'] ?? [];
            $referencesForNames[] = NodeReferencesForName::fromReferences(
                ReferenceName::fromString($name),
                array_map(fn (mixed $reference) => $this->reference($name, $declaredProperties, $reference), $references),
            );
        }
        return NodeReferencesToWrite::create(...$referencesForNames);
    }

    /**
     * @param array<string, array{type?: string}> $declaredProperties
     */
    private function reference(string $name, array $declaredProperties, mixed $reference): NodeReferenceToWrite
    {
        $properties = is_array($reference) ? $reference['properties'] ?? [] : null;
        if (!is_array($reference) || !is_string($reference['nodeAggregateId'] ?? null) || !is_array($properties) || array_diff(array_keys($reference), ['nodeAggregateId', 'properties']) !== []) {
            throw new InvalidPropertyValue(sprintf('A reference %s must be {"nodeAggregateId": "…", "properties": {…}}, properties optional', $name));
        }
        try {
            $target = NodeAggregateId::fromString($reference['nodeAggregateId']);
        } catch (\InvalidArgumentException) {
            throw new InvalidPropertyValue(sprintf('"%s" is no node aggregate id, in the references %s', $reference['nodeAggregateId'], $name));
        }
        $converted = [];
        foreach ($properties as $propertyName => $value) {
            $propertyName = (string)$propertyName;
            $type = $declaredProperties[$propertyName]['type'] ?? null;
            if ($type === null) {
                throw new InvalidPropertyValue(sprintf('The reference %s has no property %s', $name, $propertyName));
            }
            $converted[$propertyName] = $value !== null ? $this->convert($propertyName, $type, $value) : null;
        }
        return $converted === []
            ? NodeReferenceToWrite::fromTarget($target)
            : NodeReferenceToWrite::fromTargetAndProperties($target, PropertyValuesToWrite::fromArray($converted));
    }

    private function convert(string $name, string $type, mixed $value): mixed
    {
        $converted = match (true) {
            $type === 'string' => is_string($value) ? $value : null,
            $type === 'integer' || $type === 'int' => is_int($value) ? $value : null,
            $type === 'float' || $type === 'double' => is_int($value) || is_float($value) ? (float)$value : null,
            $type === 'boolean' || $type === 'bool' => is_bool($value) ? $value : null,
            $type === 'array' => is_array($value) ? $value : null,
            in_array($type, self::DATE_TYPES, true) => is_string($value) ? self::date($value) : null,
            self::isAssetType($type) => $this->asset($name, $type, $value),
            self::isAssetListType($type) => is_array($value) && array_is_list($value)
                ? array_map(fn (mixed $item) => $this->asset($name, self::elementType($type), $item) ?? throw self::invalid($name, $type), $value)
                : null,
            default => throw new InvalidPropertyValue(sprintf('The property %s is of the type %s, which can\'t be written yet', $name, $type)),
        };
        return $converted ?? throw self::invalid($name, $type);
    }

    /**
     * As the content repository serializes dates (RFC 3339), with or without milliseconds. Impossible dates are null,
     * PHP would roll them over (2026-02-31 becomes 2026-03-03) with only a warning
     */
    private static function date(string $value): ?\DateTimeImmutable
    {
        foreach ([\DATE_RFC3339, \DATE_RFC3339_EXTENDED] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || $errors['warning_count'] === 0)) {
                return $date;
            }
        }
        return null;
    }

    /**
     * The asset {"id": "…"} as NodeSerializer gives it (its url is ignored), null if it isn't one
     *
     * @throws InvalidPropertyValue if there is no asset of the type with the id
     */
    private function asset(string $name, string $type, mixed $value): ?AssetInterface
    {
        if (!is_array($value) || !is_string($value['id'] ?? null)) {
            return null;
        }
        $asset = $this->assetRepository->findByIdentifier($value['id']);
        if (!$asset instanceof AssetInterface || !is_a($asset, ltrim($type, '\\'))) {
            throw new InvalidPropertyValue(sprintf('There is no asset of the type %s with the ID %s for the property %s', $type, $value['id'], $name));
        }
        return $asset;
    }

    /**
     * ImageInterface is no AssetInterface (image variants implement it as well), the assets it declares are
     */
    private static function isAssetType(string $type): bool
    {
        $type = ltrim($type, '\\');
        return is_a($type, AssetInterface::class, true) || is_a($type, ImageInterface::class, true);
    }

    private static function isAssetListType(string $type): bool
    {
        return preg_match('/^array<[^>]+>$/', $type) === 1 && self::isAssetType(self::elementType($type));
    }

    private static function elementType(string $type): string
    {
        return substr($type, 6, -1);
    }

    private static function invalid(string $name, string $type): InvalidPropertyValue
    {
        return new InvalidPropertyValue(sprintf('The value of the property %s doesn\'t fit its type %s', $name, $type));
    }
}
