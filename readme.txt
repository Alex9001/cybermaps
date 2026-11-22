=== CYBERMAPS: XML Sitemaps & llms.txt ===
Contributors: oreshkin
Tags: sitemap, llms-txt, technical-seo, content-audit, indexnow
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 8.1.2
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish XML sitemaps, llms.txt and readable Markdown. Check delivery, understand crawler visits, and find content issues in WordPress.

== Description ==

CYBERMAPS helps you publish a map of your WordPress content, check that it reaches visitors and crawlers, and understand what needs attention.

Start with the three-step Quick Setup, choose what to publish, then check the results from your dashboard. The plugin is free and open source, with no license key or paid feature locks.

= Sitemaps for search engines and visitors =

* XML sitemap index and child sitemaps for public posts, pages, custom post types and taxonomies.
* News, image, video, author, archive and RSS output, with content-selection controls.
* A configurable HTML sitemap for visitors using the [cybermap] shortcode.
* Optional XML management: keep another sitemap solution in charge while using Cybermaps discovery and reporting features.

= Readable content for AI clients =

* llms.txt with selected links, and Markdown versions of eligible public pages.
* Optional full-text llms-full.txt and bounded retrieval chunks.
* An opt-in AI Publication Hub with an AI manifest, JSON Feed, knowledge graph, AI sitemap, API Catalog, OpenAPI and public search.
* Site identity, crawler preferences and a read-only Agent Skills guide.
* Optional read-only MCP connections through the separate WordPress MCP Adapter plugin. Core features work without it.

These publications make content available to compatible clients; they do not guarantee search rankings, AI training, citations or traffic. Some discovery formats are emerging conventions or drafts, as identified in the documentation.

= Check delivery and improve your content =

* Sitemap Status and AI Discovery Status check public URLs, response headers and routing.
* Content reports highlight metadata, media and internal-link findings, with saved comparisons and printable, CSV or JSON exports.
* Opt-in local analytics show crawler requests, with retention, export and clearing controls.
* The optional Static File Engine publishes supported files with ownership checks; multisite uses dynamic delivery.
* Optional IndexNow notifications, WebSub notifications and Cloudflare configuration are explained below. Each requires configuration or consent.

Cybermaps can work alongside supported SEO and translation integrations. Choose one XML sitemap owner, then verify your site's public output. Server, cache and CDN behavior can affect delivery; diagnostics show what was actually observed.

[Getting started](https://cybermaps.dev/docs/getting-started/) | [Feature guide](https://cybermaps.dev/features/) | [Documentation](https://cybermaps.dev/docs/)

== Screenshots ==

1. Overview and Quick Setup: choose what your site publishes.
2. XML Sitemaps: select content and choose who manages sitemap generation.
3. AI Publishing: configure optional discovery publications and read-only MCP guidance.
4. Site Identity: describe your organization and public content.
5. Sitemap Status: inspect public sitemap delivery.
6. AI Discovery Status: see configured publications and their delivery checks.
7. Content Reports: review saved findings and export results.
8. Analytics: inspect local crawler observations after opting in.

== Installation ==

1. Install and activate CYBERMAPS from Plugins → Add New Plugin, or upload the installable release ZIP.
2. Open Cybermaps → Overview → Quick Setup and choose your publishing settings.
3. In XML Sitemaps, choose the content to include. Turn XML management off if another plugin handles your sitemaps.
4. Review Sitemap Status. Enable the AI Publication Hub in AI Publishing only if you want those publications, then review AI Discovery Status.
5. Enable analytics, external notifications or static publication separately if needed.

Requires WordPress 7.1+ and PHP 8.2+; PHP 8.3+ is recommended. Back up your site before updating.

== Frequently Asked Questions ==

= Does Cybermaps replace WordPress sitemaps? =

Cybermaps replaces the built-in sitemap only when XML management and the Core sitemap redirect are enabled and your site uses pretty permalinks (any setting other than Plain). Turn XML management off to let WordPress handle its built-in sitemap again, subject to your site's privacy settings and other plugins.

= Do I need MCP Adapter? =

Only for MCP clients. Install WordPress MCP Adapter 0.7.0+, then save the read-only opt-in in AI Publishing. Connect over HTTPS with a Subscriber Application Password, or use WP-CLI. This exposes reviewed public resources and search, not remote site administration. [MCP setup](https://cybermaps.dev/docs/mcp/).

= Does it send analytics to a service? =

No. Optional crawler analytics stays in your WordPress database. External services run only for their configured purpose, as disclosed below. Review diagnostic bundles before sharing them publicly.

= Can I use it on a large site? =

Publications have documented byte, query and scan limits. Large full-text exports or reports may be incomplete or reach a limit; the plugin reports that instead of claiming a complete result. Check your own site's workload and hosting resources.

= Where can I get help? =

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/cybermaps/) for help, or [GitHub issues](https://github.com/Alex9001/cybermaps/issues) for reproducible bugs and feature requests. Cybermaps → Debugging can create a support bundle. Report security issues [privately](https://github.com/Alex9001/cybermaps/security/advisories/new).

= Source Code =

https://github.com/Alex9001/cybermaps/tree/v8.1.2
JS/CSS: assets/; setup-wizard.js uses wp.element. No compilation.
Build: composer install; bash bin/package-candidate.sh.

= External Services: Cloudflare and Cybermaps OAuth relay =

Opt-in Cloudflare OAuth sends PKCE challenge, state and code to
https://connect.cybermaps.dev for five minutes. No verifier, token, WP identity, site URL or rules. Hosting processes
network metadata; limits use salted IP hashes. WordPress sends Cloudflare credentials for exchange/revocation, zone,
hostname and rules.
Manual/self-managed modes bypass the relay. Policies:
https://cybermaps.dev/privacy/#cloudflare-oauth-relay
https://www.cloudflare.com/website-terms/
https://www.cloudflare.com/privacypolicy/

= External Services: IndexNow =

Opt-in changes send URLs, host, key and key location to
https://api.indexnow.org/indexnow. Headless sites must serve `/{key}.txt`.
Terms/privacy: https://www.indexnow.org/terms

= External Services: WebSub =

Opt-in WebSub notifies HTTPS hubs of public /feed.json. Hubs/policies:
https://pubsubhubbub.appspot.com/
https://policies.google.com/terms
https://policies.google.com/privacy
https://pubsubhubbub.superfeedr.com/
https://superfeedr.com/terms
https://superfeedr.com/privacy
Custom hubs: own policies.

= Public delivery checks =

GET/HEAD probes use the site/Frontend Base URL; `X-Cybermaps-Diagnostic: 1` excludes analytics. Configured Varnish receives purges.

= Privacy =

Opt-in analytics is local: /24 IPv4, /64 IPv6 by default; 1–365-day retention,
export and clear. Public rate limits: 60 seconds.
REST search stores no raw IPs or queries. Logged-in rows keep WP user IDs,
but omit IPs, requester keys and User-Agent. Diagnostic probes are excluded.
Uninstall retains data unless cleanup is enabled.

== Changelog ==

= 8.1.2 =
* Withdraw content from origin publications when a public page becomes private or password-protected.

= 8.1.1 =
* Prevent shared proxies from serving another site's sitemap or sitemap redirect.
* Avoid writing a temporary content-analysis cache entry for every post in a full content report.

= 8.1.0 =
* Stable WordPress.org launch with clearer setup, directory presentation and support links.
* Improve dashboard contrast, mobile tables and keyboard navigation.
* Reduce content-report database writes while preserving findings and WordPress field validation.
* Stronger release checks for installation, upgrades, complete workloads, screenshots and website consistency.


= 8.0.4 =
* Condition Core sitemap replacement on XML ownership.

= 8.0.3 =
* Fix sitemap/report buffers.

Full release history and archived upgrade notices: `changelog.txt`.

== Upgrade Notice ==

= 8.1.2 =
Improves withdrawal of previously published content in Full publication cache mode. Purge separate page and CDN caches.

= 8.1.1 =
Fixes cross-site sitemap responses on shared hosting. Existing cached responses may appear until they expire.

= 8.1.0 =
Stable release. Existing settings are retained; GitHub issue reporting remains available. Back up before updating. MCP still requires the optional adapter and explicit opt-in.


= 8.0.4 =
XML management switch added; defaults stay enabled.

= 8.0.3 =
Preserves caller output buffers.
