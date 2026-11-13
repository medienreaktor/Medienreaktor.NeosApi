# Neos.Api

[![Packagist Version](https://img.shields.io/packagist/v/medienreaktor/neos-api)](https://packagist.org/packages/medienreaktor/neos-api)
[![PHP Version Support](https://img.shields.io/packagist/dependency-v/medienreaktor/neos-api/php)](https://www.php.net/)
[![CI](https://github.com/medienreaktor/Medienreaktor.NeosApi/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/medienreaktor/Medienreaktor.NeosApi/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The HTTP API for Neos 9 and the Content Repository, defined in PHP with [neos/openapi](https://github.com/neos/openapi) and secured with OAuth 2.

## Usage

Every operation is below `/api`. The OpenAPI document at `/api/openapi.json` describes each operation, its parameters,
responses and scopes. The optional Neos.Api.Docs package serves it as Swagger UI on `/api/docs`.

Clients send an access token as `Authorization: Bearer <token>`. Each scope (`nodes.read`,
`workspaces.publish`, …) is also a privilege in `Configuration/Policy.yaml`: a token works only if it was granted the
scope _and_ its account has the privilege. Neos' node, workspace and media privileges apply on top.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents. Lists come as `{data, meta, links}`;
`include`, `sort` and paging work as in [JSON:API](https://jsonapi.org).

## Upgrading from Medienreaktor.NeosApi 1.x

Tokens are issued by [Neos.OAuth](https://github.com/medienreaktor/Neos.OAuth) now, with keys of its own that it
generates on first use (`./flow oauth:status`, `./flow oauth:generatekeys`). Clients and tokens of 1.x don't carry
over. `./flow neosapi:generatekeys` is kept, deprecated, for deploy scripts that call it: it succeeds when the keys
exist and generates them otherwise.

## Development

`composer lint` runs `composer validate --strict`, PSR-12 and PHPStan level 8, without a baseline. The functional
tests extend Flow's `FunctionalTestCase` and need a whole Neos with Neos.Demo and a MariaDB or MySQL database (on
another database the content repository tests skip themselves). `.github/workflows/functional.yml` shows how CI runs
them in Neos' base distribution:

```bash
FLOW_CONTEXT=Testing bin/phpunit -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml Packages/Application/Neos.Api/Tests/Functional
```

## License

MIT, see [LICENSE](LICENSE).
