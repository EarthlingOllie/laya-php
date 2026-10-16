"""bin/laya-record-fixtures, driven against a small stand-in for laya-serve."""
import json
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

from _support import ROOT, clean_env

RECORDER = ROOT / "bin" / "laya-record-fixtures"
KEY = "fixture-key"
REVISION = "55cf4c4ebb4ebe31b2550e8bdf3bd21b99753851"
REQUIRED = {
    "choice", "score", "noul", "multiple_questions", "batch", "below_min_confidence",
    "unauthorized_bad_key", "too_many_options", "invalid_request",
}
# Scenarios that reach inference and so need the checkpoint weights.
MODEL_SCENARIOS = {
    "choice", "score", "noul", "multiple_questions", "batch", "below_min_confidence",
    "batch_below_min_confidence",
}


class MiniLaya:
    """Enough of laya-serve's rules to exercise every scenario the recorder sends."""

    def __init__(self, abstain=True, openapi_status=200, revisions=None):
        owner = self
        self.revisions = {"english": REVISION} if revisions is None else revisions
        self.abstain = abstain
        self.openapi_status = openapi_status

        class Handler(BaseHTTPRequestHandler):
            def _send(self, status, payload, extra=None):
                body = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("X-Inference-Time-Ms", "12.34")
                self.send_header("Date", "Thu, 01 Jan 2026 00:00:00 GMT")
                for k, v in (extra or {}).items():
                    self.send_header(k, v)
                self.end_headers()
                self.wfile.write(body)

            def _authorized(self):
                return self.headers.get("Authorization") == "Bearer " + KEY

            def do_GET(self):
                if self.path == "/health":
                    payload = {"status": "ok"}
                    if self._authorized():
                        payload.update(loaded=list(owner.revisions), revisions=owner.revisions)
                    return self._send(200, payload)
                if self.path == "/openapi.json":
                    if owner.openapi_status != 200:
                        return self._send(owner.openapi_status, {"detail": "Internal Server Error"})
                    return self._send(200, {"openapi": "3.1.0", "info": {"title": "laya-serve"}})
                self._send(404, {"detail": "Not Found"})

            def do_POST(self):
                if not self._authorized():
                    return self._send(401, {"detail": "invalid or missing bearer token"})
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                if self.path == "/v1/systemone/batch":
                    return self._send(200, {"results": [owner.answer(body, body["questions"]) for _ in body["states"]],
                                            "total_usage": {"input_tokens": 1, "output_tokens": 0}})
                if body.get("state") is None:
                    return self._send(400, {"detail": "'state' is required"})
                for qid, q in body["questions"].items():
                    if q["type"] == "choice" and len(q["criteria"]) > 100:
                        return self._send(413, {"detail": "too many choice options"})
                    if q["type"] == "score" and None in q["criteria"]:
                        return self._send(422, {"detail": "null level"})
                if body.get("min_confidence", 0) > 1:
                    return self._send(422, {"detail": "min_confidence must be a float in [0.0, 1.0]"})
                if "/" in str(body.get("model", "")):
                    return self._send(422, {"detail": "unknown model"})
                self._send(200, owner.answer(body, body["questions"]))

            def log_message(self, *args):
                pass

        self.server = HTTPServer(("127.0.0.1", 0), Handler)
        self.url = "http://127.0.0.1:%d" % self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def answer(self, body, questions):
        answers = {}
        for qid, q in questions.items():
            a = {"type": q["type"], "answer_confidence": 0.5}
            if "min_confidence" in body:
                a["abstention"] = "abstained" if self.abstain else "passed"
                if self.abstain:
                    a["low_confidence"] = True
            answers[qid] = a
        return {"model": "laya", "answers": answers, "usage": {"input_tokens": 1, "output_tokens": 0}}

    def close(self):
        self.server.shutdown()
        self.server.server_close()


class RecorderTest(unittest.TestCase):
    def record(self, laya, *extra):
        out = Path(self.tmp.name)
        result = subprocess.run(
            [sys.executable, str(RECORDER), "--url", laya.url, "--out", str(out),
             "--laya-version", "0.4.8", *extra],
            capture_output=True, text=True, timeout=60, env=clean_env({"LAYA_API_KEY": KEY}))
        return result, out

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.laya = MiniLaya()
        self.addCleanup(self.laya.close)

    def test_it_records_every_required_scenario_with_its_status(self):
        result, out = self.record(self.laya)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        manifest = json.loads((out / "manifest.json").read_text())
        names = {s["name"] for s in manifest["scenarios"]}
        self.assertLessEqual(REQUIRED, names)
        for s in manifest["scenarios"]:
            fixture = json.loads((out / s["file"]).read_text())
            self.assertEqual(fixture["response"]["status"], s["status"], s["name"])
        statuses = {s["name"]: s["status"] for s in manifest["scenarios"]}
        self.assertEqual(statuses["unauthorized_bad_key"], 401)
        self.assertEqual(statuses["too_many_options"], 413)
        self.assertEqual(statuses["invalid_request"], 422)
        self.assertEqual(statuses["batch"], 200)

    def test_the_manifest_names_the_laya_version_and_checkpoint_revision(self):
        _, out = self.record(self.laya)
        manifest = json.loads((out / "manifest.json").read_text())
        self.assertEqual(manifest["laya_version"], "0.4.8")
        self.assertEqual(manifest["checkpoints"], {"english": REVISION})

    def test_a_fixture_holds_the_request_and_the_response(self):
        _, out = self.record(self.laya)
        fixture = json.loads((out / "choice.json").read_text())
        self.assertEqual(fixture["request"]["method"], "POST")
        self.assertEqual(fixture["request"]["path"], "/v1/systemone")
        self.assertEqual(fixture["request"]["body"]["questions"]["department"]["type"], "choice")
        self.assertEqual(fixture["response"]["headers"]["content-type"], "application/json")
        self.assertIn("answers", fixture["response"]["body"])

    def test_the_real_key_and_volatile_headers_are_not_written(self):
        _, out = self.record(self.laya)
        for path in out.glob("*.json"):
            text = path.read_text()
            self.assertNotIn(KEY, text, path.name)
        fixture = json.loads((out / "choice.json").read_text())
        self.assertEqual(fixture["request"]["headers"]["Authorization"], "Bearer <valid-key>")
        self.assertNotIn("date", fixture["response"]["headers"])
        self.assertNotIn("x-inference-time-ms", fixture["response"]["headers"])
        bad = json.loads((out / "unauthorized_bad_key.json").read_text())
        self.assertEqual(bad["request"]["headers"]["Authorization"], "Bearer <wrong-key>")

    def test_it_fails_when_min_confidence_did_not_mark_an_answer(self):
        laya = MiniLaya(abstain=False)
        self.addCleanup(laya.close)
        result, _ = self.record(laya)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("below_min_confidence", result.stdout + result.stderr)

    def test_skip_inference_records_only_what_needs_no_checkpoint(self):
        result, out = self.record(self.laya, "--skip-inference")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        manifest = json.loads((out / "manifest.json").read_text())
        names = {s["name"] for s in manifest["scenarios"]}
        self.assertLessEqual({"unauthorized_bad_key", "too_many_options", "invalid_request"}, names)
        self.assertEqual(names & MODEL_SCENARIOS, set())
        self.assertEqual(set(manifest["pending"]), MODEL_SCENARIOS)
        self.assertFalse((out / "choice.json").exists())

    def test_a_full_recording_leaves_nothing_pending(self):
        _, out = self.record(self.laya)
        self.assertEqual(json.loads((out / "manifest.json").read_text())["pending"], {})

    def seed_existing_fixtures(self):
        out = Path(self.tmp.name)
        (out / "manifest.json").write_text('{"old": true}\n')
        (out / "unauthorized_bad_key.json").write_text('{"old": true}\n')
        (out / "choice.json").write_text('{"old": true}\n')
        return out

    def assert_untouched(self, out):
        for name in ("manifest.json", "unauthorized_bad_key.json", "choice.json"):
            self.assertEqual((out / name).read_text(), '{"old": true}\n', name)

    def test_a_failed_recording_leaves_the_existing_fixtures_untouched(self):
        out = self.seed_existing_fixtures()
        laya = MiniLaya(abstain=False)
        self.addCleanup(laya.close)
        result, _ = self.record(laya)
        self.assertNotEqual(result.returncode, 0)
        self.assert_untouched(out)

    def test_a_broken_openapi_response_fails_without_touching_fixtures(self):
        out = self.seed_existing_fixtures()
        laya = MiniLaya(openapi_status=500)
        self.addCleanup(laya.close)
        result, _ = self.record(laya)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("Traceback", result.stderr)
        self.assertIn("openapi", result.stderr)
        self.assert_untouched(out)

    def test_skip_inference_removes_fixtures_of_pending_scenarios(self):
        out = self.seed_existing_fixtures()
        result, _ = self.record(self.laya, "--skip-inference")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((out / "choice.json").exists())

    def test_an_unreachable_server_is_a_clear_error(self):
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            port = s.getsockname()[1]
        result = subprocess.run([sys.executable, str(RECORDER), "--url", "http://127.0.0.1:%d" % port,
                                 "--out", self.tmp.name, "--laya-version", "0.4.8"],
                                capture_output=True, text=True, timeout=60,
                                env=clean_env({"LAYA_API_KEY": KEY}))
        self.assertEqual(result.returncode, 2)
        self.assertNotIn("Traceback", result.stderr)
        self.assertIn("could not reach", result.stderr)

    def test_a_checkpoint_without_a_revision_is_not_provenance(self):
        # A local-path checkpoint reports revision null; that names no Hub commit.
        laya = MiniLaya(revisions={"mine": None})
        self.addCleanup(laya.close)
        result = subprocess.run([sys.executable, str(RECORDER), "--url", laya.url.replace("127.0.0.1", "localhost"),
                                 "--out", self.tmp.name, "--laya-version", "0.4.8"],
                                capture_output=True, text=True, timeout=60,
                                env=clean_env({"LAYA_API_KEY": KEY, "PATH": "/nonexistent"}))
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("revision", result.stderr)

    def test_a_remote_server_needs_its_version_given_explicitly(self):
        # The local container cannot vouch for a server somewhere else.
        result = subprocess.run([sys.executable, str(RECORDER), "--url", "http://laya.example.invalid:8000",
                                 "--out", self.tmp.name], capture_output=True, text=True, timeout=60,
                                env=clean_env({"LAYA_API_KEY": KEY}))
        self.assertEqual(result.returncode, 2)
        self.assertIn("--laya-version", result.stderr)

    def test_it_needs_the_server_key_to_record_the_401(self):
        result = subprocess.run([sys.executable, str(RECORDER), "--url", self.laya.url,
                                 "--out", self.tmp.name, "--laya-version", "0.4.8"],
                                capture_output=True, text=True, timeout=60, env=clean_env())
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("LAYA_API_KEY", result.stderr)


class CommittedFixturesTest(unittest.TestCase):
    """The fixtures in the repo were recorded from a real laya-serve and stay complete."""

    DIR = ROOT / "tests" / "Fixtures" / "laya"

    def setUp(self):
        self.manifest = json.loads((self.DIR / "manifest.json").read_text())

    def test_every_required_scenario_is_recorded_or_pending_with_a_reason(self):
        names = {s["name"] for s in self.manifest["scenarios"]}
        pending = self.manifest["pending"]
        self.assertLessEqual(REQUIRED, names | set(pending))
        self.assertTrue(all(reason.strip() for reason in pending.values()))
        for s in self.manifest["scenarios"]:
            fixture = json.loads((self.DIR / s["file"]).read_text())
            self.assertEqual(fixture["response"]["status"], s["status"])

    def test_the_error_fixtures_are_recorded(self):
        statuses = {s["name"]: s["status"] for s in self.manifest["scenarios"]}
        self.assertEqual(statuses["unauthorized_bad_key"], 401)
        self.assertEqual(statuses["too_many_options"], 413)
        self.assertEqual(statuses["invalid_request"], 422)

    def test_they_name_laya_048_and_the_reviewed_english_checkpoint(self):
        self.assertEqual(self.manifest["laya_version"], "0.4.8")
        self.assertEqual(self.manifest["checkpoints"]["english"], REVISION)

    @unittest.expectedFailure
    def test_no_scenario_is_pending(self):
        # Expected to fail until the model-backed fixtures are recorded: the sandbox they were
        # first recorded in could not download the weights (see docs/PLAN.md, Phase 0 findings).
        # Once `bin/laya-record-fixtures` runs against a server with the checkpoint loaded, this
        # "unexpectedly passes" -- remove the decorator then.
        self.assertEqual(self.manifest["pending"], {})


if __name__ == "__main__":
    unittest.main()
