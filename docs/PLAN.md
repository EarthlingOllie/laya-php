# Plan: `laravel-system-one-classification`

A typed Laravel client for System 1 decision models (Laya, Jev and compatible models).

## Decisions so far

| # | Topic | Decision |
|---|---|---|
| 1 | Package name | `laravel-system-one-classification`. The plan uses `earthlingollie/laravel-system-one-classification`, namespace `EarthlingOllie\SystemOneClassification` and facade `SystemOne`; the vendor is provisional. |
| 2 | AWS / Bedrock | Out of scope for now. There is no AWS connection or SigV4 transport in v1. |
| 3 | Confidence | Every answer exposes both `confidence` values. `answerConfidence` (the probability of the reported answer) is what you threshold on, because it can be compared across models. The raw `confidence` is kept and tagged with the model profile that produced it. |
| 4 | Local provisioning | A standalone `compose.yaml` is the primary path, with an optional Laravel Sail service. Both are offered by `system-one:install`. |
| 5 | PHP version | PHP 8.4 minimum, with Laravel 13. Value objects are kept as simple as possible (see Quality bar). |
| 6 | Vendor | `earthlingollie`. |
| 7 | Framework coupling | One Laravel package that depends on `illuminate/http`. Everything outside `src/Laravel/` stays free of other framework imports. |
| 8 | Question identity | Explicit string keys (`Question::choice('department', …)`). The question object is the handle you read results with. |
| 9 | Enum conventions | Descriptions come from `#[Describe]` on each case, with an optional `DescribesCases` interface for translated text. Score order follows the int backing value. |
| 10 | Abstention | `value` always holds the model's best guess. A separate `abstained` flag marks low-confidence answers. |
| 11 | Decision classes | Question handles only in v1. Attribute-driven DTOs may come later. |
| 12 | Local image | Built on the dev machine from our Dockerfile; there is no published image. Loads only the English checkpoint by default; multilingual is opt-in. |
| 13 | Sync and batch | `decide()` and `decideMany()` (the batch endpoint) are synchronous. Queued and concurrent dispatch come later. |
| 14 | Caching and data | Caching is off by default. Context text is never logged or put in events by default. Remote connections (`laya-hosted`, `jev`) are used only when they are configured explicitly with a key; the default connection is always `local`. |
| 15 | Quality | All code is immutable and fully tested, as defined in the Quality bar section below. |

## Goal

A Laravel package that gives PHP code a fully typed, immutable way to ask System 1
decision models (Laya, Jev and compatible models) typed questions about a piece of
context and get typed answers back. The package also ships a Docker Compose
setup that runs Laya locally, so a dev machine can use it with no other setup.

## What we found during research

These findings shape the design, so they come first.

- **Laya** ([PyPI `laya` 0.4.8](https://pypi.org/project/laya/), Apache-2.0,
  [GitHub](https://github.com/NandhaKishorM/laya)) is an encoder-only,
  non-autoregressive decision model. It comes as three checkpoints, and a router
  picks one for each request:

  | checkpoint | encoder | params | context |
  |---|---|---|---|
  | `english` (`convaiinnovations/laya`) | ModernBERT-large | 421M | 512 |
  | `multilingual` | mmBERT-base | 322M | 1024 (up to 8192) |
  | `typed-decisions` | ModernBERT-large | 421M | 1024 |

- **It has three question types:**
  - `choice`: pick one label from a set of labelled criteria.
  - `score`: ordinal levels, where every level needs a description.
  - `noul`: a yes/no statement check that returns P(true).

  Each answer carries a `confidence` value and an `answer_confidence` value, and
  `choice` and `score` answers also carry the full probability distribution. The
  server can also abstain when an answer falls below `min_confidence`.
- **`laya-serve` (`pip install "laya[serve]"`) speaks the same wire protocol as
  TypeSafe's hosted Jev API.** It serves `POST /v1/systemone` and
  `POST /v1/systemone/batch` (up to 64 states per call), with optional bearer
  auth (`LAYA_API_KEY`). Laya says its answer payload has the same schema as
  Jev's. **So one HTTP client can reach both Jev and self-hosted Laya.** The host
  becomes configuration (a base URL, a key and a model name), not a separate
  adapter.
- **Known differences between Laya and Jev**, which our "model profile" has to
  capture:
  - Laya allows at most about 100 choice options per question and a 192–256
    token budget for all the options together. Jev allows 255 options.
  - Laya requires a description for every score level.
  - `confidence` is computed differently on each (Laya uses 1 − normalised
    entropy). A threshold from one does not carry over to the other.
- **Docker:** upstream ships a CPU Compose quickstart and a CUDA override. It
  needs about 8 GB RAM and 10 GB disk. Weights download from Hugging Face on
  first run (no token needed) and are kept in a volume. Apple Silicon and ARM64
  run on CPU only, because there is no MPS inside Docker. Useful settings:
  - `LAYA_MODELS` and `LAYA_PRELOAD` choose which checkpoints to load at start.
  - `LAYA_REVISION=reviewed` pins the checkpoint commits.
  - `LAYA_DEFAULT_MODEL` sets the checkpoint used when the language is unclear.
  - `OMP_NUM_THREADS` caps CPU threads.
- **Prior art:** [`marcreichel/laya-php`](https://github.com/marcreichel/laya-php)
  (Packagist, PHP 8.4+, Laravel 13+) already targets `laya-serve`. We are writing
  our own client under a different name (`laravel-system-one-classification`).
- **Response format confirmed** on [laya-ai.com/laya-api](https://laya-ai.com/laya-api):

  ```json
  {
    "model": "laya-rl-agent",
    "answers": {
      "queue":   {"type": "choice", "choice": "billing",
                  "probabilities": {"billing": 0.9281, "tech": 0.0412, "other": 0.0307},
                  "confidence": 0.4534, "answer_confidence": 0.9281},
      "urgency": {"type": "score", "score": 2.6389,
                  "legend": {"0": "calm", "1": "firm", "2": "angry", "3": "furious"},
                  "probabilities": {"0": 0.0099, "1": 0.0713, "2": 0.536, "3": 0.3828}},
      "refund":  {"type": "noul", "noul": 0.97}
    },
    "usage":   {"input_tokens": 74, "output_tokens": 0},
    "routing": {"model": "english", "reason": "English Latin text"}
  }
  ```

  The request takes `state` (a string or a JSON object) and `questions`, plus
  these optional fields: `model`, `lang`, `lang_guess`, `min_confidence`,
  `max_len`, `head_max_len` and `task`. Answers below `min_confidence` come
  back marked as low confidence. Phase 0 confirmed the exact fields
  (`low_confidence`, `abstention`, `abstention_threshold`); see "Phase 0
  findings" below.
- **There is a hosted Laya API at `https://api.laya-ai.com/v1/systemone`.** It
  is run by laya-ai.com, a third party, not by Convai, and uses the same
  protocol with a bearer key, at $0.05 per 1,000 calls. That makes three hosts
  behind one client: local `laya-serve`, hosted Laya, and Jev.
- **More server settings:**
  - `LAYA_TOURNAMENT_GROUP` raises the choice-option cap from 100 to 255.
  - `LAYA_JEV_STRICT=1` returns only the fields in Jev's response format.
  - The Hugging Face checkpoint (`convaiinnovations/laya`, Apache-2.0) is at
    revision `7b928d8`. `LAYA_REVISION=reviewed` in laya 0.4.8 pins it to
    `55cf4c4ebb4ebe31b2550e8bdf3bd21b99753851` instead (all three checkpoints
    live in that one repo; `multilingual` and `typed-decisions` are subfolders).

## Phase 0 findings

Recorded against `laya[serve]==0.4.8` in our own image (`docker/laya/Dockerfile`,
torch 2.14.1+cpu, transformers 5.20.0) on 2026-10-16. Fixtures are in
`tests/Fixtures/laya/` with a `manifest.json` naming the Laya version and the
checkpoint revision; re-record them with `bin/laya-record-fixtures`.

**What was and was not recorded.** The environment the fixtures were first
recorded in could reach huggingface.co but not the Hugging Face CDN the weights
are served from (`us.aws.cdn.hf.co`, `cas-server.xethub.hf.co`, both refused by
its egress proxy). So:

- Recorded from the real server: every response laya-serve gives before
  inference: 401 (wrong key, missing key), 413 (101 choice options), 422
  (null score level, `min_confidence` 1.5, unknown model), 400 (missing
  `state`), `GET /health` and the server's own `/openapi.json`.
- **Pending** (listed under `pending` in the manifest, and guarded by an
  expected-failure test in `docker/tests/test_recorder.py`): the model-backed
  scenarios: `choice`, `score`, `noul`, `multiple_questions`, `batch`,
  `below_min_confidence`, `batch_below_min_confidence`. Record them on any
  machine that can download the weights:
  `LAYA_API_KEY=k composer laya:up && LAYA_API_KEY=k bin/laya-record-fixtures`.
  No made-up answer payloads were committed in their place.

**Answer fields.** Read from the laya 0.4.8 source (`agent.py`,
`confidence.py`, `serve.py`) and checked against live responses from the same
server running a checkpoint with the english architecture and random weights
(the values are meaningless; the field names and shapes are the server's own):

- **`noul` answers do include `answer_confidence`.** A noul answer is
  `{type, noul, confidence, answer_confidence, action}`. For noul,
  `confidence` and `answer_confidence` are both `max(p, 1 − p)` (before any
  calibration map), while `noul` is P(true). Only `LAYA_JEV_STRICT=1` strips
  noul down to `{type, noul}`, which is Jev's shape.
- `choice` and `score` answers also carry `answer_confidence` (= max p) next to
  the entropy-based `confidence`, plus `x_jev_confidence` (Jev's formula for the
  same probabilities) and an `action` object (`{"act_probability": …}`).
- **Low-confidence marking, exact names.** Only when the request sends
  `min_confidence`, *every* answer gets:
  - `abstention`: `"passed"`, `"abstained"` or `"unevaluated"` (no usable
    confidence);
  - `abstention_threshold`: the float the answer was gated at;
  - `low_confidence: true`, **only** on answers below the threshold (the key
    is absent otherwise, never `false`).

  The gate compares `answer_confidence`, not `confidence`. Without
  `min_confidence` none of the three keys appear. `min_confidence` is either a
  float in [0, 1] or a per-bucket map such as
  `{"choice:3-5": 0.8, "noul:2": 0.9, "default": 0.7}`; anything else is a 422.
- Root fields: `model`, `answers`, `usage` (input/output tokens plus truncation
  details) and `routing` (`{model, reason}`). The batch endpoint returns
  `{results: [<one single-call payload per state>], total_usage}`.
- Errors are always `{"detail": "<message>"}`: 400 (body shape, missing
  `state`), 401 (bad or missing bearer, only when `LAYA_API_KEY` is set), 413
  (limits), 422 (invalid question, `min_confidence`, budget or model), 500
  (`"inference failed"`) and 503 (`"server busy"`, with `Retry-After: 1`).
- laya-serve limits (all 413): 64 questions, 100 choice options (255 with
  `LAYA_TOURNAMENT_GROUP`), 32 score levels, 512 options across questions,
  50,000 state characters, 64 batch states, 2 MiB body.
- laya-serve's own `/openapi.json` has no request or response schemas (the
  routes read the raw body), so contract tests must take the body schema from
  Jev's spec and the recorded fixtures, not from it.
- Without `LAYA_DEFAULT_MODEL=english`, text with no language evidence routes to
  `multilingual` and makes the server download and load a second checkpoint;
  our Compose file sets it.

**CPU latency and RAM** (`bin/laya-measure`, 20 runs after 3 warm-ups). The
machine: a cloud VM, Intel Xeon @ 2.10 GHz, 4 vCPUs, 15.7 GiB RAM, no GPU,
shared with other workloads (load average ~3.5 during the run). Because the
real weights could not be downloaded, this ran a checkpoint built from the
published english config with random weights: 421,293,830 parameters, a
842,609,220-byte `model.safetensors` against the published 842,609,210 bytes.
Compute and memory depend only on the architecture, so the figures stand in
for the real checkpoint, but re-measure with it (and on an Apple Silicon
machine) when possible.

| request | p50 inference | p95 inference |
|---|---|---|
| 1 noul question | 321 ms | 469 ms |
| 3 questions (choice, score, noul) | 559 ms | 725 ms |
| batch: 8 states × 2 questions | 2,535 ms | 3,885 ms |

Memory: 2.06 GiB resident once the english checkpoint is loaded, peaking at
2.75 GiB during load (`VmHWM`); `docker stats` reported 2.4–2.5 GiB for the
container. The image is 1.7 GB; the english weights add 0.84 GB to the cache
volume.

**Jev OpenAPI spec: not fetched.** `api.typesafe.ai` was unreachable from the
recording environment (HTTP 403 from its egress proxy). `bin/fetch-jev-openapi`
saves it to `tests/Fixtures/jev/openapi.json`; until it has been run, that
directory holds only a marked placeholder, and the comparison with the
recorded fixtures is still to do. Tried again for #9 on 2026-10-17: still refused
by the egress proxy. Until it is fetched, the request-schema contract tests run
against a hand-written stand-in, `tests/Fixtures/jev/provisional/openapi.json`
(marked `x-provisional`; see "Laravel integration" below).

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Laravel layer   ServiceProvider · config/system-one.php ·     │
│                 Facade · Fake · artisan commands · events    │
├─────────────────────────────────────────────────────────────┤
│ Client          DecisionClient::decide(Decision): Result     │
│                 DecisionClient::decideMany(...): ResultSet   │
├───────────────────────────────┬─────────────────────────────┤
│ Model profile                 │ Wire codec                  │
│ LayaProfile / JevProfile      │ SystemOneCodec              │
│ - option & level limits       │ Decision → /v1/systemone    │
│ - confidence semantics        │ JSON → Result               │
│ - model id / checkpoint names │                             │
├───────────────────────────────┴─────────────────────────────┤
│ Transport (seam)   HttpTransport (Laravel Http) · Fake       │
├─────────────────────────────────────────────────────────────┤
│ Connection (config)  base_url · api_key · model · profile ·  │
│                      timeout · retries                       │
└─────────────────────────────────────────────────────────────┘
          │                                  │
   local: laya-serve in Docker   laya-hosted: api.laya-ai.com   jev: TypeSafe
```

### The two abstractions you asked for

1. **Model** → a `ModelProfile`. Both models share one protocol, so a profile
   doesn't encode requests. It describes the model instead: its limits (option
   counts, whether score levels need descriptions), how to read `confidence`,
   and which `model` values are valid. Before sending, the client checks a
   `Decision` against the profile, so a request that is too large fails in PHP
   with a clear exception instead of a 413 or 422 from the server. To switch
   models, change the profile and the model name in config.
2. **Host** → a named `Connection` in config, set up the way Laravel database
   connections are (`local`, `laya-hosted`, `jev`). Every connection uses the
   same `HttpTransport`. The `Transport` interface exists only so tests can
   replace HTTP (the client's own; an app's tests replace the whole client with
   `SystemOne::fake()`, #10). AWS is out of scope (decision 2).

### Proposed public API

```php
enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';
    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';
    #[Describe('everything else')]
    case Other = 'other';
}

enum Urgency: int            // score: ordinal levels, in backing-value order
{
    #[Describe('can wait')]  case Low = 0;
    #[Describe('soon')]      case Medium = 1;
    #[Describe('blocking')]  case High = 2;
}

$department = Question::choice('department', Department::class, 'Which department should handle this?');
$urgency    = Question::score('urgency', Urgency::class, 'How urgent is this?');
$churn      = Question::yesNo('churn', 'Does the customer threaten to cancel?');

$decision = Decision::about(Context::text($ticket->body))   // or Context::fields(['subject' => …, 'body' => …])
    ->ask($department, $urgency, $churn)
    ->withMinConfidence(0.6);                                // returns a new instance; Decision is immutable

$result = SystemOne::decide($decision);                      // default connection (local)
$result = SystemOne::connection('jev')->decide($decision);   // the same call against a different host

$result->get($department)->value;                            // Department::Billing (ChoiceAnswer<Department>)
$result->get($department)->answerConfidence;                 // P(reported answer): threshold on this
$result->get($department)->confidence;                       // Confidence { value, profile } (raw model-specific value)
$result->get($department)->isConfident(0.8);                 // shorthand for answerConfidence >= 0.8
$result->get($department)->probabilityOf(Department::Other); // float
$result->get($department)->distribution;                     // Distribution<Department>, ordered
$result->get($urgency)->value;                               // Urgency::High
$result->get($urgency)->expected;                            // 1.7 (the mean backing value under the distribution)
$result->get($churn)->probability;                           // 0.91
$result->get($churn)->isYes(threshold: 0.8);                 // bool
$result->get($churn)->verdict(yes: 0.8, no: 0.2);            // Verdict::Yes | No | Unsure
$result->get($department)->abstained;                        // true if below min_confidence
$result->meta;                                               // model / checkpoint used, routing reason, usage, latency
```

How the typing works:
- **Everything is immutable.** Every value object is a `final readonly class`
  with promoted public properties. Derived values are methods
  (`isConfident(0.8)`, `probabilityOf(...)`). Builder methods (`ask`,
  `withMinConfidence`, `withModel`) return a new instance. See the Quality bar
  for why this uses `readonly` rather than property hooks.
- **Typed results without casting, through generics.** The answer type is a
  PHPStan or Psalm generic. `ChoiceQuestion<TEnum of BackedEnum>` extends
  `Question<ChoiceAnswer<TEnum>>`, and `Result::get(Question<TAnswer>): TAnswer`
  returns that answer type. Static analysis then knows that
  `$result->get($department)->value` is a `Department`.
- **Questions are handles.** You keep the question object and pass it back to
  `get()`. The string key exists only so the wire format and logs stay stable.
  As built in #3: `Question` is a `final readonly` factory (`Question::yesNo()`
  …), and the generic type every question implements is
  `Question\QuestionHandle<TAnswer>` (`key`, `instructions`, `answerType()`), so
  `Result::get(QuestionHandle<TAnswer>): TAnswer`. Handles compare by value: an
  equal handle built elsewhere reads the same answer, but one that only shares
  the key is refused (`UnknownQuestion`). Keys follow `Question\QuestionKey`:
  not blank, and not a whole number such as `'0'` (PHP would make it an
  integer array key and the questions would go out as a JSON list).
- **Enums drive the options.** `choice` and `score` take a `BackedEnum`
  class-string. Labels come from backing values, and descriptions come from a
  `#[Describe]` attribute on each case. On Laya a `score` enum must describe every
  case, because Laya requires it (the profile checks it since #6). As built in #4: `Question::choice()` returns
  `Question\ChoiceQuestion<TEnum>`; an enum implementing `DescribesCases` is described
  by `describe()` instead of the attribute, and a case with neither goes out as its bare
  label (`null` criterion). Criteria always go out as a JSON object (the codec builds a
  `stdClass`), so whole-number labels survive. A blank label or description, a non-backed class
(an interface extending `BackedEnum` included),
  an enum without cases, or a `#[Describe]` PHP cannot build (repeated, or wrong
  arguments; PHP's `Error` is chained as the previous exception) is an `InvalidDecision`.
- **Choice answers** (#4): `ChoiceAnswer<TEnum>` has `value`, `answerConfidence` (the
  server's `answer_confidence`, or P(chosen) when it sends none, as Jev does),
  `confidence` (a `Result\Confidence {value, profile}`, the profile coming from the
  connection's `profile` config key), `distribution` (`Result\Distribution<TCase>`, every case once, in enum
  order, read with `entries()`/`cases()`/`probabilityOf()`), `probabilityOf()` and
  `isConfident()`. `Result\Probability::check()` is the one [0, 1] check (NAN refused).
  An unknown label, a missing or extra probability, or a non-numeric confidence is a
  `MalformedResponse`.
- **Score questions** (#5): `Question::score()` returns `Question\ScoreQuestion<TEnum>`
  over an int-backed enum (a string-backed one is refused at its first case). `levels()`
  lists `{case, value, description}` sorted by backing value, whatever the declaration
  order, and goes out as `criteria`: a list of descriptions, index 0 first, because
  laya-serve knows levels only by index. Levels are described by `DescribesCases`
  or `#[Describe]`, read by `Question\CaseDescription` for both enum question types; a
  blank description is an `InvalidDecision` naming the case, and since #6 a missing one
  is `null`, refused before sending by a profile that requires descriptions (Laya).
- **Score answers** (#5): `ScoreAnswer<TEnum>` has `value` (the most probable level; the
  lowest of equally likely ones, since the server sends none for a score), `expected`, and
  `answerConfidence`, `confidence`, `distribution` (in level order), `probabilityOf()` and
  `isConfident()` as on choice answers. `ScoreAnswer` computes `expected` itself, as the
  mean backing value under its (normalised) distribution, so it is always consistent with
  it and between the lowest and highest value; the server's `score` is the expected level
  *index* (the same number only for levels backed by 0..n-1), so the codec checks it but
  does not read it. A distribution over a string-backed enum or with no probability above
  0, or a `value` that is not a most likely level, is an `InvalidDistribution`. The
  answer's `legend` must give back every description (`ScoreQuestion::criteria()`) under
  the index it was sent with (key order aside); any other legend, a `score` outside
  0..n-1, all-zero probabilities, or a missing or extra probability is a
  `MalformedResponse` (probability errors name the level behind the index).
- **Model profiles** (#6): `Model\ModelProfile` has `name` (which tags every raw
  `confidence`), `maxChoiceOptions`, `requiresScoreDescriptions` and `models()`.
  `LayaProfile`: 100 options (255 with `tournamentGrouping`), descriptions required, and
  `LayaProfile::MODELS` (laya-serve's checkpoints and aliases, as its unknown-model 422
  lists them) plus any `extraModels`, matched in any case as laya-serve does (it trims and
  lowercases a name before looking it up); `acceptsModel()` holds that rule. `JevProfile`: 255 options, descriptions optional, and
  only the `models` its connection lists, matched exactly, because Jev's are not in anything recorded yet
  (so `withModel()` is refused on a Jev connection that lists none). The contract tests
  read the cap and the model list out of the recorded 413 and 422 messages.
  `SystemOneCodec::encode()` runs `Model\ProfileLimits::check()` first: a pinned model
  the profile does not accept, a choice over more options than it allows, or (when it
  requires them) an undescribed score level is an `InvalidDecision` naming the question
  key and the limit, so nothing is sent. `ScoreQuestion` therefore keeps an undescribed
  level as a `null` description (and criterion) instead of refusing it; a blank one is
  still refused when the question is built. `Decision::withModel()` returns a new
  decision whose `model` goes out as the wire's `model` (left out when none is pinned); a
  blank model is refused at once. The codec also encodes the state and each question's
  request up front, so text that is not valid UTF-8 is an `InvalidDecision` naming the
  context or the question key (the `JsonException` chained), not the HTTP client's
  exception. Laya's other 413 limits (64 questions, 32 score levels, 512 options across
  questions, 50,000 state characters) are not in the profile yet: Jev's values for them
  are unknown.
- **Typed errors.** Every exception the package throws implements the
  `Exceptions\SystemOneException` marker (enforced by the architecture tests,
  including a scan of every `throw` in `src/`).
  - `InvalidDecision`: the decision can't be sent (blank text or key, a
    whole-number or repeated key, no questions, an unknown question type, text
    that is not valid UTF-8, or a broken profile limit), always raised before
    anything is sent.
  - `UnknownQuestion`: `Result::get()` was given a handle the decision didn't ask.
    Handles match by value, case descriptions included (they are what the model
    reads), so a choice handle rebuilt under another locale is refused; the
    message names the fields that differ. #10's fake and #12's cache key follow
    the same identity.
  - `InvalidProbability`: a probability or threshold outside [0, 1], or a no
    threshold above the yes threshold.
  - `InvalidConfiguration`: `config/system-one.php` doesn't describe a usable
    connection.
  - `HostUnavailable`: the server couldn't be reached, or answered with any 5xx,
    after the connection's retries (since #9; the message names the attempts
    when a connection failed more than once).
    The message is redacted (`Transport\Redactor`: URL credentials, query
    values and the API key), and the HTTP client's exception isn't chained.
  - `RejectedByModel`: the server returned 4xx (`status`, `detail`), never
    retried. Since #9 `detail` is read by `Protocol\ErrorDetail` out of any of
    the shapes a host or gateway uses: `{"detail": "..."}`, FastAPI's validation
    list (as `loc: msg` pairs, leaving out the `input` it echoes),
    `{"message": ...}`, `{"error": ...}` or `{"error": {"message": ...}}`, or a
    plain-text body (collapsed, cut to 200 characters; an HTML page is no
    message). HttpTransport masks the connection's secrets and echoed URL
    credentials in every non-2xx body before the codec reads it.
  - `MalformedResponse`: the status or JSON didn't match the protocol (`body`
    holds the raw response).
- **One dispatch per question type.** `SystemOneCodec::wire()` maps each
  question type to both its request and its answer decoder, so a type can't be
  sent without being readable back.

### Laravel integration

- `config/system-one.php` has a default connection and named connections, each
  with its own profile. Built in #3: published with
  `vendor:publish --tag=system-one-config`; `default` from
  `SYSTEM_ONE_CONNECTION` (`local`); `local` takes `url` from `SYSTEM_ONE_URL`
  (`http://localhost:8000`), `api_key` from `SYSTEM_ONE_API_KEY` (empty means
  none) and `timeout` from `SYSTEM_ONE_TIMEOUT` (seconds; empty means 30).
  Since #4 each connection has a `profile` (`'laya'` or `'jev'`; unset or empty
  means `'laya'`, anything else is `InvalidConfiguration`; `local` takes it from
  `SYSTEM_ONE_PROFILE`). Since #6 the manager builds a `Model\LayaProfile` or
  `Model\JevProfile` from it and hands it to `SystemOneCodec`, with two more keys:
  `tournament_grouping` (Laya only; `SYSTEM_ONE_TOURNAMENT_GROUPING`; a bool or an
  env string such as `'1'`/`'true'`/`'0'`/`''`) and `models` (`SYSTEM_ONE_MODELS`; a
  list of names or a comma-separated string: the `model` values the host accepts
  beyond the profile's own). Anything else in either is `InvalidConfiguration`.
  `SystemOneManager` reads the config on every call, so runtime `config()`
  changes apply.
- **Named connections (#9).** The shipped config has three: `local` (the
  default), `laya-hosted` (`https://api.laya-ai.com`) and `jev`
  (`https://api.typesafe.ai`), each with `url`, `api_key`, `profile`,
  `tournament_grouping` (not on jev), `models`, `model`, `timeout`, `retries` and
  `retry_delay`, read from `SYSTEM_ONE_*`, `SYSTEM_ONE_LAYA_HOSTED_*` and
  `SYSTEM_ONE_JEV_*`.
  - `remote` (true on `laya-hosted` and `jev`; any connection may set it):
    `connection()` refuses a remote connection without an `api_key`
    (`InvalidConfiguration`, nothing sent), and its `url` must be https (plain
    http only to localhost, 127.0.0.0/8 or ::1). Keys set for remote hosts never
    change the default, which stays `local` unless `SYSTEM_ONE_CONNECTION`
    names another (then that connection still needs its key).
  - `model` (#12 uses the same key and `InvalidConfiguration::modelNotString`):
    sent when a decision pins none (`SystemOneCodec::encode($decision, $model)`,
    `DecisionClient(..., model:)`); a pin wins. It is trimmed, empty means the
    server routes, and it counts as one of the connection's `models`, so it is
    never refused by its own profile.
  - `retries` / `retry_delay` (`Transport\RetryPolicy`): a 5xx or a failed
    connection is sent again up to `retries` times, waiting `retry_delay` ms
    (default 200) doubled before each further retry, at most 30 s. A 4xx never
    is. The shipped config retries twice (200 ms locally, 500 ms remote); a
    connection that sets no `retries` retries nothing, and none may set more
    than 10. A 5xx's `Retry-After` (seconds) wins over a shorter backoff,
    within the same cap. Timeouts are retried too (the issue asks for transport
    failures to be), so a slow host may answer, and bill, one decision twice;
    the config says so. The waits go through `Illuminate\Support\Sleep`
    (`Laravel\LaravelSleeper`), faked in every Feature test.
  - The API key goes out only as the bearer token. `HttpTransport` and
    `Laravel\ConnectionConfig` hold it as a `SensitiveParameterValue` behind a
    `#[SensitiveParameter]`, so dumps, `var_export()`, `json_encode()` and stack
    traces never show it, and `serialize()` refuses it. Requests go through
    `Laravel\QuietHttpFactory`, the app's HTTP factory without its event
    dispatcher, so Laravel's `RequestSending`, `ResponseReceived` and
    `ConnectionFailed` (which carry the Authorization header) are not
    dispatched for them; `Http::fake()` and the app's global middleware still
    apply, but Telescope and Pulse do not see these requests. Error bodies are
    masked of the key and echoed URL credentials before anything reads them.
  - `remote` is opt-in: a connection added without it gets neither check. It
    cannot be inferred from the URL, since a Sail app reaches its own
    laya-serve with a key over `http://laya:8000`.
  - An app that published `config/system-one.php` before #9 keeps its own
    `connections` (`mergeConfigFrom` merges top-level keys only): republish it,
    or copy the `laya-hosted` and `jev` blocks in, to get them.
  - `SystemOneManager::configuration($name)` returns the checked
    `ConnectionConfig` without requiring the key: what the testing fake (#10)
    should check, so that faking a remote connection needs no real key.
  - Contract tests check every encoded request body against the request schema
    of `POST /v1/systemone` in Jev's OpenAPI document (`Tests\Support\JevSchema`,
    justinrainbow/json-schema), currently the provisional stand-in.
  - Smoke tests (`tests/Smoke`, `composer test:smoke`, not a default suite) reach
    the real hosts; each skips without its key, and the manual "Smoke (remote
    hosts)" workflow (workflow_dispatch only) fails a chosen host whose key is
    missing (`SYSTEM_ONE_REQUIRE_SMOKE`).
- A singleton is bound in the container and exposed through a `SystemOne`
  facade.
- `SystemOne::fake([...])` returns scripted answers and lets tests assert on the
  decisions that were sent. Built in #10:
  - `SystemOne::fake([FakeAnswer::…])` swaps `Laravel\Testing\SystemOneFake` in behind
    the facade, whose accessor is the new `Laravel\ConnectionResolver` interface (aliased
    to the manager's singleton). Inject `ConnectionResolver` to stay fakeable: the final
    `SystemOneManager` itself stays the real manager under the fake (it could not be
    swapped for a fake without a `TypeError`).
    `SystemOne::connection()` returns a `Client\Decider` (the interface `DecisionClient`
    implements); under the fake it is a `Laravel\Testing\FakeConnection`.
  - Typed helpers, generic over the question's enum: `FakeAnswer::yesNo($q, 0.91)`,
    `FakeAnswer::choice($q, Case, confidence: 0.9)` (default 1; the rest is shared evenly
    among the other options, so below 1/n is an `InvalidFakeAnswer`) and
    `FakeAnswer::score($q, Case, expected: 1.7)` (default: the case's value; the case
    blended with the fewest levels that move the mean there, so the case stays the most
    likely level, ties going to the lowest as in the codec; an expected level it cannot
    reach that way is an `InvalidFakeAnswer`). They
    build the real `YesNoAnswer`/`ChoiceAnswer`/`ScoreAnswer`; the raw `confidence` is
    Laya's formula (1 − normalised entropy) tagged with the connection's `profile`.
  - Scripts match by `Domain\Question\HandleIdentity`, the identity `Result::get()` now
    reads by too; the last script for a question wins. With no script a question gets
    `FakeAnswer::unscripted()` (yes/no 0.0, the first option, the lowest level, all
    certain), or after `->strict()` an `UnscriptedQuestion` naming any same-key script and
    what differs.
  - The fake encodes each decision with `SystemOneCodec` first, so it refuses (and does
    not record) what the real client refuses, with the connection's `ModelProfile` (so
    its limits apply too); and it has the real manager check each connection with
    `configuration()`, so a connection config the real client refuses is refused under
    the fake too, except a remote host's missing API key: nothing is sent, so faking
    `jev` needs no real key (integration branch).
  - `assertDecided(fn (Decision, string $connection): bool)`, `assertDecidedTimes(n)`,
    `assertNothingDecided()` and `assertAsked($question)` (some result reads that handle).
    They use PHPUnit's `Assert`, which an app's test suite always has.
  - **Still to wire in the stack** (built on #5's branch, without #6, #7, #8 or #9):
    - #8 `decideMany()` (done on the integration branch): on `Client\Decider` (so
      `ConnectionResolver` too), `SystemOneFake` and `FakeConnection`. The batch is first
      encoded with `encodeBatches()`, so the fake refuses what the real client refuses
      before recording anything; then each context is answered and recorded as one
      decision (`withContext()`), so the assertions cover batches unchanged. The
      `ResultSet`'s usage is null.
    - #7 abstention (done on the integration branch): `FakeAnswer::abstained($script)`
      keeps the script's value and reads as `Abstained` at the decision's
      `min_confidence`. Every other answer on a gated decision is gated as laya-serve
      gates it, on `answer_confidence` (for yes/no, the probability of the side
      reported): `Abstained` below the threshold, `Passed` otherwise; on an ungated
      decision it reads as `NotRequested`. An abstained script asked for by a
      decision with no `min_confidence` is an `InvalidFakeAnswer`: no server would
      abstain on it.
    - #6 `ModelProfile` (done on the integration branch): `FakeConnection::$profile` is
      the connection's `ModelProfile`; answers are tagged with its `name`.
    - #9 named connections (done on the integration branch): the fake checks connections
      with `configuration()`, so the remote-host key rule does not apply under it.
- Events: `DecisionMade` and `DecisionFailed`. Logging the context text is off
  by default, because it may contain PII.
- Optional result caching through Laravel's cache, keyed on the decision, the
  model and the checkpoint revision.
- **Built in #12.** The core client has two framework-free seams,
  `Client\DecisionObserver` and `Client\ResponseCache`; the Laravel layer
  implements them (`Laravel\DispatchesDecisionEvents`,
  `Laravel\StoreResponseCache`), and `SystemOneManager` wires them per
  connection from `system-one.events` and `system-one.cache`.
  - `Laravel\Events\DecisionMade` carries the connection, the model asked (the
    decision's pinned one, else the connection's `model`), the question keys, the `Result`, its `Meta`, the latency and
    `cached`; `DecisionFailed` the connection, model, question keys and the
    `SystemOneException` (for decisions refused before sending too). Neither
    holds the API key, and the context (text or fields) only with
    `events.include_context` (`SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT`). Events are
    on by default (`SYSTEM_ONE_EVENTS`). An exception's trace can still hold
    the context as an argument where `zend.exception_ignore_args` is off (it is
    on in php.ini-production); that is PHP's, not the event's.
  - Caching is off by default (`SYSTEM_ONE_CACHE`), uses `cache.store`
    (`SYSTEM_ONE_CACHE_STORE`, empty for the default store) and `cache.ttl`
    (`SYSTEM_ONE_CACHE_TTL`, seconds, an hour when empty). With it on, every
    decision first reads `GET /health`, and the key is a SHA-256 of the
    checkpoint revisions it reports and the whole encoded request body (context,
    every question as sent, so a choice's options and descriptions too, the
    request controls and the `model`), scoped to the connection name. A loaded
    checkpoint with no revision (a local path) skips the cache, and an answer
    is stored only when the checkpoint it reports (`routing.model`) is one the
    key holds a revision for, so a checkpoint loaded lazily by that very call,
    or a host that reports no routing, is never cached. A checkpoint swapped
    between the /health read and the decision (a restart in between) can
    still be stored under the old revision; the TTL bounds that. Entries are
    `{status, body}` arrays, so stores that refuse to unserialize objects work;
    a hit is decoded again, with no latency. A store that throws, a /health
    that is refused or unreadable, and an entry that does not decode are
    reported through the exception handler and skipped. On the integration
    branch the /health read is a probe sent once (`Transport::get()`), and one
    that cannot reach the server is skipped and reported too: the decision then
    goes out with the connection's retries (#9), which decide whether the host
    is really down, instead of the probe spending that budget first.
  - Trade-offs kept on purpose: the /health read costs one cheap GET per
    cached decision, hits included, so a revision change is never missed (a
    memo of the revisions would serve stale answers for its lifetime); a
    /health that keeps failing is reported on every decision, as a store that
    keeps failing is, so the operator sees that caching does nothing. A
    listener that throws propagates, as Laravel's synchronous listeners do;
    queue the listener to isolate it. #9 should decide how hosts without
    /health (Jev) cache, if at all.
  - Each connection may set `model` (`SYSTEM_ONE_MODEL`); on the integration
    branch this is #9's key: sent when a decision pins none, a pin
    (`withModel()`, #6) wins, and it is held to the profile.
  - Batches (integration branch): `decideMany()` treats each context as one
    decision, as the fake does. Each result's meta carries the latency of the
    request that carried its state, so a null latency still means "from the
    cache". The observer gets a `DecisionMade` per context
    (its own context, result and `cached`), or, when the batch fails, a
    `DecisionFailed` per context with the batch's exception (one, for the
    decision itself, when the batch is refused before it has contexts). With the
    cache on, /health is read once per batch and each context is keyed exactly as
    `decide()` keys it (a batch body is `decide()`'s with `state` swapped for
    `states`), so the two share entries; only misses are sent, as batches, and
    each result is stored as the single-call body `decide()` would have got
    (`SystemOneCodec::batchEntries()`), as soon as its request answers, so a
    later request that fails does not lose the answers already paid for. The
    call itself fails then, so every context gets a `DecisionFailed`: the caller
    gets no results. The set's `usage` counts the requests sent, so a batch
    answered from the cache alone reports zero tokens.
  - The miss tests cover each part of the key the codec sends, a choice's
    options and their descriptions (#4) and a pinned model (#6) included.
- Artisan commands. Built in #11 (`src/Laravel/Commands/`), each with exit
  codes 0 (done), 1 (the server or a file it must edit let it down) and 2
  (input or config it cannot use, before anything is sent or written):
  - `system-one:install [--mode=standalone|sail]` copies config/system-one.php,
    then sets up the server (see "Docker" below). It only ever adds: an
    existing file, service, volume or `.env` key is left as it is, so a second
    run changes nothing. Without `--mode` it asks, defaulting to standalone;
    `--no-interaction` takes the default.
  - `system-one:health [--connection=]` reads `GET /health` through
    `DecisionClient::health()` (a `Result\Health`: status, loaded checkpoints
    or null when the server lists none to a keyless request, revisions,
    device) and prints it with the URL redacted. 1 when unreachable, a 4xx/5xx,
    an unexpected body or any status but `ok`; 2 for an unknown connection.
  - `system-one:try [text] [--file=] [--yes-no=...] [--choice=...] [--score=...]
    [--connection=] [--json]` asks each question about the argument, the file or
    stdin (refused with 2 when stdin is a terminal, rather than waiting on it),
    and prints value, confidence (the reported value's probability) and
    distribution per answer. Its errors go to stderr, so stdout stays valid JSON
    under `--json`. On the integration branch: `--choice` and `--score` (#4, #5)
    name a backed enum of the app before the instructions
    (`--choice="App\Enums\Department:Which department?"`), as choice and score
    questions are built in code; keys run q1, q2, ... over the yes/no questions,
    then the choices, then the scores. A score also prints its expected level.
    It prints how the server routed the decision (#7's `Meta`: model, checkpoint,
    reason, input tokens), and `--json` adds a `routing` object.
- `Transport::get()` sits next to `post()` for the health probe; every
  transport implements both.

### Docker (part of the package)

```
docker/
  laya/Dockerfile        # python:3.12-slim, CPU torch wheel, pip install "laya[serve]==0.4.8", non-root user
  laya/healthcheck.py    # stdlib probe: healthy only once every LAYA_MODELS checkpoint is loaded
  compose.yaml           # service `laya`: 127.0.0.1:8000, HF cache volume, healthcheck, LAYA_REVISION=reviewed
  compose.cuda.yaml      # optional NVIDIA override (separate image built against a CUDA torch index)
  tests/                 # stdlib unittest suite for all of the above (python3 -m unittest discover -s docker/tests)
bin/
  laya-up, laya-down     # behind `composer laya:up` (waits for healthy) and `composer laya:down`
  laya-record-fixtures   # records tests/Fixtures/laya/*.json + manifest.json from a running server
  laya-measure           # latency/RAM report as JSON
  fetch-jev-openapi      # saves Jev's OpenAPI document to tests/Fixtures/jev/openapi.json
```

- The image is always built locally (`pull_policy: build`, rebuilt from the
  layer cache on each `laya:up`), and the build fails if the probe's copy of
  laya's checkpoint names no longer matches the installed laya. The
  healthcheck's 60 m start period must stay longer than `LAYA_WAIT_TIMEOUT`,
  because `up --wait` gives up as soon as the container turns unhealthy.
- Default: `LAYA_MODELS=english`, `LAYA_PRELOAD=1` and
  `LAYA_DEFAULT_MODEL=english`, which keeps RAM use down. The multilingual
  checkpoint is opt-in: `LAYA_MODELS=english,multilingual composer laya:up`.
- Other knobs read from the environment: `LAYA_HOST_PORT` (stays on
  loopback), `LAYA_API_KEY`, `LAYA_THREADS`, `LAYA_MAX_LOADED`,
  `LAYA_EXTRA_MODELS`, `LAYA_JEV_STRICT`, `LAYA_TOURNAMENT_GROUP`,
  `LAYA_HF_CACHE` (a host directory instead of the named volume, used by CI),
  `LAYA_CUDA=1` (adds the CUDA override), `LAYA_COMPOSE_OVERRIDE` (one more
  Compose file of your own) and `LAYA_WAIT_TIMEOUT`.
- Behind a TLS-inspecting corporate proxy, the image builds with
  `docker build --secret id=extra_ca,src=<ca.pem> …`, and the container needs
  the proxy's CA (`SSL_CERT_FILE`, `REQUESTS_CA_BUNDLE`) and, if the proxy
  blocks the Xet hosts (`cas-server.xethub.hf.co`), `HF_HUB_DISABLE_XET=1`, set
  through a `LAYA_COMPOSE_OVERRIDE` file.
- `system-one:install` asks which to set up. Both files are built from
  `docker/compose.yaml` at install time (`Laravel\Install\ComposeFiles`), so
  they cannot drift from it; the build context points at the installed
  package's `docker/laya` (`./vendor/...` when the package is inside the app).
  - **Standalone** (the default): writes `compose.system-one.yaml` into the
    app (the package file without its repo-only header) and sets
    `SYSTEM_ONE_URL=http://localhost:8000` in `.env`.
  - **Sail**: adds a `laya` service (on the `sail` network when the file has
    one) and a `sail-laya` model-cache volume to the app's Sail Compose file
    (`compose.yaml`, `compose.yml`, `docker-compose.yaml` or
    `docker-compose.yml`, in Compose's own order) as new lines only, so the
    existing services and their formatting are untouched, and sets
    `SYSTEM_ONE_URL=http://laya:8000`, so it is reachable from the
    `laravel.test` container. The new lines follow the file's own line endings
    and each section's indentation, and Compose tags such as `!reset` are
    accepted. A file it cannot edit safely (flow-style `services:`/`volumes:`,
    not YAML) is refused with exit 1 and left as it is.
  - The standalone file keeps the package's `name: system-one`, so every app
    on a machine that uses it shares one server on port 8000 and one copy of
    the weights (the file's header says so).
- The repo also has its own dev Compose file, which runs a PHP container next to
  `laya` for integration tests. CI runs the same setup with the HF cache volume
  cached between runs: `.github/workflows/integration.yml` builds the image
  (GHA layer cache), restores the weights with `actions/cache` (keyed on the
  checkpoint SHA), runs `bin/laya-up` and smoke-tests every fixture scenario
  against the live server. The PHP integration suite plugs in there.

### Package layout

```
src/
  Domain/        Context, Decision, Question (factory), Question/{QuestionHandle,QuestionKey,
                 Choice,Score,YesNoQuestion}, Describe attribute
  Result/        Result, ResultSet, Answer/{Answer,Choice,Score,YesNoAnswer}, Distribution, Verdict, Meta
  Model/         ModelProfile, LayaProfile, JevProfile, ProfileLimits
  Protocol/      SystemOneCodec (request/response mapping)
  Transport/     Transport, HttpTransport, Response, Redactor
  Client/        Decider (interface), DecisionClient (one per connection)
  Laravel/       SystemOneServiceProvider, SystemOneManager, ConnectionResolver, Facades/SystemOne,
                 Commands/*, Events/*, Testing/{SystemOneFake,FakeConnection,FakeAnswer}
  Exceptions/    SystemOneException marker and the typed errors above
config/system-one.php
docker/
tests/{Unit,Contract,Integration}
```

## Quality bar

### Immutability

- **Every value object, answer, result, profile, connection config and the
  client itself is a `final readonly class`.** The engine then rejects any
  write after construction. This is why we don't use asymmetric visibility
  (`public private(set)`) or property hooks: `private(set)` still allows the
  class to change its own state, and PHP 8.4 doesn't allow hooks on readonly
  properties. Derived values are plain methods instead.
- Collections (questions in a `Decision`, answers in a `Result`, a
  `Distribution`) are built once and exposed only through read methods. They
  never expose a mutable array by reference.
- Every `with*()` method returns a new instance. Tests prove the original is
  unchanged.
- Pest architecture tests enforce all of this: every class in the domain,
  result, model and protocol namespaces must be `final` and `readonly`, and
  the core namespaces must not import `Illuminate\*`.
- The only stateful pieces are Laravel singletons and the testing fake, which
  records the decisions sent to it. Both live under `src/Laravel/`. Since #10 the
  architecture tests check it: every `Laravel` class is final, and readonly except the
  service provider and facade (PHP does not allow it for subclasses of the framework's
  classes) and `Testing\SystemOneFake`; and `Laravel\Testing` is used only by the facade.
  The fake answers decisions itself rather than through a fake `Transport`, so it needs
  no wire encoding of answers and returns the scripted answer objects as they are.

### Testing

- **Tools:** Pest 3 on Orchestra Testbench (Laravel 13, PHP 8.4), PHPStan at
  max level with strict rules, Laravel Pint, and Infection for mutation
  testing.
- **CI gates, all required on every pull request:**
  - 100% line and branch coverage of `src/`.
  - Infection MSI ≥ 90% (covered-code MSI 100%).
  - PHPStan max with no baseline.
  - Pint.
  - Architecture tests.
- **Test layers:**
  - **Unit:** every value object, validation rule and error path.
  - **Type inference:** PHPStan `assertType()` files prove
    `$result->get($q)->value` comes out as the right enum without casting.
  - **Contract:** the codec is checked against fixtures recorded from a real
    `laya-serve`, and request bodies are checked against Jev's OpenAPI schema.
  - **Feature (Testbench):** the service provider, config, facade, fake,
    commands and events.
  - **Integration:** a separate suite runs against the Docker container in CI.
    It is tagged so local runs can skip it when Docker isn't running.
- Each ticket lands with its own tests, and nothing merges with a gate
  failing.

## Phases

0. **Spike and verify (about 1 day).**
   - Run `laya-serve` in Compose.
   - Record real request and response fixtures for each question type, for
     batch calls, for abstention and for each error code.
   - Get Jev's OpenAPI spec (`api.typesafe.ai/openapi.json`) and compare it
     with what we recorded. Confirm whether `noul` answers include
     `answer_confidence`, and how low-confidence answers are marked.
   - Measure CPU latency and RAM on an Intel dev machine and an Apple Silicon
     dev machine.
1. **Docker:** the Dockerfile, the Compose file, a healthcheck, a pinned
   version, and composer scripts (`composer laya:up` and `laya:down`).
2. **Domain core:** value objects, generics and validation. Unit tests, with
   PHPStan at max level and type-inference tests.
3. **Protocol and transport:**
   - `SystemOneCodec`, the profiles and `HttpTransport`.
   - Contract tests against the recorded fixtures and the Jev OpenAPI schema.
   - Integration tests against the container.
4. **Laravel layer:** the provider, config, facade, fake, commands, events,
   install flow and Sail option.
5. **Ergonomics:** `decideMany` (the batch endpoint), caching, retries and
   timeouts, and docs and examples.
6. **Remote hosts:** check the `laya-hosted` and `jev` connections against
   the real APIs.

## Open architectural questions

New questions get added here as they come up.

1. **Which field drives `abstained`?** Phase 0 found that the server reports a
   three-state `abstention` (`passed`, `abstained`, `unevaluated`) plus
   `abstention_threshold`, and a `low_confidence: true` that only appears on
   abstained answers. Decision 10 has a boolean `abstained`. Suggested: derive
   `abstained` from `abstention === 'abstained'` (falling back to
   `low_confidence` when `abstention` is missing), and decide whether
   `unevaluated` needs its own representation or reads as "not abstained".

   **Settled in #7.** Every answer (the `Answer` interface) has `abstained`,
   `abstention` and `abstentionThreshold`. `abstained` is true only for
   `Result\Abstention::Abstained`. `abstention` is that enum: `Passed`,
   `Abstained`, `Unevaluated` (gate ran, no usable confidence) or
   `NotRequested` (no `min_confidence`, so no gate ran). `abstention` decides,
   and `low_confidence: true` without it reads as `Abstained`. The codec reads
   all three fields in one helper (`decodeAbstention`), which each new answer
   type's decoder spreads into its constructor.

   An answer with neither `abstention` nor `low_confidence: true` reads as
   `NotRequested` only when the decision set no `min_confidence`. When it did,
   the answer reads as `Unevaluated`: a gate was asked for and the server gave
   no verdict. It does not read as `Passed`, because a missing (or `false`)
   `low_confidence` is not a pass. Laya also leaves the flag off answers it
   could not evaluate, and its gate docs call reporting those as a pass "the
   same lie as reporting it as a flag". Laya 0.4.8 always sends `abstention`
   when `min_confidence` is set (live and in the fixtures), so this only
   applies to a host that does not, such as Jev, whose spec is still unknown.

2. **How strictly is response metadata read?** `usage` and `routing` are
   informational, and hosts differ (Jev, and laya-serve under
   `LAYA_JEV_STRICT=1`, send no `routing`), so a strict check could cost a
   caller valid answers over a field it may never read.

   **Settled in #7: strict, like the answers.** Each meta field is optional,
   and keys the codec doesn't read are ignored, which already covers the
   known differences between hosts. A field that is present but has the wrong
   shape (`"routing": "english"`, a string token count) raises
   `MalformedResponse` with the raw body. It is not mapped to null. `Meta`'s
   null means "the host did not report this", and turning a broken report
   into a null would make usage totals undercount without any sign. A root
   field changing shape also means the protocol has drifted, so the answers
   in the same body can't be trusted either. laya-serve 0.4.8 always sends
   whole-number token counts (`int(...)` sums in `agent.py` and `serve.py`)
   and a `routing` object (`dict(decision)` in `router.py`), so no recorded
   host trips the check. A per-profile split (strict for Laya, tolerant for
   others) was rejected because it means two behaviours to keep and test, for
   a host whose spec isn't known yet.

   One exception: a token count written as a whole number with a fraction or
   exponent (`12.0`, `1e2`) is the same JSON number as `12` or `100`, so it
   reads as that int. Fractional, negative, infinite and out-of-range values
   are still refused.
