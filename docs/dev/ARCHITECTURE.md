# Cybermaps Core Architecture Decision

Status: binding for Core and any future companion.

## Decision

Cybermaps Core is one complete WordPress.org plugin. It contains no Freemius
runtime, licensing client, plan check, external updater, premium-only source,
build-stripping marker, or locked feature control.

A future commercial product is a separate additive WordPress plugin. It may use
Extension API 2.0, but Core never requires it and never contains an inert
replacement for its features.

This is a clean break. There are no production customers whose old package
state must constrain the foundation.

## Core responsibility

Core owns and keeps functional:

- final publication eligibility;
- XML/RSS/HTML sitemap generation;
- the fixed discovery publication registry and Core handlers;
- dynamic routing and static materialization;
- generated-file ownership and cleanup;
- robots and configured identity/schema output;
- crawler observations and retention;
- saved content-measurement evidence and exports;
- settings, migration, REST, CLI, lifecycle, and uninstall; and
- the versioned read/registration boundary.

The WordPress.org artifact is built directly from this runtime source. No paid
source is mixed into or stripped from the artifact.

## Companion responsibility

A companion must own:

- a separate namespace;
- its own options, tables, assets, cron, routes, and files;
- licensing, checkout, account UI, updater, and telemetry, if any;
- its own activation, deactivation, migration, and uninstall;
- every implementation behind metadata it registers; and
- graceful behavior when Core is absent or incompatible.

It may add workflow, presentation, automation, or integrations. It must not
remove Core behavior, replace Core screens with locks, mutate preserved Core
audit runs, or delete Core data.

## Extension API 2.0

Entry point:

```php
$api = \Cybermaps\Core\ExtensionAPI::get_instance();
```

Ready action:

```text
cybermaps_extension_api_ready
```

### Endpoint registration

`$api->endpoints()` returns the shared `EndpointRegistry`. A companion can
register factual metadata during `cybermaps_register_endpoints`.

Registration validates:

- stable ID;
- `path` or `rest` kind;
- canonical path/route and conflicts;
- aliases;
- media type and body format;
- maturity, adoption, delivery, and presentation group;
- optional static target metadata; and
- optional enablement setting metadata.

Registration does not create a callback, permission check, static write, or
cleanup implementation. The companion owns those behaviors. Invalid metadata is
rejected and reported without preventing Core from loading.

### Eligibility

`$api->eligibility()` returns the same final
`Cybermaps\SEO\PublicationEligibility` service Core uses. A companion must
consume this decision instead of reproducing Genesis/Mai or SEO-plugin meta
logic.

Compatibility adapters can be added through
`cybermaps_seo_compatibility_adapters`. The final typed decision can be replaced
through `cybermaps_indexability_decision`.

### Audit reads

`$api->audits()` returns `Cybermaps\Audit\AuditReadAPI`. It reads completed
immutable Core runs and exact baseline differences. It exposes no create,
update, delete, or presentation mutation method.

A companion may build workflows around a run ID, but the Core record remains
the source of truth.

## Data ownership

Core owns four configuration roots: the general `cybermaps_settings` array,
the JSON-backed `cybermaps_discovery_center` matrix, the
`cybermaps_robots_manager` crawler policy, and `cybermaps_identity_data`.
Each root has a dedicated sanitizer; runtime consumers use shape-safe readers.
The shared settings page submits only the active workspace, and all sanitizers
preserve omitted inactive roots. A companion must use its own option keys and
sanitizer.

Core owns:

- crawler analytics tables and health state;
- audit run/resource/finding tables;
- generated-file ownership records;
- Core cache/transient families;
- Core cron events; and
- Core uninstall preferences.

Companion data must be independently identifiable and independently removable.

## Publication model

Dynamic WordPress handlers are the baseline. Static modes are:

- `off`: no physical output;
- `well_known`: four registered origin-root JSON well-known copies; and
- `all`: eligible sitemap, discovery, localized LLMS, and RAG output.

Multisite is dynamic-only. `robots.txt` remains dynamic.

Static writes use `WP_Filesystem` and ownership hashes. Core may overwrite or
delete only the bytes it still owns. A companion does not acquire Core
filesystem ownership through API 2.0.

## Endpoint truth policy

Every publication must distinguish:

- delivery mechanism;
- enablement;
- format maturity;
- evidence of adoption;
- public HTTP validation; and
- observed requests.

Implementing a representation does not prove that a consumer exists. A formal
specification does not prove adoption. A static file on disk does not prove the
public server delivered it. A PHP request observation does not prove a
particular provider made it. Crawler-registry matches are User-Agent claims
unless a separate verification method says otherwise. Discovery Analytics
preserves the claim, its evidence basis, and useful unidentified-client patterns
without rewriting historical rows.

Core does not publish a protocol-branded endpoint unless it implements the
service that name implies. This is why 5.0 removed the old OpenAI plugin
manifest, MCP card, and Agent Skills index.

## Evidence policy

Core may report literal stored facts and explicit rule outcomes. It does not
invent composite health, moat, authority, quality, information-gain, semantic,
or ranking scores.

Reports persist:

- exact policy and policy hash;
- resource measurements and content hash;
- finding rule/measured/threshold evidence; and
- exact finding identity changes from the prior completed run.

Exports are presentations of the preserved run, not regenerated assessments.

Configuration portability is separate from report export. `MigrationHub`
produces a complete versioned JSON envelope for exact configuration backup and
restore, validates its checksum and required option groups before writing, and
rolls back a partially applied import if verification fails. The editable
Markdown AI template is merge-only; `null` means preserve. Analytics CSV and
report exports are offline records and are not database restore formats.

## Lifecycle

Activation provisions Core schema. The 5.0 foundation reset:

- establishes `well_known` as the default static mode;
- disables optional full and experimental LLMS publications unless explicitly
  enabled;
- removes retired synthetic report/cache settings and transient families; and
- provisions audit and crawler-observation schema.

Deactivation unschedules Core work and attempts an ownership-safe purge.
Uninstall deletes persistent Core data only when the explicit delete-data
setting is enabled.

## Release gates

Before every Core release:

1. lint every first-party PHP file;
2. run PHPUnit;
3. validate JavaScript syntax;
4. regenerate and compare `docs/dev/manifest.json`;
5. verify header, constant, stable tag, changelog, upgrade notice, and docs
   version;
6. reject Freemius/preprocessor/premium markers from runtime and distribution
   docs;
7. build the direct WordPress.org artifact;
8. run WordPress Plugin Check on that artifact; and
9. verify the free artifact loads with no external SDK.

## Historical rationale

Cybermaps 3.x tried to generate coupled Free and Pro editions from one runtime.
WordPress.org constraints forced removal of code that the shared application
still referenced, leaving holes in routes, settings, hooks, and UI.

With no customers to migrate, preserving that coupling had no product value.
The 5.0 foundation makes Core complete and moves every future commercial
decision across a real plugin boundary.
