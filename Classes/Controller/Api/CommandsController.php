<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\CommandRegistry;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\Flow\Annotations as Flow;

/**
 * The write API: execute whitelisted content repository commands.
 *
 * Single:  POST /api/commands        {"type": "...", "payload": {...}}
 * Batch:   POST /api/commands/batch  {"commands": [{"type": ..., "payload": ...}, ...]}
 *
 * Batches execute sequentially and stop at the first failure; the response
 * reports how many commands were applied. (Commands on an event-sourced CR
 * are not transactional as a group - partial application is reported, not
 * rolled back.)
 *
 * Every command is authorized centrally by the content repository
 * (workspace permissions, EditNodePrivilege) - a denial maps to 403 here.
 */
class CommandsController extends AbstractApiController
{
    /**
     * Authenticated exclusively via sessionless bearer tokens - CSRF does not apply
     */
    #[Flow\SkipCsrfProtection]
    public function executeAction(): string
    {
        $this->requireScope('neos.write');

        $body = $this->getJsonBody();
        $result = $this->handleCommand($body);
        if (isset($result['error'])) {
            $this->throwJsonStatus($result['statusCode'], $result['error'], $result['message']);
        }

        return $this->json(['status' => 'ok', 'type' => $body['type']]);
    }

    #[Flow\SkipCsrfProtection]
    public function batchAction(): string
    {
        $this->requireScope('neos.write');

        $body = $this->getJsonBody();
        $commands = $body['commands'] ?? null;
        if (!is_array($commands) || $commands === []) {
            $this->throwJsonStatus(400, 'invalid_request', 'Expected a non-empty "commands" array.');
        }

        $executed = 0;
        foreach (array_values($commands) as $index => $command) {
            $result = $this->handleCommand(is_array($command) ? $command : []);
            if (isset($result['error'])) {
                $this->throwJsonStatus($result['statusCode'], $result['error'], sprintf(
                    'Command #%d (%s) failed after %d successfully executed commands: %s',
                    $index,
                    is_array($command) && is_string($command['type'] ?? null) ? $command['type'] : 'invalid',
                    $executed,
                    $result['message']
                ));
            }
            $executed++;
        }

        return $this->json(['status' => 'ok', 'executed' => $executed]);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{error?: string, message?: string, statusCode?: int}
     */
    private function handleCommand(array $envelope): array
    {
        $type = $envelope['type'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (!is_string($type) || !is_array($payload)) {
            return ['error' => 'invalid_request', 'message' => 'Each command needs a string "type" and an object "payload".', 'statusCode' => 400];
        }

        try {
            $command = CommandRegistry::deserialize($type, $payload);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => 'unknown_command', 'message' => $exception->getMessage(), 'statusCode' => 400];
        } catch (\Throwable $exception) {
            return ['error' => 'invalid_payload', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        try {
            $this->getContentRepository()->handle($command);
        } catch (AccessDenied $exception) {
            return ['error' => 'access_denied', 'message' => $exception->getMessage(), 'statusCode' => 403];
        } catch (\Throwable $exception) {
            return ['error' => 'command_failed', 'message' => $exception->getMessage(), 'statusCode' => 422];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(): array
    {
        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        if (!is_array($body)) {
            $this->throwJsonStatus(400, 'invalid_request', 'Request body must be a JSON object.');
        }

        return $body;
    }
}
