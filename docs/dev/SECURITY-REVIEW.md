# Function-scoped source review

The prior 7.5.3 gate froze aggregate annotation counts and allowed raw request
access by filename. That could miss a new handler in an existing file or a
changed implementation with the same scanner findings.

`security-reviews.json` migrates those existing exceptions to exact function
identities. This is an inherited-exception inventory, not a claim that all
annotation-blind findings are vulnerabilities or that they disappeared. It records
the function body hash, raw request types, suppression text, annotation-blind
finding counts, existing boundary rationale and regression-test references.
The containing file code hash also binds helper callers and authorization flow.
Whitespace and line movement do not invalidate bodies. Added handlers, altered
bodies, new suppressions and changed finding sets require inspection.

The migration also enables WordPress.DB.PreparedSQL in the annotation-blind pass.
Its additional findings were inspected in DiscoveryAuditor, MediaAuditor,
AuditRunRepository, PublishedPostSource, AtomicOneTimeStateStore, Uninstaller,
StaticOwnershipStore, TaskRepository and EligibleContentRepository. They involve
fixed SQL fragments with placeholders and preparation across variables, rather
than unprepared request interpolation. In particular, IN lists construct one
typed placeholder per value; static ownership appends literal lock predicates;
atomic deletion selected between two fixed prepared templates. This paragraph
describes the inherited migration inventory: `AtomicOneTimeStateStore` has since
been removed. Current Cloudflare transaction pointers use the reviewed
`CloudflareOptionStore` and exact-byte `RawOptionStore` operations under the
captured database-session fence.

Nonce findings in private helpers rely on authenticated callers; reviewing only
the helper is insufficient. Inspect callers and negative request tests whenever
the flow changes. ExceptionNotEscaped findings represent error data consumed by
contextual HTML or JSON sinks. ProtocolOutput's exact non-HTML emitter exception
remains separately pinned in wporg-source-allowlist.json.

This release removes whole-request copies from conditional HTTP handling,
Markdown alternate selection and settings notices. It adds bounded conditional
header fields, retains protocol validator tests, and checks the Cloudflare start
action's method and nonce before its bounded operation selector.

Never refresh this ledger merely to silence a failure. Read the affected function,
callers, output sinks and regression evidence, fix unsafe behavior first, and
record why any remaining scanner limitation is safe. The per-release security
review must explicitly assess this inherited inventory, not mistake it for a
zero-findings annotation-blind audit. Official Plugin Check still requires zero
findings on the installed ZIP.
