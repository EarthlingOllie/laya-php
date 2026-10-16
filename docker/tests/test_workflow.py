"""The integration workflow starts the container in CI and keeps the model cache between runs."""
import json
import shutil
import subprocess
import unittest

from _support import ROOT

WORKFLOW = ROOT / ".github" / "workflows" / "integration.yml"


def steps_text(job):
    return "\n".join(json.dumps(s) for s in job["steps"])


@unittest.skipUnless(shutil.which("ruby"), "ruby is needed to read the workflow YAML")
class IntegrationWorkflowTest(unittest.TestCase):
    def setUp(self):
        out = subprocess.run(["ruby", "-ryaml", "-rjson", "-e",
                              "puts JSON.dump(YAML.safe_load(File.read(ARGV[0])))", str(WORKFLOW)],
                             check=True, capture_output=True, text=True).stdout
        self.workflow = json.loads(out)
        self.jobs = self.workflow["jobs"]

    def test_the_docker_setup_tests_run(self):
        self.assertIn("python3 -m unittest", steps_text(self.jobs["docker-setup"]))

    def test_the_container_job_caches_the_hugging_face_directory(self):
        job = self.jobs["laya-container"]
        cache = [s for s in job["steps"] if s.get("uses", "").startswith("actions/cache@")]
        self.assertEqual(len(cache), 1)
        self.assertEqual(cache[0]["with"]["path"], job["env"]["LAYA_HF_CACHE"])
        # Keyed on the pinned checkpoint, so a new revision downloads fresh weights.
        self.assertIn("55cf4c4", cache[0]["with"]["key"])

    def test_the_container_job_starts_waits_and_always_stops(self):
        job = self.jobs["laya-container"]
        text = steps_text(job)
        self.assertIn("bin/laya-up", text)
        self.assertIn("bin/laya-record-fixtures", text)
        down = [s for s in job["steps"] if "bin/laya-down" in s.get("run", "")]
        self.assertEqual(down[0].get("if"), "always()")
        self.assertEqual(job["env"]["SYSTEM_ONE_URL"], "http://127.0.0.1:8000")

    def test_the_container_job_sets_a_key_so_the_401_path_is_exercised(self):
        self.assertTrue(self.jobs["laya-container"]["env"]["LAYA_API_KEY"])


if __name__ == "__main__":
    unittest.main()
