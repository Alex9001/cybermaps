=== CYBERMAPS: XML Sitemaps & llms.txt ===
Contributors: oreshkin
Tags: sitemap, llms-txt, technical-seo, content-audit, indexnow
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 7.4.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish sitemaps and machine-readable WordPress maps for search engines and agents.

== Description ==

Cybermaps honors Genesis/Mai, Yoast, Rank Math, and All in One SEO signals.

= A complete sitemap engine =

* XML indexes for content, taxonomies, authors, dates, News, and multisite
* RSS, media XML/JSON-LD, slugs, XSL, validation, caching, and redirects
* Bounded `[cybermap]` HTML output, external URLs, and headless rewriting

= An AI-readable publication layer =

* `llms.txt`, briefings, manifests, AI sitemap, JSON Feed, knowledge graph,
  policies, actions, Site Guide, chunks, catalogs, and crawler controls
* Optional MCP 2026-07-28 Streamable HTTP with authorized operations
* Agent Skills, literal Markdown alternates, and opt-in negotiation
* API Catalog/Linksets, RFC 8288 headers, OpenAPI, REST search/health,
  OAuth/Auth.md, and enabled capability catalogs
* Draft MCP Server Card, ARD AI Catalog, and read-only WebMCP tools

No AI account is required; full output is bounded to 32 MiB.

= Flexible, verified delivery =

Static modes are `off`, default `well_known`, and `all`. Dynamic routes retain
CORS/validators; static files use ownership hashes and bounded safe repair.

WordPress caches support Redis/Memcached and isolated APCu.
LSCWP hooks and opt-in exact-URL Varnish PURGE are supported.

Debugging provides redacted support bundles. Advanced manages caches, Cloudflare
OAuth, and temporary tokens.

= Standards and controlled agent access =

OpenAPI supports 3.2.0 and 3.1.2. Draft features are labelled; AIPREF defaults off.

MCP operations require OAuth 2.1 PKCE, consent, and WordPress capabilities.
OAuth discovery never fabricates OIDC/JWKS metadata; RFC 8628 registration
requires logged-in review.

== Installation ==

1. Install and activate Cybermaps.
2. Optionally launch **Guided Setup** from **Cybermaps → Overview**.
3. Review **XML Sitemaps**, configure **Schema**, then enable **AI Publishing** and guidance.
4. Validate status; optionally enable **Discovery Analytics** and **Reports**.

== Frequently Asked Questions ==

= How do I avoid competing XML sitemaps with Yoast, Rank Math, or AIOSEO? =

Choose one XML sitemap owner. Cybermaps honors supported noindex/canonical signals.

= Does Cybermaps make ChatGPT or other AI systems cite or rank my site? =

No. Machine-readable files do not guarantee crawling, indexing, ranking, citation, recommendation, or use.

== External Services ==

= Cybermaps Cloudflare OAuth Relay =

When an administrator explicitly clicks Connect Cloudflare & optimize,
Cybermaps contacts https://connect.cybermaps.dev to create and consume a
five-minute one-time OAuth transaction. The relay receives a PKCE challenge,
random transaction state, and Cloudflare's short-lived authorization code. It
does not receive the PKCE verifier, Cloudflare access token, WordPress identity,
site URL, or rule payload. WordPress exchanges and revokes the token directly
with Cloudflare. The relay is not contacted for ordinary publication, crawling,
status collection, the manual-token fallback, or self-managed OAuth mode. In
self-managed mode, the administrator registers this site's exact WordPress
callback as a secretless PKCE client. Cloudflare returns directly to WordPress;
only the public client ID is saved.

Service information and privacy: https://cybermaps.dev/privacy/
Cloudflare terms: https://www.cloudflare.com/website-terms/
Cloudflare privacy: https://www.cloudflare.com/privacypolicy/

= IndexNow =

When enabled, Cybermaps submits eligible changed publication URLs, host, key, and key location to `https://api.indexnow.org/indexnow` through the queue above.

Headless frontends must proxy or publish Cybermaps' generated `/{key}.txt` verification path.

* Docs: https://www.indexnow.org/
* Terms and privacy: https://www.indexnow.org/terms

= WebSub hubs =

When enabled, Cybermaps publishes `/feed.json` to configured HTTPS hubs.
Defaults: Google PubSubHubbub (`https://pubsubhubbub.appspot.com/`; terms:
https://policies.google.com/terms) and Superfeedr
(`https://pubsubhubbub.superfeedr.com/`; terms: https://superfeedr.com/terms).

= Public delivery checks =

Sitemap and AI Discovery Status may GET/HEAD the site or Frontend Base URL.
Probes use `X-Cybermaps-Diagnostic: 1`, are excluded from analytics,
and verify status, media type, parseability, and headers.

== Privacy ==

Opt-in Discovery Analytics stores bounded request metadata and unverified
User-Agent evidence. IPs default to IPv4 `/24` or IPv6 `/64` anonymization;
logged-in and diagnostic requests omit identifying request data. Public routes
use local 60-second rate limits; REST search stores neither raw IPs nor queries.
Retention is 1–365 days with export and clearing controls.

Persistent settings, tables, and post metadata remain after uninstall unless
**Uninstall Cleanup** was enabled beforehand.

== Changelog ==

= 7.4.1 =
* Hardened request sanitization and SQL identifiers; documented Plugin Check false positives.

= 7.4.0 =
* Fixed delivery and reports.

= 7.3.4 =
* Fixed cross-site origin caching for `/ai-discovery` without changing its public URL.

= 7.3.3 =

* Added an ownership-safe `/ai-discovery` fallback for origins with unpurgeable path caches.

= 7.3.2 =

* Isolated APCu and shared object-cache entries across independent WordPress installations.

= 7.3.1 =

* Fixed the Advanced Cloudflare eligibility control fatal and confirmation-row visibility.

= 7.3.0 =

* Added opt-in, expiring redacted diagnostics/support bundles; renamed System Status to Debugging.

= 7.2.2 =

* Disabled Cloudflare mutations when proxy traffic is not detected, with an
  exact-host, page-scoped manual confirmation for advanced deployments.

= 7.2.1 =

* Fixed recoverable OAuth completion, canonical rule paths, and automatic
  browser-visible verification with per-resource diagnostics.
* Added Cloudflare execution proof and complete OAuth metadata cache bypass.

= 7.2.0 =

* Added public Cloudflare OAuth with PKCE, account consent, automatic combined
  rule installation, immediate token revocation, and no stored credential.
* Added a bounded open-source callback relay, ambiguity-safe zone selection,
  OAuth lifecycle status, and a one-time-token fallback.
* Added self-managed Cloudflare OAuth for organizations that want the callback,
  PKCE transaction, code exchange, and token revocation to remain on WordPress.

= 7.1.0 =

* Added System Status with stack detection and explicit optimization-utilization evidence.
* Added one-time-token Cloudflare header and cache-safety automation plus default-on LiteSpeed and APCu controls.
* Unified edge diagnostics with the static publication header contract and added scoped Cybermaps-only cache invalidation.

= 7.0.1 =

* Added ownership-safe `.well-known` fallbacks with explicit conformance status.
* Unified OAuth metadata, bounded LLMS scans, and fixed duplicate-header diagnostics.

= 7.0.0 =

* Raised the minimum to WordPress 7.1 and added a native public Ability kernel shared by MCP, WebMCP, API Catalog, OpenAPI, and ARD discovery.
* Replaced nested well-known configuration with exact WordPress global rewrite rules, removed physical well-known defaults, and retained ownership-safe cleanup and public verification.

= 6.6.1 =

* Added automatic ownership-safe `/.well-known/` routing for header-sensitive discovery endpoints on compatible Apache and LiteSpeed servers.
* Added public GET/HEAD verification and explicit blocked-before-WordPress diagnostics without substituting wrong-MIME static files.

= 6.6.0 =

* Added enriched per-API RFC 9727/RFC 9264 Linksets and a public REST health endpoint.
* Added OAuth metadata, optional RFC 8628 device approval, and opt-in Auth.md without unsupported OIDC or fabricated JWKS claims.
* Added the experimental current MCP Server Card, draft ARD AI Catalog, and opt-in read-only WebMCP with explicit maturity guidance.
* Added server/cache/CDN rules and Configured, Advertised, Publicly verified, and canonical-interception diagnostics.

Earlier release history is included in `changelog.txt`.

== Upgrade Notice ==

= 7.4.1 =
Improves request handling and WordPress.org Plugin Check compatibility.

= 7.4.0 =
Delivery fixes.

= 7.3.4 =
Reconnect Cloudflare once.

= 7.3.3 =

Compatibility mode now materializes the discovery index when nginx bypasses WordPress.

= 7.3.2 =

Prevents cross-site cache collisions on shared PHP/cache infrastructure.

= 7.3.1 =

Fixes a fatal error when opening Advanced settings.

= 7.3.0 =

Debugging is off by default and auto-expires.

= 7.2.2 =

Cloudflare controls now unlock only after automatic detection or explicit
confirmation that the configured hostname is orange-cloud proxied.

= 7.2.1 =

Cloudflare setup now confirms browser-visible delivery after updating rules and
recovers a completed authorization after a missed poll or reload.

= 7.2.0 =

Cloudflare optimization now uses a guided OAuth consent flow by default.
Organizations can instead register a site-local secretless OAuth client, while
the existing scoped API-token workflow remains available under Troubleshooting.

= 7.1.0 =

Review System Status after upgrading. LiteSpeed and APCu integrations default on when available; Cloudflare automation remains optional and never stores its token.

= 7.0.1 =

Regenerate Core discovery files to materialize enabled `.well-known` fallbacks.

= 7.0.0 =

Requires WordPress 7.1. Well-known protocols now use WordPress-owned global routing; Cybermaps removes only its verified legacy nested routing block.

= 6.6.1 =

Automatically repairs compatible Apache/LiteSpeed well-known routing so the RFC 9727 API Catalog can retain its required status, media type, and Link header.
