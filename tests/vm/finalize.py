"""Collect secret-free evidence after the explicitly scoped VM experiments."""

import json
import os
from pathlib import Path
import platform
import subprocess
import sys


ROOT = Path('/home/codex/flowscope-vm-test')
if os.name != 'posix' or os.geteuid() != 0 or not ROOT.is_dir():
    raise SystemExit('Run with sudo in the authorized disposable VM only')
os.umask(0o077)
EVIDENCE = ROOT / 'evidence'
# verify.py is a CLI; invoke it as a subprocess rather than importing its arguments.
verify = str(Path(__file__).with_name('verify.py'))
commands = [('compare', 'baseline', 'normal'), ('compare', 'baseline', 'outage'),
            ('compare', 'baseline', 'kill-recovery'), ('retained', 'outage', 'kill-recovery'),
            ('compare', 'baseline', 'flushed'), ('api-persisted', 'flushed', 'api-restart'),
            ('compare', 'baseline', 'pre-reboot'), ('reboot', 'pre-reboot', 'post-reboot'),
            ('compare', 'post-reboot', 'pre-forced-reboot'),
            ('reboot', 'pre-forced-reboot', 'post-forced-reboot')]
checks = []
for command in commands:
    checks.append(json.loads(subprocess.check_output([sys.executable, verify, *command], text=True)))
last = json.loads((EVIDENCE / 'post-forced-reboot.json').read_text())
result = {'environment': {'host': '192.168.128.129', 'os': platform.platform(),
                         'python': platform.python_version(), 'nic': 'ens33',
                         'php': subprocess.check_output(['php', '-v'], text=True).splitlines()[0],
                         'node': subprocess.check_output(['node', '--version'], text=True).strip(),
                         'root_filesystem': subprocess.check_output(['findmnt', '-no', 'FSTYPE', '/'], text=True).strip()},
          'scope': 'Disposable VMware Ubuntu; native FlowScope routes/models on file SQLite; loopback fixture Admin, not production Xboard auth/MySQL/Octane',
          'checks': checks, 'final_sequence': last['metadata']['sequence'],
          'final_totals': last['api']['totals'], 'final_pending': len(last['outbox']),
          'collector_id': last['metadata']['collector_id'],
          'boot_ids': [json.loads((EVIDENCE / (name + '.json')).read_text())['host_boot_id']
                       for name in ('baseline', 'post-reboot', 'post-forced-reboot')],
          'manual_checks': {'duplicate_latest': 'actual HTTP accepted duplicate=true, API unchanged',
                            'systemd_sigkill': 'main PID 11130 killed signal9; automatic restart PID11145 NRestarts=1',
                            'installer': 'installed as non-root; check read-only; no automatic start; reinstall preserved stopped state/config/database',
                            'forced_reboot': 'systemctl reboot --force --force while SIGSTOP collector held open WAL with 3 pending reports',
                            'hard_poweroff': 'NOT RUN: host vmrun requires missing VIX.DLL; forced guest reboot is not hypervisor power-off'},
          'limits': ['Unsampled old-boot tail is not recoverable; no zero-loss packet accounting claim.',
                     'VMware virtual ens33 on native guest Linux, not bare-metal NIC/driver certification.',
                     'Test loopback HTTP does not validate production TLS, Nginx, MySQL, Octane or Admin session authentication.']}
logs = {
    'collector-journal.log': ['journalctl', '-u', 'flowscope-collector', '--no-pager', '-o', 'short-iso'],
    'api-journal.log': ['journalctl', '-u', 'flowscope-vm-api', '--no-pager', '-o', 'short-iso'],
    'service-properties.log': ['systemctl', 'show', 'flowscope-collector', 'flowscope-vm-api',
                             '-p', 'User', '-p', 'Group', '-p', 'NoNewPrivileges', '-p', 'PrivateNetwork',
                             '-p', 'CapabilityBoundingSet', '-p', 'ProtectHome', '-p', 'ProtectSystem',
                             '-p', 'ActiveState', '-p', 'UnitFileState'],
    'forced-boot-kernel.log': ['dmesg', '--level=err,warn'],
}
token = json.loads((ROOT / 'private-fixture.json').read_text())['token']
for name, command in logs.items():
    contents = subprocess.check_output(command, text=True, stderr=subprocess.STDOUT)
    assert token not in contents
    (EVIDENCE / name).write_text(contents)
serialized = json.dumps(result, indent=2)
assert token not in serialized
(EVIDENCE / 'vm-results.json').write_text(serialized)
for path in EVIDENCE.iterdir():
    assert path.is_file() and not path.is_symlink()
    os.chown(path, 1000, 1000)
os.chown(EVIDENCE, 1000, 1000)
print(json.dumps({'checks': len(checks), 'all_passed': all(check['pass'] for check in checks),
                  'sequence': result['final_sequence'], 'pending': result['final_pending'], 'evidence': str(EVIDENCE)}))
