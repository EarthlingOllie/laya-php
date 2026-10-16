"""bin/fetch-jev-openapi saves Jev's OpenAPI document only when it is valid JSON."""
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

from _support import ROOT, clean_env

FETCH = ROOT / "bin" / "fetch-jev-openapi"


class FetchJevOpenApiTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.dir = Path(tmp.name)
        self.target = self.dir / "out" / "openapi.json"

    def fetch(self, source):
        return subprocess.run([str(FETCH)], capture_output=True, text=True, timeout=30,
                              env=clean_env({"JEV_OPENAPI_URL": source.as_uri(),
                                             "JEV_OPENAPI_OUT": str(self.target)}))

    def test_it_saves_a_valid_document_pretty_printed(self):
        source = self.dir / "spec.json"
        source.write_text('{"openapi":"3.1.0","paths":{"/v1/systemone":{}}}')
        result = self.fetch(source)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(self.target.read_text())["openapi"], "3.1.0")
        self.assertIn("\n", self.target.read_text())

    def test_it_refuses_something_that_is_not_an_openapi_document(self):
        source = self.dir / "spec.json"
        source.write_text("<html>blocked</html>")
        result = self.fetch(source)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.target.exists())

    def test_it_fails_when_the_host_is_unreachable(self):
        result = self.fetch(self.dir / "missing.json")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.target.exists())


if __name__ == "__main__":
    unittest.main()
