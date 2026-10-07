# FlowScope 1.0.1 historical README

FlowScope adds monthly traffic monitoring to Xboard Admin and hides selected dashboard cards and navigation entries. Existing pages, APIs, permissions and business logic remain available. It uses native plugin lifecycle, its own settings table, existing daily server traffic and plugin-owned assets. It does not edit shared Admin files or Xboard core.

Version 1.0.1 fixes resource loading behind reverse proxies that treat `.js`/`.css` URLs as static files. The loader now uses `/api/v2/flowscope/assets/script` and `/api/v2/flowscope/assets/style`, with fixed JS/CSS responses. No proxy configuration, database schema or Xboard core change is required.

## Requirements and supported version

- Xboard unified `App\Models\Server` / `v2_server`, current PluginManager/AbstractPlugin lifecycle, PHP 8.2+ (64-bit), Laravel 12.
- Audited/test dependency checkout: branch `master`, commit `f52adba714ad87fb3d02b3ad1b66410856e676da`. This checkout contains local Xboard machine-management changes; the full test status, including what was not run, is in [TEST_REPORT.md](TEST_REPORT.md).
- Supported Admin entry `assets/index-CEIYH7i8.js`, reference SHA256 `0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666`. Structural signatures verify the actual build and allow unrelated foreign modifications. An unsupported or ambiguous build disables UI integration without changing its files. Backend APIs remain independently available while the plugin is enabled.
- A working Xboard statistics queue and complete current-cycle `v2_stat_server` daily records. No additional Node extension is required.

## Installation

Upload `dist/flowscope-1.0.1.zip` in Xboard Admin → Plugin Management, then install and enable `FlowScope` (`flowscope`). Refresh the Admin page. The archive contains the native `Flowscope/` root directory.

For manual installation, copy the repository's `FlowScope/` contents to `<Xboard>/plugins/Flowscope/`, then install and enable through Plugin Management. **Linux directory case matters:** Xboard calls `Str::studly('flowscope')`, which produces `Flowscope`, not `FlowScope`. The repository keeps the requested `FlowScope` source directory; the application follows its native loader convention. No core autoload or PluginManager changes are needed.

Build an upload archive from source with `php scripts/package.php` (PHP zip extension required). Do not run migration manually: the native plugin manager owns migration state.

## Server traffic settings

Open Node/Server Management → edit an existing protocol node. The original editor gains a `FlowScope / Traffic Cycle` region with an independent save button:

- Monitoring is OFF by default. Only enabled settings appear on the dashboard.
- Display name can be left empty to use the current native node name.
- Quota accepts GB/TB; these labels use binary units, 1 GB = 1024³ bytes and 1 TB = 1024⁴ bytes. The database stores positive integer bytes, never floating-point traffic.
- Choose Outbound, Inbound or Bidirectional; cycle day can be 1–31.

Save native node changes with the native button and FlowScope changes with its own button. New nodes must be created before FlowScope settings can be saved. The monitored entity is Xboard's protocol node identity, not the physical server/machine NIC. Different nodes hosted by one machine have separate settings.

## Traffic mode semantics

| Mode | Native daily fields | Meaning on the proxy client connection |
| --- | --- | --- |
| Inbound | `u` | Client → node |
| Outbound | `d` | Node → client |
| Bidirectional | `u + d` | Both directions, once each |

This mapping is traced from node socket counters through reporting, controller/service/jobs and server statistics; see [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Server statistics contain raw reported bytes, without billing multipliers. User account counters and user statistics must not be used as substitutes.

This is application proxy traffic, not complete physical NIC traffic or a cloud-provider invoice meter. The node's target-server connection leg, protocol overhead and unrelated services are not independently available in Xboard's daily data.

## Cycle semantics and dashboard behavior

Cycles use the running Laravel application's timezone and local midnight. Day 15 means the 15th through the next month's 14th. Days 29–31 temporarily clamp to the last day of shorter months; March returns to the configured day. Queries use `[cycle_start, next_cycle_start)` to avoid overlap. JS displays the server-computed boundaries.

The Period Traffic section lists enabled, still-existing exact server identities, highest percentage first, with stable name/identity tie breaks. It shows name, used/quota, real percentage, direction and cycle. Above-quota percentages remain visible (for example 127.4%); bar width stops at 100%. Missing traffic rows mean zero usage. Deleted nodes are ignored safely. Loading, error/retry and empty states are supported.

When enabled, FlowScope hides today's income, monthly income, pending tickets, pending commission and income overview. It also hides notice, payment, knowledge, order, coupon, gift-card and ticket sidebar entries. This changes presentation only: authorized direct URLs and original APIs still work.

## Upgrade, enable/disable and uninstall

Use native Plugin Management for enable, disable, upgrade and uninstall. Disable retains settings and restores the original UI on the next page load; an already-open supported page also checks plugin status every 15 seconds and removes its owned UI. After re-enabling, refresh an already-cleaned page to load the assets again. Uninstall rolls back only `flowscope_server_settings`. It never deletes Xboard server or traffic data. Native published static asset copies may remain after uninstall, per Xboard convention; FlowScope's loader uses enabled-gated dynamic asset routes and does not rely on these stale copies.

Upload a higher SemVer release to upgrade. Native Xboard refuses the same or a lower version. Reload the Admin after upgrading; restart long-lived workers using the application's normal deployment procedure when replacing PHP code. There is no new custom lifecycle. Patch versions cover fixes, minor versions add features and major versions change storage/API contracts.

### Upgrading from 1.0.0 to 1.0.1

Upload `flowscope-1.0.1.zip` directly through Plugin Management. **Do not uninstall 1.0.0 first:** native uninstall deletes the plugin's saved settings. This patch has no new migration and preserves existing settings.

After a successful upgrade, reload/restart the application's Octane or other long-lived PHP workers through your normal deployment controls, then force-refresh the Admin page. PHP cannot replace already-loaded plugin classes inside a running worker; reloading is important for the new resource aliases to become available. Use your deployment's existing controls rather than changing Xboard core or the reverse proxy.

## Compatibility and bridge/runtime dependency

No shared runtime, on-disk bridge patch, marker migration or manual Admin restoration is required. A Laravel view composer wraps the current Admin template, renders it unchanged and injects owned assets only into the response. No shared file lock is needed because no shared file is written. SmartExpiry/QRCodeExtend/other modifications are not removed, rewritten or restored from backups.

The build-locked browser adapter reads native React Hook Form context to obtain an exact node id/type, and refuses ambiguous identity. This private UI integration requires re-audit when Admin changes. Foreign modifications that change a required structural signature or replace the editor/layout can make the UI unsupported; they are preserved, not classified as corruption.

## Troubleshooting and known limitations

- If the UI is absent, confirm the plugin is enabled, reload, and inspect `/api/v2/flowscope/status` for compatibility status. Unsupported builds fail closed. Keep the audited Admin build or add a reviewed adapter for a new build.
- If 1.0.0 installs/enables but `flowscope.js` and `flowscope.css` return 404 while `/status` works, a static-extension rule may be intercepting those dynamic URLs. Upgrade to 1.0.1: its extensionless `/assets/script` and `/assets/style` endpoints avoid that conflict. The old extension-bearing aliases remain available when requests reach Laravel, but the loader no longer depends on them. If the new endpoints still fail after upgrading, reload long-lived workers and verify the status version is `1.0.1`.
- Browser diagnostics use the exact globals `window.__FLOWSCOPE__` and `window.__FLOWSCOPE_RUNTIME__` (two underscores on each side). Do not paste or share administrator token values.
- If settings fail to save, verify administrator authentication, an existing node and valid positive quota/day/mode. Wrong id/type returns 404; validation returns 422; non-admin requests use Xboard's native 403 convention.
- The node editor's configuration is saved independently. Native protocol-type changes may orphan old settings; reconfigure the current identity afterward. Names never determine identity.
- Statistics reflect node reports and queue processing. Native daily jobs assign reports to the processing day, so delayed reports crossing midnight cannot be reconstructed precisely. Native retries/reports and other plugins' traffic filters can also affect totals. FlowScope does not silently repair or invent history.
- Legacy upgrades may retain statistics keyed by old IDs/types while the unified server migration assigns new IDs. FlowScope does not guess remappings. Verify the current cycle's history after migration.
- The audited sing-box UDP zero-copy packet wrappers contain a direction discrepancy relative to their ordinary packet path. FlowScope preserves reported native `u/d`; resolve any affected node-core behavior upstream when strict directional accounting matters.
- Historical data deleted by administrators or retention tasks cannot be recovered. Current Xboard retains roughly two months, enough for a monthly cycle.
- No quota enforcement, auto reset, alerts, billing changes or financial features are included.

## Development and verification

Set `XBOARD_PATH` to a compatible checkout with Composer dependencies. Tests borrow code read-only, use in-memory SQLite and write generated fixtures only to `tests/.runtime/` in this repository.

Windows: `./scripts/test.ps1 -XboardPath 'D:/Xboard-Independent/source/Xboard'`.

Linux: `XBOARD_PATH=/path/to/Xboard sh scripts/test.sh`.

The browser suite requires Node 24+, PHP 8.2+ and a local Chromium browser, without npm dependencies: set `FLOWSCOPE_ADMIN_DIR` to the audited `public/assets/admin` directory and `FLOWSCOPE_BROWSER` to Chrome/Chromium's executable, then run `node tests/ui-browser.mjs`. PHP is found on PATH, or can be selected using `FLOWSCOPE_PHP`. It runs the actual PHP response-injection code with isolated state/config dependencies, loads the real Admin build and uses isolated API fixtures. A local HTTP proxy simulates static-extension interception in front of the fixture backend. This is a regression for the observed rule, not a claim that a real Nginx deployment was run. Screenshots and results are written to `tests/.runtime/ui/`.

After packaging, run `php tests/package.php` with zip/PDO SQLite/mbstring enabled to exercise the actual archive through native upload, installation and uninstall. This verifies Composer loads extracted archive code rather than the source-directory test fallback.

See [DEVELOPMENT.md](DEVELOPMENT.md) for data flow and API details, and [TEST_REPORT.md](TEST_REPORT.md) for executed results and limitations.
