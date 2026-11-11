# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.2] - 2026-11-11

### Fixed

- **Node types without a package prefix no longer break the node type list.** The
  content repository accepts any node type name that isn't empty, the API accepted only
  `Vendor:Name`. A single node type configured without a prefix, such as `unstructured`
  from packages written for Neos 8, made `GET /cr/{contentRepositoryId}/nodetypes` fail
  with a 500 for every client. Node type names are now valid wherever the content
  repository takes them, in paths, filters and bodies alike.

### Changed

- `neos/openapi`, `neos/openapi-flowadapter`, `neos/schematic` and `neos/jsonschema` are
  no longer required on their own: `medienreaktor/neos-openapi-bundle`, which replaces
  them, already is.

## [2.0.1] - 2026-11-10

### Fixed

- `./flow neosapi:generatekeys` is back, deprecated, for deploy scripts written for 1.x.
  It succeeds when the OAuth keys exist and generates them otherwise.

## [2.0.0] - 2026-11-09

### Changed

- **Rewritten on neos/openapi**, described as OpenAPI at `/api/openapi.json`. Every
  operation is below `/api`, errors are RFC 9457 problem documents, lists come as
  `{data, meta, links}`.
- **Tokens are issued by Neos.OAuth**, with keys of its own. Clients and tokens of 1.x
  don't carry over.
- Requires Neos 9.2 and PHP 8.4.
- Licensed under MIT instead of GPL-3.0.

## [1.18.0] - 2026-10-30

### Added

- Upload links for clients that can only send JSON.

## [1.17.7] - 2026-10-12

### Added

- Confidential clients can register dynamically.

## [1.17.6] - 2026-10-07

### Fixed

- Publishing selected documents goes ancestors first, discarding them descendants first.

## [1.17.5] - 2026-09-28

Same code as [1.17.4].

## [1.17.4] - 2026-09-28

No changes to the API; the branch alias moves to 1.17.x-dev.

## [1.14.4] - 2026-09-25

### Fixed

- Workspace changes work with Neos 9.2.
- Creating a node keeps the ids given for its tethered child nodes.

## [1.14.3] - 2026-08-27

Same code as [1.14.1].

## [1.14.1] - 2026-08-27

No changes to the API; the branch alias moves to 1.14.x-dev.

## [1.13.0] - 2026-08-25

### Added

- Node read filters: packages can narrow the node listings the API serves.

## [1.12.1] - 2026-08-24

Same code as [1.12.0].

## [1.12.0] - 2026-08-24

No changes to the API; the branch alias moves to 1.12.x-dev.

## [1.11.2] - 2026-08-19

Same code as [1.11.0].

## [1.11.1] - 2026-08-19

Same code as [1.11.0].

## [1.11.0] - 2026-08-19

No changes to the API; the branch alias moves to 1.11.x-dev.

## [1.10.0] - 2026-08-19

### Added

- Creating a node runs its node type's `nodeCreationHandlers`, as the Neos UI does: node
  templates, promoted elements and the `uriPathSegment` work for every API client.

## [1.9.10] - 2026-08-18

Same code as [1.9.8].

## [1.9.9] - 2026-08-18

Same code as [1.9.8].

## [1.9.8] - 2026-08-18

### Fixed

- Out-of-band rendering re-enters at the last `ContentCase`, so a deep `fusionPath` no
  longer renders a stale resolution.

## [1.9.7] - 2026-08-18

Same code as [1.9.6].

## [1.9.6] - 2026-08-18

### Added

- The whole API is described as OpenAPI 3.1, at `/api/openapi.json` and `/api/docs`.

### Fixed

- The render endpoint answers `private, no-store`: Safari served `no-cache` fragments from
  its cache without revalidating.

## [1.9.5] - 2026-08-06

Same code as [1.9.0].

## [1.9.4] - 2026-08-06

Same code as [1.9.0].

## [1.9.3] - 2026-08-06

Same code as [1.9.0].

## [1.9.2] - 2026-08-06

Same code as [1.9.0].

## [1.9.1] - 2026-08-06

Same code as [1.9.0].

## [1.9.0] - 2026-08-06

### Added

- Free-form per-user preferences on `/me/profile`, merged on update.

## [1.8.0] - 2026-08-06

No changes to the API; the branch alias moves to 1.8.x-dev.

## [1.7.6] - 2026-07-29

Same code as [1.7].

## [1.7.5] - 2026-07-29

Same code as [1.7].

## [1.7.4] - 2026-07-29

Same code as [1.7].

## [1.7.3] - 2026-07-29

Same code as [1.7].

## [1.7] - 2026-07-29

### Added

- Packages can enrich the workspace JSON through registered enrichers.

## [1.6] - 2026-07-28

### Added

- Replacing an asset's resource takes options and checks what it is given.
- The trash bin can be asked for the deleted pages alone.

## [1.5] - 2026-07-27

### Added

- The workspace trash bin: what was deleted, and how to get it back.
- Changes tell a created node from a created dimension variant of an existing one.

### Fixed

- Soft-removed nodes are resolved in the workspace change resources.

## [1.4] - 2026-07-24

### Changed

- **Breaking: one convention per concern across the API.**
  - Single-object reads are wrapped like the rest: `{workspace}`, `{node}`, `{variants}`.
  - Deletes answer `{success: true}`; updates are `PATCH` only.
  - 400 means the request is invalid, 422 that the domain rejected a valid command.
  - No request bodies on `DELETE`: role unassignment, tag and collection membership
    are addressed as sub-resources.
  - Assets are written at the same two-segment address they are read at.
  - Paths are kebab-case: `/api/node-types`, `/api/data-sources`.
  - Failed batches report what ran and which command failed.
  - Every response is `Cache-Control: private, no-store` with `Vary: Authorization`.
- Dynamic client registration is off by default.
- Server bugs surface as 500 and are logged instead of being caught.

### Added

- CLI commands `prunetokens`, `revoketokens` and `policycoverage`.

## [1.3] - 2026-07-23

### Added

- The pending events of a workspace since it branched off, with their stream versions and
  a diff per event.
- The net document diff: what publishing a document would actually apply.

### Fixed

- Document changes count distinct nodes, not projection rows.

## [1.2.1] - 2026-07-22

### Added

- The node types listing can include properties and references.

## [1.2] - 2026-07-22

### Added

- Workspace management: create, update, delete, role assignments.
- Site administration: create, update, delete, domains, options.
- User administration: create, update, delete, the roles catalog.

### Fixed

- The workspaces permission flag, and the uniqueness check for subdomains.

## [1.1] - 2026-07-22

### Added

- Collaboration: the workspace event feed and a presence heartbeat.

## [1.0.2] - 2026-07-21

### Added

- Doctrine migrations for the OAuth tables, MySQL and PostgreSQL: `doctrine:migrate`
  instead of `doctrine:update` on a fresh install.

## [1.0.1] - 2026-07-21

Same code as [1.0].

## [1.0] - 2026-07-21

### Added

- The first release: an OAuth 2.1 authorization server (authorization code with PKCE,
  client credentials, consent, first-party clients) and a REST API below `/api` for
  nodes (read, search, descendants, breadcrumbs, rendering), commands (including a
  recursive copy), workspaces and their changes, node types, dimensions, sites, media,
  users, the user's own profile and password, and data sources. Every endpoint has a
  privilege of its own. Licensed under GPL-3.0.

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.2...HEAD
[2.0.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.1...2.0.2
[2.0.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.18.0...2.0.0
[1.18.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.17.7...1.18.0
[1.17.7]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.17.6...1.17.7
[1.17.6]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.17.5...1.17.6
[1.17.5]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.17.4...1.17.5
[1.17.4]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.14.4...1.17.4
[1.14.4]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.14.3...1.14.4
[1.14.3]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.14.1...1.14.3
[1.14.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.13.0...1.14.1
[1.13.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.12.1...1.13.0
[1.12.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.12.0...1.12.1
[1.12.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.11.2...1.12.0
[1.11.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.11.1...1.11.2
[1.11.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.11.0...1.11.1
[1.11.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.10.0...1.11.0
[1.10.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.10...1.10.0
[1.9.10]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.9...1.9.10
[1.9.9]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.8...1.9.9
[1.9.8]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.7...1.9.8
[1.9.7]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.6...1.9.7
[1.9.6]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.5...1.9.6
[1.9.5]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.4...1.9.5
[1.9.4]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.3...1.9.4
[1.9.3]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.2...1.9.3
[1.9.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.1...1.9.2
[1.9.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.9.0...1.9.1
[1.9.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.8.0...1.9.0
[1.8.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.7.6...1.8.0
[1.7.6]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.7.5...1.7.6
[1.7.5]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.7.4...1.7.5
[1.7.4]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.7.3...1.7.4
[1.7.3]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.7...1.7.3
[1.7]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.6...1.7
[1.6]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.5...1.6
[1.5]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.4...1.5
[1.4]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.3...1.4
[1.3]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.2.1...1.3
[1.2.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.2...1.2.1
[1.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.1...1.2
[1.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.0.2...1.1
[1.0.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/1.0...1.0.1
[1.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/releases/tag/1.0
