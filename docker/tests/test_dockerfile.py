import re
import unittest

from _support import DOCKER


class DockerfileTest(unittest.TestCase):
    def setUp(self):
        text = (DOCKER / "laya" / "Dockerfile").read_text()
        # Line continuations joined, so each instruction reads as one line.
        self.instructions = re.sub(r"\\\n\s*", " ", text)

    def test_it_is_based_on_slim_python_312(self):
        self.assertRegex(self.instructions, r"(?m)^FROM python:3\.12-slim(\s|$)")

    def test_it_installs_the_cpu_torch_wheel_by_default(self):
        self.assertIn("ARG TORCH_INDEX_URL=https://download.pytorch.org/whl/cpu", self.instructions)
        self.assertRegex(self.instructions,
                         r'--index-url "\$\{TORCH_INDEX_URL\}" "torch==\$\{TORCH_VERSION\}"')

    def test_it_pins_laya_serve_048(self):
        self.assertIn("ARG LAYA_VERSION=0.4.8", self.instructions)
        self.assertIn('"laya[serve]==${LAYA_VERSION}"', self.instructions)

    def test_it_runs_as_a_non_root_user(self):
        users = re.findall(r"(?m)^USER\s+(\S+)", self.instructions)
        self.assertTrue(users, "no USER instruction")
        self.assertNotIn(users[-1], ("root", "0", "0:0"))

    def test_it_does_not_need_apt(self):
        self.assertNotIn("apt-get", self.instructions)

    def test_the_healthcheck_is_the_python_probe(self):
        self.assertRegex(self.instructions, r"(?m)^HEALTHCHECK .*python /opt/laya/healthcheck\.py")

    def test_the_build_fails_if_the_probe_drifted_from_laya(self):
        self.assertRegex(self.instructions, r"(?m)^RUN python /opt/laya/healthcheck\.py --verify-against-laya$")

    def test_it_starts_laya_serve(self):
        self.assertRegex(self.instructions, r'(?m)^CMD \["laya-serve"\]')


if __name__ == "__main__":
    unittest.main()
