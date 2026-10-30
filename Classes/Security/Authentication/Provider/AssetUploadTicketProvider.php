<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\Authentication\Provider;

use Medienreaktor\NeosApi\Domain\Repository\AssetUploadTicketRepository;
use Medienreaktor\NeosApi\Security\Authentication\Token\AssetUploadTicketToken;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Psr\Log\LoggerInterface;

/**
 * Authenticates an upload URL as the account that created its ticket, so an
 * upload runs with exactly that account's roles and policies - like a bearer
 * request of the same account, limited to the upload endpoint.
 */
class AssetUploadTicketProvider extends AbstractProvider
{
    #[Flow\Inject]
    protected AssetUploadTicketRepository $ticketRepository;

    #[Flow\Inject]
    protected AccountRepository $accountRepository;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'accountAuthenticationProviderName')]
    protected string $accountAuthenticationProviderName;

    public function getTokenClassNames(): array
    {
        return [AssetUploadTicketToken::class];
    }

    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof AssetUploadTicketToken) {
            throw new UnsupportedAuthenticationTokenException('This provider cannot authenticate the given token.', 1761830401);
        }
        if ($authenticationToken->getUpload() === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);

            return;
        }

        $ticket = $this->ticketRepository->findById($authenticationToken->getUpload());
        if ($ticket === null || !$ticket->matchesSecret($authenticationToken->getSecret()) || $ticket->isExpiredAt(new \DateTimeImmutable())) {
            $this->logger->info(sprintf('NeosApi: upload ticket "%s" rejected (unknown, wrong secret or expired).', $authenticationToken->getUpload()));
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

            return;
        }

        $account = $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName(
            $ticket->accountIdentifier,
            $this->accountAuthenticationProviderName
        );
        if ($account === null) {
            $this->logger->info(sprintf('NeosApi: upload ticket "%s" belongs to no active account "%s".', $ticket->id, $ticket->accountIdentifier));
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

            return;
        }

        $authenticationToken->setTicketId($ticket->id);
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}
