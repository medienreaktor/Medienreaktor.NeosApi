<?php

declare(strict_types=1);

namespace Neos\Api\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\OAuth\Infrastructure\League\KeyManager;

/**
 * Commands of Medienreaktor.NeosApi 1.x that deployments still call
 *
 * Neos.OAuth issues the tokens now and has commands of its own (oauth:*). These keep
 * deploy scripts written for 1.x working.
 */
#[Flow\Scope('singleton')]
class NeosApiCommandController extends CommandController
{
    #[Flow\Inject]
    protected KeyManager $keyManager;

    /**
     * Make sure the OAuth keys exist (deprecated, use oauth:generatekeys)
     *
     * Medienreaktor.NeosApi 1.x signed its tokens with keys this command generated. Neos.OAuth signs them now,
     * with keys of its own, and generates them on first use. So this command succeeds when the keys exist or are
     * configured in Neos.OAuth.keys, and generates them otherwise; unlike in 1.x, existing keys are no error.
     * Keys of 1.x are not taken over: its tokens end with the update.
     *
     * @param bool $force Replace existing keys, which invalidates all issued tokens
     */
    public function generateKeysCommand(bool $force = false): void
    {
        $this->outputLine('<comment>neosapi:generatekeys is deprecated, use oauth:generatekeys.</comment>');
        if ($this->keyManager->keysAreConfigured()) {
            $this->outputLine('The OAuth keys are configured in Neos.OAuth.keys, there is nothing to generate.');
            return;
        }
        if ($this->keyManager->keyFilesExist() && !$force) {
            $this->outputLine('The OAuth keys exist. Use --force to replace them, which invalidates all issued tokens.');
            return;
        }
        $this->keyManager->generateKeys();
        $this->outputLine('<success>Generated the OAuth keys.</success>');
    }
}
