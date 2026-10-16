"""Container healthcheck for laya-serve: healthy only once the configured checkpoints are loaded.

`GET /health` answers as soon as uvicorn binds, so liveness alone does not say the weights are
resident. This probe reads the `loaded` list and compares it with what the container was asked to
preload (`LAYA_MODELS`, empty meaning every bundled checkpoint). With `LAYA_PRELOAD` off nothing
is resident until the first request, so liveness is all there is to wait for.

Stdlib only, so the slim image needs no curl. Exit 0 = healthy, 1 = not (yet).
"""
import json
import os
import sys
import urllib.request

ALL_CHECKPOINTS = ("english", "multilingual", "typed-decisions")
# The aliases `laya.router` accepts in LAYA_MODELS, so the probe reads them the way laya does.
ALIASES = {
    "en": "english", "laya": "english", "default": "english",
    "multi": "multilingual", "ml": "multilingual", "laya-multilingual": "multilingual",
    "typed": "typed-decisions", "typed_decisions": "typed-decisions",
    "laya-typed-decisions": "typed-decisions", "decisions": "typed-decisions",
}


def env_bool(name, default):
    """Same reading as laya.serve: unset or blank means `default`; only four spellings are on."""
    value = os.environ.get(name)
    if value is None or not value.strip():
        return default
    return value.strip().lower() in ("1", "true", "yes", "on")


def expected_checkpoints():
    names = [n.strip().lower() for n in os.environ.get("LAYA_MODELS", "").split(",") if n.strip()]
    return {ALIASES.get(n, n) for n in names} or set(ALL_CHECKPOINTS)


def main():
    port = (os.environ.get("LAYA_PORT") or "8000").strip()
    request = urllib.request.Request("http://127.0.0.1:%s/health" % port)
    key = os.environ.get("LAYA_API_KEY")
    if key:
        # With a key set, an unauthenticated /health returns liveness only, without `loaded`.
        request.add_header("Authorization", "Bearer " + key)
    try:
        with urllib.request.urlopen(request, timeout=4) as response:
            payload = json.load(response)
    except (OSError, ValueError) as error:  # URLError/HTTPError are OSErrors; bad JSON is ValueError
        print("laya-serve not ready: %s" % error)
        return 1
    if payload.get("status") != "ok":
        print("laya-serve status: %r" % payload.get("status"))
        return 1
    if not env_bool("LAYA_PRELOAD", True):
        print("laya-serve is up (lazy loading)")
        return 0
    missing = expected_checkpoints() - set(payload.get("loaded") or [])
    if missing:
        print("waiting for checkpoints: %s" % ", ".join(sorted(missing)))
        return 1
    print("laya-serve ready: %s" % ", ".join(payload.get("loaded")))
    return 0


def verify_against_laya():
    """Check the copied names against the installed laya (run at build time and in CI).

    The probe keeps its own copy because importing laya on every probe costs ~0.4 s of CPU;
    this keeps the copy honest when the pinned laya version changes.
    """
    from laya.router import _ALIASES, DEFAULT_MODELS

    problems = []
    if dict(_ALIASES) != ALIASES:
        problems.append("aliases differ: laya has %r" % (dict(_ALIASES),))
    if set(DEFAULT_MODELS) != set(ALL_CHECKPOINTS):
        problems.append("checkpoints differ: laya has %r" % (sorted(DEFAULT_MODELS),))
    if problems:
        print("healthcheck.py is out of date with the installed laya:\n" + "\n".join(problems))
        return 1
    print("healthcheck.py matches laya's checkpoint names and aliases")
    return 0


if __name__ == "__main__":
    sys.exit(verify_against_laya() if sys.argv[1:] == ["--verify-against-laya"] else main())
