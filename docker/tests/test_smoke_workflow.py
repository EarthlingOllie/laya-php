"""The remote-host smoke tests are opt-in: run by hand only, never by default CI or the default suites."""
import json
import re
import shutil
import subprocess
import unittest

from _support import ROOT

WORKFLOWS = ROOT / ".github" / "workflows"
SMOKE = WORKFLOWS / "smoke.yml"


def load(path):
    out = subprocess.run(["ruby", "-ryaml", "-rjson", "-e",
                          "puts JSON.dump(YAML.safe_load(File.read(ARGV[0])))", str(path)],
                         check=True, capture_output=True, text=True).stdout
    return json.loads(out)


def triggers(workflow):
    # YAML 1.1 reads a bare `on:` key as true, which JSON writes as "true".
    return workflow.get("on", workflow.get("true"))


@unittest.skipUnless(shutil.which("ruby"), "ruby is needed to read the workflow YAML")
class SmokeWorkflowTest(unittest.TestCase):
    def test_it_runs_only_when_started_by_hand(self):
        self.assertEqual(list(triggers(load(SMOKE))), ["workflow_dispatch"])

    def test_it_runs_the_smoke_suite_and_requires_the_hosts_chosen(self):
        job = load(SMOKE)["jobs"]["smoke"]
        runs = [s.get("run", "") for s in job["steps"]]
        self.assertIn("composer test:smoke", runs)
        self.assertEqual(job["env"]["SYSTEM_ONE_REQUIRE_SMOKE"], "${{ inputs.hosts }}")
        for host, secret in (("laya-hosted", "SYSTEM_ONE_LAYA_HOSTED_API_KEY"), ("jev", "SYSTEM_ONE_JEV_API_KEY")):
            self.assertIn(f"secrets.{secret}", job["env"][secret])
            self.assertIn(f"contains(inputs.hosts, '{host}')", job["env"][secret])

    def test_no_other_workflow_runs_the_smoke_suite_or_holds_a_remote_key(self):
        for path in WORKFLOWS.glob("*.yml"):
            if path == SMOKE:
                continue
            text = path.read_text()
            with self.subTest(workflow=path.name):
                self.assertNotIn("test:smoke", text)
                self.assertNotRegex(text, r"--testsuite[= ]+\S*Smoke")
                self.assertNotIn("tests/Smoke", text)
                self.assertNotIn("LAYA_HOSTED_API_KEY", text)
                self.assertNotIn("JEV_API_KEY", text)


class SmokeSuiteTest(unittest.TestCase):
    def test_the_default_suites_leave_smoke_out(self):
        config = (ROOT / "phpunit.xml.dist").read_text()
        default = re.search(r'defaultTestSuite="([^"]+)"', config).group(1).split(",")
        self.assertNotIn("Smoke", default)
        self.assertIn('<testsuite name="Smoke">', config)

    def test_composer_test_runs_only_the_default_suites(self):
        scripts = json.loads((ROOT / "composer.json").read_text())["scripts"]
        self.assertEqual(scripts["test"], "@php vendor/bin/pest")
        self.assertEqual(scripts["test:smoke"], "@php vendor/bin/pest --testsuite=Smoke")


if __name__ == "__main__":
    unittest.main()
