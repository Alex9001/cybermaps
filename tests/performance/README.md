# Disposable real-WordPress performance measurements

`python3 -B tests/performance/run.py --zip <exact ZIP> --label baseline --sizes 1000 10000 100000 1000000`
installs the ZIP in a disposable Podman WordPress 7.1 / PHP 8.2 / MariaDB 10.11
environment. Nothing is installed in Local Sites. Run only when the audit
coordinator grants the shared profiling slot. A development smoke may use
`--sizes 100 --warm 2 --cold 1 --operations sitemap_index,llms,search_hit`.
Use `--wordpress-version` to pin a later WordPress release, identically for both
packages. Official core checksums are still required.

The harness pins its workload to six shared CPU cores and applies individual address-space limits, with a 1 GiB PHP process/request ceiling, per-operation timeout, a combined observed-RSS stop at 12 GiB, and a 90 GiB workspace stop. The named database/Redis/PHP/SQL-helper limit sum is 10.5 GiB; it excludes inherited limits on small timeout supervisors and is not an absolute worst-case aggregate address-space bound.
Its MariaDB volume is disposable and its network does not publish the database.
Only the HTTP test server binds a random loopback port. Outbound WordPress HTTP
requests are blocked after setup. Scheduled requests are disabled. No IndexNow
delivery runs. ZIP digest, images, versions, hardware and raw samples are saved.

Fixtures contain exactly the requested number of primary content records,
plus 10% image attachments and 5% revisions. They mix post/page/public custom
types, publish/draft/private/future statuses, protected and noindex entries,
100 authors, deterministic categories/tags/meta, internal links, HTML/Gutenberg
and multilingual text. A fixed fixture clock and deterministic arithmetic avoid
random data and time-dependent corpus drift. Bulk SQL measures production query
behavior, not the cost of importing content through WordPress hooks.

`cache=on/off` means the plugin's actual `enable_caching` setting; it does not
pretend that this switch disables independent discovery caches. WordPress's
request-local object cache and database-backed transients are measured separately from a Redis Object Cache 2.7.0 persistent drop-in. Every plugin-cache mode runs with Redis both off and on; a cross-process probe verifies each mode.
Cold means application-cache-cleared before each sample, **not** OS page-cache or
database-buffer-pool cold. Warm samples follow an uncounted warm-up; by default
there are 20 samples and nearest-rank p 95. Cold defaults to three samples.
HTTP wall latency includes the actual WordPress bootstrap and routing. CLI
operation time excludes bootstrap; both are kept separate. Instrumented PHP
query time/count and peak allocated memory accompany raw response status/size.
The SQL timer begins at MU-plugin load (the earlier bootstrap queries remain in the total count). The bounded query recorder retains only 12 slowest SELECTs; their separate
`ANALYZE FORMAT=JSON` runs are outside latency measurement and report actual
rows read as well as optimizer estimates. Timing instrumentation overhead is
identical across packages and retained as a limitation.

Full audit and static synchronization are separately bounded operations, not
claimed complete when a deadline or process memory cap stops them. Full audit
uses one sample per cache mode by default because it writes the complete report;
the HTTP sampling rule applies to read-only endpoints. Static synchronization
runs fresh PHP requests until the plugin clears its continuation checkpoint.
It retains every slice and fails on errors, stalled progress, the request limit,
or the total deadline. A successful slice report is insufficient: the final
continuation state must also be cleared, as StaticBridge does at terminal
completion. Prior output is purged through the plugin's ownership-safe API
before each cache mode; publication no longer inherits a previous mode's files
or progress. Production per-slice limits remain unchanged. The `sitemaps`
operation compares the actual index with the full internal inventory and fetches
every advertised child, in addition to early/deep latency samples. The separate
`ownership` operation measures forced per-file ownership persistence, including
total SQL bytes, at each requested fixture size. `--stress-page-lists N` replaces the
fixture with N published pages containing a stored page-list block, measuring
the real internal-link analyzer under its PHP memory and time limits.

All results go to `docs/generated/releases/<release-version>/audit/performance/`;
the version defaults to the exact ZIP's plugin header. Set `--release-version`
to retain a baseline comparison under the candidate's evidence directory. Disposable
files and subprocess temporary directories stay under `docs/generated/tmp/`.
The harness never edits plugin source, repairs a package, commits, or releases.

The require-based PHP runner preserves `declare(strict_types=1)` (WP-CLI
`eval-file` does not). Core is extracted from the official WordPress 7.1 ZIP
with Python and verified against upstream checksums, avoiding the container's
legacy tar extractor's long-path truncation. Each run snapshots and hashes the
harness and records exact image IDs. It copies the initially hashed plugin ZIP
into read-only staging inside its disposable workspace and installs only that
copy. The frozen digest is checked before and after installation and before and
after the final completion check. A changed staged ZIP makes completion false
and preserves the error; replacing the original source ZIP after staging cannot
change the package being measured.

Two separate PHP workers check live advisory-lock exclusion/reacquisition and
forced IndexNow queue claims without sending any delivery. For real MySQL 8
compatibility, run the same ZIP with `--database mysql --lock-only`. Corpus
SQL uses MariaDB sequence tables and is intentionally unavailable in that mode.
A baseline concurrency defect makes completion false and exit status 2; the
successful timing samples remain usable individually. Timeouts, missing metrics,
non-200/invalid/empty bodies, incomplete audits and partial static sync never
count as passing samples. Percentiles are suppressed when any requested sample
in that group is invalid. All raw evidence is retained, including failures.

Container flags request 6 CPUs/12 GiB, but nested Podman may accept these flags without kernel cgroup enforcement. The static Linux `limits.c` launcher therefore pins all fixture processes to the same six CPUs and sets hard `RLIMIT_AS` limits: database 6 GiB, Redis 1 GiB, each PHP process 1 GiB, and SQL/helper clients 256 MiB. The controlled schedule permits at most three PHP processes at once (one HTTP server plus two CLI contenders), with at most two bounded helper clients; those named process limits total 10.5 GiB. Small BusyBox timeout supervisors additionally inherit the CLI address-space cap; the 10.5 GiB subtotal is not a complete sum of every process limit. Actual combined RSS is measured, and the watchdog stops the workload above 12 GiB; this is a sampled watchdog, not a kernel cgroup aggregate-memory guarantee. Actual CPU affinity and limits are verified through procfs for the running database, Redis, HTTP server and CLI process before measurements. Address space differs from cgroup resident-memory accounting; host engine/controller overhead is outside this container workload budget. A five-second watchdog also stops all fixture containers above 12 GiB combined observed RSS or 90 GiB workspace use. Redis persistence is disabled.
Each PHP operation has a container-side wall-clock timeout so killing a host
client cannot leave work behind. No benchmark changes the plugin source.

Run harness self-tests with
`python3 -B -m unittest discover -s tests/performance -p 'test_*.py'`.

The launcher requires a Linux C compiler capable of static linking. Compiler failure or mismatched live-process limits abort setup. Early evidence directories without `verified-process-limits.json` predate verified enforcement and are diagnostic only; use the final bounded runs for comparison.

Each run resolves container tags to immutable local image IDs before startup.
For a candidate comparison, pass `--image-manifest <baseline>/environment.json`
to use the exact recorded baseline IDs; a missing image or database-mode mismatch
fails setup. The prior run's harness hashes remain attached to its raw samples.

Three invalid responses stop further requests for that endpoint/cache mode.
Every remaining requested sample is recorded as skipped due to those failures;
its group remains incomplete with no percentile. Failure bodies retain a bounded
2 KiB diagnostic prefix. Missing, nonfinite, negative, wrongly typed metrics and
malformed operation results fail validation. Metrics are read incrementally.

Watchdog exceptions (including subprocess timeouts or malformed size output)
stop owned containers and propagate a fatal state to the measurement thread.
Cleanup joins the watchdog before removing containers. The bounded static RSS probe
reads every process inside each owned PID namespace, including itself, avoiding
unreliable host-PID mappings from nested Podman. Exited zombies contribute zero;
An incomplete scan is retried at most three times, 20 ms apart; every failed scan
and its diagnostic output is preserved. Only a complete positive observation is
accepted. Persistent missing metrics for a live userspace process abort the run.

Each independent audit cache case verifies that no earlier CLI PHP worker
remains before clearing only the disposable fixture's audit lease. The previous
lease value, runtime identity, process list, timestamp and reset reason are saved
as setup evidence. This does not change the plugin or erase earlier timeout and
lease-retention evidence. Page-list stress discovers the advertised page sitemap
when that fixture contains no posts.

## Stable release completion gate

The required baseline is versioned in `docs/dev/release-policy.json` under
`upgrade_baseline`, shared with the native upgrade check. Each future stable
release must use the previous published stable version and its actual ZIP
checksum. The first 8.1.0 stable launch is the documented exception: its baseline
is the published 8.0.4 beta ZIP, SHA-256
`b63cb6f54f800d758cfe49e2056bb82bfc4e122121c46c98c19ed7f6bab9e7f9`.
After a timeout, package the revised candidate with `bash bin/package-candidate.sh`
and run `composer release:workloads -- --preflight-only` before repeating the
runtime matrix. For a full comparison, run `composer release:workloads` after
`composer release:build`.
It checks for a fresh passing receipt, then runs the candidate's 10,000-entry
audit with Redis and the plugin cache enabled and disabled. If any audit misses
the 120-second deadline, the launcher retains the failing sample and stops
before starting the full baseline or candidate. The full candidate also stops
after its first failed measurement group. Inspect that group before retrying.

The launcher runs both full packages **serially**, with no native matrix or
competing load test. The underlying commands for the original stable launch were:

```sh
python3 -B tests/performance/run.py --zip docs/generated/releases/8.0.4/cybermaps_8.0.4.zip --label baseline-8-0-4 --release-version 8.1.0 --wordpress-version 7.1.4 --sizes 1000 10000 --timeout 90 --long-timeout 120 --static-timeout 600 --static-slices 50
python3 -B tests/performance/run.py --zip docs/generated/releases/8.1.0/cybermaps_8.1.0.zip --label candidate-8-1-0 --release-version 8.1.0 --wordpress-version 7.1.4 --sizes 1000 10000 --timeout 90 --long-timeout 120 --static-timeout 600 --static-slices 50 --image-manifest <baseline-run>/environment.json
python3 -B tests/performance/check_release.py --version 8.1.0 --commit <full-candidate-commit> --zip docs/generated/releases/8.1.0/cybermaps_8.1.0.zip --candidate <candidate-run> --baseline <baseline-run>
```

Use the actual frozen candidate filename if promotion has not happened yet.
The comparison keeps the historical 90-second HTTP and 120-second report/slice
deadlines. Complete sitemap-inventory traversal also has a 120-second acceptance
deadline, checked against its aggregate measured duration; each child request
still has its own 90-second transport timeout. A full static publication may use up to 50 bounded requests within
600 seconds; this new whole-operation measurement does not relax production
slice limits or treat a timeout as success. The ordinary harness defaults are
diagnostic settings, not the stricter release acceptance profile.

The receipt writer requires all 1k/10k candidate workloads to complete across
both plugin-cache settings and both native/Redis modes, plus all requested cold
and warm observations. A completed baseline collection may contain recorded
failures; they remain visible and do not become successful samples. Both runs
must use the same reviewed harness, corpus, WordPress version, immutable images,
hardware, and resource limits. This is a controlled completion claim, not a
claim that 100k/1m workloads or arbitrary hosting configurations complete.

The selected `everyday-workloads.json` receipt binds the candidate ZIP and full
source commit and hashes every retained evidence file. The release gate calls
`validate_receipt(path, zip_sha256, commit, max_age_hours=24)` from
`check_release.py`; it recomputes raw validity and hashes instead of trusting a
saved pass flag. Before publication, stale measurements must be rerun against
the same frozen ZIP. The writer stores each new immutable receipt attempt under
`audit/performance/receipts/`, preserves the previous receipt bytes by their hash,
then atomically updates the selected receipt only after validating fresh raw
evidence. An expired receipt does not require deleting old measurements. Never
edit passing results or copy timestamps forward.

Allow approximately 15–35 minutes per package on the existing six-core fixture,
depending on database/cache speed and observed continuations. This is a planning
estimate, not a performance result. Existing 10k reports have previously hit the
120-second bound; a candidate that does so is a release blocker requiring a real
fix and a new exact-package run. Do not increase the acceptance deadline to make
the release pass. Historical 8.0.2 measurements remain unchanged.
