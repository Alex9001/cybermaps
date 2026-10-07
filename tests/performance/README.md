# Disposable real-WordPress performance measurements

`python3 tests/performance/run.py --zip <exact ZIP> --label baseline --sizes 1000 10000 100000 1000000`
installs the ZIP in a disposable Podman WordPress 7.1 / PHP 8.2 / MariaDB 10.11
environment. Nothing is installed in Local Sites. Run only when the audit
coordinator grants the shared profiling slot. A development smoke may use
`--sizes 100 --warm 2 --cold 1 --operations sitemap_index,llms,search_hit`.

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
runs one slice and records resumable state; a pending/partial slice fails the completion flag. A successful slice report is insufficient: the final continuation state must also be cleared, as StaticBridge does at terminal completion. It is never reported as a completed whole-site synchronization. The separate `ownership` operation measures forced per-file ownership persistence, including total SQL bytes, at each requested fixture size. `--stress-page-lists N` replaces the
fixture with N published pages containing a stored page-list block, measuring
the real internal-link analyzer under its PHP memory and time limits.

All results go to `docs/generated/releases/8.0.2/audit/performance/`; disposable
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
`python3 -B -m unittest discover -s tests/performance -p test_harness.py`.

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
