"""bin/laya-measure: latency figures from a running laya-serve."""
import importlib.machinery
import importlib.util
import json
import subprocess
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

from _support import ROOT, clean_env

MEASURE = ROOT / "bin" / "laya-measure"


class TimedLaya:
    def __init__(self, status=200):
        self.bodies = []
        self.status = status
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                owner.bodies.append((self.path, self.headers.get("Authorization"), body))
                payload = json.dumps({"answers": {}}).encode()
                self.send_response(owner.status)
                self.send_header("Content-Type", "application/json")
                self.send_header("X-Inference-Time-Ms", "100.00")
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, *args):
                pass

        self.server = HTTPServer(("127.0.0.1", 0), Handler)
        self.url = "http://127.0.0.1:%d" % self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def load_measure():
    loader = importlib.machinery.SourceFileLoader("laya_measure", str(MEASURE))
    spec = importlib.util.spec_from_loader("laya_measure", loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class SummaryTest(unittest.TestCase):
    def test_p95_is_the_nearest_rank(self):
        summary = load_measure().summary
        # Nearest rank: the ceil(0.95 n)-th smallest value.
        self.assertEqual(summary(range(1, 31))["p95"], 29)    # ceil(28.5) = 29
        self.assertEqual(summary(range(1, 21))["p95"], 19)    # ceil(19) = 19
        self.assertEqual(summary(range(1, 71))["p95"], 67)    # ceil(66.5) = 67
        self.assertEqual(summary([5])["p95"], 5)


class MeasureTest(unittest.TestCase):
    def setUp(self):
        self.laya = TimedLaya()
        self.addCleanup(self.laya.close)

    def run_measure(self, *args, **env):
        result = subprocess.run([sys.executable, str(MEASURE), "--url", self.laya.url, "--runs", "3",
                                 "--warmup", "1", "--no-docker", *args],
                                capture_output=True, text=True, timeout=60, env=clean_env(env))
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_it_reports_inference_and_wall_time_per_scenario(self):
        report = self.run_measure()
        self.assertEqual(set(report["scenarios"]), {"noul_1q", "mixed_3q", "batch_8_states_2q"})
        for figures in report["scenarios"].values():
            self.assertEqual(figures["runs"], 3)
            self.assertEqual(figures["inference_ms"]["p50"], 100.0)
            self.assertGreater(figures["wall_ms"]["p50"], 0)
        self.assertIn("cpu", report["machine"])

    def test_it_sends_warmup_plus_runs_per_scenario(self):
        self.run_measure()
        self.assertEqual(len(self.laya.bodies), 3 * (1 + 3))
        self.assertEqual(sum(1 for p, _, _ in self.laya.bodies if p == "/v1/systemone/batch"), 4)

    def test_a_failing_server_is_reported_without_a_traceback(self):
        laya = TimedLaya(status=500)
        self.addCleanup(laya.close)
        result = subprocess.run([sys.executable, str(MEASURE), "--url", laya.url, "--runs", "1",
                                 "--warmup", "0", "--no-docker"], capture_output=True, text=True,
                                timeout=60, env=clean_env())
        self.assertEqual(result.returncode, 1)
        self.assertNotIn("Traceback", result.stderr)
        self.assertIn("HTTP 500", result.stderr)

    def test_it_can_pin_a_model_and_send_the_key(self):
        self.run_measure("--model", "english", LAYA_API_KEY="k")
        self.assertTrue(all(b.get("model") == "english" for _, _, b in self.laya.bodies))
        self.assertTrue(all(a == "Bearer k" for _, a, _ in self.laya.bodies))


if __name__ == "__main__":
    unittest.main()
