=== CYBERMAPS: XML Sitemaps & llms.txt ===
Contributors: oreshkin
Tags: sitemap, llms-txt, technical-seo, content-audit, indexnow
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 7.5.3
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WordPress sitemaps and machine-readable maps.

== Description ==

XML/News/RSS/HTML sitemaps, llms.txt, Markdown, JSON discovery, static delivery,
local analytics, and reports. MCP requires authorization and consent.
Publication guarantees neither ranking nor AI citations.

== Installation ==

Install and activate Cybermaps, then open **Cybermaps → Overview → Quick Setup**.

== Source Code ==

Public source and build tools: https://github.com/Alex9001/cybermaps
Release source: https://github.com/Alex9001/cybermaps/tree/v7.5.3
The files in assets/js/ and assets/css/ are editable, unminified source,
including setup-wizard.js, which uses WordPress's wp.element directly.
There is no JavaScript/CSS compilation step. Packaging copies these files
unchanged. Run composer install, composer test, and composer release:build
from the source checkout; build prerequisites and validation are documented in
https://github.com/Alex9001/cybermaps/blob/v7.5.3/docs/dev/RELEASE-WORKFLOW.md

== External Services ==

= Cloudflare and the Cybermaps OAuth relay =

Only **Connect Cloudflare & optimize** contacts https://connect.cybermaps.dev
for a one-time, five-minute transaction. The relay receives a PKCE challenge,
random state, and authorization code, not the verifier, access token,
WordPress identity, site URL, or rules. Its hosting provider processes network
metadata; rate limits use salted IP hashes. WordPress sends authorization
credentials directly to Cloudflare to exchange/revoke tokens and sends zone,
hostname, and rule configuration for the requested optimization.
Manual tokens and self-managed OAuth bypass the relay.

Relay service and privacy: https://cybermaps.dev/privacy/#cloudflare-oauth-relay
Cloudflare terms: https://www.cloudflare.com/website-terms/
Cloudflare privacy: https://www.cloudflare.com/privacypolicy/

= IndexNow =

When enabled, eligible publication changes queue URLs, host, verification key,
and key location for https://api.indexnow.org/indexnow. Headless frontends
must proxy or publish Cybermaps' generated `/{key}.txt` verification path.
Terms and privacy: https://www.indexnow.org/terms

= WebSub =

When WebSub and AI publishing are enabled, content reconciliation sends the
public /feed.json URL and a publish notification to configured HTTPS hubs.
The default hubs are Google PubSubHubbub (https://pubsubhubbub.appspot.com/)
and Superfeedr (https://pubsubhubbub.superfeedr.com/).
Google terms: https://policies.google.com/terms
Google privacy: https://policies.google.com/privacy
Superfeedr terms: https://superfeedr.com/terms
Superfeedr privacy: https://superfeedr.com/privacy
Custom hubs receive the same data under their own service policies.

= Public delivery checks =

Status checks GET/HEAD the site or configured Frontend Base URL to verify
responses and headers. Probes use `X-Cybermaps-Diagnostic: 1` and are excluded
from analytics.

== Privacy ==

Opt-in crawler analytics stays in WordPress. IPs default to IPv4 /24 or IPv6
/64 anonymization. Retention is 1–365 days with export and clearing controls.
Public routes use local 60-second rate limits; REST search stores neither raw IPs nor queries.
Logged-in and diagnostic requests omit identifying data.
Data remains after uninstall unless Uninstall Cleanup was enabled beforehand.

== Changelog ==

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
