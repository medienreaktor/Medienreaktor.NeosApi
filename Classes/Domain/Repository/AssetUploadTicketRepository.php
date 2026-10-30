<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Domain\Repository;

use Medienreaktor\NeosApi\Domain\Model\AssetUploadTicket;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * Upload tickets live in a cache (MedienreaktorNeosApi_AssetUploadTickets):
 * they are worthless after a few minutes, so a lost entry only means the
 * client asks for a new link.
 */
#[Flow\Scope('singleton')]
class AssetUploadTicketRepository
{
    #[Flow\Inject]
    protected CacheManager $cacheManager;

    public function save(AssetUploadTicket $ticket): void
    {
        $lifetime = max(1, $ticket->expiresAt->getTimestamp() - time());
        $this->cache()->set($ticket->id, $ticket, [], $lifetime);
    }

    public function findById(string $id): ?AssetUploadTicket
    {
        // Cache identifiers only allow a narrow character set; ticket ids are hex.
        if (!ctype_xdigit($id)) {
            return null;
        }
        $ticket = $this->cache()->get($id);

        return $ticket instanceof AssetUploadTicket ? $ticket : null;
    }

    private function cache(): VariableFrontend
    {
        $cache = $this->cacheManager->getCache('MedienreaktorNeosApi_AssetUploadTickets');
        assert($cache instanceof VariableFrontend);

        return $cache;
    }
}
