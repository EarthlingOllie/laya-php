# PROVISIONAL fixtures: real server shape, meaningless values

**Do not read any number in here as a model answer.** These files were recorded with
`bin/laya-record-fixtures` from the real `laya-serve` 0.4.8 image (`docker/laya`), but the
real english weights could not be downloaded (the Hugging Face weights CDN was blocked in the
recording environment). The server's built-in `english` name was re-pointed, through
`LAYA_EXTRA_MODELS`, at a checkpoint built from the published english config with **random
weights**. So:

- every field name, nesting, type and status code is the server's own;
- every probability, confidence and `act_probability` is noise (most noul answers are 0.5);
- `routing.repo` names the local random-weight checkpoint, not a Hub revision.

`manifest.json` says the same in its `weights` (`"random-init, shape only"`), `provisional`
and `checkpoint_source` fields. Those three fields were added after recording; nothing under
`scenarios` or in any response was edited by hand.

## How these were made

```sh
LAYA_MODELS=english LAYA_DEFAULT_MODEL=english \
LAYA_EXTRA_MODELS='{"english": "/home/laya/.cache/huggingface/shape-proxy-english"}' \
LAYA_API_KEY=fixture-key composer laya:up
LAYA_API_KEY=fixture-key bin/laya-record-fixtures --out tests/Fixtures/laya/provisional
```

## Replacing them with real fixtures

On a machine that can download the weights:

1. `LAYA_API_KEY=fixture-key composer laya:up && LAYA_API_KEY=fixture-key bin/laya-record-fixtures`
   (records into `tests/Fixtures/laya/`, the real fixture directory).
2. Point `LayaFixtures::DIRECTORY` in `tests/Support/LayaFixtures.php` at `tests/Fixtures/laya`
   (the one switch the PHP contract tests read from).
3. Delete this directory, and remove the `@unittest.expectedFailure` on
   `test_no_scenario_is_pending` in `docker/tests/test_recorder.py`.
