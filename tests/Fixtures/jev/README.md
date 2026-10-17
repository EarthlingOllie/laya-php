# PLACEHOLDER: Jev OpenAPI document not fetched yet

`openapi.json` belongs in this directory, but it is **not here yet**. It could not be
downloaded: `api.typesafe.ai` was blocked by the network policy of every environment this
package was built in so far (HTTP 403 from the egress proxy, last tried on 2026-10-17 for #9).

Fetch it from a machine that can reach TypeSafe:

```sh
bin/fetch-jev-openapi   # saves tests/Fixtures/jev/openapi.json, only if it is a valid OpenAPI document
```

## What the contract tests use meanwhile

`tests/Contract/JevSchemaContractTest.php` checks every request body the codec encodes against
the request schema of `POST /v1/systemone` in Jev's document. `Tests\Support\JevSchema` reads
`openapi.json` here when it exists, and otherwise `provisional/openapi.json`: a **hand-written
stand-in, not TypeSafe's document**, marked `x-provisional` in its `info`. It describes only the
request body, from what is recorded: the request fields laya-ai.com documents, and the bodies
laya-serve 0.4.8 (which says it speaks Jev's protocol) accepted or refused in `../laya/`.

Once `openapi.json` is fetched, the tests switch to it with no other change. Then:

1. Run `composer test` and fix anything the real schema refuses (the path to the schema is
   `paths./v1/systemone.post.requestBody.content.application/json.schema`; if Jev keeps it
   elsewhere, update `JevSchema::REQUEST`).
2. Compare it with the recorded `laya-serve` fixtures in `../laya/` (and with
   `../laya/laya-serve-openapi.json`), note the differences in `docs/PLAN.md` (Jev's model
   names, its limits, its error shapes), and delete this file and `provisional/`.
