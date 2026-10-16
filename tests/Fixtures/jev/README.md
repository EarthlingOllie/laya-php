# PLACEHOLDER: Jev OpenAPI document not fetched yet

`openapi.json` belongs in this directory, but it is **not here yet**. It could not be
downloaded when the Laya fixtures were recorded: `api.typesafe.ai` was blocked by the
recording environment's network policy (HTTP 403 from its egress proxy).

Nothing in this directory is a stand-in for the real document. Fetch it from a machine that
can reach TypeSafe:

```sh
bin/fetch-jev-openapi   # saves tests/Fixtures/jev/openapi.json, only if it is a valid OpenAPI document
```

Then compare it with the recorded `laya-serve` fixtures in `../laya/` (and with
`../laya/laya-serve-openapi.json`), note the differences in `docs/PLAN.md`, and delete this file.
