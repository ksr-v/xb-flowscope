# Linux VM API fixture (test only)

This harness uses the real FlowScope routes, enable middleware, machine-token
authentication, controllers, services, migrations and native Xboard model classes.
It deliberately uses **fixture Admin authentication and file SQLite**, not the
production Xboard bootstrap, Admin login/Sanctum flow or production database.
It never reads Xboard `.env` or writes to the dependency checkout.

Run it only in the disposable Linux VM, with all fixture runtime and inputs under
`/home/codex/flowscope-vm-test`. PHP 8.2+, PDO SQLite, SQLite3 and mbstring are
required, together with an audited Xboard checkout containing its Composer
dependencies. The VM must not have an unrelated service using port 18080.

## Initialize once

As the same non-root test user that will run the API, prepare a private `0600`
JSON input file inside the dedicated directory. Its contents are:

```json
{"machine_id":41,"token":"GENERATE-A-RANDOM-FAKE-TEST-TOKEN"}
```

Do not put a production token in this fixture. Do not print the generated token.
Use that same fake token in the collector's private configuration.

```sh
export XBOARD_PATH=/home/codex/flowscope-vm-test/dependencies/Xboard
export FLOWSCOPE_VM_RUNTIME=/home/codex/flowscope-vm-test/runtime/api
export FLOWSCOPE_VM_DB="$FLOWSCOPE_VM_RUNTIME/database.sqlite3"
php tests/vm/fixture-control.php init --config /home/codex/flowscope-vm-test/private-fixture.json
php -S 127.0.0.1:18080 tests/vm/api-router.php
```

`init` creates the fixture schema and machine 41 once. Repeating it with the same
fake token preserves settings, enable state, cursor and every daily record;
different credentials or an unrecognized existing DB fail closed. API startup
requires an initialized DB and never seeds, resets or truncates it. Stop/restart
the PHP process with the same environment to test persistence.

All stateful runtime files are private. SQLite uses WAL and synchronous FULL.
This tests file-SQLite/process/reboot behavior, not MySQL locking or production
Octane worker lifecycle.

The actual 2026-10-07 run is recorded in `docs/VM_TEST_REPORT.md`. Supporting
VM-only tools in this directory are `prepare.py` (create synthetic inputs once),
`verify.py` (frozen DB snapshots/exact comparisons/replay/drain),
`flowscope-vm-api.service` (loopback fixture service), and `finalize.py` (secret-free
evidence collection). They are not production components and are not shipped in
the plugin/collector archives. `drain` must run as the installed flowscope user,
with the service stopped; other evidence operations need sudo to read its DB.
The `codex` home is intentionally not made accessible to the service user; copy
only the non-secret verification helper to `/opt/flowscope-vm-verify.py` when a
service-user drain is needed. No production permission changes are required.

## HTTP surface

The router refuses requests unless PHP is bound to **127.0.0.1:18080** and the
client is loopback. Use an SSH port forward for a Windows host test client. Do
not bind the test server to `0.0.0.0`, a LAN address or a public interface.

- `POST /api/v2/flowscope/collect`: real plugin machine authentication and write path.
- `GET /api/v2/flowscope/dashboard`: real plugin controller and native Admin
  middleware, with an explicitly synthetic local fixture Admin user.
- `GET /__test__/audit`: read-only exact lifetime RX/TX sums, daily rows, cursor,
  settings and enable state. No token is returned; no SQL floating-point SUM.
- `GET /traffic/download?bytes=262144`: deterministic `0123456789abcdef` bytes,
  truncated to the requested 1..1048576 length, with `X-Content-SHA256`.
- `POST /traffic/upload`: accepts a `Content-Length` of 1..1048576 and returns
  exact received length and SHA256. It does not persist uploaded data.

The PHP development server is single-threaded. Use trusted short requests of at
most 1 MiB each, not slow clients; repeated SSH-forwarded transfers exercise the
VM's real NIC while collector reports remain loopback. These transfers are not
a production traffic server or a load benchmark. Loopback-only transfers do not
exercise `ens33` unless they traverse the SSH connection from the Windows host.

For v2.1.0, the fixture additionally allows GET `/api/v2/flowscope/installer`,
POST `/api/v2/flowscope/install/redeem`, and POST `/api/v2/flowscope/machine/41/install-ticket`.
The last uses deliberately synthetic loopback Admin authentication; do not expose
this fixture publicly. Other HTTP controls cannot alter enable state/settings or reset a binding.
Unlisted routes, including native Admin write routes, return 404 at the router.

## Local CLI controls

```sh
php tests/vm/fixture-control.php audit
php tests/vm/fixture-control.php disable
php tests/vm/fixture-control.php enable
php tests/vm/fixture-control.php machine-disable
php tests/vm/fixture-control.php machine-enable
php tests/vm/fixture-control.php settings --config /home/codex/flowscope-vm-test/private-settings.json
```

The settings input uses the real plugin controller's request fields, for example
`{"enabled":true,"quota_bytes":"1073741824","traffic_mode":"both","cycle_start_day":1}`.

`reset-binding` is an explicit destructive **fixture-cursor-only** control;
daily totals remain. Never use it to repair ordinary network/service downtime.
Normal restart/reboot/outbox replay tests must not call it or delete/replace
either the API database or collector state. No automatic cleanup is performed.

## Evidence boundaries

Record test timestamps, VM boot IDs, service UID/PID, exact committed collector
snapshot/outbox payloads, API cursor and exact daily totals before/after each
operation. A normal service restart should preserve collector ID and continue
sequence. A VM reboot should change boot ID and continue committed history.
Committed outbox entries must replay exactly once after receiver downtime.

Hard-power-off tests are only for this disposable VM. Already committed data
must survive, subject to the VM storage stack honoring fsync; unsampled bytes
after the last old-boot snapshot cannot be reconstructed. A test that observes
those missing tail bytes does not justify claiming zero loss across power cuts.

`oneclick.py` is a separate opt-in disposable-VM test for v2.1.0. It refuses
unknown/non-synthetic existing config and existing backup targets; it preserves
the three original test installation paths as `.v2.0.0-vm-test-backup` before
starting an isolated file-SQLite API and trusted local-CA HTTPS proxy. It runs
the actual generated bash command, checks single-use/revocation, simulates
collector-endpoint downtime, reinstalls with pending reports and checks byte
conservation. It stops/disables its collector and stops its API/proxy on exit.
Never run it on a production machine. It needs the earlier non-secret
`/opt/flowscope-vm-verify.py` helper for service-user drain. Actual execution
status belongs in `TEST_REPORT.md`, not inferred from the presence of the script.
