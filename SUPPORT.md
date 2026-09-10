# Cybermaps support

## Start here

1. Check the [WordPress readme](readme.txt) and
   [technical documentation](docs/documentation.md).
2. Use **Sitemap Status** or **AI Discovery Status** to capture the exact public
   URL, response status, media type, and delivery mode.
3. Re-test with the current Cybermaps beta or release on WordPress 7.1+ and
   PHP 8.2+; PHP 8.3+ is recommended.
4. Search [existing GitHub issues](https://github.com/Alex9001/cybermaps/issues)
   before opening a new one. The settings sidebar has an **Open beta feedback**
   card with links to report an issue, suggest an improvement, and open Debugging.

## Bug reports

Use the [bug report form](https://github.com/Alex9001/cybermaps/issues/new?template=bug_report.yml) and include:

- Cybermaps, WordPress, and PHP versions;
- single-site or multisite;
- web server and relevant cache/CDN layer;
- active SEO or translation integrations;
- exact steps, expected behavior, and actual behavior; and
- the diagnostic support bundle from **Cybermaps → Debugging**.

### Collect the diagnostic support bundle

1. Open **Cybermaps → Debugging** in WordPress.
2. If you can reproduce the issue safely, enable **Enable diagnostic logging**,
   select **1 hour**, and repeat the action that triggers the problem.
3. Click **Copy GitHub support bundle**. Review the copied JSON and paste it into
   the issue form's **Cybermaps diagnostic support bundle** field. If copying
   fails, choose **Download support bundle**, open the JSON file, and copy its
   contents instead.
4. Turn logging off when finished, or let it expire automatically.

The bundle contains system/optimization status and retained Cybermaps diagnostic
events. It is different from a configuration backup or the crawler Request Log.
For a delivery problem, add the affected endpoint path and relevant **Sitemap
Status** or **AI Discovery Status** finding separately.

If the plugin or WordPress cannot open, explain that in the diagnostic field and
include the versions, server, site setup, and exact error you can access. Missing
diagnostics should not prevent you from reporting an activation failure.

Do not post configuration backups, API secrets, IndexNow keys, raw IP
addresses, private URLs, or customer data.

## Feature requests

Explain the publishing or administration problem first, then the proposed
behavior. A concrete example and the expected compatibility boundary are more
useful than a broad feature list.

## Scope

Community support covers Cybermaps itself. Site-specific server rules, CDN
configuration, custom code, third-party plugin behavior, migrations, and client
implementation work may require professional web development support.

Security reports belong in the private channel described in [SECURITY.md](SECURITY.md).

PHP 8.2 remains supported for this release. Its support policy will be
reassessed when upstream PHP security support ends; no automatic removal date
is implied.
