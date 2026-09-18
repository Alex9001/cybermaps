#!/usr/bin/env python3
import json
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CHECKER = ROOT / "bin" / "check-runtime-baseline.php"
FIXTURES = ROOT / "tests" / "fixtures" / "runtime-baseline"


class RuntimeBaselineCheckerTest(unittest.TestCase):
    def run_fixture(self, name: str) -> subprocess.CompletedProcess[str]:
        fixture = json.loads((FIXTURES / f"{name}.json").read_text())
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_fixture(root, fixture)
            return subprocess.run(
                ["php", str(CHECKER), f"--root={root}"],
                cwd=ROOT,
                check=False,
                capture_output=True,
                text=True,
            )

    @staticmethod
    def write_fixture(root: Path, fixture: dict[str, str]) -> None:
        versions = {
            "plugin": fixture["declared"],
            "readme": fixture.get("readme", fixture["declared"]),
            "manifest": fixture.get("manifest", fixture["declared"]),
            "docs": fixture.get("docs", fixture["declared"]),
            "phpcs": fixture.get("phpcs", fixture["declared"]),
        }
        files = {
            "cybermaps.php": f" * Requires at least: {versions['plugin']}\n",
            "readme.txt": f"Requires at least: {versions['readme']}\n",
            "docs/dev/manifest.json": json.dumps({"wp_min": versions["manifest"]}),
            "docs/documentation.md": f"> Version 7.5.2 · PHP 8.2 · WordPress {versions['docs']}\n",
            "docs/features.md": f"> Standalone WordPress.org plugin · PHP 8.2+ · WordPress {versions['docs']}+\n",
            "docs/comparison.md": f"CYBERMAPS requires PHP 8.2+ and WordPress {versions['docs']}+.\n",
            "CONTRIBUTING.md": f"Cybermaps requires PHP 8.2+ and WordPress {versions['docs']}+.\n",
            "AGENTS.md": f"- Language/runtime: PHP 8.2+, WordPress {versions['docs']}+\n",
            "phpcs.xml.dist": f'<config name="minimum_wp_version" value="{versions["phpcs"]}"/>\n',
            "docs/dev/wordpress-org-reviewer-response-7.5.2.md": f"WordPress {versions['docs']} / PHP 8.2 environment\n",
            "docs/dev/runtime-baseline.json": json.dumps(
                {
                    "schema_version": 1,
                    "minimum_floor_wordpress": fixture["floor"],
                    "release_runtime_wordpress": fixture.get("runtime", fixture["declared"]),
                    "basis": "Native Abilities API contract.",
                }
            ),
        }
        for relative, contents in files.items():
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(contents)

    def test_wordpress_7_0_is_rejected(self) -> None:
        result = self.run_fixture("wordpress-7.0-rejected")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("below the independent 7.1 product floor", result.stderr)

    def test_wordpress_7_1_passes(self) -> None:
        result = self.run_fixture("wordpress-7.1-valid")
        self.assertEqual(0, result.returncode, result.stderr)

    def test_metadata_drift_fails(self) -> None:
        result = self.run_fixture("metadata-drift")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("baseline drift", result.stderr)

    def test_release_runtime_drift_fails(self) -> None:
        result = self.run_fixture("runtime-drift")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("must exactly match", result.stderr)

    def test_future_intentional_baseline_increase_passes(self) -> None:
        result = self.run_fixture("future-increase")
        self.assertEqual(0, result.returncode, result.stderr)


if __name__ == "__main__":
    unittest.main()
