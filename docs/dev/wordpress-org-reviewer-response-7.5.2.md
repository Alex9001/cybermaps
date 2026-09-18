# WordPress.org reviewer response — Cybermaps 7.5.2

Thank you for the detailed review. Cybermaps 7.5.2 addresses every reported
item and includes a repository-wide audit for the same patterns.

- Report CSS now ships as an enqueued stylesheet; report markup is filtered
  through a narrow allowlist and the five existing themes use body classes.
- Administrative handlers verify the request method, capability, and nonce
  before reading action data. Decoded setup data now has an exact bounded
  schema, and the applied configuration is regenerated and hash-verified on
  the server.
- Public OpenAPI negotiation remains nonce-free and read-only through a bounded
  request adapter. The client-IP filter now receives only its documented,
  sanitized IP-header context, and its result is revalidated as a canonical IP.
- Public JSON, XML, RSS, Markdown, text, CSV, and report HTML now pass through
  audited contextual protocol emitters. The one unavoidable final protocol
  write is fixed in a machine-checked allowlist.
- Dynamic SQL lists use one prepared placeholder per value. Direct access to
  WordPress `_transient_` option names was removed in favor of the Transients
  API fallback. Permitted WordPress includes are loaded immediately before the
  API that requires them.
- The `Cybermaps\` namespace and `cybermaps_` prefix remain unchanged because
  they are plugin-specific. The reserved WordPress transient-option prefix was
  the defect, and that use has been removed.

The release adds fail-closed source and annotation-blind PHPCS gates for inline
assets, request boundaries, exact decoded schemas, output escaping, nonce
verification, unsafe SQL, transient option names, include ordering, and
high-risk suppressions. These gates are part of both local release validation
and the GitHub publisher; no GitHub Actions were added.

The exact `cybermaps_7.5.2.zip` was installed and activated in a disposable
WordPress 7.1 / PHP 8.2 environment with `WP_DEBUG` enabled. Official Plugin
Check 2.0.0 completed both new-plugin and experimental checks with no findings,
the endpoint/report smoke tests and native Cybermaps ability-registration checks
passed, and the debug log remained clean.
