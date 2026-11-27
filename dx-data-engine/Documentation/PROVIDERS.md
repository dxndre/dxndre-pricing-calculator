# Provider development and future integration

## Registration contract

Register trusted code through `dx_data_register`; the Gym provider is registered immediately before that hook. The engine itself has no gym-specific retrieval or scoring logic. The canonical route adapter and gym provider are kept separate from `Core/Engine.php`. The plugin remains useful if a different theme supplies future providers; gym generation fails safely if its theme adapter is absent.

Provider keys:

| Key | Meaning |
| --- | --- |
| `id`, `name`, `module` | Stable slug, human label and owning module |
| `classification` | Must be `public_static`; all other classifications are rejected |
| `schemaVersion`, `schema` | Positive integer and explicit strict schema |
| `build`, `count` | Trusted callbacks returning an allowlisted array and integer record count |
| `postTypes`, `taxonomies`, `metaKeys` | CMS invalidation dependencies |
| `dependencies` | Other provider IDs, registered earlier; prevents cycles/forward dependencies |
| `maxBytes`, `maxSeconds`, `minRecords`, `retain` | Resource/failure budgets; retain 1–2, shared max 8 MB, deadline at most 30 seconds, nonempty minimum |
| `freshness`, `manifestTTL` | Descriptive change policy and intended cache TTL; web-server headers must match it |
| `shell` | Optional callback producing anonymous static HTML; no authenticated hooks or arbitrary session capture |

Example for a genuinely public catalogue (documentation only; no placeholder provider is installed):

```php
add_action('dx_data_register', function (\DX\Data\Engine $engine) {
    $engine->register([
        'id' => 'public-catalog',
        'name' => 'Public catalogue',
        'module' => 'Portfolio',
        'classification' => 'public_static',
        'schemaVersion' => 1,
        'postTypes' => ['project'],
        'metaKeys' => ['public_summary'],
        'schema' => [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'items' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 200,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'title' => ['type' => 'string', 'maxLength' => 200],
                        ],
                    ],
                ],
            ],
        ],
        'build' => function () {
            $posts = get_posts([
                'post_type'=>'project', 'post_status'=>'publish',
                'has_password'=>false, 'numberposts'=>201,
            ]);
            if (count($posts)>200) throw new RuntimeException('generation_record_budget');
            return ['items'=>array_map(static fn($post)=>[
                'id'=>(int)$post->ID,
                'title'=>wp_strip_all_tags(get_the_title($post)),
            ], $posts)];
        },
        'count' => static fn($data)=>count($data['items']),
        'maxBytes' => 262144,
        'maxSeconds' => 10,
        'freshness' => 'On approved public project changes',
    ]);
});
```

The validator is a deliberately small schema subset, not a complete JSON Schema implementation: object/array/string/integer/number/boolean/null, required keys (all declared keys by default), additional-key rejection, enum, nullable, explicit sentinel, ranges, length/count limits and maximum nesting depth. Do not assume unsupported JSON Schema keywords enforce security. Validate provider-specific semantics, URLs and ownership in the adapter too. Never dump `get_post_meta()` or an entire ACF object. An explicit schema cannot determine whether an editor has mistakenly put a secret in a public field; classification and field review remain mandatory.

CMS events use the registry's post types/fields/taxonomies. New adapters should specify their actual dependencies and freshness policy, then add focused lifecycle tests. Attachment changes conservatively invalidate providers; providers with non-WordPress sources must explicitly invalidate after approved source imports. Prefer deterministic sort/order, consistent nulls and small normalized records. Trusted callbacks must avoid unbounded queries, remote request waterfalls and irreversible side effects.

## Consuming a provider

```js
import {loadDataset} from '/wp-content/plugins/dx-data-engine/Frontend/loader.js';
const bundle = await loadDataset('/wp-content/uploads/dx-data/public-catalog/manifest.json', {
  id: 'public-catalog', schemaVersion: 1,
  validate(data) {
    if (!Array.isArray(data.items) || data.items.length > 200) {
      throw new Error('Invalid catalogue');
    }
  },
  signal: controller.signal,
});
// Render approved records locally. Label bundle.stale when true.
```

Provide a readable empty/failure state and avoid an automatic dynamic API fallback. Keep DOM insertion safe; arbitrary text belongs in `textContent`. Gym presentation fragments are a reviewed, trusted-renderer exception, not a pattern for embedding unescaped external HTML. Map components consume the same records; do not fetch the whole dataset again on each popup or navigation. Loader cancellation affects the caller, not shared consumers. Module-specific validation and state handling belong outside the shared loader.

## Administration and operational interfaces

`Engine::providers()`, `provider(id)`, `metadata(id)`, `controls(id)`, `events()`, `validate(id)`, `generate(id)`, `invalidate(id,withdraw)` and `control(id,key,value)` are internal PHP interfaces. Private metadata contains current/previous manifests, controls, status, attempt/success timestamps, duration, process peak memory, success/failure counts, bounded error codes, unchanged flag and optional shell checksum. Do not expose these through a public diagnostic REST route.

Business HQ and the WordPress Tools fallback enforce `manage_options`; existing HQ owner/manager checks still apply. Every mutation checks a dataset-specific nonce. Isolation/pruning require confirmation. The console reads metadata on demand and reports actual counts/age/bytes; it has no live cache-hit score or monitoring polling. The CLI emits JSON and exits nonzero for unknown providers/operations or failed validation/generation. Process datasets serially rather than launching parallel CLI workers on a 1 GB host.

Subscribe to `dx_data_event` for future Sentinel integration:

```php
add_action('dx_data_event', function (array $event) {
    // Forward approved bounded metadata to an existing internal incident recorder.
    // Do not add remote HTTP work or log raw content/queries here.
});
```

Event structure: `dataset`, `event`, ISO UTC `at`, bounded `metrics`. Implemented events are generation started/completed/failed, publication completed and invalidation requested. Validation failures are recorded as generation failure codes/status; stale consumption is surfaced in the browser rather than sending a new telemetry request. Private `events.json` retains the latest 100 entries under its own lock. Sentinel can compute failure frequency/age from real metadata when it exists; no Sentinel platform or fabricated health statistics have been added.

## Classification boundaries and roadmap

| Candidate | Reusable static portion | Keep separate |
| --- | --- | --- |
| Bus Routes Dashboard | Route shapes, stops, public route metadata, fleet reference data | Live arrivals/vehicle predictions; bounded dynamic endpoints with independent cache/limits |
| Map storytelling | Approved chapters, pins, captions and public media references | Private drafts and editorial tooling |
| DX. Command | Public navigation/content search index | Authenticated business/client search, private documents |
| Portfolio/case studies | Public summaries, filters and project metadata | Unpublished work, client records, financial data |
| Polls | Approved aggregate snapshots with a clear update policy | Vote submission, identities, abuse controls and write endpoints |
| Other public tools | Stable public reference records | Account-specific calculations, tokens and restricted operational information |

Introduce these one at a time after measuring actual costs. Do not convert real-time data into a misleading static promise. No empty bus, portfolio or poll providers are registered. This task establishes reusable infrastructure and one real provider, without refactoring unrelated applications.

## Limits and deliberate exclusions

Single-site WordPress is the tested deployment. Multisite needs site-scoped private paths/provider namespaces before reuse. No distributed lock, cross-host publication guarantee, external message queue, authenticated public dataset, browser telemetry endpoint or permanent-delete UI is implemented. File locks/atomic rename require reliable local/shared filesystem semantics. Callbacks are trusted and deadline checks are cooperative. Host hard limits still bound a hung callback. Unchanged generation still reads CMS sources to establish that nothing changed; normal public reads do not.

Theme/global asset size and photography bandwidth remain existing front-end costs. A future dedicated gym asset build and records-only renderer may reduce bytes, but should be separately profiled and visually compared. The current approved fragment approach preserves presentation while eliminating request multiplication.
