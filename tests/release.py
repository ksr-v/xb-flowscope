"""Check both delivered release artifacts without deploying them."""

import json
from pathlib import Path, PurePosixPath
import tarfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parent.parent
VERSION = json.loads((ROOT / "FlowScope/config.json").read_text(encoding="utf-8"))["version"]


class ReleaseTests(unittest.TestCase):
    def test_native_plugin_and_collector_source_match(self):
        with zipfile.ZipFile(ROOT / "dist" / f"flowscope-{VERSION}.zip") as archive:
            names = archive.namelist()
            self.assertEqual(len(names), len(set(names)))
            for name in names:
                path = PurePosixPath(name)
                self.assertEqual(path.parts[0], "Flowscope")
                self.assertNotIn("..", path.parts)
                self.assertNotIn("\\", name)
                self.assertNotIn("__pycache__", name)
                self.assertFalse(name.endswith((".pyc", ".sqlite3", ".sqlite3-wal", ".sqlite3-shm")))
            for directory, prefix in (("FlowScope", "Flowscope"), ("collector", "Flowscope/collector")):
                for source in (ROOT / directory).rglob("*"):
                    if not source.is_file() or "__pycache__" in source.parts or source.suffix in (".pyc", ".sqlite3"):
                        continue
                    name = f"{prefix}/{source.relative_to(ROOT / directory).as_posix()}"
                    self.assertEqual(archive.read(name), source.read_bytes(), name)
            config = json.loads(archive.read("Flowscope/config.json"))
            self.assertEqual(config["version"], VERSION)
            for document in ("README.md", "DEVELOPMENT.md", "IMPLEMENTATION_PLAN.md", "TEST_REPORT.md", "LICENSE", "docs/TEST_REPORT_1.0.1.md", "docs/VM_TEST_REPORT.md", f"docs/UPGRADE_v{VERSION}.md"):
                self.assertEqual(archive.read("Flowscope/" + document), (ROOT / document).read_bytes())

    def test_collector_tar_safe_and_complete(self):
        with tarfile.open(ROOT / "dist" / f"flowscope-collector-{VERSION}.tar.gz", "r:gz") as archive:
            members = archive.getmembers()
            self.assertGreater(len(members), 3)
            self.assertEqual(len(members), len({member.name for member in members}))
            for member in members:
                path = PurePosixPath(member.name)
                self.assertEqual(path.parts[0], f"flowscope-collector-{VERSION}")
                self.assertNotIn("..", path.parts)
                self.assertTrue(member.isfile())
                self.assertNotIn("__pycache__", member.name)
                source = ROOT / "collector" / Path(*path.parts[1:])
                self.assertEqual(archive.extractfile(member).read(), source.read_bytes(), member.name)
                self.assertEqual(member.mode, 0o755 if source.suffix in (".sh", ".py") else 0o644)


if __name__ == "__main__":
    unittest.main(verbosity=2)
