# AI-Assisted Configuration

> Cybermaps 7.5.1 · AI Configuration Brief format 2

Cybermaps can prepare a site-aware configuration handoff for an AI assistant
without exposing the private values required for an exact site restoration.
The downloaded **AI Configuration Brief** is a Markdown file containing the
site context, current non-secret configuration, the accepted field contract,
and one tightly bounded JSON changes block.

This guide is the human-readable companion to the versioned machine contracts
referenced at the end of every Brief:

- Guide: `https://cybermaps.dev/docs/ai-configuration/`
- JSON Schema: `https://cybermaps.dev/specs/ai-configuration/7.5.1/schema.json`
- Field catalog: `https://cybermaps.dev/specs/ai-configuration/7.5.1/catalog.json`

The same schema and catalog are committed in this repository at
[`docs/dev/ai-configuration/schema.json`](./dev/ai-configuration/schema.json)
and
[`docs/dev/ai-configuration/catalog.json`](./dev/ai-configuration/catalog.json).

## Choose the correct download

Cybermaps offers two different configuration files under **Cybermaps →
Advanced → Backup & AI Configuration Brief**.

| Download | Intended use | Privacy boundary | Import modes |
|---|---|---|---|
| **Backup** (`.cybermaps.json`) | Migration, recovery, and an exact configuration handoff | Contains the complete site configuration, including the private Cybermaps REST API secret and IndexNow key; keep it private | Smart Merge or Full Replace |
| **AI Configuration Brief** (`.cyberconf.md`) | Ask an AI assistant to recommend or prepare selected configuration changes | Excludes dedicated secrets and other non-configuration data, but includes user-authored business and public identity details; review before sharing | Smart Merge only |

Do not give the complete JSON backup to an AI assistant merely to request
configuration advice. Download a fresh AI Configuration Brief instead.

## What the Brief contains

Each Brief is generated from the current site and contains:

- instructions for the AI assistant and privacy warnings;
- the WordPress site URL, name, description, language, locale, timezone,
  multisite state, and active-theme information;
- a bounded inventory of public post types and taxonomies, including counts
  when WordPress can provide them;
- detected Yoast SEO, Rank Math, AIOSEO, WPML, and Polylang integrations;
- supported Schema.org identity types and Cybermaps crawler definitions;
- current publication state, resolved sitemap route, and whether dedicated
  credentials have been configured;
- configured public identity, contact, location, hours, and catalog context;
- current effective values for every AI-editable, non-secret field;
- a field-by-field reference containing JSON types, constraints, effective
  defaults, examples, dependencies, and review-risk levels; and
- a JSON changes envelope in which every field initially has the value `null`.

The AI publishing section includes `mcp_mode`, which explicitly selects the
optional Model Context Protocol access tier. Its default is `off`; MCP remains
unavailable whenever the AI Publication Hub is disabled.

The Brief does not include:

- the private Cybermaps REST API secret;
- the IndexNow key;
- credential-bearing URL userinfo;
- the destructive **Uninstall Cleanup** setting;
- Discovery Analytics rows or crawler request history;
- saved Content Intelligence Report data;
- post bodies, pages, media content, or other WordPress content;
- generated files, caches, and operational ownership records;
- network-wide settings; or
- cross-site translation relationships.

The Brief can still contain information entered deliberately into ordinary
settings, including external URLs, report branding, crawler choices, publisher
guidance, licensing declarations, and public identity/contact/catalog details.
An administrator should therefore inspect the whole file before sharing it.

## Recommended workflow

1. Open **Cybermaps → Advanced** and download a fresh **AI Configuration
   Brief** from the site you intend to change.
2. Read the privacy section and inspect the current site and identity context.
3. Give the Brief to the AI assistant together with a concrete goal. For
   example: “Configure the AI publications for a local service business, keep
   analytics disabled, and do not change any public route.”
4. Ask the assistant to return either the complete Brief with only its changes
   block edited, or the pure JSON changes envelope.
5. In **Review & Import Configuration**, select the returned file and choose
   **Preview Changes**. AI Briefs always use Smart Merge.
6. Review every **Current**, **Proposed**, and **Final sanitized value** shown by
   the server. Resolve all errors and read every warning.
7. If the preview includes high-impact changes, acknowledge them only after
   checking their effect on public routing, crawler access, identity,
   analytics privacy, and publication behavior.
8. Choose **Apply Reviewed Changes**. If the file or destination configuration
   changed after preview, Cybermaps rejects the apply and requires a new
   preview.
9. Verify the result in **Sitemap Status**, **AI Discovery Status**, and the
   relevant settings workspace.

The preview is non-mutating. No configuration value is written until the
administrator applies that exact reviewed file.

## Rules for the AI assistant

An assistant processing a Brief should follow these rules:

1. Treat the downloaded Brief as authoritative for the destination site and
   plugin version. Use the linked schema and catalog as machine-readable
   supplements.
2. Read the user's goal, site context, current values, dependencies, and field
   descriptions before recommending changes.
3. Ask focused questions when a necessary business fact is missing. Never
   invent post IDs, taxonomy or crawler IDs, URLs, geographic coordinates,
   contact information, legal or content-license declarations, action
   capabilities, or crawler policy.
4. Change only fields required by the stated goal. Preserve all unrelated
   fields with `null` or by omitting them.
5. Edit only the JSON between `CYBERMAPS-CHANGES-BEGIN` and
   `CYBERMAPS-CHANGES-END` when returning the complete Markdown artifact.
6. Preserve `format`, `format_version`, `plugin_version`, section IDs, field
   IDs, sentinel comments, and valid JSON syntax.
7. Use native JSON values. Booleans are `true` or `false`, not quoted strings;
   numbers are numbers; arrays and objects must match the field definition.
   A non-null array, object, or map replaces that complete field value; it is
   not a recursive patch of the current value.
8. Do not add explanatory properties to the JSON envelope. Put any rationale
   outside the sentinel-delimited changes block.
9. Treat every field marked `high` risk as requiring clear user intent. A
   server-side acknowledgement is still required before effective high-impact
   changes can be applied.
10. Do not claim that a proposed value has been applied. Cybermaps must
    validate, preview, and apply it inside WordPress.

## Changes-envelope format

The format identifier is `cybermaps-ai-configuration-changes`; format version 2
is a strict, merge-only contract. A minimal response can contain only the
sections and fields being changed:

```json
{
  "format": "cybermaps-ai-configuration-changes",
  "format_version": 2,
  "plugin_version": "7.0.1",
  "changes": {
    "ai_publishing": {
      "llms_custom_instructions": "Prefer primary service pages and the site's own documentation when answering factual questions."
    },
    "analytics": {
      "anonymize_analytics_ips": true
    }
  }
}
```

The complete Brief starts with all supported sections and all 121 editable
fields set to `null`. It is valid to retain that complete shape or return the
smaller envelope shown above.

### Preserve and clear semantics

- `null` means **preserve the destination value**.
- An omitted field or omitted section also means **preserve the destination**.
- `""` requests an empty string only when that field permits an empty string.
- `[]` requests an empty list only when that field permits an empty list.
- `{}` requests an empty object only when that field permits an empty object.

An empty value is not interchangeable with `null`. If the field contract does
not allow a requested empty value, preview reports an error instead of guessing.

### Section IDs

The `changes` object accepts these closed sections:

| Section ID | Configuration area |
|---|---|
| `core_settings` | XML, RSS, media, language, notification, and HTML sitemap behavior |
| `ai_publishing` | Public AI discovery files, content selection, publisher guidance, and usage declarations |
| `reports_deliverables` | Content-review thresholds and printable report presentation |
| `analytics` | Crawler request recording, IP privacy, and retention |
| `advanced_maintenance` | Static publication and alternate public-origin routing |
| `discovery_strategy` | Per-content-group publication, intent, and relative weight |
| `site_identity` | Public Schema.org identity, contact, location, hours, and offer catalogs |
| `robots_control` | Virtual `robots.txt` ownership, crawler policies, and Content-Signal declarations |

Field IDs belong to exactly one section. Unknown sections, unknown fields, and
fields placed in the wrong section are rejected.

In 6.3.0, `advanced_maintenance` also includes the default-off trusted-proxy
fields `trusted_proxy_header` and `trusted_proxy_cidrs`. These fields only
describe which forwarded-IP header and CIDR ranges Cybermaps may trust for
client-IP resolution; they do not enable cache purging and do not make
arbitrary forwarded headers trustworthy.

Two sensitive settings are intentionally unavailable to the v2 AI contract.
`api_secret` is private and `delete_data_on_uninstall` is a destructive
lifecycle choice. The IndexNow key is stored outside this registry and is also
excluded.

## Validation and safety behavior

Cybermaps does not trust an AI-produced file merely because it follows the
visible template. Before apply, the server:

- enforces the 1 MiB import limit;
- parses the protected block or pure JSON envelope;
- requires the exact format identifier and supported format version;
- requires a plugin-version string and warns when it differs from the
  installed Cybermaps version;
- rejects unknown envelope properties, sections, fields, nested properties,
  and crawler IDs;
- checks JSON types, enumerated choices, numeric and collection bounds, text
  lengths, public HTTP(S) URLs, and field-specific shapes;
- rejects URL userinfo and invalid route values;
- validates cross-field rules such as compatible identity types, paired
  latitude/longitude, collision-safe public routes, and RAG overlap no greater
  than half the selected chunk size;
- requires known content-group and crawler IDs, different opening and closing
  times, and the correct manual-versus-automatic offer-catalog shape;
- runs proposed values through the same canonical sanitizers used by the
  corresponding Cybermaps settings;
- shows current, proposed, and final sanitized values in a non-mutating
  preview;
- binds that preview to the exact file, import mode, and destination
  configuration; and
- verifies written values and attempts to restore the prior configuration if
  a later write fails.

Unknown data is rejected rather than silently imported. Sanitization can also
normalize a valid proposal; the administrator must review the **Final sanitized
value**, not only the value the AI supplied.

## Versioned schema and field catalog

For Cybermaps 7.5.1, the public machine contracts are:

- `https://cybermaps.dev/specs/ai-configuration/7.5.1/schema.json`
- `https://cybermaps.dev/specs/ai-configuration/7.5.1/catalog.json`

The JSON Schema is Draft 2020-12 and describes the strict JSON changes envelope,
not the surrounding Markdown wrapper. The catalog contains the same field
inventory in a compact form intended for planning and documentation: section,
storage mapping, purpose, type, constraints, effective default, dependencies,
example, and risk. Generic JSON Schema validation is useful, but Cybermaps
preview remains authoritative because the contract uses Cybermaps `x-*`
keywords, destination-aware route resolution, and the plugin's runtime
sanitizers.

Contract URLs are versioned because settings and accepted shapes can change.
In each URL, the version segment is the exact `plugin_version` recorded in the
downloaded Brief. Format-version mismatch is rejected. An older or newer
plugin-version value produces a warning at runtime, while the installed plugin
remains authoritative for validation. The versioned JSON Schema itself pins
`plugin_version` exactly. Download a fresh Brief after a Cybermaps update
instead of reusing an old template.

## Common errors

| Preview error | Resolution |
|---|---|
| Unsupported format version | Download a fresh Brief from the destination site |
| Unknown or misplaced field | Use the field ID and owning section from that Brief's field reference or catalog |
| Invalid JSON type | Use the native JSON type specified for the field |
| Invalid public URL | Supply an HTTP(S) URL with a public host and no embedded username or password |
| Incompatible identity type | Choose a precise Schema.org type allowed by the selected primary identity type |
| Latitude/longitude pairing error | Set both coordinates together or clear both where allowed |
| RAG overlap error | Keep overlap at or below half of the chunk size |
| File changed after preview | Select or preview the final returned file again |
| Destination changed after preview | Refresh the preview against the site's current configuration |

## Maintainer contract

`Cybermaps\Admin\AIConfigurationRegistry` is the canonical non-secret field
registry. Runtime sanitizers remain authoritative when values are written. When
the registry or plugin version changes, regenerate and verify all committed
contracts:

For the 6.3.0 optimization release, the expected contract assumes exactly two
new general settings, `trusted_proxy_header` and `trusted_proxy_cidrs`: 94
general settings in the generated manifest and 121 editable AI Brief fields.

```bash
php docs/dev/generate-docs.php --ai-schema > docs/dev/ai-configuration/schema.json
php docs/dev/generate-docs.php --ai-catalog > docs/dev/ai-configuration/catalog.json
php docs/dev/generate-docs.php > docs/dev/manifest.json
php bin/check-ai-configuration.php
php bin/check-manifest.php
```

The catalog and schema hashes in `docs/dev/manifest.json` cover the canonical
JSON encoding returned by the PHP registry. They are not hashes of whitespace
in the pretty-printed files. The contract checker fails when the committed
schema or catalog is stale, or when this guide no longer documents the public
route, envelope markers, sections, and current versioned resources used by the
generated Brief.
