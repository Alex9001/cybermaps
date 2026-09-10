# Cybermaps Cloudflare OAuth Relay

This independently deployed Worker gives the WordPress plugin one fixed public
OAuth callback without proxying Cloudflare API requests. It stores a PKCE-bound
authorization code for at most five minutes. WordPress holds the verifier,
exchanges the code directly with Cloudflare, immediately revokes the access
token, and never sends a site URL or WordPress identity to this service.

## Provision the public Cloudflare client

1. Create a Cloudflare OAuth client named Cybermaps with response type code,
   grant type authorization_code, and token authentication method none.
2. Register only https://connect.cybermaps.dev/cloudflare/callback as its
   redirect URI and require PKCE S256.
3. Select the current OAuth scope IDs displayed as Zone Read, Zone Transform
   Rules Write, and Cache Settings Write. Fetch the current IDs from
   GET /client/v4/oauth/scopes; never guess or reuse stale identifiers.
4. Set the client URL, logo, privacy policy, and terms URLs on cybermaps.dev,
   complete DNS publisher verification, and promote the client to public.

## Deploy

Copy wrangler.toml.example to wrangler.toml. In the oreshkin account, set:

    wrangler secret put CLOUDFLARE_OAUTH_CLIENT_ID
    wrangler secret put CLOUDFLARE_OAUTH_SCOPES
    wrangler secret put CLOUDFLARE_OAUTH_REDIRECT_URI
    wrangler deploy

Set CLOUDFLARE_OAUTH_SCOPES to the exact space-delimited dot-form scope names
returned by Cloudflare. Set the redirect value to the exact callback above.
Before production use, configure a rate-limiting rule for POST
/v1/cloudflare/, starting at ten requests per minute per source IP.

Do not enable CORS or request logging that records callback query strings.
Monitor only aggregate status codes, latency, Durable Object errors, and
rate-limit events. Run dependency-free contract tests with npm test.

## Capacity and abuse controls

Production uses adaptive 2/3/5/8/10-second polling, a 48-poll transaction cap,
per-IP transaction creation limits, per-transaction poll limits, and an exact
1,500-transaction UTC daily admission budget. Rate-limited responses include
`Retry-After`. The IP address is salted and hashed before it is used as a rate
limit key; it is never stored in transaction state. Stateful routes also have
hashed per-IP and global per-location burst limits so rotating fake transaction
IDs cannot force unbounded Durable Object creation. Workers Observability logs
rate-limit events and the first crossing of 80 percent of the daily budget.

`RATE_LIMIT_SECRET` is required in production and must be installed with
`wrangler secret put RATE_LIMIT_SECRET`; never commit it to this directory.
