# WordPress.org release conditions

The machine-readable contract is [release-policy.json](release-policy.json).
Directory acceptance remains the WordPress team's decision. These gates prevent
known regressions and require a policy review; a green scanner alone is insufficient.

## Commands and artifacts

Install Composer development dependencies and Docker/Podman, Python 3 with lxml,
Node/npm, PHP and WP-CLI. Run `python3 -B bin/setup-validation.py` once to install
the locked Playwright browser under `docs/generated/tmp/`. Runtime cases use three
isolated workers by default; `CYBERMAPS_MATRIX_WORKERS=1` through `6` adjusts
concurrency without omitting any case.

`composer release:build` and `composer release:validate` use the same gate. They
run unit, standards, compatibility, complexity, security, metadata, tooling and
package-parity checks, then test the exact ZIP on minimum WordPress/PHP and latest
stable WordPress across PHP 8.2–8.5, plus multisite. Runtime checks include current
pinned Plugin Check, experimental and low-severity checks, debug logs, upgrade,
activation, deactivation, uninstall retention/deletion, and browser interactions.
The multisite case runs both Plugin Check runtime modes on the single-site
installation first, then converts that same installation and runs the two-site
and lifecycle fixtures. Plugin Check 2.1.0's early temporary-site bootstrap fails
inside WordPress `wp_install_defaults()` on an already-converted network before
`WP_Rewrite` exists. Reports explicitly identify the checker environment; no
scanner mode or finding is suppressed to accommodate this upstream limitation.

Candidates live in `docs/generated/releases/<version>/candidate/`. Every attempt
keeps logs and runtime evidence under `attempts/`; failed attempts remain available.
`validation.json` records source, policy, ZIP and evidence hashes. Source changes,
changed evidence, failed checks, new upstream versions and evidence older than
24 hours block reuse. Missing or malformed Plugin Check output fails closed.

`composer release:status` reports readiness. `composer release:ready` additionally
requires an evidence-backed agent review, clean committed main matching pushed
main, and fresh upstream checks and current website preparation evidence. Only then does it promote the ZIP to the final
release directory and write `release-readiness.json`. Publishing requires that
receipt. Do not upload a candidate manually to bypass these conditions.

The agent review is local `agent-review.json`, with exactly: `schema_version: 1`,
the validation record's `identity` and `zip_sha256`, a named `reviewer`, UTC
`reviewed_at`, and `sections`. Each of the six section names in release-policy.json
must contain `status: "passed"`, a specific rationale, and a nonempty `evidence`
mapping of repository-relative paths to SHA-256 hashes. Read the evidence before
writing this record. Do not generate approvals from passing exit codes. No routine
owner sign-off is required. A changed package requires renewed agent review.

## Permanent conditions

1. Source is identifiable, clean and publicly available before publication.
2. The installed ZIP matches source and contains only permitted regular files.
3. Version headers, stable tag, release notes, generated contracts and POT agree.
4. Unit, syntax, WPCS, PHP compatibility and complexity checks pass.
5. Administrative actions check method, capability and nonce before payloads.
6. Inputs have bounded schemas; whole request bags never cross boundaries.
7. Output is escaped at its final context; protocol exceptions are individually reviewed.
8. SQL parameters, filesystem operations, transients and core includes use safe APIs.
9. Assets have editable public sources, reproducible packaging and compatible licenses.
10. Core works alone, without trialware, licensing gates or an external updater.
11. Services disclose destination, data, purpose and consent; public credit defaults off.
12. The pinned Plugin Check is current and reports zero findings in both modes.
13. Real WordPress, browser, lifecycle, supported PHP and multisite checks pass.
14. An agent closes every policy and reviewer obligation with current evidence.
15. Website content matches the exact candidate commit/channel; editorial reviews, tests, build and verification pass before promotion/publication.
16. The published tag is deployed to cybermaps.dev and its live contracts, download links and changelog are verified before release completion.

Website preparation records bind the canonical website content, validation log,
plugin commit, version and channel; promotion additionally binds its Git commit
and the package checksum. Changed website content or expired evidence requires
another website check, not an unchanged plugin runtime matrix. A candidate alone
cannot satisfy the deployment condition.

`release:status` distinguishes `candidate-pending`, `candidate-validated`,
`release-ready`, `published-website-pending` and `release-complete`. A completed
release reports the time of its recorded production verification. Publication
readiness still leaves publication and website verification pending. Historical
completion evidence is separate from uncommitted work for a later candidate.

The workflow verifies https://cybermaps.dev after deployment, with at most two
minutes of propagation retries. It compares live product contracts and versioned
schemas with the built files and checks download/checksum links and changelog.
Failure preserves the published package and leaves the website step incomplete;
use `composer release:resume -- --tag vX.Y.Z`. It never rebuilds or republishes the
plugin. `website-prepared.json` and `website-live.json` retain the evidence.

## Reviewer issue map

The private June 15, September 17 and September 26, 2026 emails were reviewed.
The September 26 review concerned 7.5.2; it does not establish approval of 7.5.3
or this candidate. Do not commit private reviewer mail.

| Reviewer category | Resolution and continuing evidence |
| --- | --- |
| Account/domain ownership | Owner confirmed resolved September 28; preserve that fact, do not claim another verification occurred. |
| Trialware/Freemius | Standalone Core; package forbidden-marker checks and CoreBoundaryTest. |
| Inline CSS/JS | Enqueued editable assets; source checker rejects literal script/style output. |
| External services | Readme disclosures; ExternalServicesTest and agent review of every network caller. |
| Nested JSON and input validation | Domain sanitizers and bounded RequestInput; malformed payload regression tests. |
| Method/capability/nonce order | Administrative controllers and request tests; function-scoped security inventory. |
| Whole server/request copies | Bounded field adapters; source guard scans all shipped PHP for whole bags. |
| Late escaping and JSON flags | Annotation-blind audit, contextual ProtocolOutput, public HTTP tests. |
| SQL IN values | Typed placeholders per value; PreparedSQL audit and source SQL-list guard. |
| Reserved transient options | Transients API and source guard rejecting reserved option access. |
| WordPress core includes | Just-in-time permitted includes; no wp-load/wp-blog-header/wp-config bootstrap. |
| Readable wizard source/build instructions | Unminified assets/js/setup-wizard.js, Source Code readme section, exact asset parity. |
| Public attribution (additional guideline review) | Explicit default-off sitemap preference; rendered XSL, sanitizer and browser tests. |

Policy references: [Directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/),
[security handbook](https://developer.wordpress.org/apis/security/) and
[Plugin Check](https://wordpress.org/plugins/plugin-check/).

## Development skills

The bundled WordPress plugin-development and plugin-directory-guidelines skills
in `.claude/skills/agent-skills/skills/` informed this review. Their durable rules
are recorded here and in AGENTS.md, so release safety does not depend on ignored
local skill files. Re-read current official guidance when changing services,
licensing, privacy, distribution or public output.
