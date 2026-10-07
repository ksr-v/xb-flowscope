#!/usr/bin/env python3
"""FlowScope 2.3.0: durable, explicit-interface Linux NIC collector (stdlib only)."""

import argparse
import ipaddress
import contextlib
import dataclasses
import http.client
import ipaddress
import json
import logging
import os
from pathlib import Path
import re
import signal
import sqlite3
import ssl
import stat
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

try:
    import fcntl
except ImportError:  # Importable on Windows for mocked unit tests; never a Linux lock substitute.
    fcntl = None

VERSION = "2.3.0"
MAX_INTEGER = 9223372036854775807
MAX_COUNTER = 18446744073709551615
MIN_TIMESTAMP = 1577836800
MAX_INTERVAL = 366 * 86400
MAX_RESPONSE = 16384
NIC_NAME = re.compile(r"[A-Za-z0-9_.:@-]{1,64}\Z", re.ASCII)
DECIMAL = re.compile(r"[0-9]+\Z", re.ASCII)
LOG = logging.getLogger("flowscope.collector")


class CollectorError(Exception):
    """An intentionally secret-free operator-facing error."""


class ConfigError(CollectorError):
    pass


class SampleError(CollectorError):
    pass


class ClockWait(SampleError):
    pass


class StateError(CollectorError):
    pass


class ReportError(CollectorError):
    def __init__(self, message, permanent=False):
        super().__init__(message)
        self.permanent = permanent


def _regular_private_file(path, description):
    """Reject symlinks, special files and exposed secrets; never chmod an input file."""
    try:
        info = Path(path).lstat()
    except OSError:
        raise ConfigError(description + " is not readable") from None
    if not stat.S_ISREG(info.st_mode):
        raise ConfigError(description + " must be a regular non-symlink file")
    if os.name == "posix":
        if stat.S_IMODE(info.st_mode) != 0o600:
            raise ConfigError(description + " must have mode 0600")
        if info.st_uid != os.geteuid():
            raise ConfigError(description + " must be owned by the running user")


def _positive_int(value, field, maximum=MAX_INTEGER):
    if type(value) is not int or not 1 <= value <= maximum:
        raise ConfigError(field + " must be a positive integer in range")
    return value


def validate_panel_url(value):
    if not isinstance(value, str) or not value or any(ord(c) < 33 for c in value):
        raise ConfigError("panel_url is invalid")
    try:
        parsed = urllib.parse.urlsplit(value)
        host = parsed.hostname
        parsed.port
    except ValueError:
        raise ConfigError("panel_url is invalid") from None
    if not host or parsed.username is not None or parsed.password is not None:
        raise ConfigError("panel_url must not contain credentials")
    if parsed.query or parsed.fragment:
        raise ConfigError("panel_url must not contain a query or fragment")
    local = host.lower() == "localhost"
    try:
        local = local or ipaddress.ip_address(host).is_loopback
    except ValueError:
        pass
    if parsed.scheme != "https" and not (parsed.scheme == "http" and local):
        raise ConfigError("panel_url requires HTTPS; HTTP is only allowed on loopback for tests")
    if "\\" in parsed.netloc or "\\" in parsed.path:
        raise ConfigError("panel_url is invalid")
    return value.rstrip("/")


@dataclasses.dataclass(frozen=True)
class Config:
    panel_url: str
    machine_id: int
    token: str
    interfaces: tuple
    state_file: Path = Path("/var/lib/flowscope-collector/state.sqlite3")
    sample_interval: int = 10
    report_interval: int = 30
    request_timeout: int = 10

    @property
    def endpoint(self):
        return self.panel_url + "/api/v2/flowscope/collect"

    @classmethod
    def from_dict(cls, data):
        if not isinstance(data, dict):
            raise ConfigError("configuration must be a JSON object")
        allowed = {field.name for field in dataclasses.fields(cls)}
        if set(data) - allowed:
            raise ConfigError("configuration contains unknown fields")
        panel = validate_panel_url(data.get("panel_url"))
        machine_id = _positive_int(data.get("machine_id"), "machine_id")
        token = data.get("token")
        if not isinstance(token, str) or not 1 <= len(token) <= 256:
            raise ConfigError("token must be a nonempty string of at most 256 characters")
        names = data.get("interfaces")
        if not isinstance(names, list) or not 1 <= len(names) <= 32:
            raise ConfigError("interfaces must be an explicit array of 1..32 NIC names")
        if any(not isinstance(n, str) or not NIC_NAME.fullmatch(n) or n in (".", "..") for n in names):
            raise ConfigError("interfaces contains an invalid NIC name")
        if len(set(names)) != len(names):
            raise ConfigError("interfaces must not contain duplicates")
        state_value = data.get("state_file", str(cls.state_file))
        if not isinstance(state_value, str) or not state_value:
            raise ConfigError("state_file must be an absolute path")
        state_file = Path(state_value)
        if not state_file.is_absolute():
            raise ConfigError("state_file must be an absolute path")
        intervals = {}
        for field, default, limit in (("sample_interval", 10, 3600), ("report_interval", 30, 3600),
                                      ("request_timeout", 10, 60)):
            intervals[field] = _positive_int(data.get(field, default), field, limit)
        return cls(panel, machine_id, token, tuple(names), state_file, **intervals)

    @classmethod
    def load(cls, filename):
        _regular_private_file(filename, "config file")
        try:
            with open(filename, "r", encoding="utf-8") as handle:
                data = json.load(handle)
        except (OSError, UnicodeError, ValueError):
            raise ConfigError("config file must contain valid UTF-8 JSON") from None
        return cls.from_dict(data)


@dataclasses.dataclass(frozen=True)
class Counter:
    ifindex: int
    rx: int
    tx: int


class LinuxReader:
    """Read only explicitly selected sysfs interfaces, never autodiscover or sum all NICs."""

    def __init__(self, interfaces, sys_root=Path("/sys/class/net"), proc_root=Path("/proc")):
        self.interfaces = tuple(interfaces)
        self.sys_root = Path(sys_root)
        self.proc_root = Path(proc_root)

    @staticmethod
    def _number(path, maximum):
        try:
            value = path.read_text(encoding="ascii").strip()
        except (OSError, UnicodeError):
            raise SampleError("a configured NIC counter is unavailable; baseline unchanged") from None
        if not DECIMAL.fullmatch(value) or len(value) > 20:
            raise SampleError("a configured NIC counter is invalid; baseline unchanged")
        number = int(value)
        if number > maximum:
            raise SampleError("a configured NIC counter is out of range; baseline unchanged")
        return number

    def _boot_id(self):
        try:
            raw_boot_id = (self.proc_root / "sys/kernel/random/boot_id").read_text(encoding="ascii").strip()
            boot_id = str(uuid.UUID(raw_boot_id))
            if raw_boot_id.lower() != boot_id:
                raise ValueError()
        except (OSError, UnicodeError, ValueError):
            raise SampleError("host boot_id is unavailable or invalid; baseline unchanged") from None
        return boot_id

    def read(self):
        boot_id = self._boot_id()
        counters = {}
        for name in self.interfaces:
            directory = self.sys_root / name
            first_index = self._number(directory / "ifindex", 2147483647)
            rx = self._number(directory / "statistics/rx_bytes", MAX_COUNTER)
            tx = self._number(directory / "statistics/tx_bytes", MAX_COUNTER)
            second_index = self._number(directory / "ifindex", 2147483647)
            if first_index == 0 or first_index != second_index:
                raise SampleError("a configured NIC changed during sampling; baseline unchanged")
            counters[name] = Counter(first_index, rx, tx)
        if self._boot_id() != boot_id:
            raise SampleError("host boot changed during sampling; baseline unchanged")
        return boot_id, counters


def check_host_namespace(proc_root=Path("/proc"), root=Path("/")):
    """Conservative guard, not a proof against an intentionally hidden container."""
    if sys.platform != "linux":
        raise ConfigError("collection requires a native Linux host; this platform is for mocked tests only")
    if any((root / marker).exists() for marker in (".dockerenv", "run/.containerenv", "run/systemd/container")):
        raise ConfigError("run the collector as a native host service, not inside a container")
    if os.environ.get("container") or os.environ.get("KUBERNETES_SERVICE_HOST"):
        raise ConfigError("container environment detected; run a native host service")
    try:
        cgroup = (proc_root / "self/cgroup").read_text(encoding="ascii", errors="replace").lower()
    except OSError:
        raise ConfigError("cannot inspect process cgroup for host deployment") from None
    if re.search(r"(?:^|[/:-])(?:docker|kubepods|libpod|lxc|podman|containerd)(?:[/.:-]|$)", cgroup):
        raise ConfigError("container cgroup detected; run a native host service")
    try:
        own_namespace = os.stat(proc_root / "self/ns/net")
    except OSError:
        raise ConfigError("cannot inspect collector network namespace") from None
    try:
        init_namespace = os.stat(proc_root / "1/ns/net")
    except PermissionError:
        # procfs namespace dereference can require PTRACE_MODE_READ_FSCREDS. Do not
        # require root/CAP_SYS_PTRACE merely to probe it; the supplied host unit has
        # no PrivateNetwork. Marker checks and deployment responsibility still apply.
        LOG.warning("host PID1 namespace probe is permission-restricted; native host deployment must be verified by the operator")
        return False
    except OSError:
        raise ConfigError("cannot inspect host init network namespace") from None
    if ((own_namespace.st_dev, own_namespace.st_ino) !=
            (init_namespace.st_dev, init_namespace.st_ino)):
            raise ConfigError("collector must share the host init network namespace")
    return True


def prepare_state_directory(state_file):
    parent = state_file.parent
    if parent.is_symlink():
        raise ConfigError("state directory must not be a symlink")
    if not parent.exists():
        parent.mkdir(mode=0o700, parents=True, exist_ok=False)
    if not parent.is_dir():
        raise ConfigError("state directory is invalid")
    if os.name == "posix":
        info = parent.stat()
        if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
            raise ConfigError("state directory must be owned by the running user with mode 0700")


class ProcessLock:
    """One process per durable state directory, held through shutdown; Linux flock only."""

    def __init__(self, state_file):
        self.path = state_file.parent / "collector.lock"
        self.fd = None

    def __enter__(self):
        if fcntl is None:
            raise ConfigError("Linux flock is required to run the collector")
        flags = os.O_RDWR | os.O_CREAT
        flags |= getattr(os, "O_NOFOLLOW", 0)
        try:
            self.fd = os.open(self.path, flags, 0o600)
            info = os.fstat(self.fd)
            if not stat.S_ISREG(info.st_mode):
                raise ConfigError("lock file must be regular")
            if os.name == "posix" and (stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.geteuid()):
                raise ConfigError("lock file must be privately owned with mode 0600")
            fcntl.flock(self.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except (OSError, CollectorError):
            if self.fd is not None:
                os.close(self.fd)
                self.fd = None
            raise ConfigError("cannot acquire collector lock; another process may be running") from None
        return self

    def __exit__(self, *unused):
        if self.fd is not None:
            os.close(self.fd)  # Do not unlink: replacement lock inodes would defeat flock.
            self.fd = None


class Store:
    """Snapshots, sequence, interval and ordered outbox commit in the SAME FULL transaction."""

    def __init__(self, config):
        self.config = config
        self.path = config.state_file
        self._mutex = threading.RLock()
        prepare_state_directory(self.path)
        if self.path.exists() or self.path.is_symlink():
            _regular_private_file(self.path, "state file")
        else:
            flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
            try:
                os.close(os.open(self.path, flags, 0o600))
            except OSError:
                raise StateError("cannot create private state file") from None
        self.db = sqlite3.connect(str(self.path), timeout=10, isolation_level=None, check_same_thread=False)
        try:
            if self.db.execute("PRAGMA journal_mode=WAL").fetchone()[0].lower() != "wal":
                raise StateError("SQLite WAL mode is unavailable")
            self.db.execute("PRAGMA synchronous=FULL")
            self.db.execute("PRAGMA busy_timeout=10000")
            with self.transaction():
                self.db.execute("CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)")
                self.db.execute("CREATE TABLE IF NOT EXISTS snapshots (interface TEXT PRIMARY KEY, boot_id TEXT NOT NULL, "
                                "ifindex INTEGER NOT NULL, rx TEXT NOT NULL, tx TEXT NOT NULL)")
                self.db.execute("CREATE TABLE IF NOT EXISTS outbox (sequence INTEGER PRIMARY KEY CHECK(sequence > 0), "
                                "payload TEXT NOT NULL)")
                version = self.get("schema_version")
                if version not in (None, "1"):
                    raise StateError("unsupported state schema; preserve state and use a compatible collector")
                owner = self.get("machine_id")
                panel = self.get("panel_url")
                if owner is not None and owner != str(config.machine_id):
                    raise StateError("state belongs to a different machine; do not reuse its database")
                if panel is not None and panel != config.panel_url:
                    raise StateError("state belongs to a different panel; do not reuse its database")
                if self.get("collector_id") is None:
                    self.put("collector_id", uuid.uuid4().hex)
                    self.put("sequence", "0")
                self.put("schema_version", "1")
                self.put("machine_id", str(config.machine_id))
                self.put("panel_url", config.panel_url)
        except BaseException:
            self.db.close()
            raise

    @contextlib.contextmanager
    def transaction(self):
        with self._mutex:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                yield
                self.db.execute("COMMIT")
            except BaseException:
                if self.db.in_transaction:
                    self.db.execute("ROLLBACK")
                raise

    def get(self, key):
        row = self.db.execute("SELECT value FROM metadata WHERE key = ?", (key,)).fetchone()
        return row[0] if row else None

    def put(self, key, value):
        self.db.execute("INSERT INTO metadata(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                        (key, str(value)))

    def sample(self, boot_id, counters, ended_at):
        if type(ended_at) is not int or ended_at < MIN_TIMESTAMP:
            raise ClockWait("wall clock is not ready; baseline and sequence unchanged")
        if not counters or tuple(counters) != self.config.interfaces:
            raise SampleError("all configured interfaces must be sampled successfully; baseline unchanged")
        for counter in counters.values():
            if (not isinstance(counter, Counter) or type(counter.ifindex) is not int or counter.ifindex <= 0 or
                    type(counter.rx) is not int or type(counter.tx) is not int or
                    not 0 <= counter.rx <= MAX_COUNTER or not 0 <= counter.tx <= MAX_COUNTER):
                raise SampleError("invalid NIC sample; baseline unchanged")
        try:
            normalized_boot_id = str(uuid.UUID(boot_id))
        except (ValueError, TypeError, AttributeError):
            raise SampleError("invalid boot_id; baseline unchanged") from None
        with self.transaction():
            previous_end = self.get("ended_at")
            if previous_end is not None:
                started_at = int(previous_end)
                if ended_at <= started_at:
                    raise ClockWait("wall clock moved backwards or has not advanced; baseline unchanged")
                if ended_at - started_at > MAX_INTERVAL:
                    raise SampleError("sample interval exceeds protocol limit; preserve state for manual recovery")
            else:
                started_at = None
            previous = {row[0]: row[1:] for row in self.db.execute("SELECT interface,boot_id,ifindex,rx,tx FROM snapshots")}
            rx_delta = tx_delta = resets = 0
            boot_changed = previous_end is not None and self.get("boot_id") != normalized_boot_id
            for name, current in counters.items():
                old = previous.get(name)
                if old is None or started_at is None:
                    continue  # First appearance: baseline, never import its historical bytes.
                old_boot, old_index, old_rx, old_tx = old
                old_rx, old_tx = int(old_rx), int(old_tx)
                if old_boot != normalized_boot_id:
                    rx_delta += current.rx
                    tx_delta += current.tx
                elif old_index != current.ifindex:
                    resets += 1  # New interface generation: baseline only, unknown intervening gap.
                else:
                    rx_reset = current.rx < old_rx
                    tx_reset = current.tx < old_tx
                    resets += int(rx_reset or tx_reset)
                    rx_delta += current.rx if rx_reset else current.rx - old_rx
                    tx_delta += current.tx if tx_reset else current.tx - old_tx
            if rx_delta > MAX_INTEGER or tx_delta > MAX_INTEGER:
                raise SampleError("interval delta exceeds protocol integer limit; baseline unchanged")
            payload = None
            if started_at is not None:
                sequence = int(self.get("sequence")) + 1
                if sequence > MAX_INTEGER:
                    raise StateError("sequence exhausted; preserve state for manual recovery")
                payload = {"protocol": 1, "machine_id": self.config.machine_id,
                           "collector_id": self.get("collector_id"), "sequence": str(sequence),
                           "started_at": started_at, "ended_at": ended_at, "boot_id": normalized_boot_id,
                           "interfaces": list(counters), "rx_bytes": str(rx_delta), "tx_bytes": str(tx_delta),
                           "resets": resets, "boot_changed": bool(boot_changed)}
                self.db.execute("INSERT INTO outbox(sequence,payload) VALUES (?,?)",
                                (sequence, json.dumps(payload, separators=(",", ":"), sort_keys=True)))
                self.put("sequence", sequence)
            self.db.execute("DELETE FROM snapshots")
            self.db.executemany("INSERT INTO snapshots(interface,boot_id,ifindex,rx,tx) VALUES (?,?,?,?,?)",
                                [(name, normalized_boot_id, value.ifindex, str(value.rx), str(value.tx))
                                 for name, value in counters.items()])
            self.put("ended_at", ended_at)
            self.put("boot_id", normalized_boot_id)
            return payload

    def peek(self):
        with self._mutex:
            row = self.db.execute("SELECT sequence,payload FROM outbox ORDER BY sequence LIMIT 1").fetchone()
            return (str(row[0]), json.loads(row[1])) if row else None

    def acknowledge(self, sequence):
        with self.transaction():
            row = self.db.execute("SELECT sequence FROM outbox ORDER BY sequence LIMIT 1").fetchone()
            if row is None or str(row[0]) != sequence:
                return False
            self.db.execute("DELETE FROM outbox WHERE sequence = ?", (int(sequence),))
            return True

    def pending(self):
        with self._mutex:
            return self.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0]

    def close(self):
        with self._mutex:
            self.db.close()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never send a machine token to a redirect target, including same-origin redirects.


class PublicIPv4:
    """Independent token-free lookup; only the report worker waits (at most 3s)."""
    def __init__(self, opener=None, clock=time.monotonic):
        self.opener = opener or urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
        self.clock, self.next_lookup, self.address, self.valid_until = clock, 0, None, 0

    def get(self):
        now = self.clock()
        if now >= self.next_lookup:
            self.next_lookup = now + 300
            try:
                request = urllib.request.Request('https://api-ipv4.ip.sb/ip', headers={'User-Agent': 'FlowScope-IP/' + VERSION, 'Accept': 'text/plain'})
                with self.opener.open(request, timeout=3) as response:
                    if response.status != 200:
                        raise ValueError()
                    raw = response.read(65)
                address = ipaddress.ip_address(raw.decode('ascii').strip())
                if address.version != 4 or not address.is_global:
                    raise ValueError()
                self.address, self.valid_until, self.next_lookup = str(address), now + 7200, now + 3600
            except (OSError, ValueError, urllib.error.URLError, http.client.HTTPException):
                pass  # IP lookup failure must never block accounting or reveal transport data.
        return self.address if now < self.valid_until else None


class HTTPClient:
    def __init__(self, config, opener=None, ipv4_lookup=None):
        self.config = config
        self.ipv4_lookup = ipv4_lookup
        self.opener = opener or urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def send(self, payload):
        authenticated = dict(payload)
        authenticated["token"] = self.config.token  # Token is never persisted in the outbox.
        request = urllib.request.Request(
            self.config.endpoint,
            data=json.dumps(authenticated, separators=(",", ":")).encode("utf-8"),
            headers={"Content-Type": "application/json", "Accept": "application/json",
                     "User-Agent": "FlowScope-Collector/" + VERSION}, method="POST")
        if self.ipv4_lookup:
            address = self.ipv4_lookup.get()
            if address:
                request.add_header('X-FlowScope-IPv4', address)
        try:
            with self.opener.open(request, timeout=self.config.request_timeout) as response:
                if response.status != 200:
                    raise ReportError("unexpected report HTTP status")
                if response.headers.get_content_type() != "application/json":
                    raise ReportError("report response is not JSON")
                raw = response.read(MAX_RESPONSE + 1)
                if len(raw) > MAX_RESPONSE:
                    raise ReportError("report response exceeds size limit")
        except urllib.error.HTTPError as error:
            status_code = error.code
            error.close()
            if status_code == 409:
                raise ReportError("sequence conflict (HTTP 409); sending paused, preserve state for manual review", True) from None
            # Disable/reenable (404) and machine auth availability (401/403) can recover.
            # Config/token changes still require an explicit service restart to load them.
            permanent = status_code in (301, 302, 303, 307, 308, 400, 405, 413, 422)
            raise ReportError("report rejected (HTTP %d)" % status_code, permanent) from None
        except (urllib.error.URLError, OSError, ValueError, http.client.HTTPException):
            raise ReportError("report transport failed; durable outbox retained") from None
        try:
            result = json.loads(raw.decode("utf-8"))
        except (ValueError, UnicodeError):
            raise ReportError("report response contains invalid JSON") from None
        data = result.get("data") if isinstance(result, dict) else None
        if (not isinstance(data, dict) or data.get("accepted") is not True or
                type(data.get("sequence")) is not str or data["sequence"] != payload["sequence"] or
                type(data.get("duplicate")) is not bool):
            raise ReportError("report acknowledgement does not match the pending sequence")
        return data


class Sender:
    def __init__(self, store, client):
        self.store = store
        self.client = client
        self.blocked = False

    def send_pending(self, limit=100, stop=None):
        sent = 0
        while not self.blocked and sent < limit and not (stop and stop.is_set()):
            pending = self.store.peek()
            if pending is None:
                break
            sequence, payload = pending
            try:
                self.client.send(payload)
                if not self.store.acknowledge(sequence):
                    raise StateError("outbox changed unexpectedly; preserve state")
            except ReportError as error:
                self.blocked = error.permanent
                LOG.warning("%s", error)  # Only our secret-free messages, never URLs/bodies/transport exceptions.
                break
            except (sqlite3.Error, OSError, StateError):
                self.blocked = True
                LOG.error("cannot commit acknowledgement; sending paused and outbox retained")
                break
            sent += 1
        return sent


class Sampler:
    def __init__(self, store, reader, clock=time.time):
        self.store = store
        self.reader = reader
        self.clock = clock

    def sample(self):
        boot_id, counters = self.reader.read()
        return self.store.sample(boot_id, counters, int(self.clock()))


def run(config, once=False):
    check_host_namespace()
    prepare_state_directory(config.state_file)
    reader = LinuxReader(config.interfaces)
    with ProcessLock(config.state_file):
        store = Store(config)
        try:
            sampler = Sampler(store, reader)
            if once:
                sampler.sample()
                sender = Sender(store, HTTPClient(config, ipv4_lookup=PublicIPv4()))
                sender.send_pending()
                pending = store.pending()
                print("one sampling pass complete; pending reports: %d" % pending)
                return 1 if pending else 0
            stop = threading.Event()
            worker_failed = threading.Event()

            def terminate(signum, frame):
                stop.set()

            signal.signal(signal.SIGTERM, terminate)
            signal.signal(signal.SIGINT, terminate)

            def reporting():
                sender_store = None
                try:
                    sender_store = Store(config)
                    sender = Sender(sender_store, HTTPClient(config, ipv4_lookup=PublicIPv4()))
                    while not stop.is_set():
                        sender.send_pending(stop=stop)
                        stop.wait(config.report_interval)
                except Exception:
                    worker_failed.set()
                    stop.set()
                    LOG.error("report worker stopped; durable state retained; restart after inspection")
                finally:
                    if sender_store is not None:
                        sender_store.close()

            worker = threading.Thread(target=reporting, name="flowscope-report", daemon=True)
            worker.start()
            LOG.info("collector started; explicit interfaces=%d; version=%s", len(config.interfaces), VERSION)
            while not stop.is_set():
                if not worker.is_alive():
                    worker_failed.set()
                    stop.set()
                    LOG.error("report worker exited unexpectedly; stopping for service restart")
                    break
                started = time.monotonic()
                try:
                    sampler.sample()
                except (ClockWait, SampleError) as error:
                    LOG.warning("%s", error)
                except (sqlite3.Error, OSError, StateError):
                    LOG.error("sample transaction failed; baseline unchanged; check durable storage")
                stop.wait(max(0, config.sample_interval - (time.monotonic() - started)))
            worker.join(timeout=config.request_timeout + 2)
            LOG.info("collector stopped; unacknowledged reports remain durable")
            return 1 if worker_failed.is_set() else 0
        finally:
            store.close()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", action="version", version="FlowScope collector " + VERSION)
    parser.add_argument("--config", default="/etc/flowscope-collector.json", help="private JSON config file (0600)")
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--check", action="store_true", help="validate config and host counters; no HTTP or state mutation")
    modes.add_argument("--once", action="store_true", help="one sample and ordered delivery pass, retaining unacknowledged data")
    args = parser.parse_args(argv)
    os.umask(0o077)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    try:
        config = Config.load(args.config)
        if args.check:
            check_host_namespace()
            LinuxReader(config.interfaces).read()
            if config.state_file.exists() or config.state_file.is_symlink():
                _regular_private_file(config.state_file, "state file")
            print("configuration and host NIC counters are valid; no report sent and no state changed")
            return 0
        return run(config, args.once)
    except CollectorError as error:
        LOG.error("%s", error)
        return 1
    except (sqlite3.Error, OSError):
        LOG.error("collector cannot access durable storage or host counters; inspect permissions and disk space")
        return 1


if __name__ == "__main__":
    sys.exit(main())
