# FlowScope 2.3.0 implementation plan

## 2.3.0 short interactive management

User explicitly authorized panel-provided root Shell execution for shorter commands, then requested old-program residue cleanup, clear success/failure messages, interactive connect/uninstall menu and zero owned-install residue after confirmed uninstall. Keep tickets on stdin, menu on controlling TTY, download buffering/failure rejection, verified bootstrap and durable upgrade semantics. Purge only exact owned install paths/accounts after explicit UNINSTALL; preserve panel history, system logs and operator backups. Test actual PTY menu, cancellation, reinstall/outbox and local resource absence on the authorized disposable VM.

## 2.2.0 IPv4 / layout / installation compatibility

Implement IP.SB-only public IPv4 lookup in collector, optional digest-independent authenticated metadata, owned migration and Admin-only batched address API. Mount address text below native machine heartbeat/node metadata by exact machine ID. Match native statistics with responsive four-column desktop traffic cards. Verify old-agent compatibility, native 2.0/2.1 upgrade, empty install, old collector in-place upgrade, pending queue preservation and fail-closed orphan state. Test actual authorized VM and real Admin browser fixture; preserve old test backups.

## Historical 2.1.0 one-command installation (2026-10-07)

The user selected option 1: generate a one-command installer in Server Management, retaining the independent collector architecture. Add a plugin-owned hash-only short-lived single-use credential table and Admin issuing endpoint; exchange only over verified HTTPS, never expose long-term machine credentials in browser commands/URLs. Serve generic bootstrap at an extensionless route, use fixed exact collector files, retain local installer safeguards and durable state, and automatically enable/start the service. Detect a unique host default-route NIC across IPv4/IPv6, otherwise require operator selection. Preserve native core/auth and existing 2.0.0 history; bump compatible feature release to 2.2.0. Test credential lifecycle, 2.0 native migration upgrade, actual ZIP extraction, real Admin browser/mobile interactions and bootstrap rejection/reinstall behavior. Current real VM status is explicitly recorded in TEST_REPORT rather than inherited from the historical run.

## 2.0.0 machine NIC revision (2026-10-07)

The user requested physical NIC traffic instead of proxy-client traffic, durable totals across server restarts, and settings in **Server Management → Edit Server**, not Node Management. After read-only audit, the user explicitly selected an independent FlowScope collector. This is a major release: identity, source semantics and API change. The historical 1.x plan below is retained as provenance, not the current implementation contract.

### Audit and selected architecture

- Native `App\\Models\\ServerMachine` / `v2_server_machine.id` is the physical-machine identity. Its `servers()` relation is one-to-many; summing a machine's NIC usage once per protocol node would duplicate traffic. Old node quotas cannot be safely merged or relabelled as machine quotas.
- Node `internal/monitor/monitor.go` reads per-interface `BytesRecv/BytesSent`, but sends only `net.in_speed/out_speed` through `internal/panel/client.go`. Its speed baseline is shared with per-node monitoring, and its uptime is process uptime. The native Machine status controller stores speed history, normally cleaning records older than 24 hours. Neither speed integration nor proxy `u/d` can recover exact monthly NIC counter history.
- Nezha's official agent maps `BytesRecv` to inbound and `BytesSent` to outbound. Its dashboard snapshots transfer deltas and handles boot changes. FlowScope borrows this counter/delta/persistence principle, independently implements its protocol, and does **not** require or copy the Nezha dashboard. Sources reviewed: [agent NIC counters](https://github.com/nezhahq/agent/blob/main/pkg/monitor/nic/nic.go), [dashboard boot/snapshot handling](https://github.com/nezhahq/nezha/blob/master/model/server.go).
- Add a Linux Python standard-library collector with explicitly selected interfaces. SQLite transactions persist per-interface baselines, stable collector identity, sequence and an ordered outbox before sending. Sampling and HTTP sending are independent. Keep TLS verification enabled and refuse redirects. A machine token, never an administrator token, authenticates reports.
- Add plugin-owned machine settings, collector cursor and daily NIC buckets. An authenticated, transactionally locked machine accepts only one collector identity and contiguous sequence numbers. Acknowledged replays do not add usage twice. An explicit administrator reset rebinds a replacement collector without erasing accumulated usage.
- Store exact byte strings; split intervals across application-local day boundaries proportionally using integer arithmetic and conserve every byte. This allocation is an approximation of traffic timing within a sampling interval, not per-packet timing. Dashboard reads machine buckets in batches; it never reads `v2_stat_server` in v2.
- Retain the old `flowscope_server_settings` table and migration, with no automatic conversion. Existing proxy history remains in Xboard untouched; administrators configure machine quotas independently.

### UI and ownership

The audited hash route is `#/server/machine`. Its machine dialog has a real form with `name`, `notes`, and `is_active`; RHF `getValues('id')` supplies the exact machine ID. Unique form/context plus edit-props cross-checks fail closed for ambiguity. New machines must be saved first. The independently saved FlowScope section is outside the native form and its reversible scrolling adjustments are plugin-owned. Node editors no longer mount it. Dashboard/sidebar presentation-only hiding and extensionless assets remain.

Shared Xboard, Admin bundle and existing Node files modified: **none**. The collector is a separate machine-side installation, not an automatic remote deployment. Plugin lifecycle remains native; native uninstall removes plugin tables, not core data or remote collector state.

### Verification and limitations

Test exact RX/TX modes, day/month/TZ boundaries, large bytes, no-N+1 aggregation, native lifecycle/upgrade preservation, authenticated reports, wrong tokens, duplicate/gap/reset sequence, reboot/agent restart, per-NIC reset, outbox recovery and acknowledged resend. Exercise the actual Admin bundle on desktop/mobile, machine identity, unsaved/create form, independent save, absence in node editor, reversible cleanup and the existing reverse-proxy resource regression.

Do not claim recovery before the first baseline, absolute zero loss for unobserved traffic immediately before sudden power failure, exact provider billing, or exact sub-sample midnight attribution. Real Linux/systemd, production deployment and MySQL/real Octane are separate from isolated Windows tests and must be reported honestly.

---

## Historical 1.x plan (superseded data source and editor)

Audit date: 2026-10-07. The requested workspace `D:/xb-flowscope` was empty, with no Git repository or installed Xboard. Development targets the existing, read-only checkout `D:/Xboard-Independent/source/Xboard`, branch `master`, commit `f52adba714ad87fb3d02b3ad1b66410856e676da`. No deployment to that checkout is implied. Upstream: https://github.com/cedar2025/Xboard.

## Architecture and evidence

- PHP requirement ^8.2, Laravel ^12.0; installed versions will be recorded in TEST_REPORT. Admin is a separately distributed React/Shadcn SPA, `.gitmodules` points at cedar2025/xboard-admin-dist. `resources/views/admin.blade.php` loads `public/assets/admin/manifest.json`; audited entry is `assets/index-CEIYH7i8.js`, SHA256 `0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666`.
- `app/Services/Plugin/PluginManager.php`: install runs migrations then install and publishes resources; enable registers routes/views then sets enabled and boots; disable sets disabled then cleanup; uninstall disables and rolls back plugin migrations; update disables, migrates, updates and enables. `InitializePlugins` runs before HTTP route dispatch. No lifecycle core changes are necessary.
- Plugin namespaces/directories use `Str::studly(code)`: `flowscope` resolves to **Flowscope** on Linux. Repository source directory remains `FlowScope`; release ZIP/native installation directory must be `Flowscope`. This native naming constraint is documented explicitly rather than patching the manager.
- `plugins/` is empty in the clean checkout; `plugins-core/` holds payment/Telegram plugins. `theme/` is the user frontend, not Admin. No shared Admin extension runtime or dashboard/server-edit slots were found. `D:/SmartExpiry` contains uncommitted SmartExpiry patches, and `D:/qrcodeextend` contains another extension. These are read-only compatibility evidence; all foreign modifications are preserved.
- Admin routes remain in `routes/web.php`; Admin API authorization is the existing `admin` middleware using Sanctum and `is_admin`. Plugin `routes/api.php` is not automatically URL-prefixed, so FlowScope declares its complete `/api/v2/flowscope` prefix and `admin` plus a dynamic plugin-enabled gate.
- Unified `App/Models/Server.php` / `v2_server` has stable numeric id and protocol type. Resolve an exact primary id + type, never the native code-or-id lookup. FlowScope monitors protocol nodes (the server abstraction used by traffic statistics), not physical machine NIC counters.

## Traffic provenance and direction

The complete trace is node `internal/kernel/singbox/conntracker.go` (client connection Read -> upload, Write -> download) -> tracker difference `[upload, download]` -> `panel/client.go` report -> Xboard node API/controller -> `UserService::trafficFetch` -> `StatServerJob` -> `v2_stat_server` -> Admin server traffic statistics. `u` is client-to-node inbound and `d` is node-to-client outbound **on the proxy client connection**. `both = u + d` once. This is not total physical NIC or provider billing traffic; the proxy's remote connection leg is not included again. The node UDP zero-copy path has an upstream direction discrepancy; document rather than change it.

`StatServerJob` stores raw bytes without billing multipliers, with `record_type = d` and `record_at = application-local midnight` at job execution. User statistics and account counters are multiplied and are unsuitable. `ResetLog` retains two months of native statistics, enough for the current monthly cycle. No duplicate traffic collector, snapshot table or cache is needed. Queue delay can assign a midnight-crossing report to the processing date; FlowScope cannot recover finer provenance from daily data. Existing legacy protocol/id migration does not reliably rewrite historical statistics; no guessed historical remapping.

## Data model and calculation

Create only `flowscope_server_settings`: id; server_type; server_id; enabled default false; display_name max 120; unsigned BIGINT quota_bytes; traffic_mode enum validated as outbound/inbound/both; cycle_start_day 1..31; timestamps; unique (server_type, server_id), index(enabled). Core server and traffic schemas remain unchanged. Disabled settings survive disable; uninstall rolls back only FlowScope's migration.

`TrafficCycleService` computes local-midnight half-open [start, next_start) cycles in `config('app.timezone')`, clamping each month's boundary independently without changing the configured day. API includes inclusive display end and exclusive end. Bytes use integers; quota conversion uses 1024 units with exact decimal input. The implemented Dashboard performs three batch queries: settings, live server identities and one daily traffic query grouped by identity/date; PHP filters each identity to its own cycle. It sorts by exact usage ratio descending, then display name and identity. Negative usage clamps to zero; textual percentages can exceed 100; progress width is capped at 100.

## Admin integration: native plugin assets, no shared-file patch

Use Laravel's existing view composer for `admin` to select a plugin-owned wrapper. The wrapper renders the original view by its file path, preserving all other template and asset modifications, then adds plugin-owned assets to the response. A dynamic enabled check prevents stale Octane callbacks/routes from exposing disabled functionality. No shared bundle, manifest, index.html, Blade file, plugin core or other plugin is written. Therefore disk patch markers, patch locks, atomic bundle replacement and historical backups are unnecessary.

Plugin JS adapts only the audited Admin build: exact structural compatibility signatures must each occur once; altered signatures or unrecognized build fail closed. It mounts a plugin-owned Dashboard section, applies reversible owner-specific hiding to the five requested Dashboard items and seven Sidebar entries, and mounts an independent settings region inside the native server editor. The read-only editor adapter extracts exact id/type from the native form context; it must refuse ambiguous/missing identity rather than infer from names. Native save behavior is preserved; FlowScope settings use their own explicit save action. All plugin DOM changes and listeners are removable. Existing routes/API/permissions and direct navigation remain intact. No new general-purpose runtime is introduced because plugin assets and native Laravel view integration suffice.

## Files to create and ownership

- `FlowScope/Plugin.php`, `config.json`, plugin routes, enabled middleware, Controllers/Models/Services, one migration: native backend/lifecycle; request validation lives in the controller.
- `FlowScope/resources/views/admin.blade.php`: response-only wrapper of the currently selected original template.
- `FlowScope/resources/assets/flowscope.js`, `.css`: business UI and audited read-only Admin adapter.
- `tests/`: calculation, real Laravel/database/API/lifecycle, foreign-modification and browser integration tests, with fixtures inside this workspace.
- `scripts/`: repeatable verification/package commands. Native ZIP root `Flowscope` for Linux naming compatibility.
- `README.md`, `DEVELOPMENT.md`, `TEST_REPORT.md`, license; build signatures are recorded in `Services/AdminCompatibility.php`.

Shared files to modify: **none**. Current application code and runtime state remain read-only. Implementation follows this plan immediately, without another approval round.

## 1.0.1 deployment compatibility patch (2026-10-07)

After 1.0.0 delivery, the user reported successful installation/enable with no visible effect. Live diagnostics showed `/status` enabled/supported, JS/CSS resources returning 404, and the same JS URL with a trailing slash returning source. This identifies a deployment static-extension interception conflict; the earlier missing-global result was not valid evidence of CSP failure because the diagnostic actually executed without the globals' required double underscores.

Keep the native response-only architecture and schema unchanged. Change only the loader's resource URLs to `/api/v2/flowscope/assets/script` and `/assets/style`; map these keys to fixed plugin JS/CSS files and retain the legacy aliases. Preserve enable gating and response security headers. Bump plugin/asset ownership metadata to 1.0.1. Add backend whitelist/MIME/byte/disable checks, native 1.0.0-to-1.0.1 upgrade coverage, and real-Admin browser regression through a local HTTP proxy that reproduces static-extension interception. No Xboard core, shared Admin or proxy configuration changes are necessary.
