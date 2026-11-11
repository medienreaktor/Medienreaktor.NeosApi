<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Media;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Model\AssetVariantInterface;

/**
 * Counts the assets of tags and collections (image variants aren't counted, as in Neos' lists)
 *
 * One DQL query instead of the repositories' native SQL, so the media privileges apply to the assets counted
 */
#[Flow\Scope('singleton')]
final readonly class AssetCounter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReflectionService $reflectionService,
    ) {
    }

    /**
     * How many assets each tag or collection has, by its ID
     *
     * @param 'tags'|'assetCollections' $relation the property of Asset that holds them
     * @return array<string, int>
     */
    public function countBy(string $relation): array
    {
        $variants = array_values(array_filter(
            $this->reflectionService->getAllImplementationClassNamesForInterface(AssetVariantInterface::class),
            fn (string $className) => $this->reflectionService->isClassAnnotatedWith($className, Flow\Entity::class),
        ));
        $rows = $this->entityManager->createQuery(sprintf(
            'SELECT r.Persistence_Object_Identifier AS id, COUNT(a) AS assetCount FROM %s a JOIN a.%s r%s GROUP BY r.Persistence_Object_Identifier',
            Model\Asset::class,
            $relation,
            $variants !== [] ? ' WHERE a NOT INSTANCE OF (' . implode(', ', $variants) . ')' : '',
        ))->getArrayResult();
        return array_map(intval(...), array_column($rows, 'assetCount', 'id'));
    }
}
