# Website documentation release handoff

The plugin owns runtime facts. The Astro repository owns explanatory guides.
The local release workflow validates both against one immutable Git commit.
GitHub Actions remain disabled.

## Automated handoff

Use the only plugin source checkout, `/home/user/Documents/CODE/cybermaps/`.
The canonical website remains `/home/user/Documents/CODE/cybermaps-astro/`.
Never create a release-specific clone or worktree. Configure both website and
Local Sites installation destinations in `.cybermaps-workspace.json` using the
tracked example, then run `composer release` (or `composer release -- --stable`).
See [RELEASE-WORKFLOW.md](RELEASE-WORKFLOW.md) for gates and recovery.

The preflight rejects staged, unstaged and untracked website changes before
running any website command. It never stashes, resets or commits existing work.
After checking committed, pushed plugin source, it runs:

```sh
npm run docs:sync -- /home/user/Documents/CODE/cybermaps --commit FULL_COMMIT_SHA --channel beta
```

The importer reads an immutable Git archive under the plugin's
`docs/generated/tmp/`. An unpublished candidate can be revised and reimported;
a tagged version cannot be repointed. Imports do not approve prose. If the
importer flags explanations, the workflow saves its output and stops with the
page list. The current agent or a human reviews the changed source/facts,
updates the explanation and records what was checked:

```sh
npm run docs:review -- --page docs--machine-publications.md --note "Reviewed bounds, output limits and retry behavior."
```

Commit those website changes yourself, then rerun `composer release`. Review
records bind guide bytes and declared source dependencies to a fingerprint,
commit, version and date. New docs need a dependency mapping or an explicit
website-only classification. Version bumps alone never advance review records.
When no prose review is needed, the workflow commits only the importer's known
generated files. It then validates source, reviews, website output and the plugin
package before publication.

After publication, the workflow imports the exact `--tag vX.Y.Z` with the same
channel, commits generated frozen-snapshot changes and runs `npm run deploy`.
That existing command verifies the newest published release (including betas),
channel and tag commit before building and again before uploading.

If the handoff or deployment is interrupted, run from the plugin source:

```sh
composer release:resume -- --tag vX.Y.Z
```

This downloads and checks the published ZIP/checksum, compares package files to
the tagged source, and resumes only the website handoff. It never publishes or
replaces assets. A frozen matching snapshot skips reimport on a deployment retry.
Normal website builds remain offline; the deployment publication guards are
online. The lower-level `composer release:github` remains available for a
prepared clean website, using the same local configuration.

## Contract and compatibility

`php docs/dev/generate-docs.php --website` emits format version 1. Numeric
limits come from runtime constants, defaults from their owning resolvers, and
publication metadata from EndpointRegistry. Supplemental REST registrations are
captured without invoking request handlers. Robots, IndexNow verification,
authorization and sitemap families are included. The route count explicitly
excludes admin screens, AJAX/admin-post actions, assets and legacy redirects;
it is not a count of enabled URLs on a particular installation.

The exporter can inspect an isolated source tree using
`CYBERMAPS_DOCS_SOURCE_ROOT`. This supports v7.4.2, which predates the exporter;
the website records the exporter hashes separately from the source commit.
Original tagged manifests and versioned schemas remain byte-for-byte snapshots.
The enriched website contract is published separately at `/product/website.json`.

When adding another route-registration owner or a new contract field, update
the exporter and its tests. The exporter rejects unknown REST registration
owners. Changes in explanatory behavior still need editorial review even when
all numeric facts remain the same. No automated check can certify arbitrary
natural-language claims: the review note records the engineer or bot's check.
