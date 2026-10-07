"""Restore only named synthetic-test backups left by the failed 2.2.1 fixture."""
import json
from pathlib import Path
import subprocess
root=Path('/home/codex/flowscope-vm-test')
if Path.home()!=Path('/home/codex') or not root.is_dir(): raise SystemExit('Disposable VM only')
for name in ('flowscope-collector','flowscope-vm-api'):
    if subprocess.run(['systemctl','is-active','--quiet',name]).returncode==0: raise SystemExit('Service still active')
paths=['/etc/flowscope-collector.json','/var/lib/flowscope-collector','/opt/flowscope-collector']
for path in paths:
    if subprocess.run(['sudo','-n','test','-e',path]).returncode==0: raise SystemExit('Target already exists; refusing overwrite')
    source=path+'.pre-v2.2.1-vm-test-backup'
    if subprocess.check_output(['sudo','-n','readlink','-f',source],text=True).strip()!=source: raise SystemExit('Unexpected backup path')
config=json.loads(subprocess.check_output(['sudo','-n','cat',paths[0]+'.pre-v2.2.1-vm-test-backup']))
if config.get('token')!=json.loads((root/'private-fixture.json').read_text())['token'] or config.get('machine_id')!=41: raise SystemExit('Unknown backup')
for path in paths:
    subprocess.run(['sudo','-n','mv','--',path+'.pre-v2.2.1-vm-test-backup',path],check=True)
print('Restored exact prior test paths without overwriting any target')
