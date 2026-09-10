# Contributing to Cybermaps

Thanks for helping improve Cybermaps. Bug reports, documentation corrections,
compatibility findings, tests, and focused code changes are welcome.

## Before opening an issue

- Search existing issues and the [support guide](SUPPORT.md).
- Confirm the behavior with the current release.
- For security issues, follow [SECURITY.md](SECURITY.md) and do not open a
  public issue.
- Keep product support, feature proposals, and reproducible bugs separate so
  each thread has a clear outcome.

## Local setup

Cybermaps requires PHP 8.2+ and targets WordPress 7.0+. PHP 8.3+ and WordPress
7.1+ remain the recommended development pair.

```bash
composer install
composer test
composer run lint:phpcompat
composer run lint:phpcs
```

The test suite supplies its own WordPress stubs. Changes that depend on a real
WordPress lifecycle changes should be checked on both WordPress 7.0/PHP 8.2 and
WordPress 7.1/PHP 8.3 installations.

## Pull requests

1. Keep the change focused and explain the user-visible reason for it.
2. Add or update tests when behavior changes.
3. Run `php -l` on every PHP file you touch.
4. Run `composer test` and `composer run lint:phpcs`.
5. Regenerate `docs/dev/manifest.json` after public-surface changes.
6. Regenerate the AI configuration schema and catalog after registry changes.
7. Update `readme.txt` and `docs/documentation.md` when public behavior changes.

Generated files under `clean/` and historical files under `freemius/` are not
source and must not be edited by hand.

## WordPress.org constraints

- Core must remain complete and standalone.
- Do not add licensing SDKs, premium gates, external update mechanisms, or
  disabled upsell controls.
- Use WordPress filesystem, escaping, sanitization, capability, and nonce APIs.
- Do not introduce remote telemetry. Existing optional analytics remains local
  to the WordPress installation.
- Every visible control and documented URL must work with only Cybermaps active.

## Coding conventions

- PHP source uses `declare(strict_types=1)`.
- Production classes use the `Cybermaps\` namespace and live under `src/`.
- Prefer the existing endpoint registry, publication eligibility, configuration
  store, and shared constraints over parallel implementations.
- Preserve backward compatibility unless a breaking change is explicitly
  proposed and documented.

By participating, you agree to follow [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## Release metadata

Before publishing a new version, keep the plugin header and version constant,
`readme.txt` stable tag/changelog/upgrade notice, `changelog.txt`, and the
technical documentation version stamp synchronized. Regenerate the manifest,
AI configuration contracts, and translation template:

```bash
php docs/dev/generate-docs.php > docs/dev/manifest.json
php docs/dev/generate-docs.php --ai-schema > docs/dev/ai-configuration/schema.json
php docs/dev/generate-docs.php --ai-catalog > docs/dev/ai-configuration/catalog.json
composer run i18n:generate
composer run release:check
```

Builds and checks run locally. See the README for GitHub publishing commands.
