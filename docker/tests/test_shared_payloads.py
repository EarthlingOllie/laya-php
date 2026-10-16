"""bin/laya-measure times the same requests bin/laya-record-fixtures records."""
import importlib.machinery
import importlib.util
import unittest

from _support import ROOT


def load(name):
    loader = importlib.machinery.SourceFileLoader(name.replace("-", "_"), str(ROOT / "bin" / name))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class SharedPayloadsTest(unittest.TestCase):
    def test_both_tools_use_one_definition_of_the_questions(self):
        measure, recorder = load("laya-measure"), load("laya-record-fixtures")
        for name in ("TICKET", "DEPARTMENT", "URGENCY", "CHURN"):
            self.assertIs(getattr(measure, name), getattr(recorder, name), name)


if __name__ == "__main__":
    unittest.main()
