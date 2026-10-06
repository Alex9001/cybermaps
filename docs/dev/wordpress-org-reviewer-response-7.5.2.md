# WordPress.org reviewer response — Cybermaps 8.0.1

Draft for the existing review thread; filename retained so the saved reply stays
in the same place. Nothing has been sent.

Before sending: finish the exact-ZIP release gates, review the matching website,
promote the candidate, verify the public source links, and upload the qualified
8.0.1 ZIP to the existing WordPress.org submission. Do not send this draft while
those checks are pending. This draft makes no claim of an independent audit.

---

Hello Plugins Team,

Thank you for explaining the remote-administration concern in your October 5
review. I have taken the removal option you described for Cybermaps 8.0.1.

I removed Cybermaps' custom MCP transport, OAuth/device authorization code,
arbitrary WordPress ability dispatcher, and user-identity switching. The remote
audit, IndexNow submission, static purge and static reconciliation tools are
removed, including their native WordPress ability registrations. Those actions
remain available through the existing authorized dashboard controls.

MCP is now an optional, explicitly enabled integration with the separate
WordPress MCP Adapter plugin. Cybermaps registers a dedicated server with one
owned read-only search tool and an explicit list of public discovery resources.
It does not discover or execute third-party abilities. Transport and
WordPress authentication are handled by the adapter; a user with the `read`
capability is sufficient for Cybermaps' read-only server.

The old implementation is deleted from the plugin. Upgrade cleanup deletes
retired configuration keys, credential tables and queued jobs. The new adapter
integration uses a separate boolean setting, with no legacy operational modes.
Deletion-only cleanup remains for users upgrading directly from 7.x. Cybermaps'
sitemaps, public AI publications, reports and dashboard controls continue to
work without MCP Adapter.

I also added release guards for this limited capability list, with regression
checks for forbidden tools, unrelated abilities, authentication, input
validation and migration. These checks are not presented as an independent
security audit.

The earlier source-code clarification still applies: `assets/js/setup-wizard.js`
is editable, unminified source using WordPress's `wp.element` API. The readme
identifies the public source and build instructions; the release copies
JavaScript and CSS unchanged.

The release ZIP and readable source are available at
https://github.com/Alex9001/cybermaps/releases/tag/v8.0.1. Thank
you for your time and guidance.
