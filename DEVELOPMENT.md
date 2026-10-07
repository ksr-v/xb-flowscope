# FlowScope 2.2.0 development

## IPv4 and compact dashboard

IP.SB `https://api-ipv4.ip.sb/ip` is the only public-IP provider. `PublicIPv4` uses a separate proxy-free verified-TLS/no-redirect opener, custom UA, <=65-byte response and public IPv4 validation. Report-worker lookup caches success for 3600s, retries failures after 300s and expires prior success after 7200s. Lookup has a 3-second request timeout, not a hard DNS deadline; sampling runs independently. Authenticated `X-FlowScope-IPv4` is optional metadata outside report digest/outbox. Transactional address writes require accepted machine authentication/sequence, invalid metadata is ignored, and old agents remain compatible. New owned address-table migration; Admin-only no-store `/machine-addresses` projects only machine ID, IPv4 and update time. No native model/schema changes. Frontend uses audited c3t exact machine props, never names; inserts owned text below heartbeat metadata, batches/polls addresses and clears on account change/disable. Traffic cards use responsive 4/2/1 columns.

## One-command installation

Native Admin POST `/machine/{id}/install-ticket` issues a 256-bit random credential with a 600-second lifetime. The plugin-owned `flowscope_install_tickets` table stores only its SHA256, a hash of the current machine token, panel origin, expiry and consumption timestamp. Machine-row locking serializes issuing/redeeming; conditional consumption prevents reuse. Regeneration revokes the machine's previous credentials. Inactive/deleted machines, expired/used credentials and token rotation reject exchange; dynamic plugin disable gates all endpoints.

The panel URL must be the current request's HTTPS host origin, with no credentials/path/query/fragment. Native Admin authorization is mandatory to issue; the public POST `/install/redeem` requires the one-time credential in its JSON body and returns no-store private configuration plus three fixed base64 files/digests. Public extensionless GET `/installer` contains only the fixed generic bootstrap, not credentials. Linux POSIX shell quoting is independent of the panel's host OS. No server token is fetched into browser state, command text or URL.

The operator intentionally trusts the panel to provide root-executed installation code. The generated command downloads into a private mktemp directory, requires HTTPS and HTTP 200, refuses redirects/TLS bypass, executes a bounded fixed installer, then cleans its own temporary file/directory. Bootstrap uses stdlib verified TLS, refuses redirects, detects default routes across IPv4/IPv6 or accepts explicit NICs, validates the exact three-file bundle, runs the existing local installer and enables/restarts its service. Multi-route and virtual/aggregate default interfaces require explicit selection. It never guesses multiple NICs or removes/reinitializes state; same-identity reinstall retains the exact existing config and pending outbox. OS dependency installation is not automated.

Native v2.0.0 to v2.2.0 update adds ticket and address migrations; v2.1.x to v2.2.0 adds only the address migration. Protocol, cursor/daily schema and state schema remain compatible. Native uninstall rolls back only the latest migration batch: fresh v2.2 rolls back all four migrations; a direct v2.0 upgrade removes ticket/address tables, while v2.1.x upgrade removes only the address table. Tests preserve exact existing traffic/settings rather than modifying PluginManager behavior.

## Ownership

Repository source: `FlowScope/`; installed directory: `plugins/Flowscope/`; namespace: `Plugin\Flowscope`. Native Xboard resolves `Str::studly('flowscope')` to `Flowscope`, including on case-sensitive Linux. Native PluginManager owns installation, migrations, upgrade, enable/disable and uninstall. No Xboard core, shared Admin asset, existing Node binary or foreign-plugin file is patched.

`collector/` is a separate Linux machine-side component. The user explicitly selected this deployment model. Installing the panel plugin alone does not install collectors on remote machines. Plugin uninstall cannot and does not remove remote collector state.

## Data flow

Selected host NIC `/sys/class/net/<name>/statistics/{rx_bytes,tx_bytes}` → per-interface snapshot differences → SQLite baseline/outbox transaction → authenticated `/api/v2/flowscope/collect` → database transaction containing cursor and daily buckets → batched machine dashboard → progress bars.

RX is inbound to the selected NIC; TX is outbound from it; both adds RX and TX once. This counts all traffic on the selected interfaces, including non-proxy services and protocol overhead visible to those counters. It is not Xboard user billing, proxy `u/d`, or a provider invoice meter. Use explicit NIC selection; do not select both a bridge/bond and its underlying interfaces unless that duplication is intended. Run in the host network namespace, not an isolated application container.

Nezha's official [NIC counter implementation](https://github.com/nezhahq/agent/blob/main/pkg/monitor/nic/nic.go) and [snapshot/boot handling](https://github.com/nezhahq/nezha/blob/master/model/server.go) informed the design. FlowScope implements its own collection and ingestion protocol; no Nezha installation, API key or dashboard dependency is required.

## Persistent collection

The collector uses standard-library SQLite WAL with full synchronous commits. One transaction advances snapshots, sequence and the outbox; an HTTP failure never acknowledges a sample. Sampling is independent of the HTTP sender. An exclusive process lock prevents concurrent collectors using the same state. Stable `collector_id` and strictly ordered `sequence` survive process restart. Only an exact positive acknowledgement removes an outbox item.

The first snapshot establishes a baseline and does not invent pre-installation history. A collector restart in the same host boot resumes from saved counters. A host boot change or per-NIC counter reset adds the new counter value and records the discontinuity; it never subtracts a previous boot's value. Other NICs retain their independent differences. Newly observed interfaces establish a baseline. Read failures or a backwards wall clock do not silently advance snapshots.

An outbox protects **already sampled and committed** traffic during disconnection or a crash. It cannot recover bytes after the last sample before sudden power loss, recover deleted state, or reconstruct when traffic occurred while the collector was stopped. Keep the state directory on durable storage and back it up. Do not reset collector identity to repair ordinary network errors. A new ifindex is a new interface generation and establishes a baseline, whereas a same-generation counter decrease counts its current post-reset value.

Namespace checks are conservative: known container markers/env/cgroup and a readable PID1 namespace mismatch are rejected. A non-root PID1 namespace probe may be permission-restricted under [Linux procfs ptrace access rules](https://man7.org/linux/man-pages/man7/namespaces.7.html); this warns without adding root/CAP_SYS_PTRACE. The supplied systemd unit does not create a private network namespace. Correct native-host deployment remains the operator's responsibility, not a fully proven property of the probe.

## Machine identity and schema

`App\Models\ServerMachine` / `v2_server_machine.id` is the only current identity. Native machines can host many protocol nodes. A machine is counted once regardless of node count. Native machine deletion is safely ignored by dashboard reads; plugin tables do not cascade into or alter native tables.

The 1.x `flowscope_server_settings` table and class remain as legacy data. They are not read as NIC settings, renamed, merged or automatically migrated. Existing Xboard proxy statistics remain untouched. Native Xboard uninstall rolls back only the latest migration batch: an upgraded 1.x table can remain, whereas a fresh v2 installation puts both migrations in the same batch. FlowScope does not bypass that lifecycle or delete data from a disable callback. NIC history is removed when its v2 migration is rolled back.

The v2 migration adds plugin-owned machine settings, collector cursor and daily traffic tables. Settings carry machine ID, enabled flag, plain-text display name, exact quota, direction and cycle day. A unique machine cursor binds a single collector ID and stores sequence/timing metadata. Daily buckets have a unique machine/date key and exact decimal-byte columns. No cache hides database state, and there is no retention task deleting NIC history.

Every accepted report locks the native machine row before reading/updating the plugin cursor and buckets. Initial binding requires sequence 1; later reports require the same collector ID and the next contiguous sequence. The last sequence stores a digest so a conflicting retry fails. Older acknowledged sequences do not add traffic again. Future/gapped/overlapping intervals and invalid byte values are rejected. Cursor and daily writes commit together; failures leave the report retryable. Administrative collector reset releases the binding but preserves usage; stop the old collector before rebinding.

## Precision, day allocation and cycles

API byte values are nonnegative decimal strings, not JSON floats. Quotas use 1024-based GB/TB aliases and a signed-BIGINT-safe upper bound of `9223372036854775807`. Decimal-string arithmetic preserves byte sums and ratio sorting beyond JavaScript's safe integer range. Percentages alone use floating point for presentation.

The collector reports the counter increment over `[started_at, ended_at)`. The backend divides that interval at application-local midnights and allocates integer bytes proportionally to elapsed seconds, with the final bucket receiving the remainder. Total bytes are conserved. This is explicitly `interval_prorated`: the distribution inside an interval is unknown. The normal 10-second sample interval bounds usual midnight uncertainty; a stopped collector or reboot can create a larger uncertainty interval.

`TrafficCycleService` independently clamps each month's configured day to its last valid day, using `config('app.timezone')` and half-open `[start,next_start)` boundaries. It never permanently changes a configured day 29/30/31. Do not change the application timezone after collection without a deliberate historical-bucket migration: existing buckets were allocated under the prior timezone.

Dashboard reads enabled machine settings, current machines, cursors and date-indexed daily buckets in a fixed number of batch queries, not one query per machine. Missing samples are shown as waiting/stale rather than pretending that a zero value proves complete history. Sorting uses exact used/quota ratios, name and machine ID. Textual percentages may exceed 100; visual width is capped at 100.

## API

Prefix `/api/v2/flowscope`. Data responses use `{ "data": ... }`.

| Method | Suffix | Authorization / purpose |
| --- | --- | --- |
| GET | `/machines` | Native admin; physical machines and independent settings |
| GET / POST | `/machine/{id}` | Native admin; read/save machine configuration |
| POST | `/machine/{id}/collector-reset` | Native admin; release collector binding, retain traffic |
| GET | `/dashboard` | Native admin; current cycles, NIC RX/TX usage and collection state |
| POST | `/collect` | Exact native machine ID/current machine token/active state; accept durable sample |
| GET | `/status` | Public nonsensitive metadata only |
| GET | `/assets/script`, `/assets/style` | Enabled-gated fixed JS/CSS; no Admin token required |

Legacy `.js`/`.css` aliases remain whitelisted, but the loader uses extensionless endpoints to avoid proxy static-file interception. Old 1.x node-settings endpoints are retired, not redirected to machine IDs. Native administrator authentication remains Xboard's existing `admin` middleware; machine ingestion does not use node-specific middleware or an Admin access token. All operational endpoints dynamically check plugin enable state.

Collector protocol 1 body:

```json
{
  "protocol": 1, "machine_id": 41, "token": "MACHINE_TOKEN",
  "collector_id": "0123456789abcdef0123456789abcdef", "sequence": "1",
  "started_at": 1791331200, "ended_at": 1791331210,
  "boot_id": "550e8400-e29b-41d4-a716-446655440000", "interfaces": ["eth0"],
  "rx_bytes": "12345", "tx_bytes": "67890", "resets": 0, "boot_changed": false
}
```

`rx_bytes`/`tx_bytes` are interval deltas, not lifetime counters. Successful acknowledgement is `data.accepted=true`, matching decimal-string `data.sequence`, and `data.duplicate`. Never place a token in a URL, status response, dashboard payload or normal log. TLS verification remains enabled; HTTP is accepted only for local test URLs. Redirects are refused to prevent credential forwarding.

## Admin integration

A native Laravel `admin` view composer wraps the **current** original template and injects owned assets into the response. No shared file is overwritten or restored. Registration is deduplicated and dynamic enable checks make retained Octane callbacks inert after disable. Reload normal long-lived workers after upgrading PHP classes.

The audited Admin build remains `assets/index-CEIYH7i8.js` (reference SHA256 `0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666`). Exact source signatures must each occur once; unrelated foreign modifications are preserved. Missing/duplicate signatures or another build fail closed without altering its files.

The machine editor is `#/server/machine`. A unique open dialog and native form with name/notes/switch, no node port, a unique RHF context and matching edit default ID establish identity. Names are never identifiers. Unsaved machines cannot be configured. FlowScope mounts a separately saved sibling section outside the native form and keeps its inputs from submitting that form. A plugin-owned dialog attribute enables scrolling; cleanup removes it along with owned nodes/listeners/observers. Node management receives no traffic configuration section.

The five dashboard items and seven sidebar items are hidden reversibly. Original routes, APIs, permissions and business logic continue to work. Unsupported builds do not mutate the UI. The browser never computes core cycles or trusts rounded quota displays for saving exact bytes.

## Verification and packaging

Tests borrow the audited Xboard checkout read-only and use isolated fixtures. The browser suite loads the actual Admin bundle with isolated API data through a simulated static-extension-blocking proxy. Unit tests mock NIC/boot inputs. The additional `tests/vm/` harness uses real native routes/models with private file SQLite and explicitly synthetic loopback Admin auth. On the authorized Ubuntu VM, the actual installer, hardened non-root systemd unit, real `ens33`, durable outbox, SIGKILL recovery, normal guest reboot and forced unclean guest reboot were exercised. See `TEST_REPORT.md` and `docs/VM_TEST_REPORT.md` for evidence and explicit limitations; this is not production Admin authentication/MySQL/Octane/Nginx or hypervisor hard-power-off validation.

`php scripts/package.php` builds the native `Flowscope/` plugin ZIP including collector source. `python scripts/package-collector.py` builds the separately installable Linux collector tarball. `tests/package.php` exercises the actual plugin ZIP through native upload/lifecycle; `tests/release.py` verifies both artifacts against source and checks archive paths/modes. No packaging script installs or contacts a production machine.
