# Historical 1.0.1 development notes

## Ownership and architecture

Repository source is `FlowScope/`, runtime plugin directory is `plugins/Flowscope/` and namespace is `Plugin\Flowscope`. Xboard resolves directory names using StudlyCase of plugin code `flowscope`. `Plugin.php` uses native install/boot/cleanup/update methods. The manager owns migrations and installs assets; FlowScope never changes `AbstractPlugin.php` or `PluginManager.php`.

Backend components validate settings, resolve exact unified identities, compute timezone-aware cycles and batch existing daily statistics. The browser only formats and presents their results. Plugin assets are served from enabled-gated, fixed-name routes to avoid depending on stale static publication during upgrades/disable.

## Data flow and direction mapping

Node `conntracker.go` client-connection Read/Write counters → `[upload,download]` → tracker deltas → panel report → `/api/v2/server/report` → `ServerController` → `ServerService::processTraffic` → `UserService::trafficFetch` → `StatServerJob('d')` → `v2_stat_server` → FlowScope batch query → dashboard JSON → progress bars.

Inbound is native `u` (client → node); outbound is native `d` (node → client). Bidirectional adds these once. TCP counters and Xray uplink/downlink reporting independently corroborate the mapping. The audited UDP zero-copy inconsistency and upstream daily queue timing/retry limitations are documented in README. Native user statistics include multipliers; native server statistics do not. Existing plugin filters can alter traffic before statistics jobs.

Native schema has server-id and date indexes, unique `(server_id,server_type,record_at)` and daily discriminator `record_type='d'`. Current server rows use globally unified numeric primary IDs. Legacy `code` is not an interchangeable identity, and parent/child nodes are not recursively aggregated.

## Tables and precision

Only `flowscope_server_settings` is created: identity, enabled flag, display name, positive quota bytes, direction, cycle day and timestamps. Unique identity prevents duplicate configurations; enabled index supports the dashboard. No foreign key cascades into Xboard tables. Orphans are ignored on reads. Disable retains settings; native migration rollback on uninstall deletes only plugin settings.

Bytes stay in integers, serialized as decimal strings at the API boundary to preserve values above JavaScript's safe integer range. Quotas use binary units and bounded decimal parsing, capped at signed BIGINT maximum (9223372036854775807) for cross-database safety. Decimal-string arithmetic preserves sums and exact ratio ordering; percentages alone use floating point for presentation. No cache or duplicate daily table is introduced.

## Cycle algorithm and query complexity

Compute this month's boundary as local midnight on `min(configured_day,days_in_month)`. If now precedes it, start is the previous month's independently clamped boundary. End-exclusive is the following month's independently clamped boundary. Never advance a clamped date by a month and inherit its shorter day. Inclusive display end is next boundary minus one second. All timezone conversion uses current application configuration.

Read enabled settings, batch live exact servers and group native daily rows by identity/date over a bounded monthly range. Each identity is filtered to its own cycle in PHP. The number of traffic queries does not scale with node count. Missing rows yield zero; negative final totals clamp to zero. Sort by percentage descending, display name ascending and identity as the final tie break.

## API contract

Prefix `/api/v2/flowscope`; native `admin` middleware plus a live plugin-enabled gate protects data endpoints. A retained Octane route remains inaccessible after disable. Xboard's existing Sanctum authorization is used; no custom administrator login is introduced.

| Method | Path suffix | Purpose |
| --- | --- | --- |
| GET | `/servers` | Live protocol nodes and settings |
| GET | `/server/{type}/{id}` | Exact node and default/existing settings |
| POST | `/server/{type}/{id}` | Validate and upsert only plugin-owned settings |
| GET | `/dashboard` | Current cycle usage, percentages, server boundaries/timezone |
| GET | `/status` | Non-sensitive enabled/compatibility information for loader teardown |
| GET | `/assets/script`, `/assets/style` | Canonical extensionless JS/CSS endpoints, gated by enabled state |
| GET | `/assets/flowscope.js`, `/assets/flowscope.css` | Explicit legacy aliases; the loader no longer uses them |

Successful data responses use `{ "data": ... }`. Settings body: `enabled`, `display_name`, `quota_bytes` decimal string (or exact `quota_value` with GB/TB unit), `traffic_mode` (`outbound`,`inbound`,`both`) and `cycle_start_day` (1–31). Display names are bounded plain text. Id/type values are bound query values, never SQL fragments. Unknown node/type returns 404, invalid settings 422, native non-admin authorization 403. Client uses Xboard's existing `XBOARD_ACCESS_TOKEN.value` without inventing an Authorization prefix.

## Admin integration and fail-closed behavior

`View::composer('admin')` remembers the current view path and selects the plugin wrapper. The wrapper renders that original path using Laravel `view()->file(...)` (a different view name, so no recursion), then injects an owner-specific loader into the response. Dynamic enable checks handle persistent worker callbacks. Registration is deduplicated without globally removing foreign composers/listeners. Rendering the currently selected path also preserves compatible earlier view customizations.

The audited Admin manifest filename and unique exact code signatures identify the supported layout, route schema, authentication storage and editor context. Unknown/duplicate/missing signatures mean no UI mutation. A reference hash records provenance but unrelated foreign edits need not be erased or rejected solely for changing a whole-file hash. No minified code is transformed, evaluated or exported by FlowScope.

Plugin JS scopes hiding to audited dashboard cards and actual sidebar link containers. It applies an owner-specific attribute, never deletes native elements, pages or routes. Server editing uses a read-only React Hook Form context adapter; exact persisted id/type and node-field evidence are mandatory. It mounts inside the existing dialog and uses its own type=button save. It does not hijack the original submission. Owned widgets, styles, observers and hiding are reversible; no foreign marker is removed.

In 1.0.1, the response loader requests extensionless `script`/`style` keys to avoid reverse-proxy static-extension rules. The asset controller maps all supported keys to two fixed internal filenames; URL input is never concatenated into a filesystem path. Correct JS/CSS MIME, enabled checks, `no-store`, `nosniff` and same-origin policy remain unchanged. Schema and data semantics do not change. Native upgrades retain settings; already-loaded PHP classes require a normal long-lived worker reload.

No shared Extension Runtime was found, and native Laravel view integration plus plugin assets suffice. Therefore no disk bridge patch, marker version, shared patch lock, whole-bundle backup, restore command or core patch is present. Future support for another build requires reviewing signatures, DOM identity extraction and browser tests before adding compatibility.

## Verification and releases

`tests/bootstrap.php` builds an isolated Laravel container, uses native Xboard models/middleware/manager with in-memory SQLite and points all generated paths to the workspace. Backend tests cover direction, quotas, boundaries, timezones, aggregation and orphans. Integration tests cover native lifecycle, authenticated API/validation, migration ownership, wrapper preservation, unsupported structures and repeated operations. UI tests exercise the real audited Admin assets in an isolated local browser fixture.

The 1.0.1 browser fixture uses the real PHP `AdminIntegration::inject()` and `AdminCompatibility` implementation with state/config dependencies stubbed for isolation. A separate local HTTP proxy rejects extension-bearing FlowScope resource URLs as static 404s while forwarding the canonical extensionless paths. Browser rendering through that proxy verifies that the new URLs survive the observed deployment rule; direct-backend checks cover retained aliases. This does not simulate every proxy configuration or a real Octane worker process.

`scripts/package.php` creates a native upload ZIP rooted at `Flowscope/`, with code/assets and documentation. See TEST_REPORT for actual results rather than treating planned coverage as executed evidence.
