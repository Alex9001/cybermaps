=== CYBERMAPS: XML Sitemaps & llms.txt ===
Contributors: oreshkin
Tags: sitemap, llms-txt, technical-seo, content-audit, indexnow
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 8.0.2
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress sitemaps, AI discovery, static delivery and reports.

== Description ==

Optional MCP requires MCP Adapter 0.7.0+.

== Installation ==

Activate; open Cybermaps → Overview → Quick Setup.
MCP: activate MCP Adapter; enable read-only MCP in AI Publishing and save.
Use HTTPS and a Subscriber Application Password, or WP-CLI.
Setup: https://cybermaps.dev/docs/mcp/

== Source Code ==

https://github.com/Alex9001/cybermaps/tree/v8.0.2
JS/CSS source: assets/; setup-wizard.js uses wp.element. No compilation.
Build: composer install; bash bin/package-candidate.sh.

== External Services ==

= Cloudflare and Cybermaps OAuth relay =

Connect Cloudflare sends a PKCE challenge, random state and authorization code
to https://connect.cybermaps.dev for five minutes. No verifier, access token,
WordPress identity, site URL or rules reach the relay. Hosting processes network
metadata; rate limits use salted IP hashes. WordPress sends credentials to
Cloudflare for exchange/revocation, plus zone, hostname and requested rules.
Manual/self-managed modes bypass the relay. Policies:
https://cybermaps.dev/privacy/#cloudflare-oauth-relay
https://www.cloudflare.com/website-terms/
https://www.cloudflare.com/privacypolicy/

= IndexNow =

Publication changes send URLs, host, key and key location to
https://api.indexnow.org/indexnow when enabled. Headless sites must serve the generated `/{key}.txt`.
Terms/privacy: https://www.indexnow.org/terms

= WebSub =

Opt-in WebSub sends public /feed.json notifications to configured HTTPS hubs.
Defaults and policies:
https://pubsubhubbub.appspot.com/
https://policies.google.com/terms
https://policies.google.com/privacy
https://pubsubhubbub.superfeedr.com/
https://superfeedr.com/terms
https://superfeedr.com/privacy
Custom hubs use their own policies.

= Public delivery checks =

Checks GET/HEAD the site or Frontend Base URL with `X-Cybermaps-Diagnostic: 1` to omit analytics. Configured Varnish receives purge requests.

== Privacy ==

Opt-in analytics is local: /24 IPv4, /64 IPv6 by default; 1–365-day retention,
export and clear. Public routes have 60-second rate limits.
REST search stores no raw IPs or queries. Logged-in rows keep WP user IDs,
but omit IPs, requester keys and User-Agent. Diagnostic probes are excluded.
Uninstall keeps data unless Uninstall Cleanup is enabled.

== Changelog ==

= 8.0.2 =
* Fix privacy, configuration, ownership, publication limits, reports and admin UI; expand release checks.

= 8.0.1 =
* Delete obsolete MCP settings and upgrade code; use one adapter switch.

= 8.0.0 =
* Optional WordPress MCP Adapter replaces custom MCP/OAuth. Only public resources and search remain; remote operations and arbitrary abilities are removed.
* Guided setup, migration and release checks.

= 7.5.4 =
* Made public sitemap attribution an explicit, default-off choice.
* Added evidence-bound release gates and stricter WordPress.org validation.

= 7.5.3 =
* Sanitized Quick Setup at the request boundary and bounded client-IP headers.
* Escaped admin markup at output and separated HTML/JSON from other protocols.
* Documented editable asset sources and expanded real-WordPress security checks.

= 7.5.2 =
* Hardened request authorization, exact setup input schemas, contextual protocol output, SQL lists, and client-IP hook data.
* Moved report CSS to an enqueued asset, removed reserved transient-option access, and added fail-closed WordPress.org release checks.
* Restored the WordPress 7.1/PHP 8.2 baseline with native Abilities API checks.

= 7.5.1 =
* Added local orphan, homepage-path, and three-click-depth content findings.
* Preserved headings and links across Markdown output.

= 7.5.0 =
* Added three-step Quick Setup with site presets, basic identity, and a settings receipt.
* Fixed setup asset caching and rendering; synchronized release documentation.

= 7.4.2 =
* Bounded LLMS work, prevented overlapping rebuilds, reduced cache reads, and corrected scan coverage.

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

= 8.0.2 =
Ownership migrates in batches. Review file conflicts and incomplete reports. Restart pending Cloudflare authorization.

= 8.0.1 =
Removes legacy MCP settings; preserves 8.0 adapter consent.

= 8.0.0 =
MCP users: install MCP Adapter 0.7.0+, enable read-only MCP in AI Publishing and reconnect. Old endpoints, credentials and tasks are retired.


= 7.5.4 =
Public sitemap credits are now off by default. Enable them explicitly in XML Sitemaps if desired.

= 7.5.3 =
WordPress.org review fixes for input validation, output escaping, and source access.

= 7.5.2 =
Security and WordPress.org hardening with the WordPress 7.1 Abilities API baseline.

= 7.5.1 =
Reports now find potential orphans and deep pages from stored links and menus.

= 7.5.0 =
Quick Setup now applies a three-step preset while preserving advanced configuration.

= 7.4.2 =
Prevents overlapping LLMS rebuilds and bounds scans of excluded posts.

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
