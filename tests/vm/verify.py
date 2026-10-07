"""VM-only exact persisted-byte accounting evidence; never emits credentials."""

import argparse
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import sys
import time


ROOT = Path('/home/codex/flowscope-vm-test')
EVIDENCE = ROOT / 'evidence'
STATE = Path('/var/lib/flowscope-collector/state.sqlite3')
API_DB = ROOT / 'runtime/api/database.sqlite3'


def readonly(path):
    db = sqlite3.connect(path.as_uri() + '?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    db.execute('PRAGMA query_only=ON')
    db.execute('BEGIN')
    return db


def snapshot():
    with readonly(STATE) as db:
        metadata = dict(db.execute('SELECT key,value FROM metadata'))
        # Never export any future metadata credentials or complete configuration.
        metadata = {key: metadata[key] for key in ('schema_version', 'machine_id', 'collector_id',
                    'sequence', 'ended_at', 'boot_id') if key in metadata}
        snapshots = [dict(row) for row in db.execute('SELECT * FROM snapshots ORDER BY interface')]
        outbox = [json.loads(row[0]) for row in db.execute('SELECT payload FROM outbox ORDER BY sequence')]
        assert all('token' not in item for item in outbox)
        assert db.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
    with readonly(API_DB) as db:
        row = db.execute('SELECT * FROM flowscope_collector_cursors WHERE machine_id=41').fetchone()
        cursor = None if row is None else {key: row[key] for key in ('collector_id', 'sequence',
                    'last_sample_at', 'boot_id', 'boot_changes', 'resets')}
        daily = [dict(row) for row in db.execute('SELECT record_at,rx_bytes,tx_bytes FROM flowscope_machine_daily WHERE machine_id=41 ORDER BY record_at')]
        assert db.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
    totals = {direction + '_bytes': str(sum(int(item[direction + '_bytes']) for item in daily))
              for direction in ('rx', 'tx')}
    return {'captured_at': int(time.time()), 'host_boot_id': Path('/proc/sys/kernel/random/boot_id').read_text().strip(),
            'metadata': metadata, 'snapshots': snapshots, 'outbox': outbox,
            'api': {'cursor': cursor, 'daily': daily, 'totals': totals},
            'permissions': {str(path): {'mode': oct(path.stat().st_mode & 0o777), 'uid': path.stat().st_uid}
                            for path in (STATE, STATE.parent, Path('/etc/flowscope-collector.json'))}}


def load(name):
    return json.loads((EVIDENCE / (name + '.json')).read_text())


def total(evidence, direction):
    return int(evidence['api']['totals'][direction + '_bytes']) + sum(int(item[direction + '_bytes']) for item in evidence['outbox'])


def validate(evidence):
    metadata = evidence['metadata']
    cursor = evidence['api']['cursor']
    accepted = int(cursor['sequence']) if cursor else 0
    assert accepted <= int(metadata['sequence'])
    assert [int(item['sequence']) for item in evidence['outbox']] == list(range(accepted + 1, int(metadata['sequence']) + 1))
    assert not cursor or cursor['collector_id'] == metadata['collector_id']
    assert len(evidence['snapshots']) == 1 and evidence['snapshots'][0]['interface'] == 'ens33'
    assert evidence['permissions'][str(STATE)]['mode'] == '0o600'
    assert evidence['permissions'][str(STATE.parent)]['mode'] == '0o700'
    assert evidence['permissions']['/etc/flowscope-collector.json']['mode'] == '0o600'


def compare(first, last, reboot=False):
    before, after = load(first), load(last)
    validate(before)
    validate(after)
    assert before['metadata']['collector_id'] == after['metadata']['collector_id']
    assert int(after['metadata']['sequence']) >= int(before['metadata']['sequence'])
    old, new = before['snapshots'][0], after['snapshots'][0]
    if reboot:
        assert before['host_boot_id'] != after['host_boot_id']
        assert old['boot_id'] != new['boot_id']
    else:
        assert old['boot_id'] == new['boot_id']
        assert old['ifindex'] == new['ifindex']
    observed = {}
    for direction in ('rx', 'tx'):
        delta = int(new[direction]) if reboot else int(new[direction]) - int(old[direction])
        expected = total(before, direction) + delta
        assert total(after, direction) == expected, (direction, expected, total(after, direction))
        observed[direction + '_bytes'] = str(delta)
    return {'pass': True, 'first': first, 'last': last, 'reboot': reboot, 'accounted_delta': observed,
            'final_api_totals': after['api']['totals'], 'pending': len(after['outbox']),
            'sequence': after['metadata']['sequence']}


def collector_module():
    spec = importlib.util.spec_from_file_location('flowscope_vm_installed_collector', '/opt/flowscope-collector/flowscope_collector.py')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def duplicate(name):
    # Call only with the service stopped: real machine auth and HTTP API, no local ACK.
    original = load(name)
    payload = original['outbox'][-1]
    before = snapshot()
    assert before['api']['cursor']['sequence'] == payload['sequence']
    c = collector_module()
    config = c.Config.from_dict(json.loads(Path('/etc/flowscope-collector.json').read_text()))
    result = c.HTTPClient(config).send(payload)
    after = snapshot()
    assert result['duplicate'] is True
    assert before['api'] == after['api']
    return {'pass': True, 'sequence': result['sequence'], 'duplicate': True, 'api_unchanged': True}


def drain():
    # Run as the installed flowscope account, never root. Does not take a new sample.
    c = collector_module()
    config = c.Config.load(Path('/etc/flowscope-collector.json'))
    with c.ProcessLock(config.state_file):
        store = c.Store(config)
        try:
            count = c.Sender(store, c.HTTPClient(config)).send_pending(limit=10000)
            assert store.pending() == 0
            return {'pass': True, 'sent': count, 'pending': 0}
        finally:
            store.close()


parser = argparse.ArgumentParser()
parser.add_argument('action', choices=('snapshot', 'compare', 'reboot', 'retained', 'api-persisted', 'duplicate', 'drain'))
parser.add_argument('names', nargs='*')
args = parser.parse_args()
if os.name != 'posix' or (args.action != 'drain' and not ROOT.is_dir()):
    raise SystemExit('Authorized disposable Linux VM only')
assert all(name and name.replace('-', '').replace('_', '').isalnum() for name in args.names)
os.umask(0o077)
if args.action == 'snapshot':
    assert len(args.names) == 1
    EVIDENCE.mkdir(mode=0o700, exist_ok=True)
    result = snapshot()
    validate(result)
    path = EVIDENCE / (args.names[0] + '.json')
    with path.open('x') as stream:
        json.dump(result, stream, indent=2)
    result = {'path': str(path), 'sequence': result['metadata']['sequence'], 'pending': len(result['outbox']),
              'totals': result['api']['totals'], 'collector_id': result['metadata']['collector_id'], 'boot_id': result['host_boot_id']}
elif args.action in ('compare', 'reboot'):
    assert len(args.names) == 2
    result = compare(*args.names, reboot=args.action == 'reboot')
elif args.action == 'duplicate':
    assert len(args.names) == 1
    result = duplicate(*args.names)
elif args.action in ('retained', 'api-persisted'):
    assert len(args.names) == 2
    before, after = map(load, args.names)
    assert before['api'] == after['api']
    assert before['metadata']['collector_id'] == after['metadata']['collector_id']
    if args.action == 'retained':
        assert len(before['outbox']) > 0
        assert after['outbox'][:len(before['outbox'])] == before['outbox']
    else:
        assert before['metadata'] == after['metadata']
        assert before['snapshots'] == after['snapshots']
        assert before['outbox'] == after['outbox']
    result = {'pass': True, 'check': args.action, 'first': args.names[0], 'last': args.names[1],
              'preserved_outbox': len(before['outbox']), 'api_unchanged': True}
else:
    assert not args.names
    result = drain()
print(json.dumps(result, sort_keys=True))
