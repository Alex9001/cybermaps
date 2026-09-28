import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('security_review', ROOT / 'bin/check-security-review.py')
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)


class SecurityReviewTest(unittest.TestCase):
    def test_new_handler_in_previously_allowed_file_is_rejected(self):
        old = {'src/Admin/Settings.php::safe': {'code_sha256': 'old'}}
        ledger = {key: {'inventory': value, 'rationale': 'specific authorization review', 'tests': ['test']} for key, value in old.items()}
        self.assertEqual(review.compare(old, ledger), [])
        new = dict(old, **{'src/Admin/Settings.php::unsafe': {'code_sha256': 'new'}})
        self.assertTrue(review.compare(new, ledger))

    def test_changed_body_or_suppression_requires_new_review(self):
        old = {'code_sha256': 'reviewed', 'suppressions': [], 'findings': {}}
        ledger = {'handler': {'inventory': old, 'rationale': 'reviewed exact method', 'tests': ['test']}}
        for changed in ({**old, 'code_sha256': 'bypass'}, {**old, 'suppressions': ['phpcs:ignore']}, {**old, 'findings': {'SQL': 1}}):
            self.assertTrue(review.compare({'handler': changed}, ledger))

    def test_missing_review_evidence_rejected(self):
        self.assertTrue(review.compare({'handler': {}}, {'handler': {'inventory': {}}}))


if __name__ == '__main__':
    unittest.main()
