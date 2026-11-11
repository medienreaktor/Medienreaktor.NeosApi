<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Finds the content repositories of the Neos.ContentRepositoryRegistry, which throws for an unknown one
 */
#[Flow\Scope('singleton')]
final readonly class ContentRepositoryFinder
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    /**
     * The content repository, null if none is configured with the ID
     */
    public function find(ContentRepositoryId $contentRepositoryId): ?ContentRepository
    {
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            if ($id->equals($contentRepositoryId)) {
                return $this->contentRepositoryRegistry->get($id);
            }
        }
        return null;
    }
}
