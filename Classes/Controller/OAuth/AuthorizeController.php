<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\OAuth;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Medienreaktor\NeosApi\Security\OAuth\AuthorizationServerFactory;
use Medienreaktor\NeosApi\Security\OAuth\Entity\ClientEntity;
use Medienreaktor\NeosApi\Security\OAuth\Entity\UserEntity;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Security\Exception\AuthenticationRequiredException;

/**
 * The OAuth 2.1 authorization endpoint with a minimal consent screen.
 *
 * Requires a logged-in Neos backend user: the "Neos.Neos:Backend" session is
 * made visible to this controller via an additional request pattern (see
 * Settings.yaml), so the editor who is logged into the Neos backend in this
 * browser is the resource owner who approves the authorization.
 */
class AuthorizeController extends AbstractOAuthController
{
    #[Flow\Inject]
    protected AuthorizationServerFactory $serverFactory;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * @var array<string, string>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth.scopes')]
    protected array $scopeDescriptions = [];

    public function authorizeAction(): string
    {
        $account = $this->securityContext->getAccount();
        if ($account === null) {
            // Redirect to the Neos backend login via the WebRedirect entry
            // point; the intercepted request resumes here (with all OAuth
            // parameters, see appendExceedingArguments in Routes.yaml) after
            // a successful login.
            throw (new AuthenticationRequiredException('Login is required to authorize an application.', 1751980040))
                ->attachInterceptedRequest($this->request);
        }

        try {
            $authRequest = $this->serverFactory->getAuthorizationServer()->validateAuthorizationRequest($this->request->getHttpRequest());
        } catch (OAuthServerException $exception) {
            return $this->applyPsr7Response($exception->generateHttpResponse(new Response()));
        }

        $client = $authRequest->getClient();

        // First-party clients (e.g. the Neos Studio UI) are trusted: skip the
        // consent screen and grant immediately for the logged-in user. The
        // grant is still bound to the account's roles and the requested scopes.
        if ($client instanceof ClientEntity && $client->isFirstParty()) {
            $authRequest->setUser(UserEntity::fromAccountIdentifier($account->getAccountIdentifier()));
            $authRequest->setAuthorizationApproved(true);

            return $this->applyPsr7Response(
                $this->serverFactory->getAuthorizationServer()->completeAuthorizationRequest($authRequest, new Response())
            );
        }
        $scopeList = '';
        foreach ($authRequest->getScopes() as $scope) {
            $scopeList .= '<li><code>' . htmlspecialchars($scope->getIdentifier()) . '</code> &mdash; '
                . htmlspecialchars($this->scopeDescriptions[$scope->getIdentifier()] ?? '') . '</li>';
        }

        $hiddenFields = '';
        foreach ($this->request->getHttpRequest()->getQueryParams() as $name => $value) {
            if (is_string($value)) {
                $hiddenFields .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '"/>';
            }
        }
        $csrfToken = htmlspecialchars($this->securityContext->getCsrfProtectionToken());

        return $this->renderPage(
            'Authorize application',
            '<p><strong>' . htmlspecialchars($client->getName()) . '</strong> requests access to the Neos API'
            . ' as <strong>' . htmlspecialchars($account->getAccountIdentifier()) . '</strong> with the following scopes:</p>'
            . '<ul>' . $scopeList . '</ul>'
            . '<p>The application can never do more than your own user is permitted to do.</p>'
            . '<form method="post" action="' . htmlspecialchars($this->getBaseUri() . '/oauth/authorize') . '">'
            . $hiddenFields
            . '<input type="hidden" name="__csrfToken" value="' . $csrfToken . '"/>'
            . '<button type="submit" name="decision" value="approve">Approve</button> '
            . '<button type="submit" name="decision" value="deny">Deny</button>'
            . '</form>'
        );
    }

    public function approveAction(): string
    {
        $account = $this->securityContext->getAccount();
        if ($account === null) {
            $this->response->setStatusCode(401);

            return $this->renderPage('Login required', '<p>Your Neos backend session expired during authorization. Please log in and retry.</p>');
        }

        $parsedBody = (array)$this->request->getHttpRequest()->getParsedBody();

        // Re-validate the authorization request from the submitted (formerly query) parameters
        $syntheticRequest = (new ServerRequest('GET', $this->getBaseUri() . '/oauth/authorize'))
            ->withQueryParams($parsedBody);

        try {
            $authRequest = $this->serverFactory->getAuthorizationServer()->validateAuthorizationRequest($syntheticRequest);
            $authRequest->setUser(UserEntity::fromAccountIdentifier($account->getAccountIdentifier()));
            $authRequest->setAuthorizationApproved(($parsedBody['decision'] ?? '') === 'approve');

            $psrResponse = $this->serverFactory->getAuthorizationServer()->completeAuthorizationRequest($authRequest, new Response());

            return $this->applyPsr7Response($psrResponse);
        } catch (OAuthServerException $exception) {
            return $this->applyPsr7Response($exception->generateHttpResponse(new Response()));
        }
    }

    private function renderPage(string $title, string $body): string
    {
        $this->response->setContentType('text/html');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;line-height:1.5}button{padding:.5rem 1.5rem;font-size:1rem}</style>'
            . '</head><body><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
    }
}
