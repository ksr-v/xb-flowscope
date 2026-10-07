"""Prepare synthetic private inputs in the explicitly authorized disposable VM."""

import json
import os
from pathlib import Path
import secrets


ROOT = Path('/home/codex/flowscope-vm-test')
if os.name != 'posix' or Path.home() != Path('/home/codex') or not ROOT.is_dir():
    raise SystemExit('Run as codex in the authorized Linux test VM only')
os.umask(0o077)
fixture = ROOT / 'private-fixture.json'
prepared = ROOT / 'private-collector.json'
if fixture.exists() or prepared.exists():
    raise SystemExit('Inputs already exist; refusing to replace test identity')
token = secrets.token_hex(32)
with fixture.open('x', encoding='utf-8') as stream:
    json.dump({'machine_id': 41, 'token': token}, stream)
with prepared.open('x', encoding='utf-8') as stream:
    json.dump({'panel_url': 'http://127.0.0.1:18080', 'machine_id': 41, 'token': token,
               'interfaces': ['ens33'], 'state_file': '/var/lib/flowscope-collector/state.sqlite3',
               'sample_interval': 2, 'report_interval': 1, 'request_timeout': 2}, stream)
print('Created two private 0600 inputs with a random synthetic token; no token displayed')
