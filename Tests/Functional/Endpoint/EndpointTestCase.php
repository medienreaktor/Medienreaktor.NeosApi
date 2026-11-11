<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Api\Security\PrivilegeScopes;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Authentication\Token\BearerToken;
use Neos\Flow\Security\Authentication\TokenAndProviderFactoryInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Service\UserService;
use Neos\OAuth\Domain\Model\OAuthClient;
use Neos\OAuth\Domain\Repository\OAuthClientRepository;
use Neos\Utility\ObjectAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Calls the API with access tokens of machine clients that act as a given account
 */
abstract class EndpointTestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    private const string SECRET = 'machine-secret';

    protected function setUp(): void
    {
        parent::setUp();
        // UserService is a singleton and maps usernames to user IDs for the whole process, while each test gets an
        // empty database: a user of an earlier test with the same username would hide the new one from getUser()
        $userService = $this->objectManager->get(UserService::class);
        ObjectAccess::setProperty($userService, 'runtimeUserCache', [], true);
    }

    /**
     * A confidential client with the client credentials grant and all scopes of the API, acting as the account; persist it before requesting tokens
     */
    protected function addMachineClient(string $clientIdentifier, string $accountIdentifier): void
    {
        $this->objectManager->get(OAuthClientRepository::class)->add(new OAuthClient($clientIdentifier, $clientIdentifier, password_hash(self::SECRET, PASSWORD_DEFAULT), [], [OAuthClient::GRANT_CLIENT_CREDENTIALS], array_keys($this->objectManager->get(PrivilegeScopes::class)->scopes()), false, $accountIdentifier));
    }

    protected function token(string $clientIdentifier, ?string $scope): string
    {
        $arguments = ['grant_type' => 'client_credentials', 'client_id' => $clientIdentifier, 'client_secret' => self::SECRET];
        if ($scope !== null) {
            $arguments['scope'] = $scope;
        }
        $response = $this->browser->request('http://localhost/oauth/token', 'POST', $arguments);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['access_token'];
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, ?string $bearer, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, $bearer, null, $headers);
    }

    /**
     * @param array<mixed> $body
     */
    protected function post(string $path, ?string $bearer, array $body): ResponseInterface
    {
        return $this->request('POST', $path, $bearer, $body);
    }

    protected function delete(string $path, ?string $bearer): ResponseInterface
    {
        return $this->request('DELETE', $path, $bearer);
    }

    /**
     * @param array<mixed> $body
     */
    protected function patch(string $path, ?string $bearer, array $body): ResponseInterface
    {
        return $this->request('PATCH', $path, $bearer, $body);
    }

    /**
     * @param array<mixed> $body
     */
    protected function put(string $path, ?string $bearer, array $body): ResponseInterface
    {
        return $this->request('PUT', $path, $bearer, $body);
    }

    /**
     * A multipart/form-data POST, as PHP parses it: the fields as parsed body, the files as uploaded files
     *
     * @param array<string, mixed> $fields
     * @param array<string, UploadedFileInterface> $files
     */
    protected function postMultipart(string $path, ?string $bearer, array $fields, array $files): ResponseInterface
    {
        $this->resetAuthenticationState();
        $headers = ['Content-Type' => 'multipart/form-data; boundary=neos-api-test'];
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        $request = (new ServerRequest('POST', 'http://localhost' . $path, $headers))->withParsedBody($fields)->withUploadedFiles($files);
        return $this->browser->sendRequest($request);
    }

    /**
     * @param array<mixed>|null $body sent as JSON
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?string $bearer, ?array $body = null, array $headers = []): ResponseInterface
    {
        $this->resetAuthenticationState();
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $request = new ServerRequest($method, 'http://localhost' . $path, $headers, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        // as PHP does for a real request, Guzzle leaves them empty
        parse_str($request->getUri()->getQuery(), $queryParams);
        return $this->browser->sendRequest($request->withQueryParams($queryParams));
    }

    /**
     * @return array<mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The browser runs all requests in one process: forget the previous request's authentication
     */
    private function resetAuthenticationState(): void
    {
        $this->inject($this->objectManager->get(AuthenticationManagerInterface::class), 'isAuthenticated', null);
        foreach ($this->objectManager->get(TokenAndProviderFactoryInterface::class)->getTokens() as $token) {
            if ($token instanceof BearerToken) {
                $this->inject($token, 'credentials', ['bearer' => '']);
            }
        }
    }
}
