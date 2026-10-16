"""Shared helpers for the Docker setup tests (stdlib only, run with `python3 -m unittest`)."""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCKER = ROOT / "docker"
# The tests load bin/ scripts as modules; keep their bytecode out of the repo.
sys.dont_write_bytecode = True


def clean_env(extra=None):
    """The current environment without any LAYA_* / SYSTEM_ONE_* overrides, plus `extra`."""
    env = {k: v for k, v in os.environ.items() if not k.startswith(("LAYA_", "SYSTEM_ONE_"))}
    env.update(extra or {})
    return env


def compose_config(*files, env=None):
    """`docker compose config` rendered to JSON, so tests assert on what Compose actually resolves."""
    args = ["docker", "compose"]
    for f in files:
        args += ["-f", str(DOCKER / f)]
    args += ["config", "--format", "json"]
    out = subprocess.run(args, check=True, capture_output=True, text=True, env=clean_env(env))
    return json.loads(out.stdout)
