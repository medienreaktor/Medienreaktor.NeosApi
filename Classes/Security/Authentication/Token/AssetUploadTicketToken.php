<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\Authentication\Token;

use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Authentication\Token\AbstractToken;
use Neos\Flow\Security\Authentication\Token\SessionlessTokenInterface;

/**
 * The credentials of an upload URL: ticket id and secret from the route.
 *
 * Deliberately not an ApiBearerToken: a ticket grants uploading with it and
 * nothing else, so it must never satisfy requireScope() on another endpoint.
 */
class AssetUploadTicketToken extends AbstractToken implements SessionlessTokenInterface
{
    /**
     * @var array{upload: string, secret: string}
     */
    protected $credentials = ['upload' => '', 'secret' => ''];

    private ?string $ticketId = null;

    public function updateCredentials(ActionRequest $actionRequest)
    {
        $this->setAuthenticationStatus(self::NO_CREDENTIALS_GIVEN);
        if (!$actionRequest->hasArgument('upload') || !$actionRequest->hasArgument('secret')) {
            return;
        }
        $upload = $actionRequest->getArgument('upload');
        $secret = $actionRequest->getArgument('secret');
        if (!is_string($upload) || !is_string($secret) || $upload === '' || $secret === '') {
            return;
        }

        $this->credentials = ['upload' => $upload, 'secret' => $secret];
        $this->setAuthenticationStatus(self::AUTHENTICATION_NEEDED);
    }

    public function getUpload(): string
    {
        return $this->credentials['upload'];
    }

    public function getSecret(): string
    {
        return $this->credentials['secret'];
    }

    public function setTicketId(string $ticketId): void
    {
        $this->ticketId = $ticketId;
    }

    /**
     * The id of the ticket this token was authenticated with, null before.
     */
    public function getTicketId(): ?string
    {
        return $this->ticketId;
    }

    public function __toString()
    {
        return 'Asset upload ticket';
    }
}
