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

## OAuth endpoints

| Endpoint                                      | Purpose                                                          |
| --------------------------------------------- | ---------------------------------------------------------------- |
| `GET/POST /oauth/authorize`                   | Authorization + consent (requires a logged-in Neos backend user) |
| `POST /oauth/token`                           | Token endpoint (auth code + PKCE, refresh, client credentials)   |
| `POST /oauth/register`                        | Dynamic client registration, RFC 7591 (public clients only)      |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata                                                |
| `GET /.well-known/oauth-protected-resource`   | RFC 9728 metadata                                                |

## API endpoints

All responses are JSON (except `/render`, which returns HTML). `{nodeAddress}`
is a base64url-encoded NodeAddress (content repository + workspace + dimension
space point + aggregate id) — treat it as opaque; you obtain addresses from
`/api/sites` and node responses.

### Account

| Endpoint                | Description                                         |
| ----------------------- | --------------------------------------------------- |
| `GET /api/me`           | Account, roles, scopes of this request              |
| `GET /api/me/profile`   | Own profile (name, email, interface language)       |
| `PATCH /api/me/profile` | Update own profile                                  |
| `PUT /api/me/password`  | Change own password (requires the current password) |

### Users

Listing users is available to every editor (for ownership / attribution
displays); creating, updating and deleting is user administration. Updates
guard against self-lockout: you cannot deactivate or delete your own user or
drop your own Administrator role.

| Endpoint                     | Description                                                                                                                                                 |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET /api/users`             | Backend users with their accounts and roles                                                                                                                  |
| `POST /api/users`            | Create a user: `{"username", "password", "firstName", "lastName", "roles"?, "email"?}`                                                                       |
| `GET /api/users/roles`       | Assignable roles (every non-abstract role known to the policy framework)                                                                                     |
| `GET /api/users/{userId}`    | One user                                                                                                                                                     |
| `PATCH /api/users/{userId}`  | Partial update: `firstName`, `lastName`, `email` (empty string removes it), `roles` (replaces), `active`, `password` (administrative reset, no old password) |
| `DELETE /api/users/{userId}` | Delete a user incl. accounts and personal workspaces                                                                                                         |

### Content reads

| Endpoint                                                | Description                                                                                                                                    |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/sites`                                        | Sites with their entry node addresses (`?workspace=`, `?dimensions=`)                                                                          |
| `GET /api/nodes/{nodeAddress}`                          | A single node                                                                                                                                  |
| `GET /api/nodes/{nodeAddress}/children`                 | Child nodes (`?nodeTypes=`, `?limit=`, `?offset=`)                                                                                             |
| `GET /api/nodes/{nodeAddress}/descendants`              | Descendants (same filters, plus `?search=` fulltext, `?searchProperty=` to match a single property, `?breadcrumbs=` to include ancestor paths) |
| `GET /api/nodes/{nodeAddress}/ancestors`                | Ancestors                                                                                                                                      |
| `GET /api/nodes/{nodeAddress}/parent`                   | Parent node                                                                                                                                    |
| `GET /api/nodes/{nodeAddress}/references`               | Outgoing references incl. reference properties                                                                                                 |
| `GET /api/nodes/{nodeAddress}/allowed-child-node-types` | Node types allowed below this node (constraints + auto-created children)                                                                       |
| `GET /api/nodes/{nodeAddress}/variants`                 | Occupied + covered dimension space points of the aggregate                                                                                     |
| `GET /api/nodes/{nodeAddress}/uri-path-segment`         | Build a URL path segment from `?text=`                                                                                                         |
| `GET /api/nodes/{nodeAddress}/render`                   | HTML through the real Fusion pipeline (`?mode=` rendering mode, `?fusionPath=` for a single content fragment)                                  |

Node reads include disabled ("hidden") nodes — this is an editing API. Pass
`?visibility=frontend` to preview what the public sees. Property values are
returned in serialized `{value, type}` form and round-trip with the command
payloads.

### Sites administration

`{siteNodeName}` is the site's node name as returned by `GET /api/sites`.

| Endpoint                                                | Description                                                                                                       |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `GET /api/sites/options`                                | Site creation options: installed site packages + node types usable for a site node                                  |
| `POST /api/sites`                                       | Create a site with a fresh site node in live: `{"packageKey", "name", "nodeTypeName", "nodeName"?, "inactive"?}`    |
| `PATCH /api/sites/{siteNodeName}`                       | Partial update: `name`, `state` (`"online"`/`"offline"`), `primaryDomainId` (empty string = first active domain)    |
| `DELETE /api/sites/{siteNodeName}`                      | Delete a site incl. content, domains and asset collection (the classic module's "prune")                            |
| `POST /api/sites/{siteNodeName}/domains`                | Add a domain: `{"hostname", "scheme"?, "port"?, "active"?}`                                                          |
| `PATCH /api/sites/{siteNodeName}/domains/{domainId}`    | Partial domain update: `hostname`, `scheme` (empty string clears), `port` (`0` clears), `active`                     |
| `DELETE /api/sites/{siteNodeName}/domains/{domainId}`   | Remove a domain (an explicit primary falls back to the first active domain)                                          |

### Workspaces

| Endpoint                                      | Description                                                                                |
| --------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `GET /api/workspaces`                         | Workspaces readable by this account, with permissions                                      |
| `POST /api/workspaces`                        | Create a workspace: `{"title", "description"?, "baseWorkspaceName"?, "visibility"?}` — visibility `"shared"` (default, every editor may collaborate) or `"private"` |
| `GET /api/workspaces/{name}`                  | One workspace incl. pending change count                                                   |
| `PATCH /api/workspaces/{name}`                | Update title and/or description (requires the manage permission)                           |
| `DELETE /api/workspaces/{name}`               | Delete a workspace incl. metadata and role assignments; pending changes block deletion unless `{"force": true}` |
| `GET /api/workspaces/{name}/changes`          | Pending changes (per node)                                                                 |
| `GET /api/workspaces/{name}/document-changes` | Pending changes aggregated per document (counts distinct changed nodes)                    |
| `GET /api/workspaces/{name}/document-diff`    | Net diff of one document against the base workspace (`?documentAggregateId=`)              |
| `GET /api/workspaces/{name}/pending-events`   | The workspace's pending history: every event since it forked off its base                  |
| `GET /api/workspaces/{name}/pending-events/diff` | Before/after detail for a slice of the pending history (`?from=`, `?to=`)               |
| `POST /api/workspaces/{name}/publish`         | Publish all changes; body `{"site": "<id>"}` or `{"document": "<id>"}` for partial publish |
| `POST /api/workspaces/{name}/discard`         | Discard (same filters)                                                                     |
| `POST /api/workspaces/{name}/rebase`          | Rebase (body `{"strategy": "force"}` optional)                                             |
| `POST /api/workspaces/{name}/base-workspace`  | Change the base workspace                                                                  |

Two complementary views answer "what changed here":

- **`document-diff`** compares **state**: each changed node's current
  properties, references, type, name, parent and visibility against the base
  workspace's version — what publishing the document would actually apply.
  Five edits of the same text arrive squashed into one old → new row.
- **`pending-events`** replays **history**: the events recorded in the
  workspace's current content stream, which exists exactly since the last
  publish/discard/rebase forked it off the base — so this is every change
  since the branch point, oldest first, enriched with the affected node's
  label/type/icon and the initiating user. The response includes the fork
  point (`forkedFrom`: base content stream + version — base events above that
  version are what makes the workspace `OUTDATED`) and returns the newest 100
  events (`truncated: true` when older ones were dropped). Consecutive events
  of one command form a contiguous sequence-number range; feed such a range to
  **`pending-events/diff`** (span < 200) to get per-property old/new values,
  old/new reference targets, node type, parent and visibility per event. "Old"
  values are resolved by scanning the same stream backwards, falling back to
  what the base workspace holds now.

Role assignments control who may view, collaborate in or manage a workspace.
All three endpoints require the manage permission (owner, manager role or
administrator); a subject holds at most one role at a time.

| Endpoint                             | Description                                                                                                             |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| `GET /api/workspaces/{name}/roles`   | The workspace's role assignments                                                                                          |
| `POST /api/workspaces/{name}/roles`  | Assign a role: `{"subjectType": "USER"\|"GROUP", "subject": <user id / Flow role identifier>, "role": "VIEWER"\|"COLLABORATOR"\|"MANAGER"}` |
| `DELETE /api/workspaces/{name}/roles`| Remove an assignment: `{"subjectType", "subject"}`                                                                        |

### Collaborative editing

Two polling endpoints make shared-workspace multiplayer work over plain HTTP —
no WebSocket server to deploy. Clients editing a shared workspace poll both
every 1–2 seconds.

| Endpoint                               | Description                                                                                                          |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `GET /api/workspaces/{name}/events`     | Change feed: everything that happened in the workspace since the client's cursor (`?stream=` content stream id, `?since=` last seen sequence number) |
| `POST /api/workspaces/{name}/presence`  | Presence heartbeat: announce your position and get everyone currently present back in one call                          |

The **event feed** is cursor-based: call it without parameters once to obtain
the baseline (`contentStreamId` + `sequenceNumber`), then pass both back as
`?stream=` and `?since=`. When the workspace has moved to a different content
stream in the meantime (publish, discard and rebase fork streams) the response
sets `reset: true` and enumerates no events — refresh everything and continue
from the new cursor. A full page sets `truncated: true` with the same client
remedy.

The **presence heartbeat** takes a JSON body of
`{"documentAggregateId"?, "focusedAggregateId"?, "dimensionSpacePoint"?}` and
answers with all users currently in the workspace, so one poll both announces
and observes. Entries expire 30 seconds after the last beat (a closed tab
disappears on its own); `{"leave": true}` removes the own entry immediately.
Presence is deliberately ephemeral cache state and requires a user-bound token
(`client_credentials` clients get a 403).

### Schema & data

| Endpoint                                           | Description                                                                                                                   |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/nodetypes` / `GET /api/nodetypes/{name}` | Node type schema (`?includeProperties=1` on the listing also serializes each type's merged property + reference declarations) |
| `GET /api/dimensions`                              | Dimension config + allowed dimension space points                                                                             |
| `GET /api/datasources/{identifier}`                | Query a `DataSourceInterface` implementation (`?node=` node address, additional query params are passed through as arguments) |

### Commands (writes)

| Endpoint                   | Description                                                               |
| -------------------------- | ------------------------------------------------------------------------- |
| `POST /api/commands`       | Execute one CR command: `{"type": "SetNodeProperties", "payload": {...}}` |
| `POST /api/commands/batch` | Execute a sequence: `{"commands": [...]}`                                 |

Supported command types: see `Service/CommandRegistry.php` (all deserialized
via the commands' own `::fromArray()`), plus the synthetic
`CopyNodesRecursively` — recursive node copy is no CR command in Neos 9, so
the commands controller dispatches it to the `NodeDuplicationService` while
keeping the same envelope; pin the copy root's id via
`nodeAggregateIdMapping` to address the copy afterwards.

### Media

| Endpoint                                                                      | Description                                                                                                 |
| ----------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `GET /api/media/asset-sources`                                                | Available asset sources                                                                                     |
| `GET /api/media/assets`                                                       | Browse/search assets (`?search=`, `?tag=`, `?collection=`, `?type=`, `?assetSource=`, sorting + pagination) |
| `POST /api/media/assets`                                                      | Upload a new asset (multipart)                                                                              |
| `GET /api/media/assets/{source}/{id}`                                         | One asset with metadata and variants                                                                        |
| `PATCH /api/media/assets/{id}`                                                | Update title, caption, copyright, tags, collections                                                         |
| `DELETE /api/media/assets/{id}`                                               | Delete an asset                                                                                             |
| `POST /api/media/assets/import`                                               | Import an asset from a remote asset source                                                                  |
| `POST /api/media/assets/{id}/resource`                                        | Replace the underlying resource (re-upload)                                                                 |
| `POST /api/media/assets/{id}/variants`                                        | Create a crop variant                                                                                       |
| `POST` / `DELETE /api/media/assets/{id}/tags`                                 | Tag / untag an asset                                                                                        |
| `POST` / `DELETE /api/media/assets/{id}/collections`                          | Add to / remove from a collection                                                                           |
| `GET /api/media/assets/{source}/{id}/usage`                                   | Where an asset is used                                                                                      |
| `GET/POST /api/media/collections`, `PATCH/DELETE /api/media/collections/{id}` | Manage asset collections                                                                                    |
| `GET/POST /api/media/tags`, `PATCH/DELETE /api/media/tags/{id}`               | Manage tags                                                                                                 |

## License

Medienreaktor.NeosApi is free software, released under the [GNU General Public License, version 3 or later](LICENSE).

Copyright (C) 2026 medienreaktor GmbH

---

Built by [medienreaktor](https://www.medienreaktor.de) with ❤️ for the Neos community. Feedback, issues and plugin experiments very welcome — this is where the Neos editing experience is headed. Come shape it.
