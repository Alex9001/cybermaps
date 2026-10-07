# Local release workflow

Work in `/home/user/Documents/CODE/cybermaps/`, the only plugin source checkout.
The Local Sites directory is an installation, never a source editing location.
Do not create automatic clones or worktrees. No checkout hooks or GitHub Actions
are used. Long release/install commands disable Composer's five-minute process
timeout so package validation and deployment can finish; their validation
gates remain mandatory.

## Configure once

Copy `.cybermaps-workspace.example.json` to `.cybermaps-workspace.json` and review
its `website` and `install` absolute paths. This ignored file is the single
local destination configuration. The website must be its canonical Git root;
the install must end in `wp-content/plugins/cybermaps` beneath an existing
WordPress installation. Scripts reject overlaps, symlink components and source
repositories at the install destination. Install swaps require both directories
on the same filesystem.

Keep dependencies installed (`composer install` and the website's existing npm
setup), authenticate `gh` and Git for `Alex9001/cybermaps`, and retain the existing
website deployment credentials. Packaging requires PHP, WP-CLI, Python 3.11+,
zip, ripgrep and the existing Docker/Podman Plugin Check environment. Website
commands retain their own Node/npm requirements.

## Reproducing the package

Assets are editable source and require no compilation. After `composer install`,
`bash bin/package-candidate.sh` reproduces the candidate files from a source
checkout, including a tagged release. This packaging step is not publication
approval; use the complete release gate below before distributing changes.
Full upgrade validation also needs the preserved 7.5.3 ZIP under its release
directory; its checksum is verified by the runner. On a new workstation obtain
that historical asset from the public GitHub v7.5.3 release, not a source archive.

## Release conditions

See [WordPress release conditions](WORDPRESS-RELEASE-CONDITIONS.md) for the
mandatory candidate, runtime matrix, browser and agent-review gates. Install
Python lxml and run `python3 -B bin/setup-validation.py` for pinned browser tooling.
`composer release:build` creates a candidate; `composer release:ready` promotes
it after review and clean pushed-source checks. `composer release:status` reports
missing conditions; a release is complete only after production website verification.

## Commands

| Command | Result |
| --- | --- |
| `composer release` | Prepare, validate, install, publish an open beta and deploy its website snapshot |
| `composer release -- --stable` | Same workflow for a stable release |
| `composer dev:install` | Build, validate and install local working source; no website commands or publication |
| `composer release:resume -- --tag vX.Y.Z` | Verify an already published release and finish only its website handoff |
| `composer release:github` | Lower-level publisher for a prepared clean website; no local installation or deployment |

`release` first requires a clean website, including staged and untracked files,
and committed plugin `main` exactly matching pushed `main`. Dirty website
rejection performs no website writes. It never stashes, resets, commits existing
work or pushes plugin `main`.

The importer reads the exact plugin commit. If editorial reviews are stale,
the console and saved log list affected pages. Review those explanations using
the website's existing `docs:review` command, then commit the website changes
and rerun `composer release`. No additional agent is required. Review records
are never automatically advanced. Successful imports commit only the known
generated snapshot paths and changelog. Unexpected output requires review.

Validation reuses the website checks, PHPUnit, runtime baseline, WPCS,
PHPCompatibilityWP, complexity, source safety, generated-input contracts,
packaging/parity and Plugin Check. The builder already runs Plugin Check, so
the publisher does not invoke a second full `release:validate`. Small input
freshness checks also run when the builder is used independently.

Every mandatory runtime case requires native publication cache-priming checks,
including the converted multisite case. The bounded fixture reuses 201 posts or
creates and removes its own temporary posts; `publication_priming_passed` must
be explicitly true in both fixture output and matrix evidence. This regression
check does not replace the separate controlled performance workload.

The installer checks the checksum, stages bounded regular package files,
verifies them, moves the previous installation to a rollback directory, then
swaps the new directory in and verifies it again. Exceptions during the swap
restore the prior installation. It never calls activation/deactivation or
uninstall hooks, writes WordPress configuration or changes database contents.
Settings and activation state remain in the database. A brief directory-swap
window exists; use this workflow for the configured local test installation.

Publication retains the existing matching-draft, no-overwrite, immutable-tag,
checksum and download-verification rules. Existing assets are never clobbered.
After publication, the workflow verifies downloaded assets against the tag,
imports that published tag, commits its generated snapshot and runs the existing
guarded `npm run deploy`. It then verifies the live cybermaps.dev contracts,
download links and changelog before marking the release complete. Deployment or
production-verification failures leave the published release awaiting its website
handoff; resume retries the downstream steps without republishing. Only the known generated crawl report may additionally
be committed from website validation/deployment. Branding or other unexpected
changes require review. Website commits are local; the workflow does not push
the website repository.

## Output and recovery

```text
docs/generated/
  releases/<version>/
    cybermaps/                    Validated installation tree
    cybermaps_<version>.zip        Package and adjacent .sha256
    plugin-check-validation.json  Runtime/compliance evidence
    workflow.json                 Release phase, commit, checksum and rollback
    workflow-*.log                Command output for each attempt
    dev-install.json              Last local-only installation
    dev-install-*.log             Local build/install logs
    imported/                     Preserved packages/reports from before relocation
  tmp/                            Disposable subprocess/test staging and caches
  backups/
    install-<id>/                 Previous cybermaps/ and install.json journal
    release-repair-7.5.2/          Preserved historical repair evidence
    migration/                    Relocation and installation parity evidence
```

This entire generated tree is ignored. Tracked documentation, manifests,
schemas and translation files remain tracked. The unrelated crawler CSV stays
where it was; normal source cleanliness rules still apply before a future
release. Temporary environment variables are passed to subprocesses, including
website imports and test fixtures, so staging stays inside the plugin source.

For an interrupted draft, rerun `composer release` with the same channel and
candidate. Expired validation reruns all checks against the retained ZIP and
artifact tree, without rebuilding or replacing those bytes. Recovery requires
unchanged source, policy and dependency identity, clean pushed main, matching
local and remote tags, exact unpublished draft metadata, and matching downloaded
existing assets. A missing draft or conflicting bytes, draft or tag stops without
replacement. Agent review and website preparation must still be current.

The candidate gate also runs the relay security tests, the WebMCP security
browser fixture with pinned Playwright/Chromium, admin UI contract tests using
Node with a mocked DOM, and performance harness self-tests. The matrix's
separate admin browser checks use real browser interactions. Each WordPress
matrix case runs the RSS
ambient-post and local-month sitemap regressions, native REST serialization,
configuration and upgrade compare-and-swap, Cloudflare persistence, audit lease,
static ownership migration and state cutover fixtures. Every required runtime
flag must be present and true; a partial fixture cannot satisfy the gate.
Optimized Python (`-O`, `-OO` or `PYTHONOPTIMIZE`) is rejected so mandatory runtime
assertions cannot disappear.

Large-corpus performance measurements use the separate disposable harness in
`tests/performance/`. Its self-tests are a routine release gate; executing the
full workload is separate evidence, not implied by those tests. Comparisons
must bind both exact ZIP hashes, workload, images and cache modes. Preserve
timeouts, unavailable responses and partial audit/static outcomes as failures
to complete, and suppress group percentiles when requested samples are invalid.
Do not run another native or load workload alongside a controlled comparison.

If publication
succeeded but its response or later website step was interrupted, use
`composer release:resume -- --tag vX.Y.Z`. Resume refuses drafts, downloads both
published assets, verifies their checksum and exact file parity with the tag,
and checks that remote assets/tag stayed unchanged. It never builds or
publishes a replacement package. A matching frozen website skips reimport and
only retries guarded deployment. Existing website edits still require a commit
before any retry; saved state never authorizes consuming user work.

For a hard process kill or machine shutdown during installation, read the
recorded `install.json` under `backups/install-<id>/`. The previous directory is
retained as `cybermaps/` when the swap had begun. Stop concurrent install/release
commands, verify the recorded destination, preserve any current installation
under `docs/generated/backups/`, and move that recorded previous directory back.
Do not run WordPress activation/deactivation to restore files.

Run `composer test:release-github` and `composer test:release-workflow` for local
fixtures covering publication conflicts, dirty-website zero-write rejection,
unsafe destinations, rollback, review pauses and downstream-only retries. These
fixtures never contact GitHub, the real website or Local Sites.

The relocation itself does not publish 7.5.2, move its tag or deploy unfinished
website work. Local's configured PHP must be used for local runtime smoke
checks; an unavailable database leaves that check pending and does not authorize
database configuration changes.
