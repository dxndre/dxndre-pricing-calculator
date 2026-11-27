# Architecture, audit and public contract

## Original implementation and failure amplification

The audit inspected `inc/gym-dashboard.php`, `templates/gym-dashboard.php`, the consolidated gym controller in `assets/main.js`, `inc/dx-command.php`, `inc/site-footer.php`, the finder controller and the existing Business HQ shell.

`dxgym_data()` previously rebuilt published review records through WordPress/ACF and cached them in `dxgym_records_v3`. A cold component sample made 85 queries for 59 gyms/63 visits. `dxgym_state()` called this path before comparison validation. `dxgym_view()` rendered the requested view, listings, filters, map JSON and pagination. The public `dxndre/v1/gym-dashboard` REST endpoint repeated HTML rendering on navigation, filtering, sorting, comparisons and additional results. A bounded three-action browser sample generated four dynamic requests because incremental loading also ran.

Query-state links exposed combinations of views, comparison permutations, filters, search and pages. Several inputs were insufficiently bounded. These URLs multiplied PHP work and cache entries even when the source data was unchanged. Map libraries were loaded eagerly. Shared footer markup could include authenticated client greetings and business links, making ordinary logged-in page snapshots unsafe for a public cache.

The inspected code used WordPress data access rather than separate database connections. The incident report and code establish a resource-amplification risk; they do **not** establish SQL injection, identify an attacker, or prove the sole cause of the production incident. No production logs or hosting metrics were available for forensic attribution.

## Current request and generation paths

```
CMS save / explicit administrator or CLI operation
    -> dirty marker + one bounded queue/fallback event
    -> provider build using existing scoring/renderers
    -> strict allowlist validation + count/size/time checks
    -> content hash + private staging + atomic rename
    -> immutable dataset and anonymous shell
    -> public manifest committed last

Browser -> static canonical shell -> manifest -> immutable bundle
    -> in-memory records + cloned presentation fragments
    -> local state/rendering; no dashboard REST rendering
```

`dxgym_build_records()` is the controlled CMS adapter. It selects published, password-free `gym-review` posts, requests at most 2,001, and rejects above 2,000 before transformation. It preserves existing latest-visit grouping, facility scoring, bands, imagery, opening-hours interpretation and editorial links. `dxgym_data()` remains a disk-only compatibility reader for other theme components; it never regenerates. Obsolete transient invalidation hooks have been removed. The old REST renderer returns HTTP 410; production rules should reject it before PHP too.

The bundle includes approved records plus pre-rendered view/record fragments from existing theme functions. This deliberately preserves the established high-fidelity markup instead of maintaining a second rendering system. The raw bundle is larger than a records-only API, but compresses substantially. Trusted renderer HTML is not an arbitrary CMS export: record text is escaped by the existing renderers and the provider explicitly selects every field. Review bodies, account records and arbitrary post metadata are not exported.

The static template omits `wp_head`, `wp_footer` and general `wp_body_open` hooks. It directly includes versioned theme assets, the named public Command index and an explicitly anonymous footer. Logged-in and anonymous HTTP responses were tested byte-identical. The public Command index includes only fixed destinations and published, password-free public content, with its existing 120-item cap. Its source post types also invalidate this provider. Analytics, WordPress admin bars, authentication settings and session nonces are not captured.

## Dataset schema v1

The definitive machine-readable schema is declared in `Providers/Gyms.php` and enforced before publication by `Core/Engine.php`.

| Object | Approved fields |
| --- | --- |
| Manifest | `dataset`, `schemaVersion`, `version`, `generatedAt`, `records`, `bytes`, `checksum`, `file`, `available`, `freshness` |
| Envelope | `dataset`, `schemaVersion`, `version`, `generatedAt`, `data` |
| Data | `gyms`, `reviews`, `visits`, `views`, `fragments`, `assetVersion` |
| Gym/review | `id`, `root`, `title`, `name`, `chain`, `chainKey`, `location`, `scores`, `overall`, `band`, `url`, `image`, `imageSmall`, `date`, `dateISO`, `published`, `publishedISO`, `excerpt`, `lat`, `lng`, `visits`, `price`, `open24`, `padel`, `rank`, `hours` |
| Scores | `gym`, `swim`, `spa`, `cafe`, `clean`, `parking`: 0–10, null or the explicit `unavailable` sentinel |
| Band | `label`, `color`; overall is 0–100 or null |
| Hours | `source`, `label`, `google`, `checked`, `sourceType`, `basis`, `open24`, `weekly`; weekly is nullable with at most seven pairs of strings |
| Views | `overview`, `league`, `explorer`, `compare`, `finder`, `map`, `reviews`, `insights` |
| Fragment | `id`, `row`, `card`, `reviewCard`, `compareCard`, `quickCard` |

IDs are positive integers. Coordinates are null or bounded latitude/longitude. Reviews and gyms are bounded to 2,000; strings and nested arrays have explicit limits. Every object rejects undeclared keys. No passwords, API keys, private notes, emails, billing data, drafts or private posts are intentionally allowed. `excerpt` is a short plain-text extract from the existing **public editorial review notes** field, not client/internal notes. Opening-hour references are public metadata, not real-time availability guarantees.

Public storage is `wp-content/uploads/dx-data/<provider>/`. Private metadata, dirty markers, locks and the last 100 events default to `dirname(ABSPATH)/dx-data-private`, outside the webroot with directory mode 0700. `DX_DATA_PRIVATE_DIR` can override it. Staging must be on the same filesystem as the public destination; a cross-device atomic rename fails closed.

## Publication, invalidation and recovery

A provider-exclusive filesystem lock prevents overlapping generation. Controls use the same lock. Data is validated, encoded and hashed; version SHA-256 covers schema version and deterministic payload, excluding generation timestamps. Each envelope has its own SHA-256 checksum. Unchanged content keeps the existing manifest and immutable file identity. Existing files are checked before declaring an unchanged generation healthy.

Each file is staged privately, byte-verified and atomically renamed. Data/gzip and optional shell are written before the manifest commit. This prevents partially written public files; it is not a filesystem-wide multi-file transaction. The shell and retained data use the same schema, and manifest validation remains the consumption boundary. A crash between public commit and private bookkeeping can require validation/regeneration. Failed attempts remove uncommitted orphan payloads while preserving committed assets. Abandoned private staging files older than one hour are removed during controlled maintenance.

Normal source edits retain the current known-good publication until a validated replacement is ready. Successful generations retain current and previous versions only, including gzip sidecars; logs retain 100 events. A failed build never replaces valid data with an empty bundle. If no valid dataset has ever existed, the static shell/maintenance response remains readable and no public request triggers a build.

Unpublishing, deletion or adding password protection is different: invalidate first, take the provider lock, replace manifest and shell with maintenance content, delete **all** historical public payloads, then rebuild from eligible sources. This prevents a retained version from resurrecting withdrawn content. CMS lifecycle tests verified this with a temporary local post, then deleted it. Attachment deletion also withdraws affected datasets conservatively. External CDN copies must still be purged; downloaded public information cannot be recalled from an offline device. Browser stale storage is explicitly not a privacy revocation mechanism.

Save/meta/ACF/taxonomy/attachment events debounce through one request queue. Administrative or CLI shutdown drains at most three providers sequentially. A single scheduled event is a fallback, attempts once and never reschedules itself. Purely static visitors do not drive WordPress cron; use controlled CLI maintenance if cron is disabled or there is no administrative follow-up. Theme, schema, public Command templates, opening-hours JSON or field-definition deployments require explicit regeneration. Dependent providers must be registered first; invalidation propagates, and missing dependencies prevent generation.

## Browser and URL state

The shared loader deduplicates concurrent loads and keeps an in-memory Promise. It fetches same-origin static JSON with credentials omitted, bounded streaming reads (32 KB manifest, up to 8 MB data), an 8-second request timeout and at most one manifest retry. The gym provider itself caps payloads at 4 MB. It validates envelope identity/schema/version, byte length and module records. SHA-256 verification runs where `crypto.subtle` is available, including production HTTPS; HTTP `dxndre.local` does not provide that cryptographic check.

Optional localStorage retains one bundle per loader key for up to 24 hours (under two million characters); storage denial is harmless. It is used only after failed retrieval and is marked stale. Explicit isolation, 403 or 410 clears that cache and fails closed. Caller cancellation stops that caller waiting without cancelling a download shared by another consumer. No service worker, polling loop or regeneration API is introduced.

Canonical URL: `/fitness/gyms/`. State lives after `#`, so it is never sent in HTTP requests. Old query links are read once, normalized and replaced with fragment links. Comparison IDs are known latest published gym IDs, unique, numerically sorted and capped at three. Removing/replacing/clearing and sharing use the same normalization. History Back/Forward and refresh preserve normalized state.

Allowed keys: `view`, `compare`, `page`, `display`, `sort`, `q`, `price`, `open24`, `location`, `facility`, `chain`. The client rejects malformed percent escapes, repeated keys, control characters, more than 16 pairs and states longer than 2,048 characters. Key limits: view/sort/facility/price 16; query 80; chain/compare 64; location 120; page 4; display 8; open24 1. Enums, known chains/locations and safe IDs are checked; page is 1–9,999 then clamped to available results. Unknown/nested keys are ignored. Default values are omitted from generated URLs. Stateful table/compare actions are semantic buttons, preventing comparison permutation crawl links. Canonical metadata and ordinary review links remain available without JavaScript.

Maps reuse the same filtered records. Leaflet is loaded lazily when the map is in view, with a bounded failure state. Pan/zoom/popups require no PHP. Tile/library requests remain external and fall back to the list when unavailable. Existing scoring motion, sharing, drawer behavior, light/dark theme, finder and responsive presentation are retained.
