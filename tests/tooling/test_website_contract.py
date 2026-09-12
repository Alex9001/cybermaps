#!/usr/bin/env python3
"""Exercise the website exporter against isolated source; no WordPress or network."""
import json
import os
from pathlib import Path
import subprocess
import tarfile
import io
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WebsiteContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.source = Path(self.temp.name)
        archive = subprocess.check_output(['git', 'archive', 'HEAD'], cwd=ROOT)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            tar.extractall(self.source, filter='data')

    def export(self, success=True):
        result = subprocess.run(['php', str(ROOT / 'docs/dev/generate-docs.php'), '--website'],
                                env=dict(os.environ, CYBERMAPS_DOCS_SOURCE_ROOT=str(self.source)),
                                text=True, capture_output=True)
        if success:
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stderr, '')
            return json.loads(result.stdout)
        self.assertNotEqual(result.returncode, 0)
        return result.stderr

    def test_public_route_inventory_includes_non_registry_routes(self):
        facts = self.export()
        paths = {route['path'] for route in facts['routes']}
        self.assertEqual(facts['route_count'], len(paths))
        self.assertEqual(len(paths), 64)
        self.assertTrue({'/robots.txt', '/{key}.txt', '/cybermaps-agent-auth',
                         '/wp-json/cybermaps/v1/editor/translation/{post_id}',
                         '/wp-json/cybermaps/v1/oauth/authorize'} <= paths)
        self.assertEqual(facts['limits']['llms_full_bytes'], 33554431)
        self.assertEqual(facts['limits']['llms_summary_bytes'], 4194303)

    def test_runtime_constant_changes_flow_to_export(self):
        file = self.source / 'src/Discovery/LLMS.php'
        file.write_text(file.read_text().replace('( 32 * 1024 * 1024 ) - 1', '( 16 * 1024 * 1024 ) - 1'))
        self.assertEqual(self.export()['limits']['llms_full_bytes'], 16777215)

    def test_new_rest_owner_requires_explicit_inventory_support(self):
        (self.source / 'src/NewRoute.php').write_text("<?php namespace Cybermaps; class NewRoute { public function register() { register_rest_route('cybermaps/v1', '/new', []); } }")
        self.assertIn('Add REST registration owner', self.export(False))

    def test_new_route_on_existing_owner_is_exported(self):
        file = self.source / 'src/Core/RestAPI.php'
        file.write_text(file.read_text().replace('public function register_routes() {',
            "public function register_routes() { register_rest_route('cybermaps/v1', '/new-route', []);"))
        self.assertIn('/wp-json/cybermaps/v1/new-route', {route['path'] for route in self.export()['routes']})

    def test_new_source_file_is_covered_by_review_hashes(self):
        (self.source / 'src/Discovery/NewPublication.php').write_text('<?php // new behavior\n')
        self.assertIn('src/Discovery/NewPublication.php', self.export()['source_files'])

    def test_export_is_deterministic(self):
        self.assertEqual(self.export(), self.export())


if __name__ == '__main__':
    unittest.main()
