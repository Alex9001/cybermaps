# WordPress.org reviewer response — Cybermaps 7.5.4

Draft for the existing review thread. The filename is retained to preserve the
existing draft's location. Nothing has been sent.

Before sending: complete release promotion, verify that the versioned public
source links in `readme.txt` resolve, and upload the qualified 7.5.4 ZIP through
the existing WordPress.org submission. The validation statement below refers to
the tested 7.5.4 candidate; retain it only if it also describes the submitted ZIP.

---

Hello Plugins Team,

Thank you for reviewing Cybermaps. I have addressed the reported issues in
version 7.5.4 and reviewed the rest of the plugin for the same patterns,
including input validation and escaping at the point of output.

One clarification about the setup wizard: `assets/js/setup-wizard.js` is the
editable, unminified source itself. It uses WordPress's `wp.element` API directly
and requires no compilation. The readme's “Source Code” section now identifies
the source and packaging instructions; JavaScript and CSS are copied unchanged
into the ZIP.

I tested the exact 7.5.4 ZIP on clean WordPress installations with `WP_DEBUG`
enabled. Plugin Check 2.1.0 passed both the new-plugin and experimental checks
with no findings, and the debug logs remained clean. Runtime checks covered
WordPress 7.1 and 7.1.2, PHP 8.2–8.5, and separate multisite lifecycle tests.

Thank you for your time and the opportunity to have the updated plugin reviewed.
