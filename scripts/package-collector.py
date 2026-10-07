"""Build the standalone Linux collector archive without deploying anything."""

import hashlib
import json
from pathlib import Path
import tarfile


root = Path(__file__).resolve().parent.parent
version = json.loads((root / "FlowScope/config.json").read_text(encoding="utf-8"))["version"]
output = root / "dist" / f"flowscope-collector-{version}.tar.gz"
output.parent.mkdir(exist_ok=True)
with tarfile.open(output, "w:gz", format=tarfile.PAX_FORMAT) as archive:
    for source in sorted((root / "collector").rglob("*")):
        if not source.is_file() or "__pycache__" in source.parts or source.suffix in (".pyc", ".sqlite3"):
            continue
        relative = source.relative_to(root / "collector")
        info = archive.gettarinfo(str(source), f"flowscope-collector-{version}/{relative.as_posix()}")
        info.mode = 0o755 if source.suffix in (".sh", ".py") else 0o644
        info.uid = info.gid = 0
        info.uname = info.gname = "root"
        with source.open("rb") as stream:
            archive.addfile(info, stream)
print(output)
print("SHA256 " + hashlib.sha256(output.read_bytes()).hexdigest())
