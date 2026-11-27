# Local verification and measured results

Date: 27 November 2026. Host: local macOS WordPress installation, PHP 8.3.23 and Nginx 1.26.1, Chromium through Playwright. Production was not tested or changed. Evidence files contain actual local results, not simulated production measurements.

## Before and after

| Measurement | Before | After |
| --- | --- | --- |
| Public UI actions: sort, navigate, compare (including automatic additional results) | 4 dashboard REST requests | 0 dynamic dashboard requests across the broader regression flow |
| Cold PHP component read/state/overview render | 92.17 ms, 85 queries | Public static route executes no PHP; disk-only compatibility component 11.95 ms, 0 queries |
| Warm original PHP component | 0.93 ms, 0 queries | No warm PHP component needed for the public route |
| Controlled dataset generation | No separate publication pipeline | 478.42 ms, 78 queries; PHP process peak 148,701,184 bytes |
| Public records | 59 gyms, 63 visits | Same 59 gyms, 63 visits |
| Bundle | Dynamic HTML per interaction | JSON 1,843,343 bytes; gzip sidecar 124,393 bytes (~121.5 KiB); manifest 472 bytes |
| Static anonymous shell | Request-rendered view | 109,386 bytes, same representation for legacy query variations |
| Seven sampled local client renders | Server-render/fetch path | 3.1–24.7 ms for template/filter/DOM serialization work |

Component timers start **after WordPress bootstrap**. PHP peak memory is a process peak, not incremental allocation. The static compatibility reader decodes the full bundle and can be slower than the old already-warm transient; it is not used by the Nginx public route. Controlled generation is intentionally heavier than the old cold record read because it validates and pre-renders all views. Its cost has moved away from visitor interactions to approved CMS maintenance.

Browser elapsed waits in the old action script are not interaction latency benchmarks. Client render timings exclude browser paint, existing transition animations, images and network startup. A single local sample cannot predict shared-host capacity or production percentiles.

The final direct canonical HTTP sample returned 200 in 9.66 ms with `X-DX-Data-Shell: nginx-static`, no PHP header, gzip and 60-second revalidation. Conditional GET returned 304. A bounded three-request static sample returned 200 for all three (24.03–32.52 ms). Startup/connection effects made the first separate direct-file sample slower; these are diagnostic observations, not a claim that one path is always a specific multiple faster. See `evidence/http-delivery.json`.

The initial PHP compatibility fallback was also tested before the local Nginx route: it returned the static file but still started WordPress. That distinction prompted the local server configuration. Production requires equivalent LiteSpeed setup before the same zero-bootstrap claim applies there.

## Completed suites

| Suite | Actual result | Scope |
| --- | --- | --- |
| Independent engine fixtures | 28/28 pass | Invalid/duplicate registration, classification, dependency/schema restrictions, initial validation, unchanged publication, restricted/missing fields, non-leaking error codes, empty data, corruption, missing/mismatched manifest, lock contention, failed rename, retention, controls, withdrawal, failure isolation, byte/deadline budgets, source mutation, unsafe shell, bounded events, interrupted private staging and killed-worker recovery |
| Dashboard browser suite | 31/31 pass | All eight views, state normalization, filters/sort, comparison, local controls, history/refresh, legacy URLs, six widths, no JS fallback, zero dynamic dashboard requests and no page errors |
| Shared loader/failure suite | 11/11 pass | In-flight/memory dedup, stale network recovery, maintenance tombstone, corrupt JSON, schema mismatch, bounded retry, timeout, cancellation, blocked map library, incremental results, failed client module |
| Additional UI/authentication | 9/9 pass | Comparison replacement/sharing, finder steps/results, light mode, Business HQ console, missing nonce 403, identical anonymous/authenticated shell, CLI-only test HTTP 404, real pricing-calculator route 200 |
| Actual CMS lifecycle | 4/4 true | Publish marks dirty and appears; unpublish immediately tombstones/purges history; rebuilt publication excludes it. Temporary local post deleted in finally cleanup |

Engine fixtures use temporary directories, not live public artifacts. Lock contention uses a separate lock handle holding an exclusive OS lock. The staging case deliberately leaves an incomplete private fixture and verifies it cannot appear publicly. A separate forked worker was killed with SIGKILL during its build callback: the previous publication remained valid, its OS lock was released, and subsequent generation recovered. This optional test requires pcntl/posix and passed on this local host; it does not simulate a power loss during filesystem rename. Publication failure uses an unwritable destination shape (a directory in place of the manifest), not a production disk outage. No actual disk-full, power-loss, MariaDB shutdown or distributed-filesystem test was performed.

The HTTP boundaries were also checked: POST canonical 405; retired renderer 410; anonymous administrator mutation rejected; hashed JSON has year-long immutable cache headers, JSON MIME type and no PHP header; missing hashed asset returns 410 without PHP. Temporarily renaming the local shell produced a static 503/no-store response without PHP; the file was immediately restored in finally cleanup and validated afterward. The manifest MIME/cache policy and JSON noindex configuration were checked separately. Unknown CLI provider exits 1 with an explicit code.

Syntax checks passed for plugin PHP, adapter/template PHP and client ES modules. `git diff --check` passed. The theme production build passed with 226 existing Sass/asset-size warnings; the global theme bundle remains large (about 2.25 MiB CSS and 608 KiB JS before transfer compression). This refactor does not claim to have removed that pre-existing asset cost.

## Regression observations

Desktop/tablet/mobile widths: 320, 390, 768, 1024, 1440 and 1920 pixels. No document horizontal overflow in the tested Explorer state. Screenshots were inspected at mobile/tablet/desktop; missing core screen-reader styling in the standalone shell was found and fixed, then the suite rerun. A fresh, settled mobile light-mode page was also inspected. The local “Instant Reload” development overlay in screenshots comes from Local tooling, not the published shell. It is not part of the product design.

Reduced-motion was exercised in the main browser suite; normal animation code remains the existing theme controller. Finder steps, selected amenity matching, additional results, comparison replace/share and light mode were explicitly exercised. Review links remain original editorial destinations. The Fitness landing-page quick search found Chigwell using the shared bundle with zero dashboard REST requests. Home, buses and fitness pages returned 200; `/pricing-calculator/` returned 200. An initial probe of `/calculator/` returned 404 because that is not this site's calculator slug; it is not reported as a regression or a passing calculator test.

Map library failure was explicitly intercepted and the list remained usable. The normal map view also rendered its list and requested lazy assets. External tile accuracy, real-time opening hours and live provider availability are outside this local deterministic verification. No production Cloudflare HIT ratio, LiteSpeed cache state, hosting RAM/worker limits or MariaDB connection metrics were available. Browser validation on actual Safari/Firefox and physical mobile hardware remains a deployment regression step; Chromium viewport emulation is not equivalent to those devices.

## Reproducing bounded checks

From the plugin directory, in the local environment:

```sh
php Tests/engine.php
node Tests/browser.cjs
node Tests/loader.cjs
wp eval-file Tests/cms-lifecycle.php
wp eval-file Tests/measure.php
wp dx-data validate gyms
```

Browser scripts deliberately use `http://dxndre.local` and the existing theme's Playwright dependency. The CMS lifecycle script refuses non-CLI/non-`.local` execution and creates one temporary local published fixture before removing it. Never run it on production. `measure.php` performs controlled generation and requires WP-CLI. PHP test harnesses reject HTTP access. Run `wp` from a configured Local site shell (or supply its PHP/database socket configuration).

The authenticated checks used a short-lived local administrator cookie generated inside the local CLI, kept in a mode-0600 temporary file and deleted after testing. No cookie/token is included in evidence. The screenshot of the administrator console is not shipped because unrelated account/consent UI is not useful verification material.

The production block remains the safe default until authorized staging deployment and hosting validation. There was no flood testing, aggressive crawl or live incident reproduction.
