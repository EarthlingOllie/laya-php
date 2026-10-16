"""`composer laya:up` / `laya:down` and the shell scripts they delegate to, driven with a fake docker."""
import json
import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

from _support import DOCKER, ROOT, clean_env

FAKE_DOCKER = """#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_DOCKER_LOG"
exit "${FAKE_DOCKER_EXIT:-0}"
"""


class ComposerScriptsTest(unittest.TestCase):
    def setUp(self):
        self.composer = json.loads((ROOT / "composer.json").read_text())

    def test_package_name(self):
        self.assertEqual(self.composer["name"], "earthlingollie/laravel-system-one-classification")

    def test_laya_scripts_delegate_to_bin_without_composers_timeout(self):
        for name, script in (("laya:up", "bin/laya-up"), ("laya:down", "bin/laya-down")):
            entries = self.composer["scripts"][name]
            # The first start downloads ~0.85 GB; Composer would kill it at its 300 s default.
            self.assertEqual(entries[0], "Composer\\Config::disableProcessTimeout")
            self.assertEqual(entries[1], script)
            mode = (ROOT / script).stat().st_mode
            self.assertTrue(mode & stat.S_IXUSR, "%s is not executable" % script)


class ScriptBehaviourTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.bin = Path(tmp.name)
        self.log = self.bin / "docker.log"
        fake = self.bin / "docker"
        fake.write_text(FAKE_DOCKER)
        fake.chmod(0o755)

    def run_script(self, name, **env):
        env = clean_env({"PATH": "%s:%s" % (self.bin, os.environ["PATH"]),
                         "FAKE_DOCKER_LOG": str(self.log), **env})
        result = subprocess.run([str(ROOT / "bin" / name)], capture_output=True, text=True,
                                env=env, cwd="/", timeout=30)
        calls = self.log.read_text().splitlines() if self.log.exists() else []
        return result, calls

    def test_up_starts_detached_and_waits_for_healthy(self):
        result, calls = self.run_script("laya-up")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls[0], "compose -f %s up -d --wait --wait-timeout 1800"
                         % (DOCKER / "compose.yaml"))

    def test_up_wait_timeout_is_configurable(self):
        _, calls = self.run_script("laya-up", LAYA_WAIT_TIMEOUT="60")
        self.assertTrue(calls[0].endswith("--wait-timeout 60"))

    def test_up_adds_the_cuda_override_on_request(self):
        _, calls = self.run_script("laya-up", LAYA_CUDA="1")
        self.assertIn("-f %s -f %s up" % (DOCKER / "compose.yaml", DOCKER / "compose.cuda.yaml"), calls[0])

    def test_up_and_down_add_an_extra_override_file(self):
        _, up = self.run_script("laya-up", LAYA_COMPOSE_OVERRIDE="/srv/my-override.yaml")
        _, calls = self.run_script("laya-down", LAYA_COMPOSE_OVERRIDE="/srv/my-override.yaml")
        self.assertIn("-f %s -f /srv/my-override.yaml up" % (DOCKER / "compose.yaml"), up[0])
        self.assertEqual(calls[-1], "compose -f %s -f /srv/my-override.yaml down" % (DOCKER / "compose.yaml"))

    def test_up_fails_and_shows_logs_when_the_server_never_gets_healthy(self):
        result, calls = self.run_script("laya-up", FAKE_DOCKER_EXIT="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(any(" logs " in " %s " % c for c in calls), calls)

    def test_down_stops_but_keeps_the_weights_volume(self):
        result, calls = self.run_script("laya-down")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, ["compose -f %s down" % (DOCKER / "compose.yaml")])

    def test_scripts_explain_when_docker_is_missing(self):
        env = clean_env({"PATH": "/nonexistent"})
        result = subprocess.run(["/bin/bash", str(ROOT / "bin" / "laya-up")], capture_output=True,
                                text=True, env=env, timeout=30)
        self.assertEqual(result.returncode, 127)
        self.assertIn("docker", result.stderr.lower())


if __name__ == "__main__":
    unittest.main()
