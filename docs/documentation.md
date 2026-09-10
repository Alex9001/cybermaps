# Cybermaps — Technical Documentation

> Version 7.4.1 · PHP 8.2 · WordPress 7.1

Cybermaps is a fast sitemap and AI-discovery plugin for WordPress.
It combines XML, RSS, and HTML sitemap publishing with compact machine-readable
site representations, crawler analytics, structured identity data, and
client-ready content reports.

The generated inventory of registered endpoints, REST routes, settings,
shortcode attributes, CLI commands, static targets, source classes, and AI
configuration contract hashes is [`docs/dev/manifest.json`](./dev/manifest.json).
Regenerate it from the current checkout with:

```bash
php docs/dev/generate-docs.php > docs/dev/manifest.json
```

The generator emits JSON; it does not rewrite this guide, the feature
reference, or the comparison. Those documents also describe runtime guards and
administration flows that need to be checked against their owning classes.
The general settings extraction and registry route inventory are not a
substitute for the delegated normalizers or separately registered OAuth routes.
Generated static counts describe registered targets, not a promise that every
target is enabled, writable, or publicly conformant on a particular site.

## 0. Standards and bounded agent access

The shipped contract uses a revision-pinned Cybermaps conformance profile for
AI Discovery Protocol 3.0 Level 3. The profile covers the manifest, bounded
updates, and the four `/news/*` publications; it does not claim an unqualified
ADP implementation level, and every publication remains conditional on its
own AI Publishing setting.

OpenAPI 3.2.0 is canonical. The retained 3.1.2 document is available through
explicit `Accept` negotiation or the documented `version=3.1.2` query form,
with `Vary: Accept` on negotiated responses. The API Catalog and generated
contracts identify the exact representation served.

The established API discovery surface uses RFC 9727 and RFC 9264. The catalog
contains an enriched Linkset for every advertised public API rather than one
undifferentiated route list. `GET /wp-json/cybermaps/v1/health` is a bounded
public health resource; it reports public service state without exposing
private WordPress diagnostics or replacing external monitoring.

The current MCP Server Card is experimental. Its canonical resource is
`GET /wp-json/cybermaps/v1/mcp/server-card`; the requested scanner compatibility
path is `/.well-known/mcp/server-card.json`. The card describes optional MCP
separately from OpenAPI and does not imply that a client supports the current
draft. The Agentic Resource Discovery catalog is also a draft: its canonical
path is `/.well-known/ai-catalog.json`, `/ai-catalog.json` is a compatibility
alias, and it lists only capabilities active for the current site.

Markdown for Agents is an opt-in vendor convention, not an RFC. When enabled,
eligible canonical singular, homepage, blog, and public archive URLs return
bounded stored-content Markdown when `text/markdown` is explicitly preferred
over HTML. Default requests remain HTML; both variants declare `Vary: Accept`.
Cybermaps reports its four-bytes-per-token estimate in `X-Markdown-Tokens` and
marks native output with `X-Cybermaps-Markdown-Source: origin`. It does not
render theme HTML, execute shortcodes, claim Cloudflare byte parity, or emit an
`X-Original-Tokens` value.

The existing `Content-Signal` header and robots declaration remain unchanged.
The optional AIPREF `Content-Usage` attachment is disabled by default; when
enabled it publishes bounded sitewide or path-specific `train-ai` and `search`
preferences. `ai-input` remains Content-Signal-only. IndexNow submissions use
the existing eligible-URL behavior and, when enabled, a durable deduplicating
same-host database queue with bounded retries and batches of up to 10,000 URLs.

MCP is disabled by default and has four explicit modes: `off`, `discovery`,
`read_only`, and `operations`. Current MCP 2026-07-28 Streamable HTTP uses one
stateless POST endpoint. The operations tier exposes exactly five bounded tools:
`cybermaps.search`, `cybermaps.audit.run`, `cybermaps.static.reconcile`,
`cybermaps.static.purge`, and `cybermaps.indexnow.submit`. OAuth 2.1
authorization code with PKCE, consent, scoped bearer tokens, and WordPress
capability checks are required. No tool edits content, settings, or general
WordPress administration. `ai-actions.json` is descriptive metadata only and
is never auto-executable.

OAuth discovery remains pure OAuth metadata. Cybermaps does not advertise
OpenID Connect, does not publish an OIDC discovery document, and does not
fabricate a JWKS. The default-off `agent_registration_mode=user_claimed` flow
uses RFC 8628 device approval and requires a logged-in WordPress user to review
and approve the request before any account or credential is created. Auth.md
publication is enabled when MCP is enabled and registration mode is
`user_claimed`; there is no separate Auth.md switch. It is documented as an
emerging agent-registration protocol, not as an established standard.

The default-off WebMCP bridge registers only three read-only browser tools:
`cybermaps.search_site`, `cybermaps.get_page_markdown`, and
`cybermaps.list_discovery_resources`. It prefers
`document.modelContext.registerTool`, falls back to
`navigator.modelContext.registerTool`, and safely performs no registration in
unsupported browsers. It grants no new server-side authority.

Static diagnostics report body parity separately from origin-header parity,
including RFC 9111 edge policy, RFC 9530 Repr-Digest, Content-Usage, and
deployment headers. Static claims are conditional on the selected Static File
Engine mode and equivalent web-server/CDN routing.

The administration UI places visible maturity guidance beside the relevant
controls: established RFC standards, current drafts, early protocols,
experimental previews, and vendor conventions are labelled separately and link
to their authoritative sources. These labels describe specification maturity,
not client adoption or a promise that any crawler will use the publication.

AI Discovery Status separates configuration, advertisement, availability, body
validity, and protocol conformance. When a compatibility alias works but its
canonical well-known URL does not, the page identifies likely
`/.well-known/` interception. Core discovery mode materializes ownership-safe
canonical bodies automatically; diagnostics keep body availability distinct
from media-type and header conformance. Cybermaps can maintain an owned
well-known rewrite block on compatible Apache/LiteSpeed installations and,
after explicit authorization, its own Cloudflare rules. It does not edit nginx
or Varnish configuration.

## 1. Administration map

Cybermaps adds one top-level administration area with seven primary tabs:

| Page | What it controls |
|---|---|
| **Overview** | Site inventory, feature status, publication links, and operational shortcuts |
| **XML Sitemaps** | XML, News, RSS, media, external URL, Content Discovery Strategy, caching, redirect, robots-link, translation, IndexNow, WebSub, and Static File Engine settings |
| **HTML Sitemap** | Shortcode availability plus an interactive builder, generated shortcode, examples, and attribute reference |
| **AI Publishing** | The publication master switch, LLMS output, discovery manifests, feeds, chunks, actions, usage policy, custom AI instructions, and crawler/robots policy |
| **Schema** | Organization, LocalBusiness, or Person identity, contact and location details, social profiles, hours, contact points, and service catalogs |
| **Reports** | Content measurement rules, saved reports, focused action lists, report themes, agency branding, and AI Discovery Publication Reports |
| **Advanced** | Delivery and performance controls, managed or self-managed Cloudflare OAuth plus a one-time-token fallback, headless and media-CDN URL rewriting, private REST secret, static cleanup, uninstall behavior, and configuration exchange |

**Debugging** is a separate submenu, with expiring diagnostic logging, redacted
support bundles, runtime and optimization status, and public delivery checks.

### Guided Setup

**Guided Setup** is an optional workspace launched from **Overview**. It is a
short, site-aware questionnaire for an administrator who wants a clean starting
configuration without manually visiting every workspace first. The site-profile
recommendation uses the same published-structure analysis as Content Discovery
Strategy; it never changes settings merely by being viewed.

Each area can independently be left as **Keep current**, set to **Configure**,
or set to **Reset guided fields**. The wizard deliberately covers only its
declared, bounded field set: strategy, sitemap surfaces and delivery
integrations, AI publishing and usage declarations, the primary identity plus
one automatic catalog action, crawler-analytics privacy, static delivery, and
report policy/presentation. Detailed custom instructions, crawler matrices,
identity addresses/contacts/hours/social profiles, manual catalogs, API
credentials, route slugs, and other expert settings remain untouched unless an
administrator edits them in their normal workspace.

The final screen sends an AI Configuration Brief v2 changes envelope to the
existing server-side preview/apply transaction. Cybermaps validates and
sanitizes the proposal, compares it with the destination fingerprint, displays
the final values and high-impact fields, and applies only the reviewed plan.
Reset actions require their own acknowledgement. There is no saved wizard
completion state or recurring setup notice.

The **Crawler & Robots Policy** workspace is shown directly within AI
Publishing. Its category rows provide bulk controls for every crawler currently
listed in that group; individual rows stay collapsed until an administrator
chooses **Customize**.

Cybermaps also provides three dedicated diagnostic pages:

- **Sitemap Status** lists the advertised sitemap inventory, estimated URL
  coverage, explicit item exclusions, configured delivery mode, and direct
  links for public inspection.
- **AI Discovery Status** checks each registered fixed-path publication's enablement,
  expected format, public HTTP status, media type, parseability, and delivery
  path. It distinguishes **Configured** settings, **Advertised** generated
  metadata, and **Publicly verified** live HTTP results.
- **Discovery Analytics** combines endpoint activity, crawler-like content
  visits, unidentified request patterns, the detailed Request Log, retention,
  CSV export, and clear-history controls.

The standard WordPress Dashboard includes a five-row
**CYBERMAPS: Recent PHP-Observed Requests** widget with a direct link to the
full analytics view.

AI Discovery Status and the AI Discovery Publication Report make bounded GET
requests to enabled publications at the configured public base URL. Requests
send the expected `Accept` media type, `X-Cybermaps-Diagnostic: 1`, and a
Cybermaps health-check User-Agent. The API Catalog receives an additional HEAD
request so its required Link header can be checked. Results are validated
locally and cached briefly. A separately hosted Frontend Base URL therefore
receives these requests when an administrator configures one; no probe data is
sent to Cybermaps.

On multisite, Network Admin adds a Cybermaps page with an optional centralized
`/sitemap-network.xml` index. It lists the Cybermaps sitemap for each public
site where the plugin is active.

For each publishable public post type, the classic editor and block editor
expose Cybermaps controls for sitemap exclusion, AI exclusion, intent, sitemap
priority, and change frequency. WordPress attachment rows are excluded from the
shared publication-type inventory. A translation panel is available when
translation integrations are enabled.

## 2. Shared publication eligibility

`Cybermaps\SEO\PublicationEligibility` supplies a shared base resolver and
channel-specific inclusion decisions to the sitemap repository, AI publication
inventory, text chunks, IndexNow, and content reports. It evaluates:

1. WordPress publication state, public object type, password protection, URL,
   and site search-engine visibility;
2. supported Genesis and Mai Theme noindex, canonical, redirect, archive, term,
   author, and home-page signals;
3. supported singular noindex and canonical metadata from active Yoast SEO,
   Rank Math, and All in One SEO installations;
4. Cybermaps global and per-resource exclusions; and
5. surface-specific sitemap or AI inclusion controls.

A redirect or canonical pointing away from the resource makes that resource
ineligible. Each result retains reason keys and source signals for diagnostics
and reports.

Content Intelligence Reports use the dedicated `report` channel. It retains
the base public/search indexability decision and extension filters, but does not
treat an XML-only exclusion, AI-only exclusion, or Content Discovery Strategy
Publish-off group as a reason to hide an otherwise search-indexable resource from content
review.

Developers can add compatibility adapters with
`cybermaps_seo_compatibility_adapters` and filter the final typed result with
`cybermaps_indexability_decision`.

`Cybermaps\Core\PublicationPostTypes` supplies the shared public post-type
inventory to XML, AI, HTML, reporting, media-audit, status, and editor
surfaces. It excludes `attachment` consistently; attachments can still be
discovered as media belonging to an eligible page or post.

## 3. Sitemap engine

The default sitemap index is `/sitemap.xml`; the base slug is configurable.
Internal child files contain no more than 2,000 URLs and are numbered when a
provider needs multiple files. Post-type children use
`{base}-posts-{post-type}-{page}.xml` and taxonomy children use
`{base}-taxonomies-{taxonomy}-{page}.xml`. These explicit namespaces keep both
providers reachable when a post type and taxonomy share a WordPress slug, and
keep custom object names such as `news`, `misc`, `authors`, or `archives`
separate from Cybermaps' canonical system publications.

### Sitemap publications and index sources

| Publication or source | Content |
|---|---|
| Post types | Eligible entries from enabled publishable public post types; attachment rows are excluded |
| Taxonomies | Eligible terms from enabled public taxonomies, with optional empty terms |
| Authors | Public author archives when enabled |
| Archives | Date archives when enabled |
| Miscellaneous | The home page and validated additional page URLs |
| Google News | Eligible recent posts from the last 48 hours, up to 1,000 entries |
| RSS | Optional standalone RSS 2.0 publication with selectable post types and a 1–1,000 item limit |
| External references | Up to 100 unique absolute HTTP(S) sitemap URLs whose path ends in `.xml` |

Additional page entries accept up to 1,000 unique absolute HTTP(S) URLs. Both
external sitemap and additional-page fields are validated locally for
structure. Cybermaps does not fetch, ping, or perform a remote availability
check while saving or generating the index, so a temporary remote outage cannot
remove an administrator's configured reference.

External sitemap URLs remain index references: the Static File Engine never
copies or claims ownership of them. Additional page URLs are emitted in the
miscellaneous child without fabricated `lastmod` or `changefreq` values.

Media discovery can be disabled, set to Standard, or set to Advanced. Standard
records featured and attached images only. Advanced additionally parses inline
images, direct `<video>`/`<source>` URLs, and YouTube or Vimeo references from
the first 2 MiB of stored post markup, stopping each matcher at the publication
budget instead of allocating every possible match. Direct video files are published as content locations;
YouTube and Vimeo references are published as player/embed locations. Protocol
video entries require a literal representative thumbnail: Advanced uses a
`poster` attribute for direct video and YouTube's deterministic thumbnail, and
does not substitute a generic site icon when no video thumbnail is known.

The bulk media rescan refreshes saved media observations for published content
in sitemap-enabled post types. Changing modes advances a constant-time audit
generation; prior and legacy unmarked rows fail closed until the post is saved
or rescanned instead of being synchronously deleted across the whole site.
Every publication revalidates saved observations and retains at most 100 media
items per post, including no more than 25 videos, so malformed legacy metadata
cannot create unbounded XML or on-page schema output.
Vendor media hints in the AI sitemap are controlled separately. Eligible
singular content can also publish `VideoObject` JSON-LD when an observed video
has the required public URL, thumbnail, title, upload date, and page URL.

### Content Discovery Strategy and per-resource controls

Content Discovery Strategy starts with a publishing profile suited to common
real-world WordPress structures: news, editorial, store, documentation,
portfolio/agency, local/service business, or mixed company content. **Suggest
from site structure** can recommend one from public content types and
published-item counts. It does not read the site's content or infer its
business goals. The suggestion is shown first and changes nothing until the
administrator explicitly applies it.
A profile is a starting baseline, not a business classification or
content-quality score.

Explicit product/download, documentation, portfolio/project, service, and
news/press post types are the strongest profile signals. Without one of those,
a post-led inventory suggests Blog / Editorial, or News / Magazine when it has
more than 1,000 published Posts. Non-editorial inventories below 50 items use
the Local / Service Business starting point; other mixed structures use Company
/ Mixed Content.

Each post-type or taxonomy row has three independent decisions. The interface
keeps explanations beside the relevant labels through mouse- and
keyboard-accessible help tips:

- **Publish** controls global eligibility for Cybermaps sitemap and AI URL
  inventories. Turning a group off does not add `noindex`, hide, or delete its
  content. Disabled groups are filtered before bounded XML, RSS, HTML, LLMS,
  search, AI-sitemap, chunk, and Knowledge Graph inventory work.
- **Discovery intent** publishes either **Informational** or **Commercial**
  metadata in AI representations that support it. Commercial covers purchase,
  booking, contact, registration, download, and similar conversion-oriented
  content. Its persisted protocol value remains `transactional` for backward
  compatibility. Explicit row and per-resource values override the profile
  baseline.
- **Publication weight** stores a positive `0.1`–`1.0` publishing hint. Higher
  weights place a group earlier in the sitemap index, and the value is emitted
  with its URLs in XML and AI sitemap output. It is not a ranking score or
  crawl guarantee.

The XML Sitemaps workspace groups related controls into Content Scope, Sitemap
Paths & Delivery, Media Discovery, News/Feeds/Notifications, and Language &
Translation sections. Primary language and WPML/Polylang relationship controls
are kept together; external pages belong to content scope, while external
sitemap files belong to sitemap-index configuration.

Publication status is stored separately from positive weight, so disabling and
re-enabling a group retains its tuned weight. Historical zero-weight exclusions
are migrated into the independent disabled map. Profile changes update inherited
rows while preserving rows marked Custom; row-level and global reset actions
return them to the selected profile.

Post-type and taxonomy rows use explicit kind-aware identities, so a post type
and taxonomy with the same WordPress slug retain independent weight, status,
and intent controls. The WordPress `post_format` taxonomy is labelled **Post
Formats** and described as archive groupings such as image, video, quote, and
link—not as a Cybermaps output-format selector. Legacy raw-slug settings remain
readable and are canonicalized on save. Individual resources can separately
override sitemap priority and change frequency or be excluded from sitemap and
AI publication.

Global controls cover post types, taxonomies, post IDs, category slugs, the home
page, author archives, date archives, and empty term archives. A sitemap
exclusion changes Cybermaps publication; it does not add a `noindex` directive.

### Dynamic delivery, redirects, and caching

Dynamic sitemap responses support:

- browser-friendly XSL;
- ETag-based `304 Not Modified` responses;
- optional 12-hour WordPress transient or object-cache entries;
- automatic invalidation after relevant content or setting changes;
- optional post or page modified-time updates when a comment is approved;
- optional redirects from WordPress core and legacy sitemap locations; and
- configurable sitemap, News, and RSS base slugs.

The index footer identifies Cybermaps as the generator and states that the
sitemap helps search-engine indexing.

### Headless and media-CDN URL rewriting

`frontend_base_url` replaces the WordPress origin in published content URLs for
a headless frontend. When enabled, `cdn_base_url` replaces the origin only for
same-site image and video URLs written into XML sitemap entries. It does not
move XSL, discovery publications, or generated files.

### HTML sitemap shortcode

`[cybermap]` builds a front-end HTML sitemap. Its builder and runtime support:

| Attribute | Default | Purpose |
|---|---:|---|
| `only` | empty | Select content groups with `post_type:slug` or `taxonomy:slug` tokens; `post_type:*` and `taxonomy:*` select every eligible group of that kind, and legacy unprefixed slugs remain supported |
| `exclude` | empty | Exclude item IDs, exact slugs, or slug wildcards containing `*` |
| `limit` | `50` | Maximum rendered entries, bounded to 1–500 |
| `depth` | `0` | `0` renders the full available hierarchy, `-1` is flat, and a positive value caps nesting levels |
| `sort` | `asc` | Sort direction |
| `nofollow` | `false` | Add `nofollow` to generated links |
| `display_title` | `true` | Show section titles |
| `layout` | `list` | `list`, `columns`, or `bare` presentation |

The combined post-type query and each selected taxonomy use bounded batches and
inspect at most 5,000 candidates. Exclusions and shared publication eligibility
are applied before the rendered-entry limit. The `bare` layout is intentionally
flat; `depth` controls the hierarchical `list` and `columns` layouts.
Namespaced `only` tokens keep a post type and taxonomy independently selectable
when they share the same WordPress slug. Kind wildcards select all groups of the
requested kind and are still narrowed by the saved Publish state and shared
eligibility. Unprefixed tokens retain the historical post-type-first resolution order. The `only` and `exclude` lists are also
byte- and item-bounded before they influence queries.

## 4. AI Publication Hub

The **Enable AI Publication Hub** setting is the master control for fixed
discovery publications, localized LLMS routes, RAG chunks, and public discovery
REST routes. Publications are assembled from WordPress content and configured
publisher data; no external AI account or API key is required.

These representations omit theme chrome, navigation, scripts, and other
repeated page layout. Compatible agents can retrieve URLs, summaries, identity,
actions, freshness, and publisher guidance with substantially less payload and
token overhead than rendering every full HTML page.

### Fixed publications

| Canonical path | Media type | Function and enablement |
|---|---|---|
| `/ai.json` | `application/json` | Primary Cybermaps discovery manifest |
| `/ai-discovery.json` | `application/json` | AI Discovery Protocol 3.0 Level 3 manifest |
| `/ai-discovery` | `application/json` | Registry-backed index of Cybermaps publications |
| `/llms.txt` | `text/markdown` | Concise Markdown map of eligible content |
| `/llms-full.txt` | `text/markdown` | Opt-in literal full stored-text corpus |
| `/llms-tldr.txt` | `text/plain` | Opt-in experimental briefing constrained by the configured token budget |
| `/knowledge-graph.json` | `application/ld+json` | Schema.org identity and content relationships |
| `/feed.json` | `application/feed+json` | JSON Feed 1.1 recent-post publication and canonical WebSub topic |
| `/updates.json` | `application/json` | Bounded seven-day ADP stream of created or modified current public content |
| `/news/llms.txt` | `text/markdown` | Bounded news-specific context for recent eligible content |
| `/news/speakable.json` | `application/ld+json` | Schema.org speakable summaries for recent eligible content |
| `/news/changelog.json` | `application/json` | ADP news publication version metadata |
| `/news/archive.jsonl` | `application/x-ndjson` | Bounded newline-delimited eligible-content archive |
| `/ai-sitemap.xml` | `application/xml` | AI-oriented content and optional media inventory |
| `/ai-usage.json` | `application/json` | Publisher-selected content-use preferences |
| `/ai-actions.json` | `application/ld+json` | Configured action and capability inventory |
| `/skill.md` | `text/markdown` | Compatibility URL for the Agent Skills site guide |
| `/.well-known/agent-skills/cybermaps-site-guide/SKILL.md` | `text/markdown` | Canonical Agent Skills-compatible read-only site guide |
| `/.well-known/agent-skills/index.json` | `application/json` | Agent Skills Discovery 0.2.0 draft index with a SHA-256 digest of the exact guide bytes |
| `/.well-known/api-catalog` | `application/linkset+json` | Established RFC 9727/RFC 9264 Linkset API Catalog with enriched per-API Linksets; `/api-catalog` is a dynamic compatibility alias |
| `/.well-known/ai-catalog.json` | `application/json` | Draft ARD catalog containing only active capabilities; `/ai-catalog.json` is the compatibility alias |
| `/.well-known/mcp/server-card.json` | `application/json` | Requested compatibility path for the experimental current MCP Server Card |
| `/cybermaps-openapi.json` | `application/vnd.oai.openapi+json` | Canonical OpenAPI 3.2.0 contract for Cybermaps' public read-only REST routes; retained 3.1.2 is negotiated explicitly |
| `/.well-known/oauth-authorization-server` | `application/json` | RFC 8414 authorization-server metadata when MCP is enabled |
| `/.well-known/oauth-protected-resource` | `application/json` | RFC 9728 metadata for the protected MCP resource when MCP is enabled |
| `/auth.md` | `text/markdown` | Dynamic registration guide when MCP and `user_claimed` registration are enabled |

`/ai.json` is the **Cybermaps AI Discovery Manifest 1.0**. It is a documented
Cybermaps vendor extension, not a claim of an external protocol or independent
standard.

`/ai-discovery.json` is the separate community AI Discovery Protocol 3.0 Level
3 publication. It links the site's knowledge graph, LLMS context, robots
directives, JSON Feed, bounded updates, AI sitemap, and the four required
`/news/*` context, speakable, changelog, and JSONL archive publications. The protocol frequency
hint is `daily`; Cybermaps also invalidates its cached publication when relevant
site content or settings change. MCP is a separate, default-off capability;
its transport and discovery metadata are available when the administrator
enables an MCP mode. `/updates.json` reports only current public content changed in
the last seven days and does not infer deletions without an event ledger.

The canonical nested `SKILL.md` uses valid Agent Skills frontmatter. The draft
well-known index binds its entry to the exact guide bytes with SHA-256.
`/skill.md` remains available for existing links. Consumer discovery and use
still depend on the client.

Eligible singular resources also have literal `text/markdown` alternates.
Pretty permalinks use `/{permalink}/index.md`, filename permalinks append `.md`,
and plain permalinks use `cybermaps_markdown=1`. The representation includes
the canonical source URL, content type, language, modified time, and visible
stored text. It does not execute shortcodes or dynamic blocks and is not
materialized by the Static File Engine.

The endpoint registry records each fixed path, aliases, handler, body format,
media type, enablement setting, static targets, maturity, adoption description,
advertising state, and request-cost tier. Routing, status checks, static
publication, analytics classification, and manifest generation consume this
same registry.

When the AI Publication Hub is enabled, eligible HTML pages advertise their
Markdown alternate and describing `llms.txt` publication through both HTML
`<link>` elements and RFC 8288 `Link` response headers. The optional
Header-Based Discovery control adds the broader feed and API Catalog headers.
The API Catalog supplies its registered
`rel="api-catalog"` relation, media type, and RFC 9727 profile on GET and HEAD
responses at the RFC 9727 well-known location. Its dynamic compatibility alias
is retained for existing integrations. The Linkset advertises Cybermaps' public
OpenAPI contract; administrative and secret-authenticated routes are omitted.

### LLMS output and custom AI instructions

`/llms.txt` lists eligible resources in stable publication order with links to
their literal Markdown alternates and compact extracts. Its configurable limit
is clamped to 20–500 links, with a default of 100. The output reports selected
and total eligible counts; when eligible resources overflow the limit, it also
links the complete XML sitemap. Configured title, mission, sitemap reference,
license, and publisher guidance are included when available.

`/llms-full.txt` is disabled by default because it can become large. It includes
the literal visible stored text for eligible resources without executing
shortcodes or dynamic blocks. LLMS inventory is traversed in bounded,
non-caching post batches rather than loaded as one corpus. Core applies a 32 MiB full-corpus
encoded-response safety ceiling to both LLMS text publications and never
silently truncates either one. If a complete body would cross the ceiling, the
dynamic route returns an explicit `507 application/problem+json` response and a
static reconciliation records `publication_too_large` without writing a
partial file. The full body is never stored in a transient or retained in the
static generator's request cache.

The optional budgeted briefing:

- publishes up to 100 configured pinned IDs first;
- follows the configured content-type order, modified date descending, and ID
  ascending;
- uses fixed-size literal excerpts;
- includes only complete entries that fit the budget;
- estimates tokens as `ceil(UTF-8 bytes / 4)`; and
- reports eligible, selected, omitted, and partial counts.

The briefing counts and traverses the same batched inventory without retaining
the complete post collection. Generated briefings larger than 512 KiB are
cached only when WordPress uses an external object cache, avoiding oversized
database transients.

**Custom AI Instructions** are stored once and published as publisher guidance
in `/llms.txt`, enabled `/llms-full.txt`, `/skill.md`, the discovery manifest,
the discovery index, and `/wp-json/cybermaps/v1/discovery`. The separate Site
Guide addition appears only in `/skill.md`. Schema-bound feeds, XML, usage,
action, Linkset, knowledge-graph, and chunk formats stay within their defined
field structures.

### Granular publication controls

AI Publishing also provides controls for:

- LLMS title, mission, content license, included post types, concise link limit,
  taxonomy filters, sitemap link, and pinned briefing resources;
- global AI-publication exclusions by post ID and by term ID or slug; the UI
  consolidates the two historical post-ID fields without dropping either saved
  list;
- budgeted-briefing token allowance;
- manifest endpoint selection, business description, topic labels, and
  capability labels;
- Knowledge Graph controls for exposing the administrator as a `Person` and
  linking the configured primary entity as the website publisher;
- JSON Feed item limit from 1–100, author fields, and full-content versus
  summary output;
- AI sitemap content types, a 1–2,000 eligible-item limit per selected type, up
  to 100 custom external links, transparent metadata excerpts, and vendor media
  hints; custom links do not fabricate a `lastmod` value;
- training, retrieval, and commercial-use preferences plus an optional
  licensing contact;
- up to 100 action-to-URL mappings using the supported Schema.org action types.

### Localized LLMS and translation relationships

With the Multilingual AI Hub enabled, active WPML or Polylang languages receive:

- `/{language}/llms.txt`;
- `/{language}/llms-full.txt` when the full file is enabled; and
- `/{language}/llms-tldr.txt` when the briefing is enabled.

The translation integration can also add sitemap `hreflang` alternates.
Cybermaps maintains its own translation relationship registry and can
synchronize supported WPML duplicates; the editor panel supports manual
grouping where needed. Sitemaps settings include the primary language code used
in supported `hreflang` output. Language tags are normalized to bounded BCP
47-style values, and an alternate is emitted only while its target remains an
eligible, public sitemap resource. Relationship and eligibility changes
invalidate the sitemap caches of every connected site.

### Text chunks and search

When enabled, `/discovery/chunks/{post_id}.json` divides eligible stored visible
text into configurable overlapping character windows and preserves headings as
Markdown. It does not execute shortcodes or dynamic blocks. A resource is
authorized only when it belongs to the exact bounded inventory selected for the
AI sitemap, so advertised chunk links, dynamic authorization, and static
publication stay aligned.

Chunk size is normalized to 100–12,000 characters; overlap is normalized from
zero to at most half the selected chunk size.

`/wp-json/cybermaps/v1/search` uses bounded WordPress text search over configured
public content. `q` is required and limited to 200 characters and UTF-8 bytes;
`limit` accepts 1–100 results, defaulting to 20.

On an unresolved front-end URL, a registered crawler signature or conservative
`bot`, `spider`, or `crawler` User-Agent receives an RFC 9457
`application/problem+json` response. Cybermaps can include up to three eligible
alternatives found by literal WordPress search of the requested slug.

## 5. Identity, catalogs, and robots

The Identity Hub publishes a configured entity both as homepage JSON-LD and in
the Knowledge Graph. Supported primary types are `Organization`,
`LocalBusiness`, and `Person`, with validated Schema.org subtypes.

Supported fields include:

- entity name, description, and image;
- international postal address, latitude, and longitude;
- phone and email;
- social-profile `sameAs` URLs;
- customer-support, technical-support, and sales contact points;
- opening hours; and
- one or more Schema.org `OfferCatalog` structures.

Catalogs can be entered manually or generated from up to 50 published direct
child pages of a selected parent page. Identity input is bounded to 12 catalogs,
200 offers overall, 20 social profiles, 12 contact points, and four opening-hour
ranges per day so editor submissions, backups, and public JSON-LD remain
predictable. Catalog and identity changes invalidate discovery caches and
schedule static reconciliation.

The Knowledge Graph also publishes a `WebSite` with the Cybermaps search action,
informational and transactional collections for public post types and
taxonomies, an optional link from the website to the primary entity, and an
optional administrator `Person`.

Robots controls support append mode or full takeover, bounded manual directives,
per-crawler overrides, and machine-readable content-use signals. WordPress's
site-visibility setting remains authoritative. The generated `robots.txt`
response is always dynamic, even when the Static File Engine publishes other
files.

## 6. Static File Engine

Dynamic WordPress handlers are always the routing baseline. The Static File
Engine is an optional materialization layer:

Dynamic responses include Cybermaps-managed CORS, ETag, Content-Digest,
Repr-Digest, X-Robots-Tag, CSP, and RFC 9111-oriented cache-policy headers.
When a physical copy is served directly, PHP does not receive the request, so
the web server or CDN must add any equivalent headers required by the
deployment.

| Mode | Physical publication |
|---|---|
| `off` | No generated files; supported requests use WordPress when the server routes them to PHP |
| `well_known` | Up to eleven small registered root, discovery-index, and canonical well-known targets, limited to enabled capabilities; this is the default |
| `all` | The sitemap index and internal children, enabled RSS output, protocol-safe discovery files, localized LLMS output, and eligible RAG chunks |

The default target inventory is:

1. `/ai.json`
2. `/ai-usage.json`
3. `/ai-actions.json`
4. `/.well-known/agent-skills/cybermaps-site-guide/SKILL.md`
5. `/.well-known/agent-skills/index.json`
6. `/.well-known/api-catalog`
7. `/.well-known/ai-catalog.json`
8. `/.well-known/mcp/server-card.json` when MCP is enabled
9. `/.well-known/oauth-authorization-server` when MCP is enabled
10. `/.well-known/oauth-protected-resource` when MCP is enabled
11. `/ai-discovery`

Dynamic routing remains preferred because it controls protocol headers, media
types, throttling, and PHP-side request observations. Some nginx and
OpenLiteSpeed configurations intercept `/.well-known/` before WordPress. The
ownership-safe physical fallbacks make canonical bodies available in that
environment when the root is writable and the server serves those files.
`/ai-discovery` is also materialized. Writing a correct physical file does not
invalidate a cached response already held by nginx or another proxy. An extensionless static API
Catalog may still receive a generic media type from the origin; Cybermaps
therefore reports availability and body validity separately from RFC 9727
header conformance and never labels the wrong media type conformant.

`/skill.md` remains a dynamic compatibility URL. The canonical nested
`SKILL.md` can be materialized, but `.md` alone does not guarantee a server will
send `text/markdown`; dynamic fallback returns the registered
`text/markdown` response when the request reaches WordPress.

On a subdirectory WordPress installation, Cybermaps derives the origin document
root conservatively. A differently rooted or remote headless frontend must
provide the `cybermaps_static_publication_root` filter. Multisite is
dynamic-only so individual sites never compete for shared root filenames.

### Write safety and reconciliation

Static writes use `WP_Filesystem`, a temporary file, a verified move, and a
recorded content hash. Cybermaps overwrites or deletes a path only while the
current bytes still match its ownership record. Pre-existing or edited files
are retained and reported as conflicts.

When WordPress receives a valid request for an active static target that is
missing or unreadable, Cybermaps serves the dynamic representation for that
request and queues the existing coalesced background reconciliation. The same
repair check covers valid sitemap children and the RSS sitemap in `all` mode.
It is disabled in `off` mode and on multisite, and never bypasses ownership
verification.

Full sync, purge, ownership migration, and static-file mutations use an
installation-local option lease plus a connection-scoped MySQL/MariaDB advisory
lock acquired through WordPress Core's `wpdb` connection. Lease renewal,
release, ownership-shard changes, and write-intent changes compare the exact
observed database value and prove the same advisory-lock owner and connection in
the mutation statement. A request that loses or reconnects its database session
fails the static mutation closed and queues reconciliation; dynamic publication
remains available. Database drop-ins that replace the exact Core `wpdb` class or
may route statements across different connections are unsupported for static
mutation.

Immediately before a file move or ownership-verified deletion, Cybermaps stores
one durable typed intent containing the normalized path, prior ownership
evidence, intended outcome, generation, epoch, and a digest of the active lease.
The operation revalidates the exact journal row, option lease, and database
fence at the last portable boundary before changing the filesystem. Recovery
adopts only an exact recorded outcome; any third-party body is retained as a
conflict.

A pending intent blocks automatic takeover of a stale option lease. That is
intentional: WordPress has no portable compare-and-move or compare-and-delete
primitive that can fence a disconnected but paused PHP process. The settings
screen exposes an exceptional recovery action for a valid journal. It requires
`manage_options`, an intent-bound nonce, and an exact typed confirmation that
all Cybermaps web, cron, and CLI static workers have been stopped or quiesced.
A malformed journal remains untouched for support review. This protocol makes
interrupted transitions diagnosable and recoverable without pretending that a
database transaction and a filesystem mutation are one atomic cross-resource
transaction.

The Core data upgrade uses a separate exact compare-and-swap option lease. It
reloads migration state after acquiring that lease, and each production state,
data-version, or coordination-state mutation compares both the exact observed
target value and the exact current lease row in the same database statement.
A worker that loses its lease cannot overwrite a successor's checkpoint. Fresh
activation uses that same lease and an insert-only version checkpoint, and it
refuses to stamp an installation that already has configuration or any upgrade
coordination state.

The shared translation-registry schema is independently monotonic and uses a
target-owned coordination record plus a database-session fence. Its schema,
state, and cleanup mutations compare exact observed values while that fence is
held, so an older release cannot downgrade a future schema or erase a future
retry. The physical shared-table migration uses one installation-wide fence;
site-option coordination remains network-specific. A database drop-in that
cannot provide the required Core connection-bound fence defers only the sharded
static-ownership migration with bounded retry state; unrelated 6.1.0 migrations,
the data-version checkpoint, and dynamic publication continue normally.

A full synchronization has deterministic write and wall-clock ceilings. When a
large sitemap or chunk inventory crosses either ceiling, Cybermaps checkpoints
its non-autoloaded continuation state and ownership inventory, schedules the
next run, and revalidates completed files against their ownership hashes before
skipping their expensive generators. A settings or content mutation advances
the generation fence and discards obsolete continuation progress.

When complete LLMS generation exceeds its safety ceiling, reconciliation first
attempts to remove the prior file only if its current bytes still prove
Cybermaps ownership. Because `WP_Filesystem` offers whole-string reads rather
than portable streaming hashes, an unusually large legacy file beyond the
separate bounded verification limit is retained and reported instead of being
read into memory or deleted without proof.

Children are written before their indexes. Reconciliation reports distinguish
desired, written, unchanged, conflicted, failed, skipped, deleted, and retained
targets. A successful timestamp advances only after the selected inventory
finishes successfully.

Changing from `all` to `well_known` removes eligible Cybermaps-owned web-root
files while retaining the active well-known set. Changing to `off` removes
eligible Cybermaps-owned files. **Regenerate Publications** clears applicable caches and
performs an immediate active-mode sync; **Static File Cleanup** purges
ownership-verified generated files.

### Cache and edge integration

The **Debugging** submenu reports availability and Cybermaps utilization as
separate facts. An installed Redis drop-in is reported as active only when
WordPress exposes a persistent object cache; an available APCu or LiteSpeed
integration is reported as unused when its independent Advanced opt-out is
disabled. The copied diagnostic report excludes tokens, credentials, network
addresses, cookies, and filesystem paths.

Advanced settings use Cloudflare's public OAuth Authorization Code flow with
PKCE to install or repair up to four enabled response-header profiles, an
origin query rewrite, and a cache-safety rule. The administrator chooses the owning account and authorizes
only Zone Read, Zone Transform Rules Write, and Cache Settings Write. WordPress
holds the verifier, exchanges the code directly, performs the bounded Rulesets
operations, attempts to revoke the access token, and discards it. A failed
revocation is reported separately; Cybermaps does not retain a reusable token.

The 7.3.4 origin rewrite applies only to GET/HEAD requests for `/ai-discovery`
on the configured hostname with an empty query string. It adds
`cybermaps_origin={plugin-version}-{hostname-hash}` to the origin query while
preserving the public URL. Existing query strings are excluded. This avoids
an older cache entry only when the origin uses query strings in its cache
decision; it cannot fix every proxy configuration. The rule is included in
combined OAuth installation, owned-rule removal, and drift fingerprints.
Reauthorize after a release or configuration change that changes the expected
rules. A recorded fingerprint is not a live Cloudflare configuration audit.

Installation checks enabled discovery bodies before applying response-header
rules. A 404 or invalid body blocks that step: changing response headers does
not create a missing origin resource. Regenerate Publications and inspect the
per-path delivery results first. The number of resource checks follows enabled
publications and is different from the number of Cloudflare rules or buttons.

The fixed https://connect.cybermaps.dev/cloudflare/callback relay stores an
unusable authorization code for at most five minutes. It never receives the
verifier, access token, WordPress identity, site URL, or rule payload. Its source
is shipped under infrastructure/cloudflare-oauth-relay/ in the development
repository and excluded from the WordPress.org plugin artifact. Accounts that
disable public OAuth can use either site-local self-managed OAuth or the
retained one-time-token fallback. Self-managed mode uses a secretless client
with response type `code`, grant type `authorization_code`, token authentication
method `none`, PKCE `S256`, and the site's exact
`/wp-admin/admin-post.php?action=cybermaps_cloudflare_oauth_callback` URL.
Cloudflare returns directly to WordPress, the relay is bypassed, and only the
public client ID is stored. The required scopes are exactly `zone.read`,
`zone-transform-rules.write`, and `cache-settings.write`. Cybermaps
records only non-secret zone, owned-rule, fingerprint, and credential-disposal
metadata so Debugging can report drift without maintaining a connection.

The one-time-token fallback currently exposes separate header and cache-safety
installation buttons, plus removal. Those install buttons do not call the
combined OAuth installation and do not install its origin query rewrite. Use
managed or self-managed OAuth for that complete flow. The API token is used
directly by WordPress for the requested operation and is not saved.

Diagnostic logging is disabled by default. Administrators can enable it for one,
four, or 24 hours while reproducing a problem. Cybermaps stores at most 200
events in non-autoloaded site options and automatically stops collection when
the selected window expires. Events older than seven days are discarded.
Support bundles combine the secret-free system report with recent redacted
events. They omit credentials, cookies and headers, request bodies, crawler
analytics, IP and email addresses, full URLs, and absolute paths.

After Cloudflare confirms a rule mutation, the Advanced page verifies every
eligible discovery resource from the administrator's browser. This avoids
false failures caused by blocked or split-DNS server loopbacks, retries briefly
for edge propagation, and reports each failing path with its HTTP, body, and
header result. A short-lived terminal OAuth result also survives a missed AJAX
response or page reload; it contains no credential.

Before presenting any mutation as available, Cybermaps checks the exact
configured public hostname for Cloudflare proxy traffic using the current
request and a fresh browser probe. Undetected sites keep OAuth and API-token
mutation controls disabled. Split-DNS, headless, or header-stripping deployments
can use an explicit page-scoped confirmation naming that hostname; the override
is not persisted and does not bypass authorization, scopes, zone selection, or
post-install verification.

Cybermaps keeps its internal publication, registry, route, and diagnostic cache
entries in generation-fenced WordPress Cache API groups. A content or settings
change advances the relevant generation, so stale values fall out without
requiring a broad object-cache flush. Keys include the blog ID and a hash of
the WordPress site URL and `WP_CACHE_KEY_SALT`, with a filesystem-root fallback
when the site URL is unavailable. Independent installations therefore do not
collide solely because both have blog ID 1. This protects Cybermaps' keys;
it does not configure another plugin's cache or the web server's cache key.
If the site provides a conforming
`object-cache.php` drop-in backed by Redis or Memcached, Cybermaps uses it
automatically through WordPress Core APIs. There is no Redis or Memcached
client dependency in the plugin, and those stores are treated as acceleration,
not as durability authorities.

When the APCu extension is available and the administrator enables Cybermaps'
APCu layer, it stores disposable derived values. Cybermaps reads APCu only for
keys populated during the current request; it does not use an older APCu value
as the authority for a fresh request. Its installation namespace also applies
to APCu keys. The plugin makes no assumption that APCu memory is isolated per
PHP worker, and never uses it for queues, locks, ownership proofs, or recovery
state. The Advanced APCu and LiteSpeed integration controls are independently
enabled by default but require their respective runtime dependencies.

IndexNow durability is database-backed. Enabled submissions enter a typed queue
with same-host deduplication, retry metadata, and bounded batch construction.
Object caches can make queue reads faster, but the queue authority remains the
database.

LiteSpeed integration is automatic when the LiteSpeed Cache for WordPress
plugin exposes its public purge/tag hooks and the integration remains enabled.
Cybermaps adds Cybermaps-specific
tags to compatible dynamic responses and asks LiteSpeed to purge affected
publication URLs after relevant content, settings, static-publication, or
IndexNow state changes. Without that plugin, LiteSpeed servers still receive
the same dynamic/static Cybermaps responses but no LiteSpeed-specific purge is
attempted.

Negotiated canonical pages require request-time cache separation. Cybermaps
marks Markdown responses that reach PHP non-cacheable through LiteSpeed's
documented API and purges LiteSpeed page objects when the feature changes, but
an existing server cache can answer before PHP. LiteSpeed/OpenLiteSpeed sites
must therefore bypass cache lookup for requests whose `Accept` field contains
`text/markdown`, for example with an operator-reviewed Apache-compatible rule:

```apache
RewriteCond %{HTTP:Accept} text/markdown [NC]
RewriteRule .* - [E=Cache-Control:no-cache]
```

nginx and Varnish should retain `Vary: Accept`; operators may normalize Accept
to HTML versus Markdown to avoid unbounded cache variants. Cloudflare's own
Markdown for Agents feature may supply the edge representation instead. A
remote headless frontend must proxy negotiation or implement it at that
frontend because its canonical requests do not reach WordPress.

Varnish support is deliberately opt-in and exact-URL only. When configured,
Cybermaps can send protected PURGE requests for known Cybermaps publication
URLs. The operator must configure Varnish ACLs, method handling, and any
required secret/header validation. Cybermaps does not claim portable nginx
purge support, because nginx purge behavior depends on non-standard modules or
CDN-specific APIs.

The Advanced diagnostics provide advisory snippets for nginx, Apache,
OpenLiteSpeed, Varnish, and common CDN edge rules. These snippets describe MIME
types, routing exclusions, cache policy, validators, Repr-Digest/header parity,
and exact Cybermaps paths. These snippets are advisory; the owned origin rewrite
block and explicitly authorized Cloudflare rule operations described above are
the automatic exceptions. Diagnostics
also report active integration settings, relevant runtime constants, and the
WordPress hooks or filters that are active or unavailable in the current
installation. Public extension points remain WordPress-native, including
`cybermaps_static_publication_root` for alternate static roots and
`cybermaps_client_ip_resolution` for validated proxy integrations.

## 7. Discovery Analytics and privacy

Analytics recording is opt-in. When enabled, Cybermaps records registered
endpoint requests that execute WordPress/PHP plus crawler-signature and
conservative crawler-candidate visits to ordinary content. Diagnostic probes
sent by Cybermaps status pages are excluded.

The classifier distinguishes:

- a claimed registered crawler signature;
- a conservative unregistered-crawler candidate;
- another automated client;
- a browser or manual client;
- a missing User-Agent;
- an unknown client; and
- a logged-in WordPress user.

Crawler names are matches against a self-reported User-Agent product token, not
verified provider network identities. Product-token matching avoids classifying
a bot name that appears only inside a comment or documentation URL.

Logged-out observations can include time, path, endpoint, response status,
method, accepted media family, identity class, normalized User-Agent,
site-specific pseudonymous requester key, resolved-IP source, IP-storage mode,
and the resolved address. IP anonymization is enabled by default and stores an
IPv4 `/24` or IPv6 `/64` network. Administrators can instead retain full
resolved addresses for future rows. Changing this choice does not rewrite
existing history.

Logged-in observations store the numeric WordPress user ID and omit IP,
requester key, and User-Agent. WordPress personal-data export and erasure tools
cover these rows, and user deletion removes that user's authenticated
observations.

`ClientIPResolver` uses the immediate peer by default. It accepts
`CF-Connecting-IP` only when that peer belongs to an official Cloudflare
network. The general trusted-proxy settings `trusted_proxy_header` and
`trusted_proxy_cidrs` are both off by default; when enabled, the selected
forwarded-IP header is trusted only from the configured CIDR ranges. Cybermaps
never trusts arbitrary forwarded headers and still exposes
`cybermaps_client_ip_resolution` for integrations that validate another proxy
boundary.

Retention accepts 1–365 days and is enforced by a daily cleanup event.
Discovery Analytics can export or clear the stored history. Physical files,
CDNs, web-server rules, and full-page caches answer before PHP and therefore
cannot appear in these statistics.

Local 60-second request limits protect discovery routes by matched crawler,
installation-salted client identifier, cost tier, and minute. REST search uses
an installation-salted one-way identifier derived from the resolved IP;
neither the raw address nor query is stored in that rate-limit transient.

## 8. Reports

Content Intelligence Reports inspect all published public post types except
attachments. The reader captures a maximum post ID and processes stable
100-item ID batches so publishing or deleting content during a run cannot shift
pagination. Attached-image presence is resolved once per batch, and completed
batch objects are released from the runtime cache. Posts and pages use the
configurable rules; another public post type uses a 150-word fallback with no
age or required-media finding.

Only one Content Intelligence Report can run for a site at a time. Generation
uses an atomic, ownership-token lease that is refreshed before each content
batch and before completion. The lease expires ten minutes after its last
refresh, so a fatal error or timed-out request cannot strand report generation;
expired takeover and release use exact-value comparisons so an older request
cannot overwrite or delete a newer owner's lease.

Each saved report records:

- the active post and page measurement rules;
- resource ID, type, title, URL, modified time, word count, age, media
  presence, indexability evidence, and content fingerprint;
- findings for thin content, configured freshness intervals, and configured
  media review; and
- the prior completed report used for comparison.

Thin-content, freshness, and media findings apply only to public,
search-indexable resources. Sitemap-only, AI-only, and Content Discovery Strategy
exclusions do not suppress report findings. Search-noindex, password,
redirect, off-site canonical, and other base indexability decisions still do.
Non-indexable resources remain in the saved measurement set with their
indexability decision and reasons.

The report workspace shows total resources and findings, finding categories,
and added, resolved, and persisting findings since the baseline. Focused action
lists isolate thin-content, freshness, and media work. Exports are available as
printable themed HTML, spreadsheet-safe UTF-8 CSV, and JSON, with optional
agency name, URL, logo, site-name override, and a choice of Swiss, Minimal,
Monochrome, Midnight, or Cyberbrand presentation.

The AI Discovery Publication Report is separate from content measurement. It
records the registered fixed-path publication inventory and enablement state,
then performs current public HTTP, media-type, header, and parseability
validation for a client-ready HTML or JSON deliverable.

Completed reports can be selected from history. A report can be deleted only
when no later report still depends on it as its comparison baseline.
The admin page loads a bounded findings preview and database-computed totals;
HTML and CSV exports load the complete finding inventory without hydrating
unused resource rows. Structured JSON and the private read API retain the full
saved resource measurements when the complete snapshot fits the shared
synchronous JSON bound.

Synchronous admin exports and private report REST reads reject impractically
large snapshots with HTTP 413 before hydrating them: CSV supports up to 50,000
current findings; printable HTML supports up to 25,000 current-plus-baseline
findings; and full JSON—whether exported or read through REST—supports up to
25,000 combined current resources, current findings, and baseline findings. CSV
response rows are emitted incrementally. These bounds protect the request from
predictable memory exhaustion while preserving the exact
saved deliverable below the documented limits.

## 9. REST API, WP-CLI, and publishing integrations

### REST API

| Route | Access |
|---|---|
| `GET /wp-json/cybermaps/v1/discovery` | Public while the AI Publication Hub is enabled |
| `GET /wp-json/cybermaps/v1/health` | Bounded public health while the hub is enabled |
| `GET /wp-json/cybermaps/v1/mcp/server-card` | Public while the hub and MCP are enabled |
| `POST /wp-json/cybermaps/v1/mcp` | Optional MCP transport; mode, caller authentication, scopes, and capability checks govern operations |
| `GET /wp-json/cybermaps/v1/llms-tldr` | Public while both the hub and budgeted briefing are enabled |
| `GET /wp-json/cybermaps/v1/search` | Public while the hub is enabled |
| `GET /wp-json/cybermaps/v1/urls` | `X-Cybermaps-Secret` |
| `GET /wp-json/cybermaps/v1/status` | `X-Cybermaps-Secret` |
| `GET /wp-json/cybermaps/v1/audit-latest` | `X-Cybermaps-Secret` |
| `GET /wp-json/cybermaps/v1/audit-run?run_id=…` | `X-Cybermaps-Secret` |
| `POST /wp-json/cybermaps/v1/purge` | Logged-in administrator with `manage_options` |

Private JSON responses send non-cacheable headers. The API secret is generated
locally, displayed in Advanced settings, redacted from CLI status, and included
in site-configuration backups.

The OAuth controller registers additional routes under `/wp-json/cybermaps/v1`
when its MCP integration is active. These are separate from the registry's
generated REST route list:

| OAuth route | Contract |
|---|---|
| `GET /oauth/authorization-server` | Authorization-server metadata |
| `GET /oauth/protected-resource` | Protected-resource metadata |
| `GET /oauth/authorize` | Begin authorization with a logged-in WordPress user |
| `POST /oauth/authorize` | Complete the nonce-bound consent transaction |
| `POST /oauth/token` | Validate a supported grant and issue credentials |
| `POST /oauth/revoke` | Process a validated revocation request |
| `POST /oauth/device-authorization` | Device flow, gated by `user_claimed` registration |
| `POST /oauth/clients` | Administrator-authorized client registration |

MCP uses scoped OAuth credentials, not the private `X-Cybermaps-Secret` read API
credential. Publication of metadata does not grant permission to execute tools.

### WP-CLI

- `wp cybermaps status`
- `wp cybermaps clear_cache`
- `wp cybermaps flush_rules`
- `wp cybermaps regenerate`

### IndexNow

When enabled, Cybermaps queues a content URL when it enters, changes within,
or leaves the eligible published sitemap inventory, including deletion. The
durable database queue deduplicates same-host URLs, records retry state, and
builds bounded submissions. Each non-blocking request sends the public URL,
host, site-specific IndexNow key, and key-location URL to
`https://api.indexnow.org/indexnow`.
While enabled, `/{key}.txt` serves the site key to GET and HEAD requests with a
one-day public cache header. If `frontend_base_url` points to a different host,
IndexNow requires that public frontend to proxy or publish the same generated
`/{key}.txt` verification path; the key location must be on the host whose URLs
are submitted.

### WebSub

When WebSub and AI Publishing are enabled, the JSON Feed at `/feed.json` is the
one canonical topic. Cybermaps sends non-blocking publish notifications after
an eligible post is published, updated, becomes included or excluded, or is
deleted. The JSON Feed contains JSON Feed 1.1 `WebSub` hub descriptors; dynamic
responses also advertise exactly one `rel="self"` topic plus the configured
`rel="hub"` links. The feed remains dynamic in every Static File Engine mode
so its media type and discovery contract do not depend on server configuration.
The hub list accepts up to 10 unique validated HTTPS URLs.

## 10. Site-configuration backup and migration

Advanced settings provide two deliberately different exchange formats.

### Versioned JSON backup

The JSON backup contains:

- all `cybermaps_settings` values, including nested values and the private API
  secret;
- the Content Discovery Strategy;
- Robots Control data;
- Identity Hub data; and
- the IndexNow key.

The file includes its format version and an integrity checksum. Import accepts
**Smart Merge** or **Full Replace**, runs each configuration group through its
canonical sanitizer, verifies the written result, and attempts to restore the
previous values if verification fails. Smart Merge preserves destination
secrets when the source value is empty; Full Replace requires a valid
Cybermaps JSON backup. Imports are limited to 1 MB.

This is a site-configuration backup, not a backup of every Cybermaps-owned or
WordPress-owned record. It excludes:

- Discovery Analytics history;
- saved Content Intelligence Report runs;
- posts, pages, media, and other WordPress content;
- generated files, ownership records, caches, and operational status;
- network-wide settings; and
- cross-site translation relationships.

Because the JSON file can contain the private Cybermaps REST API secret and
information entered in plugin settings, keep it private. The IndexNow key is
also preserved for exact restoration but is not an integration credential.

### AI Configuration Brief

The Markdown AI Configuration Brief is a site-aware, credential-excluding handoff
for AI-assisted configuration. It contains operating instructions, privacy and
safety rules, current non-secret values, public identity/contact/catalog
context, site content and taxonomy inventories, route and publication state,
detected integrations, supported crawlers and schema types, and a complete
field reference with defaults, allowed values, dependencies, examples, and
risk levels. Its fenced JSON changes envelope starts with every editable field
set to `null`; only non-null changes are considered.

The v2 envelope is merge-only and strict: unknown sections, fields, nested
properties, crawler IDs, malformed public URLs, incompatible identity types,
and invalid cross-field combinations are errors. Cybermaps first produces a
non-mutating field-level preview of current, proposed, and canonical sanitized
values. Applying requires that exact file and unchanged destination
configuration; high-impact changes require explicit acknowledgement.

The complete administrator and AI-assistant workflow, changes-envelope rules,
privacy boundary, validation behavior, examples, and troubleshooting guidance
are documented in [AI-Assisted Configuration](./ai-configuration.md).

The canonical machine contracts are published at
`https://cybermaps.dev/specs/ai-configuration/{version}/schema.json` and
`https://cybermaps.dev/specs/ai-configuration/{version}/catalog.json`, with
usage guidance at `https://cybermaps.dev/docs/ai-configuration/`. The same
artifacts are committed under `docs/dev/ai-configuration/` and their hashes
appear in the generated manifest. The private Cybermaps REST API secret and
IndexNow key, destructive uninstall state, analytics, report data, content, and
generated files are not included. Credential-bearing URL userinfo is omitted as
well.
Because ordinary configuration fields are user-authored and can still contain
sensitive business details, review the Brief before sharing it. Use the
complete JSON backup—not the Brief—when exact restoration is required, and
keep that backup private.

Only the complete JSON backup and the self-describing v2 Brief are accepted for
configuration import. Earlier Markdown templates are intentionally unsupported.

## 11. Security and operational boundaries

- Cybermaps administration pages require `manage_options`; multisite network
  settings require `manage_network_options`.
- Setting saves and state-changing admin actions use WordPress nonces and
  capability checks.
- Private REST reads compare `X-Cybermaps-Secret` with `hash_equals()` and send
  private, non-cacheable responses.
- Protected Varnish PURGE requests are disabled until configured and are limited
  to exact Cybermaps publication URLs.
- Report, analytics, and configuration exports require authorization and use
  explicit content types and filenames, no-cache headers, and
  `X-Content-Type-Options: nosniff`. Downloadable formats use attachment
  disposition; printable report HTML is served inline.
- CSV exports protect spreadsheet-leading formula characters and use UTF-8
  output.
- Configuration imports are size-bounded, parsed before writes, sanitized by
  their owning configuration group, verified after writes, and rolled back on
  a failed verification.
- Cybermaps admin responses restrict framing to the same origin with a
  `Content-Security-Policy: frame-ancestors 'self'` header.
- Public discovery documents are read-only. The admin REST purge requires
  `manage_options`; the optional MCP operations mode exposes a separately
  authenticated and capability-checked purge tool. Filesystem changes remain
  ownership-verified.

## 12. Extension and lifecycle notes

Cybermaps Core is a complete standalone WordPress.org plugin. Its Extension API
2.0 exposes:

- endpoint metadata registration;
- read-only access to the final publication-eligibility service; and
- read-only access to saved content report runs.

Extensions implement and route their own capabilities; Core retains ownership
of its settings, tables, files, lifecycle, and public namespace.

Activation creates the required analytics, translation, and report storage,
schedules log cleanup, adds rewrite rules, and initializes publication state.
Relevant settings changes invalidate caches and schedule ownership-safe static
reconciliation. Deactivation clears scheduled events and rewrite rules.
It also removes unchanged Core-owned generated output; reactivation schedules
restoration for the selected physical-publication mode.

Uninstall always clears scheduled hooks and attempts to remove unchanged
Core-owned generated files. Persistent options, tables, and post metadata are
removed only when **Uninstall Cleanup** was enabled beforehand. Edited and
pre-existing files are retained.

The 6.0 data upgrade is an idempotent, stepwise transaction with a renewable
owner lock, persisted step state, exponential retry backoff, and post-write
verification. New installations are stamped at the current data version before
defaults are created, while existing installations retain and normalize their
configuration. The four structured configuration roots and operational
coordination options are explicitly non-autoloaded.

## 13. Operational checklist

For a new or migrated site:

1. Save the desired sitemap providers and inspect **Sitemap Status**.
2. Configure **Schema**, then enable AI Publishing, add Custom AI Instructions,
   and inspect **AI Discovery Status**.
3. Confirm public HTTP status, body format, and media type instead of relying
   only on a local file indicator.
4. On OpenLiteSpeed or nginx, test the extension-bearing Core JSON paths and
   the canonical `/ai-discovery` and `/.well-known/api-catalog` routes;
   AI Discovery Status reports whether the automatic origin bridge is active or
   the request is still blocked before WordPress.
5. If Redis or Memcached is installed, confirm the WordPress `object-cache.php`
   drop-in is active; Cybermaps uses only the WordPress Cache API.
6. If edge caching is present, review the advisory snippets and confirm RFC 9111
   policy, Repr-Digest/header parity, and exact Cybermaps purge behavior at the
   proxy or CDN.
7. Before enabling Markdown for Agents, verify `Vary: Accept` at the public edge
   and install the documented LiteSpeed/OpenLiteSpeed request bypass when used.
8. If analytics is wanted, choose the IP-storage mode, trusted-proxy policy, and retention before
   enabling recording.
9. Run a first Content Intelligence Report as the future comparison baseline.
10. Download and secure a JSON site-configuration backup.
11. After large setting or content changes, use **Regenerate Publications** and review the
   reported written, unchanged, conflicted, failed, deleted, and retained
   counts.
12. On a Cloudflare-proxied site, use Advanced's authorized optimization flow
    if header or cache repair is needed. Confirm per-resource body and header
    results after consent; an authorization success message alone is not a
    delivery result. Compare the ordinary URL as well as diagnostic probes
    because a query string can take a different cache path.
13. For a reproducible failure, enable expiring logging in **Debugging**,
    reproduce the action, and export the redacted support bundle. Record the
    failing path and expected versus observed behavior alongside it.

### Discovery delivery diagnostics and full-corpus limits

The Reports and AI Discovery Status views distinguish body/media-type checks
from the static delivery header policy. Missing cache directives, exposed
headers, and server-specific validators remain visible as delivery-policy
issues rather than automatically invalidating a publication's format. Incorrect
media types and supplied incorrect digest values remain failures. The ARD
compatibility catalog still requires its wildcard CORS header. A dynamic
publication without a disk copy is shown as "Not required".

Cloudflare header repair includes the registered root compatibility publications
(`/ai.json`, `/ai-usage.json`, `/ai-actions.json`, and `/ai-discovery`) as well as
the supported well-known publications. JSON-LD and MCP server cards retain their
own media types. After updating, use the existing Cloudflare authorization flow
to reapply rules; a plugin update alone does not change deployed account rules.
Cloudflare remains optional, and these changes do not configure an nginx origin.

LLMS Summary retains its existing 4 MiB-minus-one-byte output ceiling. LLMS Full
uses a 32 MiB-minus-one-byte ceiling for dynamic generation and static writes.
Before extracting content or expanding the output, the generator also reserves
16 MiB plus working memory for string copies and extraction against PHP's
configured memory limit. Low-memory requests can therefore fail below the byte
ceiling. No partial corpus is returned or written. HTTP 507 identifies this
publication safety failure, not necessarily exhausted disk storage. The full
corpus is still assembled in memory; this is not an unlimited streaming exporter.
Full-corpus health probes allow up to 32 MiB and eight seconds; larger or slower
responses remain unverified rather than being represented as fully validated.

### Opt-in automatic local discovery routing

Advanced > Automatically configure local delivery can now install an owned
`Cybermaps managed delivery` block before existing root `.htaccess` rules on
Apache/LiteSpeed installations with direct WordPress filesystem access. AI
Discovery Status links to this action when response-header policy needs attention.
The action is administrator-only, POST-only, nonce protected, and opt-in. It does
not save other pending settings changes or require Cloudflare.

This is a rewrite-only compatibility mode: fixed registry discovery URLs reach
WordPress even when a physical publication exists. PHP supplies the response
headers; static copies remain on disk. It trades direct static-file delivery for
correct protocol handling. It does not install Apache header directives on
OpenLiteSpeed, change nginx configuration, or bypass an upstream static location.
OpenLiteSpeed may require a reload before rewritten rules take effect. Multisite,
remote home-URL overrides, symlinked `.htaccess`, oversized existing configuration,
and non-direct filesystem connections are refused rather than guessed at.

Undo and plugin deactivation remove only an unchanged recorded Cybermaps block.
Other plugins' blocks and custom content are preserved. Conflicting marker edits
are retained for manual review. A before/after homepage check removes the new
block if an initially successful homepage starts returning a server error. The
post-install check samples three discovery URLs and requires HTTP 200, their
expected MIME type, and a PHP response marker. It is explicitly a routing/header
sample, not full-body verification or proof that every public client follows the
same proxy route. A file write alone is not presented as verified delivery.

Local routing checks now retain a per-URL evidence table: HTTP status, expected
and received media types, PHP marker, Cloudflare rule marker, and bounded transport
error text. The table includes the last-check UTC timestamp. HTTP/media-type
success is reported separately from an observed PHP marker; missing PHP evidence
is not reported as an unavailable publication. These probes originate from
WordPress and are not independent external measurements. Response bodies are not
validated by this bounded routing sample.
