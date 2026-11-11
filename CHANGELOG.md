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

Releases before 2.0.0 are described by their commits.

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.2...HEAD
[2.0.2]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.1...2.0.2
[2.0.1]: https://github.com/medienreaktor/Medienreaktor.NeosApi/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/medienreaktor/Medienreaktor.NeosApi/releases/tag/2.0.0
