"""Negative release guards: source scanning cannot be replaced by MCP annotations."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MCPContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=ROOT / 'docs/generated/tmp')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        shutil.copytree(ROOT / 'src', self.root / 'src')
        (self.root / 'bin').mkdir()
        (self.root / 'docs/dev').mkdir(parents=True)
        shutil.copy2(ROOT / 'bin/check-mcp-contract.php', self.root / 'bin/check-mcp-contract.php')
        shutil.copy2(ROOT / 'docs/dev/mcp-contract.json', self.root / 'docs/dev/mcp-contract.json')

    def check(self):
        return subprocess.run(['php', str(self.root / 'bin/check-mcp-contract.php')], capture_output=True, text=True)

    def test_reviewed_contract_passes(self):
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_identity_switch_and_generic_dispatch_fail_even_with_readonly_annotation(self):
        path = self.root / 'src/Injected.php'
        for code in ('wp_set_current_user(1);', 'wp_get_abilities();', '$bridge->execute_tool($input);', 'wp_register_ability("evil/read", []);'):
            with self.subTest(code=code):
                path.write_text("<?php // readonly: true\n" + code)
                self.assertNotEqual(self.check().returncode, 0)

    def test_retired_modes_and_empty_upgrade_placeholder_fail(self):
        path = self.root / 'src/Injected.php'
        for code in ("$settings['mcp_mode'];", "$settings['agent_registration_mode'];", 'function upgrade_oauth_schema() { return true; }'):
            with self.subTest(code=code):
                path.write_text('<?php ' + code)
                self.assertNotEqual(self.check().returncode, 0)

    def test_unreviewed_transport_and_contract_change_fail(self):
        path = self.root / 'src/MCP/Transport.php'
        path.write_text('<?php // new transport')
        self.assertNotEqual(self.check().returncode, 0)
        path.unlink()
        contract = self.root / 'docs/dev/mcp-contract.json'
        data = json.loads(contract.read_text())
        data['tools'].append('cybermaps/run-audit')
        contract.write_text(json.dumps(data))
        self.assertNotEqual(self.check().returncode, 0)


if __name__ == '__main__':
    unittest.main()
