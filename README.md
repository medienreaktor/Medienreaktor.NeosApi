# Medienreaktor.NeosApi

A unified HTTP API for Neos 9 in one package:

- **OAuth 2.1 authentication** (authorization code + PKCE, refresh token rotation,
  client credentials, dynamic client registration, discovery metadata) built
  directly on `league/oauth2-server`
- **Read API** over the ContentGraph (nodes, relations, sites, workspaces,
  node types, dimensions)
- **Write API** for content repository commands plus use-case operations
  (publish / discard / rebase workspaces)

Requires Neos `^9.1` and PHP `^8.2`. No dependencies on community packages —
only Neos core and framework-agnostic libraries.

## Security model

1. **Bearer token → Flow account.** Every `/api` request authenticates via
   `Authorization: Bearer <token>`. The provider validates the JWT and hydrates
   the **Flow account** of the user who approved the token (or the mapped
   account for `client_credentials`). From then on the request has the same
   roles and policies as an interactive backend session.
2. **Deny-by-default endpoint policy.** A catch-all privilege target matches
   every action in `Controller\Api`; unmatched-by-a-grant means denied. Grants
   go to the standard Neos roles (see `Configuration/Policy.yaml`).
3. **Structural content authorization.** All reads run through
   `ContentRepository::getContentSubgraph()` (the account's visibility
   constraints are applied to every query — hidden/disabled nodes are visible,
   permission-restricted subtrees are not). All commands run through
   `ContentRepository::handle()` which checks workspace permissions and
   `EditNodePrivilege` centrally.
4. **Scopes narrow, never widen.** Token scopes (`neos.read`, `neos.write`,
   `neos.publish`) are enforced on top of the account's policies.

## Setup

```sh
composer require medienreaktor/neos-api

# create the database tables for OAuth clients and token records
./flow doctrine:update          # dev; use doctrine:migrationgenerate + migrate for production

# generate the OAuth signing / encryption keys
./flow neosapi:generatekeys

# register a client
./flow neosapi:createclient --identifier my-app --name "My App" \
  --redirect-uris "https://my-app.example/callback"

# machine-to-machine client (client_credentials), bound to an existing Neos account
./flow neosapi:createclient --identifier importer --name "Importer" \
  --grant-types client_credentials --confidential
```

For `client_credentials` clients, map the client to the Flow account whose
roles it should act with:

```yaml
Medienreaktor:
  NeosApi:
    oauth:
      clientCredentialsAccounts:
        'importer': 'importer@example.com'
```

## OAuth endpoints

| Endpoint | Purpose |
|---|---|
| `GET/POST /oauth/authorize` | Authorization + consent (requires a logged-in Neos backend user) |
| `POST /oauth/token` | Token endpoint (auth code + PKCE, refresh, client credentials) |
| `POST /oauth/register` | Dynamic client registration, RFC 7591 (public clients only) |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata |

## API endpoints

All responses are JSON. `{nodeAddress}` is a base64url-encoded NodeAddress
(content repository + workspace + dimension space point + aggregate id) —
treat it as opaque; you obtain addresses from `/api/sites` and node responses.

| Endpoint | Description |
|---|---|
| `GET /api/me` | Account, roles, scopes of this request |
| `GET /api/sites` | Sites with their entry node addresses (`?workspace=`, `?dimensions=`) |
| `GET /api/nodes/{nodeAddress}` | A single node |
| `GET /api/nodes/{nodeAddress}/children` | Child nodes (`?nodeTypes=`, `?limit=`, `?offset=`) |
| `GET /api/nodes/{nodeAddress}/descendants` | Descendants (same filters) |
| `GET /api/nodes/{nodeAddress}/ancestors` | Ancestors |
| `GET /api/nodes/{nodeAddress}/parent` | Parent node |
| `GET /api/nodes/{nodeAddress}/references` | Outgoing references |
| `GET /api/workspaces` | Workspaces readable by this account, with permissions |
| `GET /api/workspaces/{name}` | One workspace incl. pending change count |
| `POST /api/workspaces/{name}/publish` | Publish all changes; body `{"site": "<id>"}` or `{"document": "<id>"}` for partial publish |
| `POST /api/workspaces/{name}/discard` | Discard (same filters) |
| `POST /api/workspaces/{name}/rebase` | Rebase (body `{"strategy": "force"}` optional) |
| `GET /api/nodetypes` / `GET /api/nodetypes/{name}` | Node type schema |
| `GET /api/dimensions` | Dimension config + allowed dimension space points |
| `POST /api/commands` | Execute one CR command: `{"type": "SetNodeProperties", "payload": {...}}` |
| `POST /api/commands/batch` | Execute a sequence: `{"commands": [...]}` |

Node reads include disabled ("hidden") nodes — this is an editing API. Pass
`?visibility=frontend` to preview what the public sees. Property values are
returned in serialized `{value, type}` form and round-trip with the command
payloads.

Supported command types: see `Service/CommandRegistry.php` (all deserialized
via the commands' own `::fromArray()`).

## Roadmap / TODO

- OpenAPI 3.1 specification + generated TypeScript client
- ETag / conditional GET based on content stream versions
- Command idempotency keys
- Change-notification channel (SSE) for cache invalidation
- Rendering/preview endpoints (out-of-band rendering stays Fusion-side)
- Consent screen as proper Fusion/Fluid view, token revocation endpoint (RFC 7009)
