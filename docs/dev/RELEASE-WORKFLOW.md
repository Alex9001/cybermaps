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
zip, ripgrep, Subversion (`svn`, `svnmucc`, and `svnadmin` for rehearsals) and the
existing Docker/Podman Plugin Check environment. Website commands retain their
own Node/npm requirements. SVN publication uses the approved `cybermaps` slug
and the `oreshkin` WordPress.org account. Use the separate WordPress.org SVN
password, not an account login password. Pass `--svn-password-stdin` for a new
SVN commit and supply the secret through a secure stdin broker. It is consumed
before any child starts; children receive EOF on stdin. Only `svnmucc` receives
the secret explicitly, using an empty config directory with password stores
disabled. Committed releases resume without this flag. Credentials never belong in arguments, environment variables,
logs, the repository, an action manifest, or an evidence record.
On the KDE release workstation, the private website README documents the saved
KDESVN login. Pipe it directly to `python3 -B bin/release-workflow.py release
--svn-password-stdin`. This invokes the canonical release workflow and avoids
Composer dropping the pipe before starting its child.
Developer release locking uses Linux process identities: gate subprocesses may
inherit their actual parent workflow's lock, while separate commands cannot
rewrite evidence concurrently.

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
| `python3 -B bin/release-workflow.py release --svn-password-stdin` | Read the publishing password from the broker's pipe, validate and install the stable plugin, publish to GitHub and WordPress.org, then deploy and verify the website |
| `composer release -- --stable` | Explicit compatibility alias for the default stable channel |
| `composer release -- --beta` | Explicit GitHub prerelease; never publish to WordPress.org or deploy over the stable production website |
| `composer dev:install` | Build, validate and install local working source; no website commands or publication |
| `composer release:resume -- --tag vX.Y.Z` | Resume the same tag/draft, uncertain SVN outcome, pending Directory distribution, GitHub publication or website handoff |
| `composer release:github` | Compatibility alias for the complete canonical workflow; cannot bypass downstream checks |

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
Every case also requires native stored-template source and direct option CAS
checks after any multisite conversion. Normal PHP includes preserve their
strict-types declarations; their own success flags are checked before matrix
evidence is accepted. Both fixtures must finish their owned-artifact cleanup.
Every case also runs the output-buffer ownership fixture after any multisite
conversion. It requires all 48 granular sitemap cases and native report-style
checks, then explicitly records `output_buffer_ownership_passed`. Ordinary
sitemap HTTP checks separately verify response bodies and conditional headers.

The installer checks the checksum, stages bounded regular package files,
verifies them, moves the previous installation to a rollback directory, then
swaps the new directory in and verifies it again. Exceptions during the swap
restore the prior installation. It never calls activation/deactivation or
uninstall hooks, writes WordPress configuration or changes database contents.
Settings and activation state remain in the database. A brief directory-swap
window exists; use this workflow for the configured local test installation.

Stable readiness additionally requires the exact-candidate native update,
everyday-workload and presentation receipts. The reviewed upgrade baseline is
defined in `release-policy.json`. The first stable release tests the 8.0.4 beta
transition; subsequent releases must use the latest published stable version and
its verified ZIP hash. The older 7.5.3 lifecycle fixture remains separate.

Freeze the Directory images in `.wordpress-org/` with their version, dimensions,
SHA-256 hashes, provenance, licenses and exact screenshot captions recorded in
`docs/wporg/manifest.json`. These images are distributed through SVN `/assets/`,
never inside the plugin ZIP. A changed pictured interface requires fresh exact-ZIP
captures and a specific visual review; version changes cannot automatically
advance screenshot or prose approvals.

Publication retains matching-draft, no-overwrite, immutable-tag, checksum and
download-verification rules. Immediately before pushing the Git tag, readiness
rechecks the evidence window. A matching GitHub draft is created and its uploaded
ZIP/checksum are downloaded and compared. The matching stable release becomes
public on GitHub before the WordPress.org upload begins.

`svnmucc` submits one atomic transaction. It uploads changed and new trunk
files and Directory images, removes obsolete paths, and sets each image MIME
type. It creates the new tag from every frozen plugin file. The planner compares
file hashes against the verified base revision, and the existing directory
ownership properties fence concurrent changes, including to skipped files. No SVN checkout, automatic
clone, worktree, old-tag edit or copy of uncommitted trunk is used. Existing trunk
must match its prior released tag. Owned remote changes stop publication; the
global revision counter advancing for another plugin does not. Readiness is
checked again immediately before the SVN write. At that point the candidate is
already on GitHub. The baseline is the preceding stable release. A later
stable release blocks an out-of-order handoff. If the publisher itself needs
a repair, resume verifies the public tag and both downloads, reconstructs the
validated source identity at that tag and checks the current release evidence.
Only publishing-tool changes and their tests or release guide can differ in a
clean, pushed descendant checkout. The plugin, policy, runtime harness, artwork
and existing release ZIP must still match. Every write attempt gets a unique transaction marker and a retained,
hash-bound record of its start time, base revision and publication evidence.
After readiness children finish, shared staging is checked again. The package and
artwork bytes are copied into a private transaction directory; svnmucc reads only
those copies, never the shared `wordpress-inputs` files. This protects against
ordinary concurrent workspace changes, not a malicious process running as the
same operating-system user.
The transaction writes `cybermaps:release-sha256` ownership properties on the
existing trunk/assets directory nodes. A local SVN rehearsal proves these fence
concurrent child additions as well as file edits, rejecting the stale transaction
before its tag is created. Unexpected server rejection is a failure, never
permission to remove the guard or submit a partial release.

Before SVN, validation, agent review and referenced runtime/visual/workload files
are copied into a write-once content-addressed evidence snapshot. Identity, ZIP
hash and all three stable-check receipts are checked before the irreversible
write. `svn-prepared`, `svn-committing` and unknown outcomes prohibit gate evidence
regeneration until reconciliation resolves whether the transaction exists.

After the commit, verify its revision and immutable tag, current trunk/artwork,
Directory API/listing, CDN images and the downloaded WordPress ZIP's individual
file bytes. ZIP container hashes may differ between hosts; file manifests must
match exactly. Run real dashboard and WP-CLI updates from the reviewed baseline,
on single sites and networks, using WordPress.org's actual update offer. Import the published tag and
its verified WordPress receipt, commit the generated snapshot, and run the existing
guarded `npm run deploy`. Production follows the newest stable release and never
promotes a beta. Verify live cybermaps.dev contracts, downloads, changelog, current
gallery and launch content before completion. Deployment or production-verification
failures retain the immutable releases for a downstream retry. Only the known generated crawl report may additionally
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
    github-publication.json       Frozen tag/draft/publication identity
    wordpress-inputs/             Frozen package files and separate Directory images
    wordpress-publication.json    Atomic SVN intent, revision and retained evidence
    wordpress-attempts/           Unique write attempts and their immutable evidence bindings
    wordpress-evidence/           Content-addressed immutable publication evidence
    wordpress-distribution.json   Verified public distribution and native-update receipt
    wordpress-pending.json        Bounded downstream delay and next-check time
    everyday-workloads.json       Complete 1k/10k controlled comparison evidence
    native-upgrade-*.json         Controlled and actual WordPress.org update evidence
    presentation/                Captures, browser checks and specific visual review
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

Use `release:resume` with the same tag for interrupted publication. If the
validation expires before SVN accepts the release, run `composer release:build`
to revalidate the retained ZIP and artifact tree. The source and policy identity,
clean pushed main, GitHub release or draft, downloaded assets and current agent
review must still match. A tag created before draft creation can resume only with
its saved frozen publication intent. Conflicting tags or bytes stop without replacement.
Refreshing only installed validation tools is permitted: all checks rerun against
the retained ZIP and record the new tool hashes. A changed source commit or policy
is not a tool-only refresh. Before a tag, draft or SVN intent exists, unpublished
inputs can be replaced by a newly validated candidate; the old inputs are backed up.

After an uncertain SVN response, query the tag and transaction marker before any
retry. A matching committed tag resumes downstream; it is never submitted again.
An absent tag does not prove that a timed-out server request stopped. An uncertain
attempt records its upload time limit. The 8.1.1 server transaction took five
minutes and 49 seconds and completed after the old five-minute client timeout.
New uploads allow 15 minutes. A follow-up may recognize the matching tag and
transaction as soon as they appear. Before treating an absent tag as an
uncommitted attempt, allow the recorded client timeout plus 15 minutes for the
server. This gives new attempts a 30-minute settling window and preserves the
original 20-minute window for historical attempts. The exception path never clears uncertainty
immediately. This settling interval is a retry delay, not proof that a late commit
is impossible: each retry has a new identifier, every earlier attempt remains
searchable, and a late matching commit uses only that attempt's original evidence
snapshot. No release-state file needs a manual edit. A saved unknown outcome with
missing or altered attempt evidence fails closed for inspection.
If SVN authentication fails, the GitHub release stays public. Resume with the
SVN password and fresh evidence before the initial SVN commit.
After an accepted SVN commit, evidence is immutable and ordinary freshness expiry
does not authorize rebuilding or rewriting the version. Fresh remote checks and
retained evidence hashes govern downstream retries.
The launch post date refers to the public GitHub/blog launch day. Propagation may
cross midnight; the final GitHub gate pauses for a reviewed date correction when
needed. There is no speculative time-of-day cutoff for otherwise-ready SVN work.

WordPress.org may require release confirmation or hold updates for its security
review/cooldown. An unavailable version, artwork or real update offer records a
pending state and next-check time, then exits; there is no hours-long polling loop.
The plugin stays available on GitHub during this wait. Check WordPress's
confirmation/review notifications if the delay persists. A security block is not
a successful release. Correct a published defect in a new version; never edit the
released tag to bypass a review or replace bytes. If publication moves to another
day, update the announcement date and rerun the affected website checks before deployment.
The committed launch-post date is checked before preparation and immediately
before GitHub publication. Only a healthy official update API response without a
matching offer is an update cooldown. Missing tools, changed harnesses, invalid
receipts and failed updates are hard failures. Post-publication native checks use
only harness bytes verified against the frozen source commit. Their controlled
proof is read from the winning SVN attempt's immutable snapshot, including all
granular evidence; later changes to live receipt pointers cannot rewrite it.
Native test failures remain in attempt directories and never replace a passing
canonical receipt. Controlled receipts are frozen once SVN starts; verified
WordPress receipts are frozen once distribution verification completes. Baseline
policy is read from the frozen release commit during receipt validation.

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

After a report timeout, package the fix and run
`composer release:workloads -- --preflight-only` before repeating the runtime
matrix. The stable publisher calls `composer release:workloads` after validating
the candidate. You can also run it directly. It runs the
10,000-entry audit in all four cache configurations first. A failed audit
stops the comparison and retains its samples. A passing preflight proceeds to
the complete baseline and candidate workloads with the same container images.
An existing passing receipt for the same commit and ZIP can be reused while fresh.

Controlled performance measurements use the separate disposable harness in
`tests/performance/`. Its self-tests are a routine release gate; executing the
1k/10k workload is mandatory evidence for stable releases, not implied by those tests. Comparisons
must bind both exact ZIP hashes, workload, images and cache modes. Preserve
timeouts, unavailable responses and partial audit/static outcomes as failures
to complete, and suppress group percentiles when requested samples are invalid.
Do not run another native or load workload alongside a controlled comparison.

If GitHub publication succeeded but its response or later website step was
interrupted, use `composer release:resume -- --tag vX.Y.Z`. Resume downloads both
published assets, verifies their checksum and exact file parity with the tag,
rechecks WordPress distribution and retained update evidence, and verifies remote
immutability. It never builds or publishes a replacement package. A matching frozen website skips reimport and
only retries guarded deployment. Existing website edits still require a commit
before any retry; saved state never authorizes consuming user work.
This workflow deliberately refuses to redeploy an older release after the SVN
trunk/artwork or current Directory version has advanced. Complete the current
release first; a later release needs its own matching website snapshot. A
historical website rollback is a separate reviewed operation, not an ordinary resume.

For a hard process kill or machine shutdown during installation, read the
recorded `install.json` under `backups/install-<id>/`. The previous directory is
retained as `cybermaps/` when the swap had begun. Stop concurrent install/release
commands, verify the recorded destination, preserve any current installation
under `docs/generated/backups/`, and move that recorded previous directory back.
Do not run WordPress activation/deactivation to restore files.

Run `composer test:release-github`, `composer test:release-workflow`,
`composer test:release-wordpress`, and `composer test:native-upgrade` for local
fixtures covering publication conflicts, dirty-website zero-write rejection,
unsafe destinations, rollback, review pauses and downstream-only retries. These
fixtures never contact GitHub, WordPress.org, the real website or Local Sites.
SVN rehearsals use `svnadmin` and `svnmucc` in the disposable workspace and cover
atomic publication, unrelated/owned remote changes, auth rejection, ambiguous
success, retries, immutable tags and actual normalized download parity.

The relocation itself does not publish 7.5.2, move its tag or deploy unfinished
website work. Local's configured PHP must be used for local runtime smoke
checks; an unavailable database leaves that check pending and does not authorize
database configuration changes.
