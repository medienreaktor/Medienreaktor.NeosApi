# Medienreaktor.NeosApi

**The missing HTTP API for Neos 9.** One package that turns the Event-Sourced Content Repository into a clean, OAuth-secured REST API — the foundation you need to build editing UIs, integrations, importers, headless clients and MCP servers against Neos. This is the API that powers [Neos Studio](https://github.com/medienreaktor/Medienreaktor.NeosStudio), a blazingly fast next-generation editing UI — and it is just as useful on its own.

No GraphQL ceremony, no coupling to the legacy backend, no community-package dependency chain. Standards-based OAuth 2.1, plain JSON over predictable routes, and the Content Repository's own security model enforced on every request:

- **OAuth 2.1 authentication** (authorization code + PKCE, refresh token rotation,
  client credentials, dynamic client registration, discovery metadata) built
  directly on `league/oauth2-server`
- **Read API** over the ContentGraph (nodes, relations, search, sites,
  workspaces, node types, dimensions, data sources) plus out-of-band
  **HTML fragment rendering** through the real Fusion pipeline
- **Write API** for content repository commands (single + batch) plus use-case
  operations (publish / discard / rebase workspaces)
- **Media API** for full asset management: assets, variants, tags, collections,
  asset sources, usage tracking

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
   `neos.publish`, `neos.media`) are enforced on top of the account's policies.

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

All responses are JSON (except `/render`, which returns HTML). `{nodeAddress}`
is a base64url-encoded NodeAddress (content repository + workspace + dimension
space point + aggregate id) — treat it as opaque; you obtain addresses from
`/api/sites` and node responses.

### Account

| Endpoint | Description |
|---|---|
| `GET /api/me` | Account, roles, scopes of this request |
| `GET /api/me/profile` | Own profile (name, email, interface language) |
| `PATCH /api/me/profile` | Update own profile |
| `PUT /api/me/password` | Change own password (requires the current password) |
| `GET /api/users` | Backend users (for ownership / attribution displays) |

### Content reads

| Endpoint | Description |
|---|---|
| `GET /api/sites` | Sites with their entry node addresses (`?workspace=`, `?dimensions=`) |
| `GET /api/nodes/{nodeAddress}` | A single node |
| `GET /api/nodes/{nodeAddress}/children` | Child nodes (`?nodeTypes=`, `?limit=`, `?offset=`) |
| `GET /api/nodes/{nodeAddress}/descendants` | Descendants (same filters, plus `?search=` fulltext, `?searchProperty=` to match a single property, `?breadcrumbs=` to include ancestor paths) |
| `GET /api/nodes/{nodeAddress}/ancestors` | Ancestors |
| `GET /api/nodes/{nodeAddress}/parent` | Parent node |
| `GET /api/nodes/{nodeAddress}/references` | Outgoing references incl. reference properties |
| `GET /api/nodes/{nodeAddress}/allowed-child-node-types` | Node types allowed below this node (constraints + auto-created children) |
| `GET /api/nodes/{nodeAddress}/variants` | Occupied + covered dimension space points of the aggregate |
| `GET /api/nodes/{nodeAddress}/uri-path-segment` | Build a URL path segment from `?text=` |
| `GET /api/nodes/{nodeAddress}/render` | HTML through the real Fusion pipeline (`?mode=` rendering mode, `?fusionPath=` for a single content fragment) |

Node reads include disabled ("hidden") nodes — this is an editing API. Pass
`?visibility=frontend` to preview what the public sees. Property values are
returned in serialized `{value, type}` form and round-trip with the command
payloads.

### Workspaces

| Endpoint | Description |
|---|---|
| `GET /api/workspaces` | Workspaces readable by this account, with permissions |
| `GET /api/workspaces/{name}` | One workspace incl. pending change count |
| `GET /api/workspaces/{name}/changes` | Pending changes (per node) |
| `GET /api/workspaces/{name}/document-changes` | Pending changes aggregated per document |
| `POST /api/workspaces/{name}/publish` | Publish all changes; body `{"site": "<id>"}` or `{"document": "<id>"}` for partial publish |
| `POST /api/workspaces/{name}/discard` | Discard (same filters) |
| `POST /api/workspaces/{name}/rebase` | Rebase (body `{"strategy": "force"}` optional) |
| `POST /api/workspaces/{name}/base-workspace` | Change the base workspace |

### Schema & data

| Endpoint | Description |
|---|---|
| `GET /api/nodetypes` / `GET /api/nodetypes/{name}` | Node type schema |
| `GET /api/dimensions` | Dimension config + allowed dimension space points |
| `GET /api/datasources/{identifier}` | Query a `DataSourceInterface` implementation (`?node=` node address, additional query params are passed through as arguments) |

### Commands (writes)

| Endpoint | Description |
|---|---|
| `POST /api/commands` | Execute one CR command: `{"type": "SetNodeProperties", "payload": {...}}` |
| `POST /api/commands/batch` | Execute a sequence: `{"commands": [...]}` |

Supported command types: see `Service/CommandRegistry.php` (all deserialized
via the commands' own `::fromArray()`), plus the synthetic
`CopyNodesRecursively` — recursive node copy is no CR command in Neos 9, so
the commands controller dispatches it to the `NodeDuplicationService` while
keeping the same envelope; pin the copy root's id via
`nodeAggregateIdMapping` to address the copy afterwards.

### Media

| Endpoint | Description |
|---|---|
| `GET /api/media/asset-sources` | Available asset sources |
| `GET /api/media/assets` | Browse/search assets (`?search=`, `?tag=`, `?collection=`, `?type=`, `?assetSource=`, sorting + pagination) |
| `POST /api/media/assets` | Upload a new asset (multipart) |
| `GET /api/media/assets/{source}/{id}` | One asset with metadata and variants |
| `PATCH /api/media/assets/{id}` | Update title, caption, copyright, tags, collections |
| `DELETE /api/media/assets/{id}` | Delete an asset |
| `POST /api/media/assets/import` | Import an asset from a remote asset source |
| `POST /api/media/assets/{id}/resource` | Replace the underlying resource (re-upload) |
| `POST /api/media/assets/{id}/variants` | Create a crop variant |
| `POST` / `DELETE /api/media/assets/{id}/tags` | Tag / untag an asset |
| `POST` / `DELETE /api/media/assets/{id}/collections` | Add to / remove from a collection |
| `GET /api/media/assets/{source}/{id}/usage` | Where an asset is used |
| `GET/POST /api/media/collections`, `PATCH/DELETE /api/media/collections/{id}` | Manage asset collections |
| `GET/POST /api/media/tags`, `PATCH/DELETE /api/media/tags/{id}` | Manage tags |

## License

Medienreaktor.NeosApi is free software, released under the [GNU General Public License, version 3 or later](LICENSE).

Copyright (C) 2026 medienreaktor GmbH

---

Built by [medienreaktor](https://www.medienreaktor.de) with ❤️ for the Neos community. Feedback, issues and plugin experiments very welcome — this is where the Neos editing experience is headed. Come shape it.
