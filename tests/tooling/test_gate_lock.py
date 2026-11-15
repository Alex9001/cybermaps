"""Gate/parent lock inheritance allows the workflow but excludes sibling commands."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'bin'))
import workspace


class WorkflowLockTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='workflow-lock-', dir=workspace.configure())
        self.addCleanup(temporary.cleanup)
        self.addCleanup(workspace.configure)
        self.root = Path(temporary.name)
        (self.root / 'bin').mkdir()
        shutil.copy(ROOT / 'bin/workspace.py', self.root / 'bin/workspace.py')
        context = patch.object(workspace, 'ROOT', self.root)
        context.start()
        self.addCleanup(context.stop)
        self.code = "import sys;sys.path.insert(0,'bin');from workspace import lock\n"

    def test_only_the_actual_workflow_descendant_can_inherit(self):
        with workspace.lock():
            child = subprocess.run([sys.executable, '-B', '-c', self.code
                                    + "with lock(allow_parent=True): print('child gate admitted')\n"],
                                   cwd=self.root, capture_output=True, text=True)
            self.assertEqual(child.returncode, 0, child.stderr)
            self.assertIn('child gate admitted', child.stdout)
            independent = subprocess.run([sys.executable, '-B', '-c', self.code
                                          + 'with lock(): pass\n'], cwd=self.root, capture_output=True, text=True)
            self.assertNotEqual(independent.returncode, 0)

    def test_standalone_gate_is_blocked_by_a_sibling_workflow(self):
        keeper = subprocess.Popen([sys.executable, '-B', '-u', '-c', self.code
                                   + "with lock():\n print('locked',flush=True)\n sys.stdin.readline()\n"],
                                  cwd=self.root, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, text=True)
        try:
            self.assertEqual(keeper.stdout.readline().strip(), 'locked')
            with self.assertRaisesRegex(RuntimeError, 'Another local release'):
                with workspace.lock(allow_parent=True):
                    self.fail('An independent gate must not rewrite publication evidence')
        finally:
            keeper.communicate('\n', timeout=5)
        self.assertEqual(keeper.returncode, 0)


if __name__ == '__main__':
    unittest.main()
