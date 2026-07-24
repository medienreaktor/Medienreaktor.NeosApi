<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Command;

use Medienreaktor\NeosApi\Domain\Model\OAuthClient;
use Medienreaktor\NeosApi\Domain\Repository\OAuthClientRepository;
use Medienreaktor\NeosApi\Domain\Repository\TokenRecordRepository;
use Medienreaktor\NeosApi\Security\OAuth\KeyManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Reflection\ReflectionService;

class NeosApiCommandController extends CommandController
{
    #[Flow\Inject]
    protected KeyManager $keyManager;

    #[Flow\Inject]
    protected OAuthClientRepository $clientRepository;

    #[Flow\Inject]
    protected TokenRecordRepository $tokenRecordRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected ReflectionService $reflectionService;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

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
     * Remove expired OAuth token records
     *
     * Every issued access token, refresh token and authorization code leaves a
     * lifecycle record behind; expired records are dead weight (a missing
     * record already means "revoked"). Run this periodically, e.g. via cron.
     */
    public function pruneTokensCommand(): void
    {
        $count = $this->tokenRecordRepository->removeExpired(new \DateTimeImmutable());
        $this->outputLine('<success>Removed %d expired token record(s).</success>', [$count]);
    }

    /**
     * Revoke all active OAuth tokens of a client and/or account
     *
     * Marks every not-yet-expired access token, refresh token and auth code
     * matching the given filters as revoked. Access tokens die immediately:
     * the resource server checks the record on every request.
     *
     * @param string $client Revoke tokens issued to this client identifier
     * @param string $account Revoke tokens issued for this account identifier
     */
    public function revokeTokensCommand(string $client = '', string $account = ''): void
    {
        if ($client === '' && $account === '') {
            $this->outputLine('<error>Give at least one filter: --client and/or --account.</error>');
            $this->quit(1);
        }

        $records = $this->tokenRecordRepository->findActive(
            $client === '' ? null : $client,
            $account === '' ? null : $account,
            new \DateTimeImmutable()
        );
        foreach ($records as $record) {
            $record->revoke();
            $this->tokenRecordRepository->update($record);
        }
        $this->persistenceManager->persistAll();

        $this->outputLine('<success>Revoked %d active token(s).</success>', [count($records)]);
    }

    /**
     * Verify that every API controller action is covered by a policy matcher
     *
     * Flow treats a controller method matched by NO privilege target as OPEN,
     * so a new Controller\Api action is unprotected until it is added to a
     * matcher in Policy.yaml. This command fails (exit code 1) if any action
     * slipped through - run it as part of the test suite.
     */
    public function policyCoverageCommand(): void
    {
        $policy = $this->configurationManager->getConfiguration('Policy');
        $methodPrivileges = $policy['privilegeTargets']['Neos\Flow\Security\Authorization\Privilege\Method\MethodPrivilege'] ?? [];

        // Only this package's own privilege targets count as coverage - a
        // foreign catch-all silently matching our actions is not protection we
        // control.
        $matcherExpressions = [];
        foreach ($methodPrivileges as $targetIdentifier => $target) {
            if (str_starts_with((string)$targetIdentifier, 'Medienreaktor.NeosApi:') && isset($target['matcher'])) {
                foreach (explode('||', $target['matcher']) as $expression) {
                    $matcherExpressions[] = trim($expression);
                }
            }
        }
        $matchers = [];
        foreach ($matcherExpressions as $expression) {
            if (preg_match('/^method\((?<class>.+)->(?<method>.+)\(\)\)$/', $expression, $matches) === 1) {
                // The pointcut patterns are regexes except that namespace
                // backslashes are literal - escape those, keep .* and (a|b).
                $matchers[] = [
                    '/^' . str_replace('\\', '\\\\', $matches['class']) . '$/',
                    '/^' . $matches['method'] . '\\(\\)$/',
                ];
            }
        }

        $uncovered = [];
        foreach ($this->reflectionService->getAllSubClassNamesForClass(ActionController::class) as $className) {
            if (!str_starts_with($className, 'Medienreaktor\NeosApi\Controller\Api\\')
                || $this->reflectionService->isClassAbstract($className)) {
                continue;
            }
            foreach (get_class_methods($className) as $methodName) {
                if (!str_ends_with($methodName, 'Action') || !(new \ReflectionMethod($className, $methodName))->isPublic()) {
                    continue;
                }
                $covered = false;
                foreach ($matchers as [$classPattern, $methodPattern]) {
                    if (preg_match($classPattern, $className) === 1 && preg_match($methodPattern, $methodName . '()') === 1) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $uncovered[] = $className . '->' . $methodName . '()';
                }
            }
        }

        if ($uncovered !== []) {
            $this->outputLine('<error>%d API action(s) are NOT covered by any Medienreaktor.NeosApi policy matcher (and therefore OPEN):</error>', [count($uncovered)]);
            foreach ($uncovered as $action) {
                $this->outputLine('  %s', [$action]);
            }
            $this->quit(1);
        }
        $this->outputLine('<success>All API controller actions are covered by policy matchers.</success>');
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
