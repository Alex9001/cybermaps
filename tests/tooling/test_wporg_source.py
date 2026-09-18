#!/usr/bin/env python3
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CHECKER = ROOT / "bin" / "check-wporg-source.php"
FIXTURES = ROOT / "tests" / "fixtures" / "wporg-source"


class WordPressOrgSourceCheckerTest(unittest.TestCase):
    def run_fixture(self, name: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["php", str(CHECKER), f"--fixture={FIXTURES / name}"],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
        )

    def test_valid_fixture_passes(self) -> None:
        result = self.run_fixture("valid")
        self.assertEqual(0, result.returncode, result.stderr)

    def test_each_guard_rejects_its_fixture(self) -> None:
        cases = {
            "inline-asset": "[inline-asset]",
            "phpcs-suppression": "[phpcs-suppression]",
            "request-boundary": "[request-boundary]",
            "json-flags": "[json-flags]",
            "reserved-transient": "[reserved-transient]",
            "sql-list": "[sql-list]",
            "core-bootstrap": "[core-bootstrap]",
            "core-include-order": "[core-include-order]",
        }
        for fixture, marker in cases.items():
            with self.subTest(fixture=fixture):
                result = self.run_fixture(fixture)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(marker, result.stderr)


if __name__ == "__main__":
    unittest.main()
