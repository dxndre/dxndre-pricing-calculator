# DX. Data Engine — local implementation

Implemented and verified on 27 November 2026. The Gym League is the first provider. The production emergency block, DNS, Cloudflare rules and production server configuration have not been changed.

The public page is an anonymous static HTML shell. A shared browser loader retrieves a small manifest and one immutable public JSON bundle. All eight views, filters, sorting, comparison, pagination, incremental results, finder and map/list switching then operate locally. Individual editorial reviews retain their existing URLs and templates.

The plugin owns the provider registry, strict validation, content hashes, publication locks, atomic file writes, bounded retention, CMS invalidation, administrator controls, CLI and event interface. The theme owns scoring and presentation. No database tables, external services, Redis, workers or paid CDN features are required.

## Read first

- [Architecture, audit and schema](Documentation/ARCHITECTURE.md)
- [Deployment, protection, reopening and rollback](Documentation/OPERATIONS.md)
- [Provider development and Sentinel integration](Documentation/PROVIDERS.md)
- [Measured results and verification limits](Documentation/VERIFICATION.md)
- [Changed files](Documentation/FILES.md)

Open **Business HQ → Infrastructure → DX. Data Engine** as an administrator, or **WordPress → Tools → DX. Data Engine**. Both use the same protected console. Nothing polls in the background.

```sh
wp dx-data list
wp dx-data status gyms
wp dx-data inspect gyms
wp dx-data generate gyms
wp dx-data validate gyms
wp dx-data invalidate gyms
```

`invalidate` queues controlled generation; administrative/CLI shutdown drains the queue. It is not a public regeneration endpoint. PHP 8.1+, DOM and mbstring are required; zlib enables precompressed sidecars. Use HTTPS in production for browser SHA-256 verification.

The local Nginx canonical route now serves the generated file directly, with a static 503 fallback when missing. The plugin also includes a compatibility fallback for servers without the rewrite, but that fallback still bootstraps WordPress. **The production server rewrite is a deployment requirement**, not something CDN caching alone replaces.
