"""Cross-language collector/API and exact daily-allocation regressions."""

import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import random
import shutil
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parent.parent
MAX = 9223372036854775807
spec = importlib.util.spec_from_file_location("flowscope_protocol_collector", ROOT / "collector/flowscope_collector.py")
collector = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = collector
spec.loader.exec_module(collector)


def receiver(payload):
    executable = os.environ.get("FLOWSCOPE_PHP") or shutil.which("php")
    if not executable:
        raise RuntimeError("PHP is required")
    command = [executable]
    extension_dir = Path(executable).parent / "ext"
    if (extension_dir / "php_pdo_sqlite.dll").is_file():
        command += ["-d", f"extension_dir={extension_dir}"]
        for extension in ("pdo_sqlite", "sqlite3", "mbstring"):
            command += ["-d", f"extension={extension}"]
    command += [str(ROOT / "tests/protocol-receiver.php")]
    result = subprocess.run(command, input=json.dumps(payload), text=True, encoding="utf-8", capture_output=True, cwd=ROOT, check=True)
    return json.loads(result.stdout)


def expected_allocation(case, boundaries):
    result = []
    allocated_rx = allocated_tx = 0
    for index, (day, start, end) in enumerate(boundaries):
        if index == len(boundaries) - 1:
            rx = int(case["rx"]) - allocated_rx
            tx = int(case["tx"]) - allocated_tx
        else:
            rx = int(case["rx"]) * (end - start) // (case["end"] - case["start"])
            tx = int(case["tx"]) * (end - start) // (case["end"] - case["start"])
        result.append({"record_at": day, "rx_bytes": str(rx), "tx_bytes": str(tx)})
        allocated_rx += rx
        allocated_tx += tx
    return result


class ProtocolTests(unittest.TestCase):
    def test_real_collector_persistence_payloads_and_php_idempotent_ingestion(self):
        runtime = ROOT / "tests/.runtime"
        runtime.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="protocol-", dir=runtime) as directory:
            state = Path(directory) / "state" / "state.sqlite3"
            config = collector.Config.from_dict({"panel_url": "https://panel.example.com", "machine_id": 41,
                "token": "fixture-machine-token", "interfaces": ["eth0"], "state_file": str(state)})
            first_boot = "550e8400-e29b-41d4-a716-446655440000"
            next_boot = "550e8400-e29b-41d4-a716-446655440001"
            start = 1760000000
            store = collector.Store(config)
            self.assertIsNone(store.sample(first_boot, {"eth0": collector.Counter(2, 1000, 2000)}, start))
            reports = [store.sample(first_boot, {"eth0": collector.Counter(2, 1100, 2200)}, start + 10)]
            identity = reports[0]["collector_id"]
            store.close()  # Process restart: pending reports and baseline must survive.
            store = collector.Store(config)
            try:
                reports.append(store.sample(first_boot, {"eth0": collector.Counter(2, 1500, 3000)}, start + 20))
                reports.append(store.sample(next_boot, {"eth0": collector.Counter(2, 30, 40)}, start + 30))
                reports.append(store.sample(next_boot, {"eth0": collector.Counter(2, 10, 90)}, start + 40))
                reports.append(store.sample(next_boot, {"eth0": collector.Counter(3, 10000, 20000)}, start + 50))
                reports.append(store.sample(next_boot, {"eth0": collector.Counter(3, 10007, 20011)}, start + 60))
                self.assertEqual(store.pending(), 6)
                self.assertTrue(all(item["collector_id"] == identity for item in reports))
                self.assertTrue(all("token" not in item for item in reports))
                wire = [dict(item, token=config.token) for item in reports]
                requests = [wire[0], wire[0], *wire[1:], wire[0],
                    dict(wire[-1], rx_bytes="999"),
                    dict(wire[0], collector_id="a" * 32),
                    dict(wire[-1], sequence="8"),
                    dict(wire[-1], token="incorrect-fixture-token")]
                result = receiver({"reports": requests, "at": "@" + str(start + 61)})
                responses = result["responses"]
                self.assertEqual([item["status"] for item in responses], [200] * 8 + [409, 409, 409, 403])
                self.assertTrue(responses[1]["body"]["data"]["duplicate"])
                self.assertTrue(responses[7]["body"]["data"]["duplicate"])
                self.assertEqual(responses[10]["body"]["expected_sequence"], "7")
                machine = result["dashboard"]["servers"][0]
                self.assertEqual((machine["machine_id"], machine["inbound_bytes"], machine["outbound_bytes"], machine["used_bytes"]),
                                 (41, "547", "1101", "1648"))
                self.assertEqual(machine["collector"]["boot_changes"], 1)
                self.assertEqual(machine["collector"]["resets"], 2)
                self.assertNotIn(config.token, json.dumps(result))
                for item in reports:
                    self.assertEqual(store.peek()[0], item["sequence"])
                    self.assertTrue(store.acknowledge(item["sequence"]))
                self.assertEqual(store.pending(), 0)
            finally:
                store.close()

    def test_php_allocation_against_python_arbitrary_precision(self):
        rng = random.Random(200007)
        cases = []
        expected = []
        for _ in range(80):
            start = 1735689600 + rng.randrange(86400 * 300)
            end = start + rng.randrange(1, 86400 * 366)
            case = {"start": start, "end": end, "rx": str(rng.randrange(MAX + 1)), "tx": str(rng.randrange(MAX + 1)), "timezone": "UTC"}
            boundaries = []
            position = start
            while position < end:
                day = position // 86400 * 86400
                until = min(day + 86400, end)
                boundaries.append((day, position, until))
                position = until
            cases.append(case)
            expected.append(expected_allocation(case, boundaries))
        # Explicit US DST transitions: local days last 23/25 hours respectively.
        transitions = (
            ("2026-03-07T00:00:00-08:00", "2026-03-08T00:00:00-08:00", "2026-03-09T00:00:00-07:00", "2026-03-10T00:00:00-07:00"),
            ("2026-10-31T00:00:00-07:00", "2026-11-01T00:00:00-07:00", "2026-11-02T00:00:00-08:00", "2026-11-03T00:00:00-08:00"),
        )
        for transition in transitions:
            day_starts = tuple(int(dt.datetime.fromisoformat(value).timestamp()) for value in transition)
            start, end = day_starts[0] + 123, day_starts[-1] - 123
            case = {"start": start, "end": end, "rx": str(MAX), "tx": "9007199254740993", "timezone": "America/Los_Angeles"}
            boundaries = [(day_starts[i], max(start, day_starts[i]), min(end, day_starts[i + 1])) for i in range(3)]
            cases.append(case)
            expected.append(expected_allocation(case, boundaries))
        actual = receiver({"allocations": cases})["allocations"]
        self.assertEqual(actual, expected)
        for case, rows in zip(cases, actual):
            self.assertEqual(sum(int(row["rx_bytes"]) for row in rows), int(case["rx"]))
            self.assertEqual(sum(int(row["tx_bytes"]) for row in rows), int(case["tx"]))


if __name__ == "__main__":
    unittest.main(verbosity=2)
