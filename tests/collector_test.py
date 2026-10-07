"""Cross-platform mocked tests. Linux/systemd deployment is deliberately NOT claimed."""

import contextlib
from email.message import Message
import importlib.util
import io
import json
import logging
import os
from pathlib import Path
import sqlite3
import ssl
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "collector" / "flowscope_collector.py"
SPEC = importlib.util.spec_from_file_location("flowscope_collector", MODULE)
c = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = c
SPEC.loader.exec_module(c)
BOOT_A = "12345678-1234-4234-8234-123456789abc"
BOOT_B = "23456789-2345-4345-8345-23456789abcd"
T = 1791331200
SECRET = "not-for-logs-SECRET-TOKEN"


class Fixture(unittest.TestCase):
    def setUp(self):
        runtime = ROOT / "tests" / ".runtime"
        runtime.mkdir(exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix="collector-tests-", dir=str(runtime))
        self.directory = Path(self.temporary.name).resolve()
        self.assertEqual(self.directory.parent, runtime.resolve())
        self.addCleanup(self.temporary.cleanup)
        self.state_directory = self.directory / "private-state"
        self.state_directory.mkdir(mode=0o700)
        self.config = c.Config.from_dict(self.config_data())
        self.open_stores = []
        self.addCleanup(self.close_stores)

    def config_data(self, **overrides):
        data = {"panel_url": "https://panel.example.test", "machine_id": 41, "token": SECRET,
                "interfaces": ["eth0"], "state_file": str(self.state_directory / "state.sqlite3")}
        data.update(overrides)
        return data

    def open_store(self, config=None):
        store = c.Store(config or self.config)
        self.open_stores.append(store)
        return store

    def close_store(self, store):
        store.close()
        self.open_stores.remove(store)

    def close_stores(self):
        for store in self.open_stores:
            store.close()
        self.open_stores.clear()

    @staticmethod
    def counters(rx=100, tx=200, ifindex=2):
        return {"eth0": c.Counter(ifindex, rx, tx)}

    def baseline(self, store, rx=100, tx=200):
        self.assertIsNone(store.sample(BOOT_A, self.counters(rx, tx), T))

    def queued(self, store):
        self.baseline(store)
        return store.sample(BOOT_A, self.counters(130, 270), T + 10)


class ConfigTests(Fixture):
    def test_defaults_and_version(self):
        self.assertEqual(self.config.sample_interval, 10)
        self.assertEqual(self.config.report_interval, 30)
        self.assertEqual(self.config.request_timeout, 10)
        self.assertEqual(c.VERSION, "2.2.0")
        self.assertEqual(self.config.endpoint, "https://panel.example.test/api/v2/flowscope/collect")

    def test_explicit_interfaces_required(self):
        for value in (None, [], "eth0", ["eth0", "eth0"], ["../eth0"], ["."], [".."],
                      ["eth 0"], ["网络"], ["a" * 65], ["eth0"] * 33):
            with self.subTest(value=value), self.assertRaises(c.ConfigError):
                c.Config.from_dict(self.config_data(interfaces=value))

    def test_nic_ascii_names_supported(self):
        cfg = c.Config.from_dict(self.config_data(interfaces=["enp1s0", "bond0.10", "eth0@if3", "nic:alias", "test-nic"]))
        self.assertEqual(len(cfg.interfaces), 5)

    def test_identity_token_and_unknown_options(self):
        for changes in ({"machine_id": True}, {"machine_id": 0}, {"machine_id": "41"},
                        {"machine_id": c.MAX_INTEGER + 1}, {"token": ""}, {"token": None},
                        {"token": "x" * 257}, {"verify_tls": False}, {"state_file": "relative.sqlite3"}):
            with self.subTest(changes=changes), self.assertRaises(c.ConfigError):
                c.Config.from_dict(self.config_data(**changes))

    def test_intervals_have_bounded_integer_values(self):
        for field, value in (("sample_interval", 0), ("sample_interval", 3601), ("report_interval", True),
                             ("report_interval", 0.5), ("request_timeout", 61)):
            with self.subTest(field=field, value=value), self.assertRaises(c.ConfigError):
                c.Config.from_dict(self.config_data(**{field: value}))

    def test_https_and_loopback_exception(self):
        for url in ("https://panel.example.test/", "https://panel.example.test/xboard", "http://localhost:9999",
                    "http://127.0.0.1:9000", "http://[::1]:9000"):
            with self.subTest(url=url):
                self.assertTrue(c.validate_panel_url(url))
        for url in ("http://panel.example.test", "ftp://localhost", "https://user:pass@example.test",
                    "https://example.test/?token=secret", "https://example.test/#secret", "https://example.test\n",
                    "https://", "https://example.test:wrong", "https://example.test\\@attacker.test"):
            with self.subTest(url=url), self.assertRaises(c.ConfigError):
                c.validate_panel_url(url)

    def test_private_config_load_and_sanitized_json_failure(self):
        path = self.directory / "config.json"
        path.write_text(json.dumps(self.config_data()), encoding="utf-8")
        path.chmod(0o600)
        self.assertEqual(c.Config.load(path), self.config)
        path.write_text('{"token":"' + SECRET, encoding="utf-8")
        with self.assertRaises(c.ConfigError) as error:
            c.Config.load(path)
        self.assertNotIn(SECRET, str(error.exception))

    @unittest.skipUnless(os.name == "posix", "POSIX mode enforcement requires a Linux/Unix filesystem")
    def test_exposed_config_and_symlink_refused(self):
        path = self.directory / "config.json"
        path.write_text(json.dumps(self.config_data()), encoding="utf-8")
        path.chmod(0o644)
        with self.assertRaises(c.ConfigError):
            c.Config.load(path)
        path.chmod(0o600)
        link = self.directory / "link.json"
        link.symlink_to(path)
        with self.assertRaises(c.ConfigError):
            c.Config.load(link)

    def test_cli_version_works_without_linux_or_config(self):
        result = subprocess.run([sys.executable, str(MODULE), "--version"], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertIn("2.2.0", result.stdout)
        self.assertNotIn(SECRET, result.stdout + result.stderr)


class StoreTests(Fixture):
    def test_first_baseline_no_historic_report(self):
        store = self.open_store()
        self.baseline(store, 9000000000000, 7000000000000)
        self.assertEqual(store.pending(), 0)
        self.assertEqual(store.get("sequence"), "0")
        self.assertRegex(store.get("collector_id"), r"^[0-9a-f]{32}$")

    def test_exact_interval_and_outbox_has_no_token(self):
        store = self.open_store()
        payload = self.queued(store)
        self.assertEqual(payload, {"protocol": 1, "machine_id": 41, "collector_id": store.get("collector_id"),
                                  "sequence": "1", "started_at": T, "ended_at": T + 10, "boot_id": BOOT_A,
                                  "interfaces": ["eth0"], "rx_bytes": "30", "tx_bytes": "70",
                                  "resets": 0, "boot_changed": False})
        self.assertEqual(store.peek(), ("1", payload))
        self.assertNotIn(SECRET, store.db.execute("SELECT payload FROM outbox").fetchone()[0])
        self.assertNotIn(SECRET, str(store.db.execute("SELECT * FROM metadata").fetchall()))

    def test_zero_traffic_still_preserves_contiguous_intervals(self):
        store = self.open_store()
        self.baseline(store)
        payload = store.sample(BOOT_A, self.counters(), T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"]), ("0", "0"))
        payload2 = store.sample(BOOT_A, self.counters(), T + 20)
        self.assertEqual((payload2["sequence"], payload2["started_at"]), ("2", T + 10))

    def test_wal_full_and_restart_resume_stable_id_and_baseline(self):
        store = self.open_store()
        one = self.queued(store)
        identity = store.get("collector_id")
        self.assertEqual(store.db.execute("PRAGMA journal_mode").fetchone()[0], "wal")
        self.assertEqual(store.db.execute("PRAGMA synchronous").fetchone()[0], 2)
        self.close_store(store)
        restarted = self.open_store()
        self.assertEqual(restarted.get("collector_id"), identity)
        self.assertEqual(restarted.peek(), ("1", one))
        two = restarted.sample(BOOT_A, self.counters(150, 300), T + 20)
        self.assertEqual((two["sequence"], two["started_at"], two["rx_bytes"], two["tx_bytes"]),
                         ("2", T + 10, "20", "30"))

    def test_ordered_ack_only_exact_head_deletes(self):
        store = self.open_store()
        self.queued(store)
        store.sample(BOOT_A, self.counters(150, 300), T + 20)
        for invalid in ("2", "999", "01", 1, "0"):
            self.assertFalse(store.acknowledge(invalid))
        self.assertEqual(store.pending(), 2)
        self.assertTrue(store.acknowledge("1"))
        self.assertFalse(store.acknowledge("1"))
        self.assertEqual(store.peek()[0], "2")
        self.assertTrue(store.acknowledge("2"))
        self.assertEqual(store.pending(), 0)
        self.assertEqual(store.get("sequence"), "2")

    def test_repurpose_machine_and_panel_failclosed_but_token_rotation_allowed(self):
        store = self.open_store()
        self.queued(store)
        identity = store.get("collector_id")
        self.close_store(store)
        for changes in ({"machine_id": 42}, {"panel_url": "https://other.example.test"}):
            with self.assertRaises(c.StateError):
                c.Store(c.Config.from_dict(self.config_data(**changes)))
        rotated = self.open_store(c.Config.from_dict(self.config_data(token="rotated-token")))
        self.assertEqual(rotated.get("collector_id"), identity)
        self.assertEqual(rotated.pending(), 1)

    def test_boot_change_counts_current_and_marks_gap(self):
        store = self.open_store()
        self.baseline(store, 1000, 2000)
        payload = store.sample(BOOT_B, self.counters(12, 18), T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"]), ("12", "18"))
        self.assertTrue(payload["boot_changed"])
        self.assertEqual(payload["resets"], 0)

    def test_same_boot_single_direction_reset_does_not_double_other_direction(self):
        store = self.open_store()
        self.baseline(store, 1000, 2000)
        payload = store.sample(BOOT_A, self.counters(12, 2025), T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"], payload["resets"]), ("12", "25", 1))
        self.assertFalse(payload["boot_changed"])

    def test_per_nic_reset_does_not_pollute_other_nic(self):
        cfg = c.Config.from_dict(self.config_data(interfaces=["eth0", "eth1"]))
        store = self.open_store(cfg)
        store.sample(BOOT_A, {"eth0": c.Counter(2, 1000, 2000), "eth1": c.Counter(3, 500, 600)}, T)
        payload = store.sample(BOOT_A, {"eth0": c.Counter(2, 10, 20), "eth1": c.Counter(3, 550, 670)}, T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"], payload["resets"]), ("60", "90", 1))

    def test_new_ifindex_baseline_only_marks_generation_gap(self):
        store = self.open_store()
        self.baseline(store)
        payload = store.sample(BOOT_A, self.counters(5000, 8000, ifindex=9), T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"], payload["resets"]), ("0", "0", 1))
        second = store.sample(BOOT_A, self.counters(5004, 8007, ifindex=9), T + 20)
        self.assertEqual((second["rx_bytes"], second["tx_bytes"]), ("4", "7"))

    def test_allowlist_addition_baselines_new_interface(self):
        store = self.open_store()
        self.baseline(store)
        identity = store.get("collector_id")
        self.close_store(store)
        cfg = c.Config.from_dict(self.config_data(interfaces=["eth0", "eth1"]))
        store = self.open_store(cfg)
        payload = store.sample(BOOT_A, {"eth0": c.Counter(2, 105, 209), "eth1": c.Counter(3, 900000, 800000)}, T + 10)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"]), ("5", "9"))
        self.assertEqual(store.get("collector_id"), identity)

    def test_removed_then_readded_nic_is_not_historically_imported(self):
        cfg_two = c.Config.from_dict(self.config_data(interfaces=["eth0", "eth1"]))
        store = self.open_store(cfg_two)
        store.sample(BOOT_A, {"eth0": c.Counter(2, 100, 200), "eth1": c.Counter(3, 1000, 2000)}, T)
        self.close_store(store)
        store = self.open_store()
        store.sample(BOOT_A, self.counters(110, 220), T + 10)
        self.close_store(store)
        store = self.open_store(cfg_two)
        payload = store.sample(BOOT_A, {"eth0": c.Counter(2, 120, 240), "eth1": c.Counter(3, 9999, 9999)}, T + 20)
        self.assertEqual((payload["rx_bytes"], payload["tx_bytes"]), ("10", "20"))

    def test_clock_rollback_waits_without_losing_accumulated_bytes(self):
        store = self.open_store()
        self.queued(store)
        for moment in (T, T + 5, T + 10):
            with self.assertRaises(c.ClockWait):
                store.sample(BOOT_A, self.counters(180, 300), moment)
        self.assertEqual((store.get("ended_at"), store.get("sequence"), store.pending()), (str(T + 10), "1", 1))
        payload = store.sample(BOOT_A, self.counters(185, 310), T + 11)
        self.assertEqual((payload["started_at"], payload["rx_bytes"], payload["tx_bytes"]), (T + 10, "55", "40"))

    def test_bad_clock_and_excessive_interval_do_not_advance_state(self):
        store = self.open_store()
        with self.assertRaises(c.ClockWait):
            store.sample(BOOT_A, self.counters(), 100)
        self.assertIsNone(store.get("ended_at"))
        self.baseline(store)
        with self.assertRaises(c.SampleError):
            store.sample(BOOT_A, self.counters(200, 300), T + c.MAX_INTERVAL + 1)
        self.assertEqual(store.get("ended_at"), str(T))

    def test_precision_above_js_integer_limit(self):
        store = self.open_store()
        self.baseline(store, 0, 0)
        payload = store.sample(BOOT_A, self.counters(9007199254740993, c.MAX_INTEGER), T + 10)
        self.assertEqual(payload["rx_bytes"], "9007199254740993")
        self.assertEqual(payload["tx_bytes"], str(c.MAX_INTEGER))

    def test_delta_overflow_and_sequence_exhaustion_preserve_state(self):
        store = self.open_store()
        self.baseline(store, 0, 0)
        with self.assertRaises(c.SampleError):
            store.sample(BOOT_A, self.counters(c.MAX_INTEGER + 1, 1), T + 10)
        self.assertEqual(store.get("sequence"), "0")
        store.put("sequence", c.MAX_INTEGER)
        with self.assertRaises(c.StateError):
            store.sample(BOOT_A, self.counters(1, 1), T + 10)
        self.assertEqual(store.get("ended_at"), str(T))
        self.assertEqual(store.pending(), 0)

    def test_read_failure_or_partial_sample_does_not_advance_any_baseline(self):
        store = self.open_store()
        self.baseline(store)
        reader = mock.Mock()
        reader.read.side_effect = c.SampleError("mocked read failure")
        with self.assertRaises(c.SampleError):
            c.Sampler(store, reader, clock=lambda: T + 10).sample()
        with self.assertRaises(c.SampleError):
            store.sample(BOOT_A, {}, T + 10)
        self.assertEqual((store.get("ended_at"), store.get("sequence")), (str(T), "0"))
        good = store.sample(BOOT_A, self.counters(160, 290), T + 20)
        self.assertEqual((good["rx_bytes"], good["tx_bytes"]), ("60", "90"))

    def test_failed_transaction_rolls_back_snapshot_outbox_and_sequence(self):
        store = self.open_store()
        self.baseline(store)
        original = store.put

        def fail_after_sequence(key, value):
            original(key, value)
            if key == "sequence":
                raise sqlite3.OperationalError("simulated full disk/crash")

        with mock.patch.object(store, "put", side_effect=fail_after_sequence):
            with self.assertRaises(sqlite3.OperationalError):
                store.sample(BOOT_A, self.counters(150, 300), T + 10)
        self.assertEqual((store.pending(), store.get("sequence"), store.get("ended_at")), (0, "0", str(T)))
        self.assertEqual(store.db.execute("SELECT rx,tx FROM snapshots").fetchone(), ("100", "200"))
        recovered = store.sample(BOOT_A, self.counters(170, 310), T + 20)
        self.assertEqual((recovered["sequence"], recovered["rx_bytes"], recovered["tx_bytes"]), ("1", "70", "110"))

    def test_sqlite_fresh_connection_sees_committed_outbox_without_clean_shutdown(self):
        store = self.open_store()
        payload = self.queued(store)
        other = self.open_store()
        self.assertEqual(other.peek(), ("1", payload))
        self.assertEqual(other.get("ended_at"), str(T + 10))

    @unittest.skipUnless(os.name == "posix", "POSIX state-file modes require a Linux/Unix filesystem")
    def test_private_database_wal_and_directory(self):
        store = self.open_store()
        self.queued(store)
        for path in (self.config.state_file, Path(str(self.config.state_file) + "-wal"),
                     Path(str(self.config.state_file) + "-shm")):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.state_directory.stat().st_mode), 0o700)


class ReaderTests(Fixture):
    def setUp(self):
        super().setUp()
        self.sys_root = self.directory / "sys/class/net"
        self.proc_root = self.directory / "proc"
        boot = self.proc_root / "sys/kernel/random/boot_id"
        boot.parent.mkdir(parents=True)
        boot.write_text(BOOT_A, encoding="ascii")
        (self.proc_root / "self").mkdir()
        (self.proc_root / "self/cgroup").write_text("0::/\n", encoding="ascii")
        self.write_nic("eth0", 2, 100, 200)

    def write_nic(self, name, ifindex, rx, tx):
        directory = self.sys_root / name
        (directory / "statistics").mkdir(parents=True, exist_ok=True)
        (directory / "ifindex").write_text(str(ifindex), encoding="ascii")
        (directory / "statistics/rx_bytes").write_text(str(rx), encoding="ascii")
        (directory / "statistics/tx_bytes").write_text(str(tx), encoding="ascii")

    def reader(self, names=("eth0",)):
        return c.LinuxReader(names, self.sys_root, self.proc_root)

    def test_rx_is_inbound_tx_is_outbound_and_no_automatic_interface_sum(self):
        for name in ("lo", "veth1", "bond0", "docker0", "br-1"):
            self.write_nic(name, 3, 999999, 999999)
        boot, counters = self.reader().read()
        self.assertEqual(boot, BOOT_A)
        self.assertEqual(counters, {"eth0": c.Counter(2, 100, 200)})

    def test_missing_configured_nic_fails_entire_sample(self):
        with self.assertRaises(c.SampleError):
            self.reader(("eth0", "missing0")).read()

    def test_invalid_or_overflow_counter_and_boot_uuid(self):
        path = self.sys_root / "eth0/statistics/rx_bytes"
        for value in ("-1", "1.5", "not-a-counter", str(c.MAX_COUNTER + 1), "１２"):
            path.write_text(value, encoding="utf-8")
            with self.subTest(value=value), self.assertRaises(c.SampleError):
                self.reader().read()
        path.write_text("100", encoding="ascii")
        (self.proc_root / "sys/kernel/random/boot_id").write_text("invalid", encoding="ascii")
        with self.assertRaises(c.SampleError):
            self.reader().read()

    def test_interface_recreation_during_read_is_rejected(self):
        reader = self.reader()
        with mock.patch.object(reader, "_number", side_effect=[2, 100, 200, 9]):
            with self.assertRaises(c.SampleError):
                reader.read()

    def test_boot_change_during_read_is_rejected(self):
        reader = self.reader()
        with mock.patch.object(reader, "_boot_id", side_effect=[BOOT_A, BOOT_B]):
            with self.assertRaises(c.SampleError):
                reader.read()

    def test_unsupported_host_rejected_not_silently_windows_sampled(self):
        with mock.patch.object(c.sys, "platform", "win32"):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace()

    def test_known_container_rejected(self):
        (self.directory / ".dockerenv").write_text("", encoding="ascii")
        with mock.patch.object(c.sys, "platform", "linux"):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace(self.proc_root, self.directory)

    def test_network_namespace_mismatch_rejected(self):
        original = c.os.stat

        def inspect(path, *args, **kwargs):
            if Path(path) == self.proc_root / "self/ns/net":
                return mock.Mock(st_dev=1, st_ino=1)
            if Path(path) == self.proc_root / "1/ns/net":
                return mock.Mock(st_dev=1, st_ino=2)
            return original(path, *args, **kwargs)

        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {}, clear=True), \
                mock.patch.object(c.os, "stat", side_effect=inspect):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace(self.proc_root, self.directory)

    def test_nonroot_pid1_permission_denied_warns_without_requiring_capabilities(self):
        original = c.os.stat

        def inspect(path, *args, **kwargs):
            if Path(path) == self.proc_root / "self/ns/net":
                return mock.Mock(st_dev=1, st_ino=1)
            if Path(path) == self.proc_root / "1/ns/net":
                raise PermissionError("restricted procfs")
            return original(path, *args, **kwargs)

        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {}, clear=True), \
                mock.patch.object(c.os, "stat", side_effect=inspect), self.assertLogs(c.LOG, level="WARNING") as logs:
            self.assertFalse(c.check_host_namespace(self.proc_root, self.directory))
        self.assertIn("permission-restricted", " ".join(logs.output))

    def test_pid1_namespace_missing_still_failsclosed(self):
        original = c.os.stat

        def inspect(path, *args, **kwargs):
            if Path(path) == self.proc_root / "self/ns/net":
                return mock.Mock(st_dev=1, st_ino=1)
            if Path(path) == self.proc_root / "1/ns/net":
                raise FileNotFoundError("missing procfs")
            return original(path, *args, **kwargs)

        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {}, clear=True), \
                mock.patch.object(c.os, "stat", side_effect=inspect):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace(self.proc_root, self.directory)

    def test_same_namespace_requires_device_and_inode_match(self):
        original = c.os.stat

        def inspect(path, *args, **kwargs):
            if Path(path) in (self.proc_root / "self/ns/net", self.proc_root / "1/ns/net"):
                return mock.Mock(st_dev=11, st_ino=22)
            return original(path, *args, **kwargs)

        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {}, clear=True), \
                mock.patch.object(c.os, "stat", side_effect=inspect):
            self.assertTrue(c.check_host_namespace(self.proc_root, self.directory))

    def test_container_env_and_cgroup_detected_without_namespace_probe(self):
        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {"container": "podman"}, clear=True):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace(self.proc_root, self.directory)
        (self.proc_root / "self/cgroup").write_text("0::/docker/abcdef\n", encoding="ascii")
        with mock.patch.object(c.sys, "platform", "linux"), mock.patch.dict(c.os.environ, {}, clear=True):
            with self.assertRaises(c.ConfigError):
                c.check_host_namespace(self.proc_root, self.directory)


class Response:
    def __init__(self, payload=None, raw=None, status=200, content_type="application/json"):
        self.status = status
        self.headers = Message()
        self.headers["Content-Type"] = content_type
        self.raw = raw if raw is not None else json.dumps(payload).encode()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        pass

    def read(self, count):
        return self.raw[:count]


class HTTPTests(Fixture):
    def test_public_ipv4_cache_failure_and_privacy(self):
        opener = mock.Mock()
        opener.open.return_value = Response(raw=b'8.8.4.4\n')
        clock = mock.Mock(return_value=10)
        lookup = c.PublicIPv4(opener, clock)
        self.assertEqual(lookup.get(), '8.8.4.4')
        self.assertEqual(lookup.get(), '8.8.4.4')
        self.assertEqual(opener.open.call_count, 1)
        req = opener.open.call_args.args[0]
        self.assertEqual(req.full_url, 'https://api-ipv4.ip.sb/ip')
        self.assertIsNone(req.data)
        self.assertNotIn(SECRET, str(req.header_items()))
        self.assertTrue(req.get_header('User-agent').startswith('FlowScope-IP/'))
        clock.return_value = 3611
        opener.open.side_effect = OSError('private-transport-detail')
        self.assertEqual(lookup.get(), '8.8.4.4')
        clock.return_value = 7211
        self.assertIsNone(lookup.get())
        for raw in (b'2001:4860:4860::8888', b'192.168.1.1', b'127.0.0.1', b'not an ip', b'x'*65):
            opener = mock.Mock()
            opener.open.return_value = Response(raw=raw)
            self.assertIsNone(c.PublicIPv4(opener).get())

    def test_ipv4_metadata_never_changes_durable_payload(self):
        client, opener = self.client(Response(self.good_ack()))
        client.ipv4_lookup = mock.Mock()
        client.ipv4_lookup.get.return_value = '8.8.4.4'
        original = json.dumps(self.payload, sort_keys=True)
        client.send(self.payload)
        req = opener.open.call_args.args[0]
        self.assertEqual(req.get_header('X-flowscope-ipv4'), '8.8.4.4')
        self.assertEqual(json.dumps(self.payload, sort_keys=True), original)
        self.assertNotIn('ipv4', json.loads(req.data))

    def setUp(self):
        super().setUp()
        self.store = self.open_store()
        self.payload = self.queued(self.store)

    def good_ack(self, duplicate=False):
        return {"data": {"accepted": True, "sequence": "1", "duplicate": duplicate}}

    def client(self, response):
        opener = mock.Mock()
        opener.open.return_value = response
        return c.HTTPClient(self.config, opener), opener

    def test_exact_ack_and_token_added_only_to_post_body(self):
        client, opener = self.client(Response(self.good_ack()))
        ack = client.send(self.payload)
        request = opener.open.call_args[0][0]
        body = json.loads(request.data)
        self.assertEqual(body["token"], SECRET)
        self.assertNotIn(SECRET, request.full_url)
        self.assertEqual(request.method, "POST")
        self.assertEqual(opener.open.call_args[1]["timeout"], 10)
        self.assertFalse(ack["duplicate"])

    def test_mismatched_and_malformed_acks_never_delete_outbox(self):
        values = (None, [], {}, {"data": None}, {"data": {"accepted": 1, "sequence": "1", "duplicate": False}},
                  {"data": {"accepted": True, "sequence": 1, "duplicate": False}},
                  {"data": {"accepted": True, "sequence": "2", "duplicate": False}},
                  {"data": {"accepted": True, "sequence": "01", "duplicate": False}},
                  {"data": {"accepted": True, "sequence": "1", "duplicate": 0}})
        for value in values:
            with self.subTest(value=value):
                client, unused = self.client(Response(value))
                sender = c.Sender(self.store, client)
                with self.assertLogs(c.LOG, level="WARNING"):
                    self.assertEqual(sender.send_pending(), 0)
                self.assertEqual(self.store.pending(), 1)

    def test_response_size_mime_status_and_json_validation(self):
        responses = [Response(raw=b"x" * (c.MAX_RESPONSE + 1)), Response(raw=b"not-json"),
                     Response(self.good_ack(), content_type="text/html"), Response(self.good_ack(), status=204)]
        for response in responses:
            with self.subTest(response=response):
                client, unused = self.client(response)
                with self.assertRaises(c.ReportError):
                    client.send(self.payload)
                self.assertEqual(self.store.pending(), 1)

    def test_permanent_http_errors_preserve_outbox_and_do_not_log_response_or_token(self):
        for status in (301, 302, 303, 307, 308, 400, 409, 413, 422):
            opener = mock.Mock()
            opener.open.side_effect = urllib.error.HTTPError("https://secret-target.test/" + SECRET, status, SECRET,
                                                            Message(), io.BytesIO(SECRET.encode()))
            sender = c.Sender(self.store, c.HTTPClient(self.config, opener))
            with self.assertLogs(c.LOG, level="WARNING") as logs:
                self.assertEqual(sender.send_pending(), 0)
            self.assertTrue(sender.blocked)
            self.assertNotIn(SECRET, " ".join(logs.output))
            self.assertEqual(self.store.pending(), 1)

    def test_transient_transport_or_5xx_failure_retained_for_later_retry(self):
        for failure in (urllib.error.URLError(SECRET), TimeoutError(SECRET),
                        *[urllib.error.HTTPError("https://x/" + SECRET, code, SECRET, Message(), io.BytesIO(b"secret"))
                          for code in (401, 403, 404, 429, 503)]):
            opener = mock.Mock()
            opener.open.side_effect = failure
            sender = c.Sender(self.store, c.HTTPClient(self.config, opener))
            with self.assertLogs(c.LOG, level="WARNING") as logs:
                self.assertEqual(sender.send_pending(), 0)
            self.assertFalse(sender.blocked)
            self.assertEqual(self.store.pending(), 1)
            self.assertNotIn(SECRET, " ".join(logs.output))

    def test_disabled_endpoint_404_then_reenable_auto_replays_same_payload(self):
        opener = mock.Mock()
        opener.open.side_effect = [urllib.error.HTTPError("https://x", 404, "disabled", Message(), io.BytesIO()),
                                   Response(self.good_ack())]
        sender = c.Sender(self.store, c.HTTPClient(self.config, opener))
        with self.assertLogs(c.LOG, level="WARNING"):
            self.assertEqual(sender.send_pending(), 0)
        self.assertFalse(sender.blocked)
        self.assertEqual(sender.send_pending(), 1)
        first = json.loads(opener.open.call_args_list[0][0][0].data)
        second = json.loads(opener.open.call_args_list[1][0][0].data)
        self.assertEqual(first, second)
        self.assertEqual(self.store.pending(), 0)

    def test_default_https_context_verifies_peer_and_hostname(self):
        actual = ssl.create_default_context()
        with mock.patch.object(c.ssl, "create_default_context", return_value=actual) as create:
            c.HTTPClient(self.config)
            create.assert_called_once_with()
        self.assertEqual(actual.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(actual.check_hostname)

    def test_success_then_lost_ack_is_replayed_as_duplicate(self):
        accepted = set()
        calls = []

        class LostAckClient:
            def send(inner, payload):
                calls.append(payload["sequence"])
                if payload["sequence"] not in accepted:
                    accepted.add(payload["sequence"])
                    raise c.ReportError("simulated lost ACK")
                return {"accepted": True, "sequence": payload["sequence"], "duplicate": True}

        sender = c.Sender(self.store, LostAckClient())
        with self.assertLogs(c.LOG, level="WARNING"):
            self.assertEqual(sender.send_pending(), 0)
        self.close_store(self.store)
        restarted = self.open_store()
        self.assertEqual(c.Sender(restarted, LostAckClient()).send_pending(), 1)
        self.assertEqual(calls, ["1", "1"])
        self.assertEqual(accepted, {"1"})
        self.assertEqual(restarted.pending(), 0)

    def test_crash_after_ack_before_delete_commit_keeps_original_payload(self):
        original_transaction = self.store.transaction

        @contextlib.contextmanager
        def crashing_commit():
            with original_transaction():
                yield
                raise sqlite3.OperationalError("simulated crash after ACK")

        client, unused = self.client(Response(self.good_ack()))
        sender = c.Sender(self.store, client)
        with mock.patch.object(self.store, "transaction", crashing_commit), self.assertLogs(c.LOG, level="ERROR"):
            self.assertEqual(sender.send_pending(), 0)
        self.assertEqual(self.store.peek(), ("1", self.payload))
        duplicate_client, unused = self.client(Response(self.good_ack(duplicate=True)))
        self.assertEqual(c.Sender(self.store, duplicate_client).send_pending(), 1)

    def test_backlog_drains_in_sequence_and_never_skips_failed_head(self):
        self.store.sample(BOOT_A, self.counters(140, 290), T + 20)
        self.store.sample(BOOT_A, self.counters(150, 310), T + 30)
        calls = []

        class Client:
            def send(inner, payload):
                calls.append(payload["sequence"])
                if payload["sequence"] == "2":
                    raise c.ReportError("simulated network failure")
                return {"accepted": True, "sequence": payload["sequence"], "duplicate": False}

        with self.assertLogs(c.LOG, level="WARNING"):
            self.assertEqual(c.Sender(self.store, Client()).send_pending(), 1)
        self.assertEqual(calls, ["1", "2"])
        self.assertEqual(self.store.peek()[0], "2")
        self.assertEqual(self.store.pending(), 2)

    def test_slow_http_sender_does_not_block_sample_transaction(self):
        entered, release = threading.Event(), threading.Event()

        class SlowClient:
            def send(inner, payload):
                entered.set()
                release.wait(timeout=5)
                return {"accepted": True, "sequence": payload["sequence"], "duplicate": False}

        sender = c.Sender(self.store, SlowClient())
        thread = threading.Thread(target=lambda: sender.send_pending(limit=1))
        thread.start()
        self.addCleanup(lambda: release.set())
        self.assertTrue(entered.wait(timeout=2))
        started = time.monotonic()
        payload = self.store.sample(BOOT_A, self.counters(150, 300), T + 20)
        self.assertLess(time.monotonic() - started, 1)
        self.assertEqual(payload["sequence"], "2")
        release.set()
        thread.join(timeout=2)
        self.assertFalse(thread.is_alive())
        self.assertEqual(self.store.peek()[0], "2")

    def test_real_loopback_http_refuses_redirect_without_leaking_token(self):
        destination_requests = []

        class Destination(BaseHTTPRequestHandler):
            def do_POST(inner):
                destination_requests.append(inner.path)
                inner.send_response(200)
                inner.end_headers()

            def log_message(inner, *args):
                pass

        destination = ThreadingHTTPServer(("127.0.0.1", 0), Destination)
        destination_thread = threading.Thread(target=destination.serve_forever, daemon=True)
        destination_thread.start()

        class Redirect(BaseHTTPRequestHandler):
            def do_POST(inner):
                inner.rfile.read(int(inner.headers.get("Content-Length", 0)))
                inner.send_response(307)
                inner.send_header("Location", "http://127.0.0.1:%d/leak" % destination.server_port)
                inner.end_headers()

            def log_message(inner, *args):
                pass

        source = ThreadingHTTPServer(("127.0.0.1", 0), Redirect)
        source_thread = threading.Thread(target=source.serve_forever, daemon=True)
        source_thread.start()
        try:
            cfg = c.Config.from_dict(self.config_data(panel_url="http://127.0.0.1:%d" % source.server_port))
            with self.assertRaises(c.ReportError) as error:
                c.HTTPClient(cfg).send(self.payload)
            self.assertTrue(error.exception.permanent)
            self.assertEqual(destination_requests, [])
        finally:
            source.shutdown()
            destination.shutdown()
            source.server_close()
            destination.server_close()
            source_thread.join(timeout=2)
            destination_thread.join(timeout=2)


class LockAndCLITests(Fixture):
    @unittest.skipUnless(c.fcntl is not None, "actual flock is Linux/Unix-only; Windows cannot verify it")
    def test_real_flock_refuses_second_process_lock_and_does_not_unlink_inode(self):
        path = self.state_directory / "collector.lock"
        with c.ProcessLock(self.config.state_file):
            with self.assertRaises(c.ConfigError):
                with c.ProcessLock(self.config.state_file):
                    self.fail("second lock unexpectedly acquired")
            self.assertTrue(path.exists())
        self.assertTrue(path.exists())
        with c.ProcessLock(self.config.state_file):
            self.assertTrue(path.exists())

    def test_check_has_no_state_or_http_mutation(self):
        path = self.directory / "config.json"
        path.write_text(json.dumps(self.config_data()), encoding="utf-8")
        path.chmod(0o600)
        with mock.patch.object(c, "check_host_namespace"), mock.patch.object(c.LinuxReader, "read") as reader, \
                mock.patch.object(c, "HTTPClient") as http, contextlib.redirect_stdout(io.StringIO()) as output:
            reader.return_value = (BOOT_A, self.counters())
            self.assertEqual(c.main(["--config", str(path), "--check"]), 0)
            http.assert_not_called()
        self.assertFalse(self.config.state_file.exists())
        self.assertIn("no state changed", output.getvalue())
        self.assertNotIn(SECRET, output.getvalue())

    def test_source_installer_is_local_only_and_unit_uses_nonroot_host_network(self):
        installer = (ROOT / "collector/install.sh").read_text(encoding="utf-8")
        unit = (ROOT / "collector/flowscope-collector.service").read_text(encoding="utf-8")
        self.assertNotRegex(installer, r"\b(curl|wget|rm)\s")
        self.assertIn("User=flowscope", unit)
        self.assertIn("ReadWritePaths=/var/lib/flowscope-collector", unit)
        self.assertNotIn("PrivateNetwork=true", unit)
        self.assertNotIn("--insecure", installer)

    def test_report_worker_failure_stops_process_nonzero_for_systemd_restart(self):
        real_store = c.Store
        calls = []

        def opening(config):
            calls.append(threading.current_thread().name)
            if threading.current_thread().name == "flowscope-report":
                raise sqlite3.OperationalError("do not print token: " + SECRET)
            return real_store(config)

        reader = mock.Mock()
        reader.read.return_value = (BOOT_A, self.counters())
        with mock.patch.object(c, "check_host_namespace"), \
                mock.patch.object(c, "ProcessLock", return_value=contextlib.nullcontext()), \
                mock.patch.object(c, "LinuxReader", return_value=reader), \
                mock.patch.object(c, "Store", side_effect=opening), \
                mock.patch.object(c.signal, "signal"), self.assertLogs(c.LOG, level="ERROR") as logs:
            self.assertEqual(c.run(self.config), 1)
        self.assertIn("flowscope-report", calls)
        self.assertNotIn(SECRET, " ".join(logs.output))


if __name__ == "__main__":
    unittest.main(verbosity=2)
