<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Command;

use Medienreaktor\NeosApi\Domain\Model\OAuthClient;
use Medienreaktor\NeosApi\Domain\Repository\OAuthClientRepository;
use Medienreaktor\NeosApi\Security\OAuth\KeyManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class NeosApiCommandController extends CommandController
{
    #[Flow\Inject]
    protected KeyManager $keyManager;

    #[Flow\Inject]
    protected OAuthClientRepository $clientRepository;

    /**
     * @var array<string, string>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth.scopes')]
    protected array $configuredScopes = [];

    /**
     * Generate the RSA key pair and encryption key for the OAuth server
     *
     * @param bool $force Overwrite existing keys (invalidates all issued tokens)
     */
    public function generateKeysCommand(bool $force = false): void
    {
        $this->keyManager->generateKeys($force);
        $this->outputLine('<success>OAuth signing and encryption keys generated.</success>');
    }

    /**
     * Register an OAuth client
     *
     * Confidential clients (--confidential) receive a generated secret and may
     * use the client_credentials grant; public clients use authorization_code
     * with PKCE.
     *
     * @param string $identifier The client identifier, e.g. "my-app"
     * @param string $name Human-readable client name
     * @param string $redirectUris Comma-separated redirect URIs (required for authorization_code clients)
     * @param string $grantTypes Comma-separated grants (default: authorization_code,refresh_token; add client_credentials for machine clients)
     * @param string $scopes Comma-separated allowed scopes (default: all configured scopes)
     * @param bool $confidential Create a confidential client with a secret
     * @param bool $firstParty Trusted client: skip the consent screen (auto-approve for logged-in users)
     */
    public function createClientCommand(
        string $identifier,
        string $name,
        string $redirectUris = '',
        string $grantTypes = 'authorization_code,refresh_token',
        string $scopes = '',
        bool $confidential = false,
        bool $firstParty = false
    ): void {
        if ($this->clientRepository->findOneByIdentifier($identifier) !== null) {
            $this->outputLine('<error>A client with identifier "%s" already exists.</error>', [$identifier]);
            $this->quit(1);
        }

        $secret = null;
        $secretHash = null;
        if ($confidential) {
            $secret = bin2hex(random_bytes(32));
            $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        }

        $parsedScopes = $scopes === ''
            ? array_keys($this->configuredScopes)
            : array_values(array_intersect(array_map('trim', explode(',', $scopes)), array_keys($this->configuredScopes)));

        $client = new OAuthClient(
            $identifier,
            $name,
            $secretHash,
            $redirectUris === '' ? [] : array_map('trim', explode(',', $redirectUris)),
            array_map('trim', explode(',', $grantTypes)),
            $parsedScopes,
            $firstParty
        );
        $this->clientRepository->add($client);

        $this->outputLine('<success>Client "%s" created.</success>', [$identifier]);
        $this->outputLine('Allowed scopes: %s', [implode(', ', $parsedScopes)]);
        if ($firstParty) {
            $this->outputLine('<b>First-party client: the consent screen will be skipped.</b>');
        }
        if ($secret !== null) {
            $this->outputLine('');
            $this->outputLine('Client secret (shown only once, store it now):');
            $this->outputLine('  <b>%s</b>', [$secret]);
        }
    }

    /**
     * List registered OAuth clients
     */
    public function listClientsCommand(): void
    {
        $rows = [];
        foreach ($this->clientRepository->findAll() as $client) {
            $rows[] = [
                $client->getIdentifier(),
                $client->getName(),
                $client->isConfidential() ? 'confidential' : 'public',
                $client->isFirstParty() ? 'yes' : 'no',
                implode(', ', $client->getGrantTypes()),
                implode(', ', $client->getAllowedScopes()),
            ];
        }
        $this->output->outputTable($rows, ['Identifier', 'Name', 'Type', 'First-party', 'Grants', 'Scopes']);
    }
}
