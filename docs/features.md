# Cybermaps 7.4.2 Feature Reference

This reference describes Cybermaps 7.4.2 using the
[generated source inventory](./dev/manifest.json). Cloudflare automation,
Debugging, compatibility publication, and independent LiteSpeed/APCu controls
are included; availability and public delivery depend on the configuration.

> Standalone WordPress.org plugin · PHP 8.2+ · WordPress 7.1+ · PHP 8.3+ recommended

Cybermaps is a focused search and AI-discovery stack for professional WordPress
sites. It delivers fast sitemaps, token-efficient machine
publications, crawler evidence, structured site identity, and polished content
reports without requiring an external AI account.

## Sitemap publishing

- Configurable XML sitemap index with automatic 2,000-URL child files and
  collision-safe post-type/taxonomy route namespaces.
- Post type, taxonomy, author, date archive, and miscellaneous providers.
- Optional Google News sitemap for the last 48 hours, up to 1,000 entries.
- Optional RSS 2.0 sitemap with selectable post types and item limit.
- Image and video sitemap extensions with off, standard, and advanced media
  discovery, bounded to 100 published media observations and 25 videos per post.
- Standard discovers featured and attached images; Advanced also distinguishes
  direct video content from hosted player/embed URLs, scans at most 2 MiB of
  stored markup with bounded match collection, and requires a real poster or
  provider thumbnail for protocol video entries.
- Optional `VideoObject` JSON-LD for eligible videos with complete public
  metadata.
- XSL browser presentation, ETags, `304 Not Modified`, and optional 12-hour
  response caching.
- Generation-fenced WordPress Cache API groups for internal publication,
  registry, route, and diagnostic caches.
- Configurable sitemap, News, and RSS slugs.
- Optional redirects from WordPress core and legacy sitemap paths.
- Optional multisite `/sitemap-network.xml` index for active public sites.
- Real-world Content Discovery Strategy profiles with independent, kind-aware
  post-type and taxonomy publication status, Informational/Commercial discovery
  intent, and positive publication weight; inherited/custom indicators and
  explicit reset controls; plus
  per-resource priority and change-frequency overrides.
- Global and per-resource sitemap exclusions.
- Optional home-page, author-archive, date-archive, and empty-term inclusion.
- Up to 100 external sitemap references and 1,000 additional page URLs.
  Validation is local and structural: absolute HTTP(S), unique entries, and an
  `.xml` path for sitemap references. No remote request is made.
- Headless frontend URL rewriting.
- Optional same-site image and video URL rewriting to a configured media CDN.
- Optional sitemap URL injection into virtual `robots.txt`.
- Optional post/page modified-time refresh when a comment is approved.
- Optional IndexNow and WebSub publication notifications.

## HTML sitemap

The `[cybermap]` shortcode includes an interactive builder and supports:

- collision-safe content-group selection through namespaced
  `post_type:slug` and `taxonomy:slug` tokens in `only`, plus
  `post_type:*` and `taxonomy:*` kind wildcards (with legacy unprefixed slugs
  still accepted);
- item-ID, exact-slug, and slug-wildcard exclusions through `exclude`;
- entry limits and hierarchy depth;
- ascending or descending order;
- optional `nofollow`;
- optional section titles; and
- `list`, `columns`, and `bare` layouts.

The builder has its own HTML Sitemap workspace. Only shortcode availability is
saved there; builder choices are encoded in the copied shortcode. Rendered
output still honors Content Discovery Strategy, public indexability, and
Cybermaps exclusions.

## Shared publication eligibility

One shared resolver supplies the base publication decision to XML sitemaps, AI
publications, chunks, IndexNow, and reports. Each channel then applies only its
own inclusion controls. Together, the base decision and channel scope account
for:

- WordPress publication state, public object types, passwords, URLs, and site
  visibility;
- Cybermaps global and per-resource exclusions;
- redirects and off-resource canonicals;
- supported Genesis and Mai Theme SEO signals; and
- supported singular noindex and canonical signals from active Yoast SEO, Rank
  Math, and All in One SEO installations.

The dedicated report channel keeps base public/search indexability and the
final extension filter authoritative, while intentionally ignoring XML-only
exclusions, AI-only exclusions, and Content Discovery Strategy Publish state. Those controls
remain authoritative on their own publication channels.

The classic and block editors expose sitemap inclusion, AI inclusion, intent,
priority, and change-frequency controls for publishable public post types.
WordPress attachment rows are consistently excluded from XML, AI, HTML,
reporting, status, audit, and editor post-type inventories while attached media
can still enrich eligible content.

## AI Publication Hub

Cybermaps publishes focused site representations without theme chrome,
navigation, scripts, or repeated layout. Compatible agents can read explicit
URLs, summaries, identity, actions, freshness, and publisher guidance with less
payload and token overhead than full-page rendering.

| Publication | Format | Purpose |
|---|---|---|
| `/ai.json` | JSON | Cybermaps discovery manifest |
| `/ai-discovery.json` | JSON | AI Discovery Protocol 3.0 Level 3 manifest |
| `/ai-discovery` | JSON | Registry-backed discovery index |
| `/llms.txt` | Plain text/Markdown | Compact map of eligible content |
| `/llms-full.txt` | Plain text/Markdown | Opt-in, complete-or-fail literal stored-text corpus within a 32 MiB safety ceiling |
| `/llms-tldr.txt` | Plain text/Markdown | Opt-in budgeted site briefing |
| `/knowledge-graph.json` | JSON-LD/Schema.org | Identity and content relationships |
| `/feed.json` | JSON Feed 1.1 (`application/feed+json`) | Recent eligible posts and the canonical WebSub topic |
| `/updates.json` | JSON | Bounded seven-day ADP update stream for current public content |
| `/news/llms.txt` | Markdown | Bounded news-specific context for recent eligible content |
| `/news/speakable.json` | JSON-LD | Schema.org speakable summaries for recent eligible content |
| `/news/changelog.json` | JSON | ADP news publication version metadata |
| `/news/archive.jsonl` | JSONL | Bounded newline-delimited eligible-content archive |
| `/ai-sitemap.xml` | XML | AI-oriented content and media inventory |
| `/ai-usage.json` | JSON | Publisher-selected content-use preferences |
| `/ai-actions.json` | JSON-LD | Action and capability inventory |
| `/skill.md` | Markdown | Compatibility URL for the Agent Skills site guide |
| `/.well-known/agent-skills/cybermaps-site-guide/SKILL.md` | Markdown | Canonical Agent Skills-compatible read-only site guide |
| `/.well-known/agent-skills/index.json` | JSON | Digest-bound Agent Skills Discovery 0.2.0 draft index |
| `/.well-known/api-catalog` | Linkset JSON | Established RFC 9727/RFC 9264 catalog with enriched per-API Linksets; `/api-catalog` is a dynamic compatibility alias |
| `/.well-known/ai-catalog.json` | JSON | Draft ARD catalog of active capabilities; `/ai-catalog.json` is a compatibility alias |
| `/.well-known/mcp/server-card.json` | JSON | Requested scanner compatibility for the experimental MCP Server Card whose canonical resource is `/wp-json/cybermaps/v1/mcp/server-card` |
| `/.well-known/oauth-authorization-server` | JSON | RFC 8414 metadata when MCP is enabled |
| `/.well-known/oauth-protected-resource` | JSON | RFC 9728 metadata for the protected MCP resource |
| `/auth.md` | Markdown | Registration instructions when MCP and `user_claimed` registration are enabled |
| `/cybermaps-openapi.json` | OpenAPI JSON | OpenAPI 3.2.0 public REST contract with explicit 3.1.2 negotiation |

Additional publication features:

- Optional Markdown for Agents negotiation at eligible canonical URLs. An
  explicit `Accept: text/markdown` preference returns bounded literal Markdown
  for singular content, home/blog views, and public archives while ordinary
  requests remain HTML; status diagnostics verify public cache separation.

- Public discovery, budgeted-briefing, and bounded text-search REST routes.
- Bounded public health at `GET /wp-json/cybermaps/v1/health`.
- Private secret-authenticated URL inventory and status routes, plus saved-report
  REST routes with the same 25,000-row synchronous JSON snapshot guard as report
  exports.
- Optional RFC 8288 discovery headers.
- Public CORS read/preflight headers, ETags, and RFC 9530 Content-Digest on
  dynamic Cybermaps machine publications. Direct static delivery depends on
  equivalent web-server or CDN header configuration.
- Revision-pinned Cybermaps ADP 3.0 Level 3 profile with the four required
  `/news/*` publications when AI Publishing and those publications are enabled.
- Optional MCP 2026-07-28 stateless POST transport with `off`, `discovery`,
  `read_only`, and `operations` modes. Operations exposes only five bounded
  tools: `cybermaps.search`, `cybermaps.audit.run`,
  `cybermaps.static.reconcile`, `cybermaps.static.purge`, and
  `cybermaps.indexnow.submit`.
- OAuth 2.1 PKCE consent and scoped WordPress capability checks protect MCP;
  `ai-actions.json` remains descriptive metadata and never creates tools.
- Pure OAuth metadata only: no OIDC claim, fabricated OIDC discovery, fake
  JWKS, or unsupported A2A surface.
- Default-off user-claimed RFC 8628 device approval with logged-in WordPress
  review; that mode also enables emerging Auth.md publication while MCP is
  enabled. No account or
  credential is created before approval.
- Default-off read-only WebMCP tools for site search, same-origin Markdown, and
  discovery-resource listing, with safe no-op behavior in unsupported browsers.
- Visible maturity guidance distinguishes established RFC standards, current
  drafts, experimental previews, and vendor conventions without promising
  crawler or client adoption.
- Opt-in AIPREF `Content-Usage` publication supplements preserved
  `Content-Signal`; IndexNow can use a deduplicating same-host queue with
  durable database storage, bounded retries, and batches up to 10,000 URLs.
- Static diagnostics distinguish body parity from origin-header parity,
  including RFC 9111 edge policy and RFC 9530 Repr-Digest.
- Endpoint registry shared by routing, publication, validation, analytics, and
  documentation.
- Cost-tiered local request limiting.
- Bounded, non-caching LLMS inventory batches; no full-corpus transient or
  request-cache copy.
- Explicit LLMS oversize failures with no silently truncated dynamic or static
  publication.
- RFC 9457 JSON 404 responses with up to three eligible literal-search
  alternatives for crawler-like requests.
- No external AI key, embeddings service, or generation API required.
- Granular controls for LLMS inclusion, title, mission, license, taxonomy
  filters, up to 100 pinned briefing resources, and token budget.
- Global AI exclusions by post ID and by taxonomy term ID or slug.
- JSON Feed limits from 1–100, AI sitemap limits from 1–2,000 eligible items
  per selected type, and up to 100 custom links and 100 action mappings.
- Granular controls for feed fields, manifest contents, AI sitemap scope,
  content hints, media hints, and usage preferences.
- Knowledge Graph privacy and publisher-link controls.

## Custom AI instructions

Publisher guidance entered once is included in:

- `llms.txt`;
- enabled `llms-full.txt`;
- `skill.md`;
- the Cybermaps discovery manifest;
- the Cybermaps discovery index; and
- the public discovery REST response.

The Site Guide has a separate guide-only addition. Schema-bound formats retain
their expected structures.

## Localized output and retrieval exports

- Active WPML and Polylang languages can receive
  `/{language}/llms.txt`, localized full files, and localized budgeted
  briefings.
- Translation relationships can add sitemap `hreflang` alternates.
- Optional `/discovery/chunks/{post_id}.json` routes split eligible stored text
  into configurable overlapping character windows and preserve headings.
- Public REST search uses bounded WordPress text matching with 1–100 results.

## Identity, schema, catalogs, and robots

- Homepage JSON-LD and Knowledge Graph publication from one Identity Hub.
- `Organization`, `LocalBusiness`, and `Person` roots with supported Schema.org
  subtypes.
- Name, description, image, postal address, coordinates, phone, email, social
  profiles, support/sales contact points, and opening hours.
- Manual `OfferCatalog` construction.
- Automatic catalog construction from up to 50 published direct child pages
  of a selected parent page.
- Bounded identity collections: 12 catalogs, 200 offers overall, 20 social
  profiles, 12 contact points, and four opening-hour ranges per day.
- Append or takeover robots modes.
- Manual directives, crawler-specific overrides, and machine-readable
  content-use preferences.
- WordPress site visibility remains authoritative.

## Static File Engine

| Mode | Result |
|---|---|
| `off` | Dynamic WordPress delivery; no generated files |
| `well_known` (default) | Up to eleven ownership-safe Core, discovery-index, Agent Skills, and protocol targets while their capabilities are enabled |
| `all` | Sitemap index and internal children, enabled RSS, discovery, localized LLMS, and eligible chunk files |

Static publishing provides:

- registered compatibility files for origins that serve paths before PHP,
  with public media-type and header verification still required;
- canonical `/ai-discovery` and `/.well-known/api-catalog` protocol endpoints,
  with ownership-safe physical fallbacks for origins that bypass WordPress;
  existing proxy cache entries can still need separate invalidation;
- dynamic `/feed.json` delivery in every mode for its JSON Feed media type and
  WebSub discovery headers;
- dynamic `/skill.md` compatibility delivery plus a materializable canonical
  nested `SKILL.md` and discovery index;
- `WP_Filesystem` writes through temporary files and verified moves;
- complete LLMS static output constrained to the same 32 MiB complete-or-fail
  response ceiling;
- renewable owner-token coordination with exact-value checks before long-running
  static write and purge boundaries;
- deterministic per-run write/runtime ceilings with generation-bound,
  ownership-revalidated continuation progress;
- content-hash ownership records;
- conflict-safe handling of pre-existing or edited files;
- children-before-index ordering;
- exact reconciliation counts;
- ownership-safe narrowing, cleanup, and regeneration; and
- request-triggered repair of missing or unreadable active files while the
  current request continues through dynamic delivery;
- dynamic-only behavior on multisite.

## Cache and edge integration

- Redis and Memcached are supported automatically through conforming WordPress
  `object-cache.php` drop-ins. Cybermaps depends on the WordPress Object Cache
  API, not on Redis or Memcached client libraries.
- Internal cache keys distinguish installations using the site URL/cache salt
  and blog ID, preventing Cybermaps keys from colliding merely because two
  independent sites both use blog ID 1.
- Optional APCu stores disposable derived values, and Cybermaps reads its L1
  only for keys populated in the current request. APCu itself can share memory
  across PHP workers; it is never a durability or locking authority here.
- LiteSpeed Cache for WordPress integration adds Cybermaps tags and purges
  affected publication URLs when LSCWP is active.
- Protected Varnish PURGE support is opt-in and exact-URL only; operators must
  configure Varnish ACLs and method handling.
- Copy-ready nginx, Apache/OpenLiteSpeed, LiteSpeed Cache, Varnish, and reverse
  proxy/CDN snippets supplement the owned well-known rewrite block on
  compatible Apache/LiteSpeed installations. nginx and Varnish configuration
  remains external to WordPress.
- Optional managed or site-local Cloudflare OAuth with PKCE installs owned
  response-header profiles, an `/ai-discovery` origin query rewrite, and cache
  safety. Existing user query strings are preserved by excluding them from
  the rewrite. It helps only where the origin distinguishes query strings.
- Cloudflare mutation controls require detected proxy traffic or an explicit
  page-scoped confirmation for the configured hostname. Core does not require
  Cloudflare.
- Separate temporary-token buttons repair headers or cache safety; the
  combined origin rewrite currently requires the OAuth install flow.
- OAuth credentials are discarded after a revocation attempt. Debugging
  distinguishes confirmed disposal from unconfirmed revocation; changed rule
  definitions require a new authorization.
- Trusted proxy support is default-off and requires both a selected
  `trusted_proxy_header` and trusted CIDR ranges.

`robots.txt` always remains dynamic. Physical files, CDNs, and web-server rules
can answer before PHP. AI Discovery Status therefore verifies public discovery
responses separately from local disk state.

## Sitemap and AI delivery status

- Debugging separates detected stack components from optimizations Cybermaps
  actually uses, and reports recorded Cloudflare rule drift.
- Optional diagnostic logging expires after one, four, or 24 hours. It retains
  at most 200 redacted events with seven-day retention and supports a redacted
  support bundle; this is separate from crawler analytics.
- Styled Sitemap Status and AI Discovery Status pages.
- Sitemap child, estimated URL-coverage, item-exclusion, intended-delivery, and
  reconciliation inventory.
- AI publication checks for public HTTP status, media type, parseability,
  markers, required profiles, local ownership, and observed PHP requests.
- Separate Configured, Advertised, and Publicly verified states, plus a specific
  canonical-interception diagnostic when an alias validates but its well-known
  canonical URL does not.
- Bounded GET probes use the expected media type, a diagnostic header, and a
  Cybermaps health-check User-Agent; the API Catalog also receives a HEAD
  probe for its required Link header.
- Diagnostic probes are excluded from crawler analytics.
- Explicit static counts for desired, written, unchanged, conflicted, failed,
  skipped, deleted, and retained paths.

## Discovery Analytics and Request Log

- Opt-in recording of registered endpoint requests that reach WordPress/PHP,
  excluding Cybermaps diagnostic probes.
- Crawler-signature and conservative crawler-candidate observations on
  ordinary content.
- Endpoint totals, crawler content activity, categories, signatures,
  unidentified patterns, errors, and recent detailed rows.
- Aliases grouped by endpoint identity.
- Request time, path, status, method, accepted media family, identity class,
  User-Agent evidence, requester grouping, and resolved-IP source/mode where
  applicable.
- Crawler labels explicitly based on self-reported User-Agent signatures.
- Logged-in observations tied to numeric WordPress user ID without IP,
  requester key, or User-Agent.
- Default IPv4 `/24` and IPv6 `/64` anonymization.
- Administrator choice to retain full resolved IPs for future rows without
  rewriting existing history.
- Trusted `CF-Connecting-IP` only behind official Cloudflare network ranges,
  plus an integration filter for another validated proxy.
- Configurable 1–365 day retention, CSV export, and clear-history action.
- WordPress personal-data export and erasure coverage for authenticated rows.
- Suggested disclosure text in the WordPress Privacy Policy Guide.
- Five-row recent-request widget on the standard WordPress Dashboard.

Requests served entirely by a static file, CDN, web server, or full-page cache
do not execute PHP and therefore cannot appear in PHP-side analytics.

## Content Intelligence Reports

- Saved runs across published public post types other than attachments.
- Stable 100-item keyset batches for predictable processing on larger sites.
- Configurable post and page minimum word counts.
- Configurable post and page review intervals.
- Configurable media-presence review.
- Saved resource URL, title, type, modified date, word count, age, media state,
  content fingerprint, and public search-indexability evidence.
- A dedicated report eligibility channel keeps otherwise search-indexable
  resources visible even when they are excluded only from XML, AI, or the
  Content Discovery Strategy.
- Thin-content, freshness, and media findings with measured values and active
  thresholds.
- Exact added, resolved, and persisting findings against the prior report.
- Focused action lists for each finding category.
- Five printable themes plus optional agency identity and site-name override.
- Themed HTML, spreadsheet-safe UTF-8 CSV, and JSON exports.
- Saved-report history and safe deletion when a report is no longer used as a
  comparison baseline.
- One ownership-safe report run per site, with a refreshed ten-minute lease
  that expires after a fatal error or abandoned request.
- Separate AI Discovery Publication Report with live HTTP, media-type, header,
  and parseability checks.

## Configuration exchange

The versioned JSON site-configuration backup includes:

- the full `cybermaps_settings` array;
- the Content Discovery Strategy;
- Robots Control;
- Identity Hub data; and
- the IndexNow key.

It provides an integrity checksum, Smart Merge, Full Replace, canonical
sanitization, written-result verification, and rollback handling. The backup
can contain secrets and must be stored securely.

Its scope excludes analytics history, saved report runs, WordPress posts and
media, generated files and caches, network-wide settings, and cross-site
translation relationships.

The separate AI Configuration Brief is a credential-excluding, site-aware
Markdown handoff with current non-secret context, 121 editable fields in the
7.4.2 generated contract, field guidance,
dependencies, examples, risk levels, and an initially null JSON changes
envelope. Imports are merge-only and require a server-generated preview that
shows canonical sanitized values. Unknown or malformed input is rejected;
high-impact changes require acknowledgement; content and destination hashes
prevent a reviewed change set from being swapped or applied to stale settings.
Canonical versioned JSON Schema and field-catalog artifacts are published on
cybermaps.dev and checked against the PHP registry during release builds.
Dedicated credential fields and URL userinfo are excluded; administrators are
still instructed to review user-authored business details before sharing.

## Administration and developer interfaces

- Seven primary tabs: Overview, XML Sitemaps, HTML Sitemap, AI Publishing,
  Schema, Reports, and Advanced.
- Optional Guided Setup from Overview: site-structure recommendations, independent
  Keep/Configure/Reset choices, and an exact server-sanitized preview before
  applying a bounded starting configuration.
- A dedicated Schema workspace plus a directly visible Crawler/Robots policy
  workspace with category bulk controls and collapsed per-crawler overrides.
- Sitemap Status, AI Discovery Status, Discovery Analytics, and Debugging submenus.
- Local API secret for private read-only integrations.
- Administrator-authenticated REST action for ownership-safe static-file purge.
- ETag, Content-Digest, and conditional `304` validators on successful public
  Cybermaps REST discovery responses.
- WP-CLI: `status`, `clear_cache`, `flush_rules`, and `regenerate`.
- Versioned machine-readable public-surface manifest.
- Extension API 2.0 for endpoint registration, read-only publication
  eligibility, and read-only saved-report access.
- Optional persistent-data cleanup on uninstall; generated-file removal remains
  ownership-safe and preserves edited or pre-existing files.
- Multisite Network Admin control for the centralized sitemap index; static
  publication remains dynamic-only on multisite.
