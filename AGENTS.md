# AGENTS.md — Working on Cybermaps

Orientation for AI agents (and humans) making changes to this plugin. Read this
before editing. It captures the conventions and tooling that are easy to miss.

**Plugin:** Cybermaps: LLM & XML Sitemap SEO — XML sitemaps + AI discovery
endpoints for WordPress, with a static-file delivery engine.

- Language/runtime: **PHP 8.2+** (`declare(strict_types=1)` in every source file), WordPress 7.0+; PHP 8.3+/WordPress 7.1+ recommended.
- Namespace: `Cybermaps\` → `src/` (PSR-4 via `src/Autoloader.php` and Composer).
- Configuration roots: the general `cybermaps_settings` array plus dedicated
  Discovery Center, crawler-policy, and identity options (see "Settings system").
- Distribution: **standalone WordPress.org plugin**. Core contains no licensing SDK,
  premium code, feature locks, or external updater.
- Commercial extensions, if built, are separate plugins with their own namespace,
  settings, lifecycle, licensing, and release artifact.

---

## Golden rules (do these every change)

1. **Lint before claiming done:** `php -l` every PHP file you touched. No syntax errors.
2. **Run the shipped-code standards gate:** `composer run lint:phpcs`. The ruleset
   follows `WordPress-Extra` with WordPress 7.0, Cybermaps i18n/prefix settings,
   selected public-contract documentation, and one documented PSR-4 filename
   exception.
3. **Keep release metadata in sync** when bumping the version (see "Versioning").
4. **Regenerate the docs manifest** after structural changes: `php docs/dev/generate-docs.php > docs/dev/manifest.json` (see "Docs generator").
5. **Keep Core complete and standalone** — every visible control, documented URL,
   scheduled event, and advertised capability must work with only this plugin active.
6. **Use `WP_Filesystem`**, not raw `fopen/fwrite/fclose/file_put_contents` — WordPress Plugin Check flags direct filesystem calls.
7. **Escape on output, sanitize on input, nonce every destructive action.** This plugin targets WordPress.org Plugin Check cleanliness.
8. **Configuration keys live in their owning sanitizer.** `SettingsSanitizer`
   governs `cybermaps_settings`; each structured domain option has its own
   sanitizer and schema.

---

## Repository layout

```
cybermaps.php                  Main plugin file: header, constants, lifecycle, bootstrap
readme.txt                     WordPress.org readme (stable tag, changelog, upgrade notice)
src/
  Autoloader.php               PSR-4 loader (+ manual fallback)
  Core/                        Plugin bootstrap, extension API, RestAPI, URLManager
  Admin/                       Settings UI, status pages, MigrationHub, Logs, reports
    Settings/                  Tabs/, Fields/FieldRenderer, Sanitizers/SettingsSanitizer
  Discovery/                   All AI discovery endpoints + StaticBridge (static file engine)
  Sitemap/                     XML/RSS/HTML sitemap engine, Orchestrator, providers, shortcode
  CLI/                         WP-CLI commands (wp cybermaps ...)
  Integration/                 Third-party hooks
docs/
  documentation.md             Technical docs (keep the version stamp + Static File Engine section current)
  dev/generate-docs.php        Docs/manifest generator (run it; see below)
  dev/manifest.json            Generated machine-readable manifest (commit the regenerated file)
  dev/ai-configuration/        Generated AI Brief JSON Schema and field catalog
tests/                         PHPUnit tests (mocks/mock-wp.php stubs WP)
vendor/                        Composer dependencies — do not hand-edit
clean/                         Direct WordPress.org release artifact — generated, do NOT hand-edit
freemius/                      Historical generated artifacts — ignored, never use for releases
```

---

## Docs generator (`docs/dev/generate-docs.php`)

A standalone CLI script (no WordPress bootstrap — WP functions are stubbed) that
introspects the source and emits a JSON manifest of the plugin's public surface:
version, discovery endpoints, sitemap features, shortcode attributes, REST routes,
WP-CLI commands, static files, Static File Engine modes, all configuration
roots, the general settings key list, every `src/` class, and the canonical AI
Configuration Brief contract.

```bash
# Regenerate the committed manifest
php docs/dev/generate-docs.php > docs/dev/manifest.json

# Regenerate the versioned AI Configuration Brief contracts
php docs/dev/generate-docs.php --ai-schema > docs/dev/ai-configuration/schema.json
php docs/dev/generate-docs.php --ai-catalog > docs/dev/ai-configuration/catalog.json

# Quick sanity check
php -r '$d=json_decode(file_get_contents("docs/dev/manifest.json"),true);
  echo $d["version"]," / settings=",$d["settings_count"]," / classes=",$d["class_count"],"\n";'
```

**Run it after any change that affects:** version, a new/renamed class, a new
discovery endpoint, a new REST route or CLI command, a new setting, Static File
Engine behavior, or the AI configuration registry. Discovery and static-path
metadata come from `EndpointRegistry`; general settings come from sanitizer
assignments, and the structured option schemas are inventoried separately. The
AI Schema/catalog come from `AIConfigurationRegistry` and must pass
`php bin/check-ai-configuration.php`. If the script fatals on a new WP function,
add a stub in the stubs block; the file's top docblock documents this.

---

## Versioning & release docs

Use semver-ish: **patch** = fix, **minor** = new feature, **major** = breaking.
When you bump the version, update **all** of these so they agree:

1. `cybermaps.php` — the `Version:` header **and** the `CYBERMAPS_VERSION` constant.
2. `readme.txt` — `Stable tag:`, a new `== Changelog ==` entry, and a `== Upgrade Notice ==` entry.
3. `docs/documentation.md` — the `> Version X · PHP 8.2 · WordPress 7.0` stamp near the top.
4. Regenerate `docs/dev/manifest.json` and both `docs/dev/ai-configuration/`
   artifacts (they read the version from the header automatically).

Historical changelog/upgrade-notice entries stay as-is — only add new ones.

The public Astro docs are part of the release handoff. Follow
`docs/dev/WEBSITE-SYNC.md`: import the exact committed candidate into the website,
review affected guides, and pass `--website /path/to/cybermaps-astro` (or set
`CYBERMAPS_WEBSITE_DIR`) to `composer run release:github`. Do not advance prose
review records merely because the plugin version changed. Runtime limits and
the combined route inventory are generated; use `--website` for the website
contract. The publisher validates the website before publishing the plugin.

---

## Core / extension boundary (critical)

This repository builds one complete WordPress.org plugin. It is not a
preprocessor input and it is not a reduced edition of another package.

Rules:
- Do not add a licensing SDK, plan check, premium marker, external updater, or
  disabled "upgrade to unlock" control to Core.
- Extensions integrate only through documented Core actions, filters, registries,
  and public contracts. Do not expose the internal service container as an API.
- Shared engine code belongs in Core. An extension adds behavior; it does not
  replace Core or duplicate the `Cybermaps\` namespace.
- A future companion plugin must use a separate namespace such as `CybermapsPro\`,
  separate constants, a separate option such as `cybermaps_pro_settings`, and
  uninstall only data that it owns.
- Core may contain one sparse extensions/comparison link, but it must not install
  or update off-repository plugins.
- Build and test the exact `clean/cybermaps/` artifact produced locally. There is
  no external preprocessing step.

---

## Settings system

- Four configuration roots are registered in the main Settings API group:
  - `cybermaps_settings` — general settings array, governed by `SettingsSanitizer`.
  - `cybermaps_discovery_center` — JSON strategy matrix, governed by `DiscoveryCenterSanitizer`.
  - `cybermaps_robots_manager` — crawler policy array, governed by `RobotsManagerSanitizer`.
  - `cybermaps_identity_data` — structured identity array, governed by `IdentityHub::sanitize_identity_data`.
- Read array options through `Core\ConfigurationStore`; it also safely decodes
  the legacy/canonical Discovery Center shapes for runtime consumers.
- The general sanitize callback runs for the whole option on every tab save, so it must
  **preserve keys from other tabs** (merge from `$old_options`). Checkboxes are
  absent from `$_POST` when unchecked; `<select>`/text always submit — handle the
  "absent means another tab was saved" case so you don't wipe values.
- The shared page submits only active-tab controls and compacts Identity/Robots
  into bounded JSON payloads in JavaScript. Every domain sanitizer must still
  support the nested-array fallback, preserve an absent inactive option, and
  reject a main submission missing the final completeness marker.
- Field rendering: `src/Admin/Settings/Fields/FieldRenderer.php`; tab registration under `src/Admin/Settings/Tabs/`.

---

## Discovery endpoints & the Static File Engine

- Fixed endpoints are served **dynamically** through the registry-backed
  `PublicationRouter`; parameterized localized LLMS and RAG chunk routes use
  explicit fallthrough handlers. Static publication is an optional
  materialization layer owned by `StaticBridge::sync_all()`.
- **Static File Engine mode** (`static_engine_mode`, resolved by `StaticBridge::get_mode()`):
  - `off` — publish no files; supported requests use WordPress when the server
    routes them to PHP.
  - `well_known` — five small registered targets are written when the Discovery
    Hub is enabled: `/ai.json`, `/ai-usage.json`, `/ai-actions.json`, the
    canonical Agent Skills `SKILL.md`, and its discovery index (**default**).
  - `all` — the sitemap index, every internal Core child advertised by that
    index, enabled RSS output, discovery output, localized LLMS output, and
    eligible RAG chunks are written to disk. `robots.txt` is always dynamic.
- `static_engine_mode` is the only runtime mode key. `Settings` removes the
  retired `enable_static_engine` value once; no behavior is inferred from it.
- **Multisite is dynamic-only.** `StaticBridge::get_mode()` resolves to `off` on
  every multisite site so sites never compete for shared web-root filenames.
- **`.well-known` gotcha:** on OpenLiteSpeed and some nginx setups, `/.well-known/`
  is served directly by the web server and never reaches PHP. Core does not mint
  unregistered Cybermaps `.well-known` names. The standard API Catalog
  (`/.well-known/api-catalog`), Discovery Index (`/ai-discovery`), and other
  protocol-sensitive endpoints stay dynamic so Core controls their headers and
  can observe requests. A remote/differently rooted headless frontend must
  provide `cybermaps_static_publication_root` for static publication.
- Settings changes invalidate the current generation and schedule an
  ownership-safe reconciliation via `Settings::on_settings_updated`. Narrowing
  the mode also attempts an immediate targeted purge. A file is overwritten or
  deleted only while its content still matches Core's recorded ownership hash;
  retained conflicts are expected and must be surfaced to the caller.

**To add a fixed discovery endpoint:** add the handler and complete publication
metadata (handler, paths, MIME/format, protocol status, throttle tier, static
targets, label/description) to `EndpointRegistry`. The router, publisher,
analytics, status page, and docs generator consume that registry. Document it in
`readme.txt` + `docs/documentation.md`, then regenerate the manifest. A
parameterized route remains an explicit handler and must get matching analytics,
throttling, static-parity, and status coverage.

---

## WordPress.org source-safety rules

- Put shipped CSS and JavaScript in registered/enqueued assets. Do not add
  literal `<style>` or `<script>` blocks to PHP output.
- At every request boundary, validate the HTTP method, capability, and nonce
  before reading action selectors or payload fields. Public read-only protocol
  negotiation is the narrow exception and must use a bounded adapter.
- Treat decoded JSON as untrusted input. Require an exact documented schema,
  reject unknown or nested fields and wrong scalar types, and enforce byte and
  collection limits before using any value.
- Never pass a complete superglobal to a hook. Publish only a documented,
  sanitized, bounded context and revalidate filtered values before use.
- Escape at the final context: HTML/attributes with the matching WordPress
  helper or a narrow `wp_kses()` allowlist, and non-HTML protocols through the
  audited contextual emitter. JSON response sinks encode structured payloads
  with `wp_json_encode()` and no presentation flags.
- Every SQL datum, including every item in an `IN (...)` list, gets its own
  typed placeholder and is passed through `$wpdb->prepare()`.
- Never manually read or write WordPress-reserved `_transient_` option names.
  Use the Transients API, object cache, or a Cybermaps-owned table.
- Load a permitted WordPress core include only immediately before the API that
  needs it. Never bootstrap `wp-load.php` or `wp-blog-header.php` from Core.
- Do not add nonce, output, input-validation, or SQL PHPCS suppressions, and do
  not weaken or regenerate an allowlist merely to make a validation command
  pass. An unavoidable protocol boundary requires a documented design review
  and an exact entry in `docs/dev/wporg-source-allowlist.json`.
- Run `composer wporg:check` for every shipped-source change. The checker is
  annotation-blind and its exact allowlists are release contracts.

---

## Tests, lint, build

Run validation and release packaging locally. GitHub Actions build, CI, and
deployment workflows are intentionally disabled; do not recreate them unless
the user explicitly requests them.

```bash
composer test          # PHPUnit (tests/ ; WP is stubbed via tests/mocks/mock-wp.php)
composer run lint:phpcompat # PHPCompatibilityWP gate for the PHP 8.2+ shipped runtime
composer run lint:phpcs # WPCS gate for cybermaps.php, uninstall.php, and src/
composer run lint:complexity # Strict cyclomatic-complexity gate (maximum 10)
composer wporg:check # WordPress.org static guards + annotation-blind audit
composer run lint:phpcbf # Mechanical fixer; always review its diff before keeping it
php -l <file>           # Syntax check anything you edited
php docs/dev/generate-docs.php > docs/dev/manifest.json   # Manifest
php docs/dev/generate-docs.php --ai-schema > docs/dev/ai-configuration/schema.json
php docs/dev/generate-docs.php --ai-catalog > docs/dev/ai-configuration/catalog.json
```

- New behavior should get a test where practical (see `tests/Discovery/StaticBridge*Test.php`,
  `tests/Admin/SettingsSanitizerTest.php` for patterns).
- WordPress **Plugin Check** is the external compliance gate. Common catches:
  direct filesystem calls (use `WP_Filesystem`), unescaped output, missing nonces,
  `Stable tag` mismatch.
- `vendor/` and `clean/` are dependencies or generated build copies — never
  hand-edit them; they are produced by Composer / the packaging step.
- `freemius/` contains ignored historical packages only. Never use it as release
  input or output.

---

## Reference material in this repo

- `docs/documentation.md` — full technical documentation.
- `docs/features.md`, `docs/comparison.md` — feature/positioning references.
- `docs/llms-tldr-whitepaper.md` — the LLMS-TLDR pipeline spec.
- `docs/dev/manifest.json` — the generated machine-readable surface map (good first read for orientation).
- `.claude/skills/agent-skills/skills/` — bundled WordPress skill references (plugin dev,
  REST API, block dev, performance, PHPStan, WP-CLI, plugin-directory guidelines, etc.).
