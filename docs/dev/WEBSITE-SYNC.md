# Website documentation release handoff

The plugin owns runtime facts. The Astro repository owns explanatory guides.
The local release workflow validates both against one immutable Git commit.
GitHub Actions remain disabled.

## Prepare a release

1. Update and validate the plugin, regenerate its manifest and configuration
   contracts, and commit the release candidate. Public routes and publication
   limits are included in `docs/dev/manifest.json`; `--website` also exports
   deterministic runtime source hashes for editorial review dependencies.
2. In the Astro checkout, import the committed candidate:

   ```sh
   npm run docs:sync -- /path/to/cybermaps --commit FULL_COMMIT_SHA --channel beta
   ```

   For an existing published version, use `--tag v7.4.2` instead. `--channel
   stable` selects a stable release explicitly. Imports use a Git archive and
   ignore uncommitted plugin work. An unpublished candidate may be revised and
   reimported at a new commit with the same version. Importing a tag freezes its
   snapshot, after which the version cannot be repointed. Normal website builds
   are offline.
3. Read the sync report, review each affected guide against changed source and
   facts, update its explanation, and record what was checked:

   ```sh
   npm run docs:review -- --page docs--machine-publications.md --note "Reviewed candidate bounds, output limits and retry behavior."
   npm run build
   ```

   Sync never approves prose. A review binds the guide's bytes and declared
   source dependencies to a source fingerprint, commit, version and date.
   New source files matching a dependency also invalidate the review. New
   documentation pages require a dependency mapping or an explicit website-only
   classification. Keep historical guides and contracts in their original form.
4. Commit the reviewed website changes. From the plugin checkout publish using:

   ```sh
   composer run release:github -- --website /path/to/cybermaps-astro
   ```

   `CYBERMAPS_WEBSITE_DIR` may supply the checkout path. Use `--stable` with a
   stable website import. The publisher requires the same source commit and
   channel, verifies imported bytes against Git, checks reviews, and builds and
   verifies the website before creating a release tag or publishing assets.
5. Import the published tag with `docs:sync -- /path/to/cybermaps --tag vX.Y.Z`
   (and the same channel), then commit the frozen website snapshot. Deploy
   through the existing `npm run deploy` command. It requires that frozen state and verifies
   that the imported version is the newest published GitHub release, including
   open betas, and its tag still resolves to the imported commit. This check runs
   before the build and again before uploading. If deployment fails, the existing site remains on its
   honestly labeled snapshot; retry the website deployment with the same release.

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
