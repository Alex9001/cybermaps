"""Mandatory assertions must never disappear under an optimized interpreter."""
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]


class OptimizationTest(unittest.TestCase):
    def test_runtime_fixtures_and_entrypoints_reject_optimization_before_work(self):
        paths = ['bin/workspace.py', 'bin/release_gate.py',
                 'tests/integration/wporg-http.py', 'tests/integration/wporg-mcp.py',
                 'tests/integration/wporg-mcp-stdio.py', 'tests/integration/sitemap_xsl.py',
                 'tests/integration/publication-http.py']
        for level in ('1', '2'):
            for path in paths:
                with self.subTest(level=level, path=path):
                    result = subprocess.run([sys.executable, '-B', str(ROOT / path)],
                                            env=dict(os.environ, PYTHONOPTIMIZE=level,
                                                     TMPDIR=str(ROOT / 'docs/generated/tmp'),
                                                     TMP=str(ROOT / 'docs/generated/tmp'),
                                                     TEMP=str(ROOT / 'docs/generated/tmp')),
                                            text=True, capture_output=True)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('Validation refuses optimized Python', result.stderr)
                    self.assertNotIn('passed', result.stdout.lower())


if __name__ == '__main__':
    unittest.main()
