# Deployment and recovery runbook

## Current state and external dependencies

Only the local `dxndre.local` installation has been changed. The production emergency Cloudflare block must remain active. No live traffic tests, origin bypass, DNS changes or production security changes were performed. Production LiteSpeed/Cloudflare configuration cannot be verified from this environment.

Local Nginx now handles `/fitness/gyms/` directly as static HTML, plus static dataset/manifest paths. Its configuration was syntax-checked before a local-only reload. Missing assets terminate without WordPress; the canonical page has a static 503 fallback. The persisted Local template and current runtime configuration both contain the route. See `examples/local-nginx.conf` for the tested server-context snippet. The PHP template-redirect fallback remains available on installations without a server route, but still incurs WordPress bootstrap and cannot serve through a database outage.

## Staged deployment — keep the block

1. Back up production theme/plugin files, existing web-server rules, relevant cache settings and database before deployment. Keep copies outside the public webroot. Record the emergency block and any temporary origin block, including their precedence and owner.
2. Use an authorized staging clone or hosting preview mechanism with the same PHP/LiteSpeed settings. Do not bypass the live block using origin IPs, hosts-file tricks or unapproved firewall exceptions. Local results alone do not authorize reopening.
3. Confirm PHP 8.1+, DOM, mbstring, writable upload storage, zlib if precompression is wanted, and a private directory outside `public_html`. Set `DX_DATA_PRIVATE_DIR` in configuration if needed. The web/CLI user must be able to atomically rename private staging files into uploads on the **same filesystem**. Do not use world-writable permissions.
4. Deploy `dx-data-engine/` and the theme files listed in `FILES.md` together. Build assets before generating the dataset. Do not upload the local public dataset: it contains local URLs. Activate the plugin, then run `wp dx-data generate gyms` and `wp dx-data validate gyms` on the target host. No table or index migration is required; the legacy transient is unused and may expire naturally.
5. Inspect the generated JSON and anonymous HTML while logged out. Check published record counts, scoring, public URLs, coordinates, public editorial excerpts, absence of drafts/password-protected posts, account names, business data, nonces and admin bars. Compare a logged-in response with the anonymous shell. Verify current/previous retention and private-path inaccessibility.
6. Install the scoped static route **before WordPress rewrite handling and plugin page-cache rules**. Verify actual HTTP headers and server access handling; an HTML page-cache HIT alone does not prove PHP was avoided on a MISS. Do not remove the emergency block yet.
7. Configure scoped CDN caching, purge the canonical shell/manifest and changed JS/CSS, and run browser tests on staging. Verify legacy query links, all eight views, comparison sharing/removal/replacement, finder, maps/list, no-JS fallback, mobile/light/dark, reviews and unrelated home/buses/calculator pages.
8. Check generation failure, maintenance isolation and rollback on staging. Confirm missing datasets and retired REST calls never fall through to WordPress. Establish normal host memory, database connections, workers, errors and request-volume baselines before any reopening decision.

## Apache/LiteSpeed shared-hosting route

These document-root rules are a **deployment template for the host/operator to validate**, not installed production configuration. Adapt paths for nonstandard uploads or a subdirectory install. Keep the canonical page anonymous for every visitor. Place before WordPress and any broad cache rules. Do not apply Cache Everything to private dashboards, login, checkout or administrator pages.

```apache
# Canonical public Gym League. Never execute WordPress to serve this shell.
RewriteEngine On
RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$
RewriteRule ^fitness/gyms/?$ - [R=405,L]

RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/dx-data/gyms/index.html -f
RewriteRule ^fitness/gyms/$ wp-content/uploads/dx-data/gyms/index.html [L]

# Missing shell: web-server error response, never a WordPress route.
RewriteRule ^fitness/gyms/$ - [R=503,L]

# Retire the old dynamic renderer before PHP.
RewriteRule ^wp-json/dxndre/v1/gym-dashboard/?$ - [R=410,L]

# Missing generated files must not be rewritten to index.php.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^wp-content/uploads/dx-data/ - [R=410,L]
```

Ensure the host's 503 ErrorDocument is static or inline, not a PHP handler; for example configure the relevant virtual-host/directory error context with `ErrorDocument 503 "Gym information is temporarily unavailable. Please return shortly."`. This may affect other 503 responses in that scope, so let the host choose the narrow context. Keep an independent static maintenance response available during a WordPress/MariaDB outage. Handle slash normalization at the server too; never redirect fragment state away from the browser. Legacy query strings may remain in the browser URL during the internal rewrite: the static file ignores them and the application migrates them to the fragment.

The generated upload-root `.htaccess` sets `nosniff`, noindex for JSON, immutable year-long caching for versioned JSON, 60-second revalidation for shell/manifest, and gzip where mod_deflate is enabled. Confirm the hosting account permits these directives; unsupported `Options`/header directives can cause HTTP 500 and must be adjusted by the host. The HTML canonical page must **not** inherit JSON's noindex header. Do not serve `.gz` as plain text: enable negotiated server compression (or correctly configured gzip-static) and `Vary: Accept-Encoding`. The precompressed sidecar alone does not enable compression.

Static delivery does not require LSCache. Keep authenticated/private page-cache exclusions intact. Do not add a separate LSCache HTML variant for each gym query state; collapse the public shell to one representation, or bypass page-cache machinery for this directly served file. Respect standard `Cache-Control` headers and inspect any `X-LiteSpeed-Cache-Control` overrides. [LiteSpeed cache controls](https://docs.litespeedtech.com/lscache/devguide/controls/), [LiteSpeed cache configuration](https://www.litespeedtech.com/docs/webserver/config/cache).

On the roughly 1 GB shared account, run regeneration serially, retain the measured payload limits, and keep the hosting execution limit in place. Do not respond to error 1203 by blindly raising `max_user_connections`, PHP workers or memory allowances. Ask the host for actual account entry-process, CPU, memory and database-connection limits, error logs and a scoped origin rule. This implementation requires neither root access nor Redis nor a daemon. A provider deadline is checked after its trusted callback; it cannot interrupt a stuck extension. Host execution limits remain necessary.

## Cloudflare Free — recommended draft rules

The following configuration is for an operator to apply **after authorized staging verification**. Check the actual zone's available rules and existing order first.

- Keep the emergency Block rule unchanged during deployment. A later challenge rule cannot override an earlier terminating block. Free currently supports five custom rules and Managed Challenge; regex is unavailable on Free. Use exact paths or `starts_with`. [Custom-rule availability and order](https://developers.cloudflare.com/waf/custom-rules/).
- Create a scoped Cache Rule matching the canonical page, direct static shell and `/wp-content/uploads/dx-data/gyms/` assets. Select **Eligible for cache**; use the origin's 60-second policy for mutable shell/manifest and immutable policy for hashed payloads. Free currently supports ten Cache Rules. Do not cache an error/challenge response as successful data, and keep cookie/session-bearing endpoints outside the rule. [Cache Rules](https://developers.cloudflare.com/cache/how-to/cache-rules/).
- For these public static resources only, select **Cache Key → Ignore query string**. This setting is currently available on Free. HTML eligibility still needs its explicit Cache Rule: the older global caching-level setting alone ignores queries only for recognized static extensions. Do not ignore queries globally across WordPress. [Cache keys](https://developers.cloudflare.com/cache/how-to/cache-keys/), [Caching levels](https://developers.cloudflare.com/cache/how-to/set-caching-levels/).
- After approval to reopen, replace the targeted emergency HTML block in a controlled change with a targeted Managed Challenge for `/fitness/gyms/`, keeping other protections. Exclude static JSON from interactive challenges, which would break data fetches. Protect the retired REST route with a terminating block, and retain origin safeguards. Review available rate-limiting controls and choose thresholds from legitimate traffic rather than guessing capacity. Free-plan rate-limiting capabilities are limited; do not assume enterprise matching, bot scores or custom counting. [Rate-limiting controls](https://developers.cloudflare.com/waf/rate-limiting-rules/).

Suggested exact-path custom expression for the human-facing canonical route:

```text
http.request.uri.path in {"/fitness/gyms" "/fitness/gyms/"}
```

Keep immutable assets cacheable even if the HTML is challenged. Confirm `CF-Cache-Status`, `Age`, response type, compression, revalidation and MISS behavior through a small authorized staging sample. Cookies or conflicting later rules can change eligibility; inspect the actual response rather than assuming a HIT. Respect host/origin protections and never trust a client-supplied Cloudflare header as proof that traffic came from Cloudflare. Ask the host to restrict direct-origin access where supported while preserving approved administration/health access.

## Cache invalidation, withdrawal and emergency isolation

A CMS withdrawal removes all origin dataset history and replaces the shell/manifest with maintenance content. **It does not purge Cloudflare automatically.** Purge the canonical URL, direct shell, manifest and all withdrawn immutable dataset URLs (including retained versions), plus the public Command shell when relevant. Capture asset names before deletion if using URL-specific purge. Account for custom cache keys; use supported purge mechanisms and verify anonymous responses. Where exact variant purge cannot be guaranteed, use the zone's available broader purge deliberately. [Cloudflare purge limitations](https://developers.cloudflare.com/cache/how-to/cache-rules/).

Browser HTTP caches and offline public copies cannot be remotely recalled. A reachable maintenance manifest clears this engine's localStorage fallback, but an already open/offline page can retain public data until reload. Do not put confidential or revocable secrets into a public provider. The 60-second mutable TTL is an upper cache window, not instant global revocation.

Business HQ controls distinguish **generation suspended** (keep current publication) from **consumption isolated** (write maintenance shell/manifest). Isolating does not delete CMS content. Reopening consumption regenerates from current eligible sources and still requires generation enabled. Pruning removes obsolete generated versions, never posts. Isolation and pruning require explicit confirmation and administrator nonce/capability checks. There is intentionally no public purge/regenerate route or one-click permanent dataset deletion.

During a complete WordPress/database outage, use the existing Cloudflare emergency block or the host's static route/maintenance file; the administrator console and WP-CLI cannot be relied on. Replace files atomically, keep a maintenance manifest with `available:false`, purge CDN copies and leave the origin protected. Never fall back to dynamic rendering as an outage workaround.

## Controlled reopening and rollback

Reopening is an **external, explicitly authorized operation**. First verify deployment on staging, then agree a monitored window with the operator/host. Change one protection layer at a time. Account for any temporary origin block before expecting the CDN route to succeed. Observe real account resource metrics and origin requests, not a synthetic health score. Keep a prepared emergency Block rule and static maintenance response ready.

Immediately isolate again for recurring MariaDB 1203, physical memory exhaustion, sustained abnormal PHP/entry-process growth, significant 500/503 increases, unexpected origin volume, or a sharp loss of cache effectiveness. Establish normal values and alert windows from the host's actual baseline; no production thresholds or capacity claims were measured here. Static delivery reduces PHP/database amplification but cannot make the site immune to DDoS or bandwidth exhaustion.

Rollback while the block remains: restore backed-up theme/plugin assets as a matching set; restore server rules only after deciding whether the old dynamic page must remain isolated. Prefer a static maintenance page to reopening the old renderer. Restore a retained dataset only if independently verified safe and still entirely public; **never restore withdrawn content**. Purge affected CDN/browser-facing mutable assets, validate URLs and schemas, and re-check unrelated pages. Keep private metadata backups private. No database schema rollback is needed; local verification fixtures have already been removed.
