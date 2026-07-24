<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api\Media;

use Medienreaktor\NeosApi\Controller\Api\AbstractApiController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Utility\ObjectAccess;

/**
 * Tags as a nested tree with CRUD. Tags form a parent/child hierarchy; the
 * index returns the full tree (roots with nested children).
 */
class TagsController extends AbstractApiController
{
    #[Flow\Inject]
    protected TagRepository $tagRepository;

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function indexAction(): string
    {
        $this->requireScope('neos.media');

        $roots = [];
        foreach ($this->tagRepository->findAll() as $tag) {
            /** @var Tag $tag */
            if ($tag->getParent() === null) {
                $roots[] = $this->serializeTree($tag);
            }
        }

        return $this->json(['tags' => $roots]);
    }

    #[Flow\SkipCsrfProtection]
    public function createAction(string $label, ?string $parent = null): string
    {
        $this->requireScope('neos.media');

        $tag = new Tag($label);
        $parentTag = $this->resolveTag($parent);
        if ($parentTag !== null) {
            $tag->setParent($parentTag);
        }
        $this->tagRepository->add($tag);
        $this->persistenceManager->persistAll();

        return $this->json(['tag' => $this->serializeTree($tag)], 201);
    }

    /**
     * Rename and/or reparent. JSON body: label, parent (identifier or empty
     * string to move to root). Absent keys are left as-is.
     */
    #[Flow\SkipCsrfProtection]
    public function updateAction(string $tagIdentifier, ?string $label = null, ?string $parent = null): string
    {
        $this->requireScope('neos.media');
        $tag = $this->requireTag($tagIdentifier);

        if ($label !== null) {
            $tag->setLabel($label);
        }
        if ($parent !== null) {
            if ($parent === '') {
                // Move to root. Tag::setParent is non-nullable, so null the
                // owning-side association directly. Deliberately NOT removing
                // the tag from the old parent's children collection: that
                // association has orphanRemoval=true, so removeElement() would
                // DELETE the tag instead of just detaching it. Nulling the FK
                // reparents it to the root; the inverse collection is rebuilt
                // on the next read.
                ObjectAccess::setProperty($tag, 'parent', null, true);
            } else {
                $tag->setParent($this->requireTag($parent));
            }
        }

        $this->tagRepository->update($tag);
        $this->persistenceManager->persistAll();

        return $this->json(['tag' => $this->serializeTree($tag)]);
    }

    #[Flow\SkipCsrfProtection]
    public function deleteAction(string $tagIdentifier): string
    {
        $this->requireScope('neos.media');
        $tag = $this->requireTag($tagIdentifier);

        // Detach the tag from every asset that carries it before removal.
        foreach ($this->assetRepository->findByTag($tag) as $asset) {
            $asset->removeTag($tag);
            $this->assetRepository->update($asset);
        }

        $this->tagRepository->remove($tag);
        $this->persistenceManager->persistAll();

        return $this->json(['success' => true]);
    }

    private function requireTag(string $identifier): Tag
    {
        $tag = $this->resolveTag($identifier);
        if ($tag === null) {
            $this->throwJsonStatus(404, 'tag_not_found', 'The tag does not exist.');
        }

        return $tag;
    }

    private function resolveTag(?string $identifier): ?Tag
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }
        $tag = $this->tagRepository->findByIdentifier($identifier);

        return $tag instanceof Tag ? $tag : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTree(Tag $tag): array
    {
        $children = [];
        foreach ($tag->getChildren() as $child) {
            /** @var Tag $child */
            $children[] = $this->serializeTree($child);
        }

        return [
            'identifier' => $this->persistenceManager->getIdentifierByObject($tag),
            'label' => $tag->getLabel(),
            'parent' => $tag->getParent() !== null ? $this->persistenceManager->getIdentifierByObject($tag->getParent()) : null,
            'assetCount' => $this->assetRepository->countByTag($tag),
            'children' => $children,
        ];
    }
}
