# Audit finding storage review

The user approved a narrow exception for the two direct-write annotations in
`AuditRunRepository::insert_finding` and `insert_finding_batch`. This exception
covers the `DirectQuery` warning at each write and `NoCaching` for the bulk
insert only. It does not suppress prepared SQL, input, nonce, or output checks,
or waive the official Plugin Check zero-findings requirement. The function-scoped
records in `security-reviews.json` bind the annotations to the reviewed code.

The native WordPress `wpdb::insert` implementation accepts one row. The Plugin
Check rule flags direct writes to the plugin-owned findings table, including
this fixed prepared insert, and treats `query()` as cacheable even though this
statement writes immutable report snapshot rows. Introducing a cache operation
would not replace their durable storage. The single-finding helper retains the
prior individual `wpdb::insert` behavior; the five-row path uses a literal SQL
statement with a `%i` table identifier and one typed placeholder per value.

The admin run action verifies POST, `manage_options`, and its nonce before
invoking the service. The service acquires and renews the run lease, evaluates
at most one 100-post source batch at a time, and releases the lease in `finally`.
Each resource is inserted through WordPress and the actual generated ID is
associated with its findings. The batch contains exactly five findings and at
most 256 KiB of raw text. WordPress column length and charset APIs check string
values; altered values or errors cause failure. When local text validation is
not supported, or the raw payload exceeds the bound, the normal individual
insert path is used. Writes must report five affected rows; exceptions mark the
run failed and prevent a partial report being marked complete. This path starts
no transaction and does not change the schema. Snapshot counters and hash are
built in resource order and completion is reached only after storage succeeds.

The baseline 8.0.4 10,000-item native-cache reports exceeded the unchanged
120-second limit. The post-measurement probe found 5,139 resources and 12,054
findings in one still-running report, and 4,989 resources and 11,722 findings
in the other, identifying persistence as the interrupted phase. Evidence is in
`docs/generated/releases/8.1.0/audit/performance/baseline-8-0-4-20261115T115949Z/`.

`tests/Audit/AuditRunRepositoryTest.php` checks mapped resource IDs, finding
fields, failures, and size and charset fallbacks; its subprocess fixture covers
missing `mb_strlen`. `tests/Audit/AuditRuntimeBudgetTest.php` exercises failure
and lease cleanup with adapters. The native fixture
`tests/integration/wporg-audit-batching.php` compares actual stored rows, nullable
values, Unicode, quotes and slashes, and rejection of overlong and invalid UTF-8
values. A standalone diagnostic on PHP 8.3.36 and MariaDB 10.11.19 passed and
cleaned its isolated tables; its evidence is in
`docs/generated/releases/8.1.0/reviews/native-audit-batching-diagnostic.json`.
This diagnostic does not establish every supported environment or the candidate
performance outcome. The complete native matrix, both Plugin Check modes, and
all controlled 1k/10k workloads remain required release gates.
