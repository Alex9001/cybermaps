#!/usr/bin/env python3
import subprocess
import unittest
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CHECKER = ROOT / "bin" / "check-wporg-source.php"
FIXTURES = ROOT / "tests" / "fixtures" / "wporg-source"


class WordPressOrgSourceCheckerTest(unittest.TestCase):
    def check_source(self, source):
        with tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp') as temporary:
            Path(temporary, 'fixture.php').write_text('<?php\n' + source)
            return subprocess.run(['php', str(CHECKER), '--fixture=' + temporary],
                                  cwd=ROOT, capture_output=True, text=True, timeout=10)

    def test_output_buffer_mutations_and_imports_are_rejected(self):
        mutators = ('ob_start', 'ob_clean', 'ob_end_clean', 'ob_end_flush',
                    'ob_flush', 'ob_get_clean', 'ob_get_flush', 'ob_implicit_flush')
        for name in mutators:
            for source in (name + '();', '\\' + name.upper() + ' /* comment */ ();',
                           'namespace\\' + name + '();', 'namespace { namespace\\' + name + '(); }',
                           name + '(...);', 'use function \\' + name + ' as hidden; hidden();',
                           'use function strlen, ' + name + ' as hidden; hidden();',
                           'use function strlen, \\' + name + ' as hidden; hidden();'):
                with self.subTest(source=source):
                    result = self.check_source(source)
                    self.assertNotEqual(0, result.returncode)
                    self.assertIn('[output-buffer]', result.stderr)

    def test_buffer_guard_ignores_text_queries_and_method_or_function_declarations(self):
        result = self.check_source('''
// ob_start();
$text = 'ob_end_clean()';
$level = ob_get_level(); $status = \\ob_get_status(true); $body = ob_get_contents();
$object->ob_start(); $object?->ob_clean(); Example::ob_end_flush();
use function Example\\ob_start as unrelated;
use function Example\\{ob_start, ob_clean};
use function strlen as ob_end_clean;
function ob_start() {} function &ob_clean() { static $value; return $value; }
''')
        self.assertEqual(0, result.returncode, result.stderr)

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
            "server-bag": "[server-bag]",
        }
        for fixture, marker in cases.items():
            with self.subTest(fixture=fixture):
                result = self.run_fixture(fixture)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(marker, result.stderr)


if __name__ == "__main__":
    unittest.main()
