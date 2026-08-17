# Medienreaktor.NeosApi — development notes

## The OpenAPI document is part of every endpoint change

`Resources/Private/OpenApi/openapi.yaml` is the hand-maintained API contract.
Any change to `Configuration/Routes.yaml`, a `Controller/Api` action's
parameters/body/response, a serializer's output shape, an error code, or a
required scope is INCOMPLETE until the document reflects it. Treat it like
`Configuration/Policy.yaml`: routes, policy and spec move together in the
same commit.

Verify before committing:

```sh
# structural validity
npx --yes @redocly/cli@latest lint Resources/Private/OpenApi/openapi.yaml

# every route documented, no phantom operations (needs PyYAML)
python3 Tests/check-openapi-sync.py
```

CI (`.github/workflows/api-docs.yml`) runs both on every push/PR and
publishes the reference to GitHub Pages from `main`. Note the sync check only
covers method+path pairs — request/response schema drift is on you: when you
change what a serializer emits or what an action reads, update the matching
`components/schemas` entry.

## Serving details worth knowing

- `GET /api/openapi.json` (`DocsController::specAction`) stamps `servers`,
  the OAuth flow URLs and the scope catalog at serve time — keep the YAML
  host-agnostic.
- `GET /api/docs` renders via the Fluid template
  `Resources/Private/Templates/Api/Docs/Docs.html`. Scalar mounts
  declaratively on `data-url`/`data-configuration` — do NOT add inline
  `<script>` initialization (Fluid parses `{...}` in inline JS).
- The Scalar bundle is committed and pinned
  (`Resources/Public/Docs/scalar-api-reference-<version>.min.js`). To upgrade:
  download the new `dist/browser/standalone.min.js` from npm, commit it under
  the new versioned name, update `DocsController::SCALAR_RESOURCE` and the
  Pages workflow's `cp` glob still matches.

## General package conventions

- Policy discipline: every new `Controller\Api` action must be matched by a
  privilege target in `Policy.yaml` (Flow treats unmatched methods as open;
  the Neos catch-all then denies them - either way, be explicit).
- Load resources via the `resource://` stream wrapper, never `__DIR__`
  (Flow proxy classes resolve to the cache directory).
- E2E testing runs against ddev; smoke tests live in `Tests/*.sh`.
