"""Generated Python bytecode stays out of git."""
import subprocess
import unittest

from _support import ROOT


def git(*args):
    return subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True, text=True)


class RepoHygieneTest(unittest.TestCase):
    def test_no_bytecode_is_tracked(self):
        tracked = git("ls-files").stdout.splitlines()
        self.assertEqual([f for f in tracked if f.endswith(".pyc") or "__pycache__" in f], [])

    def test_bytecode_next_to_our_python_is_ignored(self):
        for path in ("docker/tests/__pycache__/x.cpython-313.pyc", "bin/__pycache__/x.pyc",
                     "bin/lib/__pycache__/x.cpython-313.pyc"):
            self.assertEqual(git("check-ignore", "-q", "--no-index", path).returncode, 0, path)


if __name__ == "__main__":
    unittest.main()
