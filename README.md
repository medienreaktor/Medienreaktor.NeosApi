# Medienreaktor.NeosApi

**The missing HTTP API for Neos 9.** One package that turns the Event-Sourced Content Repository into a clean, OAuth-secured REST API — the foundation you need to build editing UIs, integrations, importers, headless clients and MCP servers against Neos. This is the API that powers [Neos Studio](https://github.com/medienreaktor/Medienreaktor.NeosStudio), a blazingly fast next-generation editing UI — and it is just as useful on its own.

No GraphQL ceremony, no coupling to the legacy backend, no community-package dependency chain. Standards-based OAuth 2.1, plain JSON over predictable routes, and the Content Repository's own security model enforced on every request:

- **OAuth 2.1 authentication** (authorization code + PKCE, refresh token rotation,
  client credentials, dynamic client registration, discovery metadata) built
  directly on `league/oauth2-server`
- **Read API** over the ContentGraph (nodes, relations, search, sites,
  workspaces, node types, dimensions, data sources) plus out-of-band
  **HTML fragment rendering** through the real Fusion pipeline
- **Change review** — pending changes per node and per document, net
  document diffs against the base workspace, and the pending event history
  since the branch point with per-event before/after detail
- **Write API** for content repository commands (single + batch) plus use-case
  operations (publish / discard / rebase workspaces)
- **Media API** for full asset management: assets, variants, tags, collections,
  asset sources, usage tracking
- **Administration API** for users (incl. roles, activation, password resets),
  sites + domains, and workspaces (incl. role assignments)
- **Collaboration primitives** — a per-workspace event feed and presence
  heartbeats over plain HTTP polling, the transport behind Studio's
  multiplayer editing

Requires Neos `^9.1` and PHP `^8.2`. No dependencies on community packages —
only Neos core and framework-agnostic libraries.

## Security model

1. **Bearer token → Flow account.** Every `/api` request authenticates via
   `Authorization: Bearer <token>`. The provider validates the JWT and hydrates
   the **Flow account** of the user who approved the token (or the mapped
   account for `client_credentials`). From then on the request has the same
   roles and policies as an interactive backend session.
2. **Feature-based endpoint policy.** Every action in `Controller\Api` is
   matched by a privilege target that names one capability of the API (read
   nodes, write content, manage media, publish workspaces, …), split by
   operation where a resource exposes both reads and writes. The standard
   Neos roles are granted these features (see `Configuration/Policy.yaml`).
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
./flow doctrine:migrate

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

### Housekeeping

Every issued token leaves a lifecycle record; prune expired ones periodically
(e.g. via cron), and revoke active tokens when a client or account is
compromised:

```sh
# delete expired token records (safe: an expired token stays dead)
./flow neosapi:prunetokens

# revoke all active tokens of a client and/or account, effective immediately
./flow neosapi:revoketokens --client my-app
./flow neosapi:revoketokens --account editor@example.com
```

### Dynamic client registration

`POST /oauth/register` (RFC 7591) is **disabled by default** — it is an
unauthenticated endpoint, so leaving it open in production invites anonymous
client creation. The Development context enables it for local MCP-client
onboarding; to offer it in production, opt in deliberately:

```yaml
Medienreaktor:
  NeosApi:
    oauth:
      dynamicClientRegistration:
        enabled: true
```

## API documentation

The API's contract — every endpoint (including the OAuth protocol endpoints),
schema, error code and scope — is the hand-maintained OpenAPI 3.1 document at
[`Resources/Private/OpenApi/openapi.yaml`](Resources/Private/OpenApi/openapi.yaml).
Browse it:

- **[Hosted API reference](https://medienreaktor.github.io/Medienreaktor.NeosApi/)** —
  rebuilt from `main` by the `API docs` workflow (GitHub Actions → Pages).
- **On any installation:** `GET /api/docs` renders the same reference
  (self-hosted Scalar, no CDN), backed by `GET /api/openapi.json`, which
  serves the document with the server URL, OAuth endpoint URLs and scope
  catalog stamped in. Both are public, like the OAuth discovery documents.
- **The raw document** is the input for typed client generation (e.g.
  `openapi-typescript`) and response validation in tests.

Keep the document in sync with `Routes.yaml` and the controllers — the same
discipline as `Policy.yaml`. CI enforces it on every push: the document is
linted, and the build fails when a route and its documented operations
diverge.

## Concepts

The conventions behind the endpoint reference:

- **Node addressing.** `{nodeAddress}` is a base64url-encoded NodeAddress
  (content repository + workspace + dimension space point + aggregate id) —
  treat it as opaque. You obtain addresses from `/api/sites` and node
  responses.
- **Editing visibility.** Node reads include disabled ("hidden") nodes — this
  is an editing API. Pass `?visibility=frontend` to preview what the public
  sees. Property values are returned in serialized `{value, type}` form and
  round-trip with the command payloads.
- **Writes are commands.** `POST /api/commands` executes one content
  repository command as `{"type": ..., "payload": ...}`;
  `POST /api/commands/batch` runs an ordered sequence, stopping at the first
  failure without rolling back. Node aggregate ids are client-supplied —
  generate one and keep it. Recursive copy is the synthetic
  `CopyNodesRecursively` command.
- **Node creation runs the creation handlers.** A `CreateNodeAggregateWithNode`
  executes the node type's configured `options.nodeCreationHandlers` (the same
  seam the classic UI uses — this is what makes Flowpack.NodeTemplates and
  promoted creation-dialog elements work through the API). Creation-dialog
  element values can be passed in the payload under the transport-only
  `elements` key `{"elements": {"title": "...", ...}}`. Property values set
  explicitly in `initialPropertyValues` always win over handler output.
- **Deleting is a soft removal.** Use `TagSubtree` with `{"tag": "removed"}`:
  the node stays in the graph as a reviewable, publishable pending change, and
  live erases it once the deletion is published. Deleted nodes are invisible
  to reads unless a client opts in with `?includeDeleted=1`; the per-workspace
  **trash bin** (`GET /api/workspaces/{name}/trash` + `/restore`) lists and
  undoes deletions.
- **Two views of "what changed".** `document-diff` compares *state* against
  the base workspace (what publishing would apply, squashed old → new);
  `pending-events` replays *history* since the workspace forked off its base,
  with `pending-events/diff` adding per-event before/after detail.
- **Collaboration is plain polling.** The per-workspace event feed
  (`/events`, cursor-based via `?stream=` + `?since=`) and presence heartbeats
  (`/presence`, entries expire after 30 seconds) power multiplayer editing
  over HTTP — no WebSocket server to deploy.

## License

Medienreaktor.NeosApi is free software, released under the [GNU General Public License, version 3 or later](LICENSE).

Copyright (C) 2026 medienreaktor GmbH

---

Built by [medienreaktor](https://www.medienreaktor.de) with ❤️ for the Neos community. Feedback, issues and plugin experiments very welcome — this is where the Neos editing experience is headed. Come shape it.
