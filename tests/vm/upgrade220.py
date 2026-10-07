"""Only the explicitly authorized disposable VM; never prints configuration."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

root = Path('/home/codex/flowscope-vm-test')
if sys.platform != 'linux' or Path.home() != Path('/home/codex') or not root.is_dir():
    raise SystemExit('Authorized disposable VM only')
def read(path):
    return subprocess.check_output(['sudo','-n','cat',path])
for service in ('flowscope-collector','flowscope-vm-api'):
    if subprocess.run(['systemctl','is-active','--quiet',service]).returncode == 0:
        raise SystemExit('Stop existing test service before upgrade test')
config = json.loads(read('/etc/flowscope-collector.json'))
fixture = json.loads((root/'private-fixture.json').read_text())
if config.get('machine_id') != 41 or config.get('panel_url') != 'https://127.0.0.1:18443' or config.get('token') != fixture['token']:
    raise SystemExit('Unknown installation; no mutation permitted')
paths = ['/etc/flowscope-collector.json','/var/lib/flowscope-collector/state.sqlite3']
before = [hashlib.sha256(read(path)).hexdigest() for path in paths]
version_before = subprocess.check_output(['python3','/opt/flowscope-collector/flowscope_collector.py','--version'],text=True).strip()
subprocess.run(['sudo','-n','bash',str(root/'project/collector/install.sh')],check=True,stdout=subprocess.DEVNULL)
after = [hashlib.sha256(read(path)).hexdigest() for path in paths]
if before != after:
    raise SystemExit('FAIL config/state changed during local installer upgrade')
version_after = subprocess.check_output(['python3','/opt/flowscope-collector/flowscope_collector.py','--version'],text=True).strip()
if '2.2.0' not in version_after:
    raise SystemExit('FAIL installed version')
result={'result':'PASS','from':version_before,'to':version_after,'config_state_hashes_unchanged':True,'service_was_stopped':True}
(root/'upgrade220-results.json').write_text(json.dumps(result,indent=2))
print('PASS old-version local installer upgrade; config/state byte-for-byte unchanged')
