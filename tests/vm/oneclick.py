"""Explicitly scoped destructive-service tests ONLY for the authorized disposable VM.

Preserves the previous FlowScope test installation as named backups. Never run
on a production server. Uses local test CA, synthetic auth and isolated SQLite.
"""

import hashlib
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import shlex
import sqlite3
import ssl
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

ROOT = Path('/home/codex/flowscope-vm-test')
PROJECT = ROOT / 'project'
RUNTIME = ROOT / 'runtime/oneclick220-api'
TLS = ROOT / 'runtime/oneclick220-tls'
PANEL = 'https://127.0.0.1:18443'
CA = '/opt/flowscope-oneclick220-test-ca.pem'
DROPIN = '/etc/systemd/system/flowscope-collector.service.d/zz-oneclick220-vm-test.conf'
checks = []


def check(value, name):
    if not value:
        raise RuntimeError(name)
    checks.append(name)


def sudo(*command, **kwargs):
    return subprocess.run(['sudo', '-n', *command], check=True, **kwargs)


class Proxy(BaseHTTPRequestHandler):
    pause_collect = False

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def log_message(self, *args):
        pass  # No request URI, credentials or bodies in access logs.

    def forward(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        if self.pause_collect and self.path == '/api/v2/flowscope/collect':
            self.send_response(503)
            self.end_headers()
            return
        connection = http.client.HTTPConnection('127.0.0.1', 18080, timeout=10)
        try:
            headers = {'Host':'127.0.0.1:18080','Content-Type':self.headers.get('Content-Type','application/json')}
            if self.headers.get('X-FlowScope-IPv4'):
                headers['X-FlowScope-IPv4'] = self.headers['X-FlowScope-IPv4']
            connection.request(self.command, self.path, body=body, headers=headers)
            response = connection.getresponse()
            raw = response.read()
            self.send_response(response.status)
            self.send_header('Content-Type', response.getheader('Content-Type', 'application/json'))
            self.send_header('Content-Length', str(len(raw)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(raw)
        finally:
            connection.close()


def request(path, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(PANEL + path, data=data, headers={'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req, context=ssl.create_default_context(cafile=str(TLS/'cert.pem')), timeout=20) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
        return status, {}


def issue():
    code, response = request('/api/v2/flowscope/machine/41/install-ticket', {'panel_url':PANEL,'interfaces':[]})
    check(code == 200, 'Admin fixture issues real short credential')
    command = response['data']['command']
    check(token not in command, 'Machine token absent from shell command')
    credential = re.search(r'[a-f0-9]{64}', command).group()
    return command, credential


def execute(command, success=True):
    parts = shlex.split(command)
    check(len(parts) == 8 and parts[0] == 'printf' and parts[1] == '%s\\n' and parts[3:7] == ['|','sudo','bash','-c'], 'Generated command has safe expected printf/stdin/bash structure')
    # Only fixture CA changes; the generated bash payload executes unchanged.
    result = subprocess.run(['sudo','-n','env','SSL_CERT_FILE='+CA,'CURL_CA_BUNDLE='+CA,'bash','-c',parts[-1]],
                            input=parts[2]+'\n', capture_output=True, text=True, timeout=120)
    check((result.returncode == 0) == success, 'Actual generated install command result matches expectation')
    check(token not in result.stdout + result.stderr, 'Installer output omits long-term token')


def state():
    code = '''import json,sqlite3
db=sqlite3.connect('file:/var/lib/flowscope-collector/state.sqlite3?mode=ro',uri=True)
db.execute('BEGIN')
meta=dict(db.execute('SELECT key,value FROM metadata'))
snap=list(db.execute('SELECT interface,rx,tx FROM snapshots ORDER BY interface'))
queue=[json.loads(row[0]) for row in db.execute('SELECT payload FROM outbox ORDER BY sequence')]
print(json.dumps({'id':meta['collector_id'],'sequence':int(meta['sequence']),'snapshots':snap,'outbox':queue,'integrity':db.execute('PRAGMA quick_check').fetchone()[0]}))'''
    return json.loads(subprocess.check_output(['sudo','-n','python3','-c',code], text=True))


if sys.platform != 'linux' or Path.home() != Path('/home/codex') or not ROOT.is_dir():
    raise SystemExit('Run as codex only in the explicitly authorized disposable VM')
os.umask(0o077)
token = json.loads((ROOT/'private-fixture.json').read_text())['token']
check(not RUNTIME.exists() and not TLS.exists(), 'Fresh isolated oneclick runtime')
for name in ('flowscope-collector','flowscope-vm-api'):
    check(subprocess.run(['systemctl','is-active','--quiet',name]).returncode != 0, 'Prior test service is stopped: '+name)
old = json.loads(subprocess.check_output(['sudo','-n','cat','/etc/flowscope-collector.json'], text=True))
check(old.get('panel_url') in ('http://127.0.0.1:18080', PANEL) and old.get('machine_id') == 41 and old.get('token') == token,
      'Previous installation is the known synthetic test, not production')
backups = [('/etc/flowscope-collector.json','/etc/flowscope-collector.json.pre-v2.2.0-vm-test-backup'),
           ('/var/lib/flowscope-collector','/var/lib/flowscope-collector.pre-v2.2.0-vm-test-backup'),
           ('/opt/flowscope-collector','/opt/flowscope-collector.pre-v2.2.0-vm-test-backup')]
for source, target in backups:
    check(subprocess.run(['sudo','-n','test','-e',source]).returncode == 0, 'Explicit backup source exists')
    check(subprocess.run(['sudo','-n','test','-e',target]).returncode != 0, 'Backup destination is unused')
for source, target in backups:
    sudo('mv','--',source,target)
TLS.mkdir(parents=True, mode=0o700)
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(TLS/'key.pem'),
                '-out',str(TLS/'cert.pem'),'-days','2','-subj','/CN=localhost',
                '-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'], check=True,
               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
sudo('install','-o','root','-g','root','-m','644',str(TLS/'cert.pem'),CA)
check(subprocess.run(['sudo','-n','test','-e',DROPIN]).returncode != 0, 'Fixture service drop-in unused')
dropin = TLS/'collector-dropin.conf'
dropin.write_text('[Service]\nEnvironment=SSL_CERT_FILE='+CA+'\n')
sudo('install','-d','-m','755',str(Path(DROPIN).parent))
sudo('install','-m','644',str(dropin),DROPIN)
environment = dict(os.environ, XBOARD_PATH=str(ROOT/'xboard'), FLOWSCOPE_VM_RUNTIME=str(RUNTIME))
subprocess.run(['php',str(PROJECT/'tests/vm/fixture-control.php'),'init','--config',str(ROOT/'private-fixture.json')],
               env=environment, check=True, stdout=subprocess.DEVNULL)
api = subprocess.Popen(['php','-S','127.0.0.1:18080','tests/vm/api-router.php'], cwd=PROJECT,
                       env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
server = ThreadingHTTPServer(('127.0.0.1',18443), Proxy)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(TLS/'cert.pem', TLS/'key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    time.sleep(1)
    command, ticket = issue()
    print('Testing actual HTTPS one-command first install...', flush=True)
    execute(command)
    time.sleep(1)
    baseline = state()
    check(baseline['sequence'] == 0 and baseline['integrity'] == 'ok', 'Fresh collector establishes baseline only')
    check(request('/api/v2/flowscope/install/redeem', {'ticket':ticket})[0] == 403, 'Actual HTTPS credential cannot be replayed')
    # Hold collector reports while allowing installation exchange, to prove durable reinstall.
    Proxy.pause_collect = True
    time.sleep(12)
    sudo('systemctl','stop','flowscope-collector')
    before = state()
    config_before = subprocess.check_output(['sudo','-n','cat','/etc/flowscope-collector.json'])
    check(len(before['outbox']) > 0, 'Real report outage creates a durable queue')
    rejected, stale = issue()
    repeat, fresh = issue()
    check(request('/api/v2/flowscope/install/redeem', {'ticket':stale})[0] == 403, 'Actual HTTPS regeneration revokes old command')
    print('Testing actual HTTPS reinstall with pending reports...', flush=True)
    execute(repeat)
    time.sleep(1)
    sudo('systemctl','stop','flowscope-collector')
    after = state()
    check(before['id'] == after['id'] and after['sequence'] >= before['sequence'], 'Reinstall keeps identity and advances sequence')
    check(after['outbox'][:len(before['outbox'])] == before['outbox'], 'Reinstall preserves every pending payload')
    check(subprocess.check_output(['sudo','-n','cat','/etc/flowscope-collector.json']) == config_before, 'Existing private config preserved byte-for-byte')
    Proxy.pause_collect = False
    sudo('systemctl','start','flowscope-collector')
    time.sleep(3)
    sudo('systemctl','stop','flowscope-collector')
    # Drain without taking another sample using installed code and its service account.
    sudo('env','SSL_CERT_FILE='+CA,'runuser','-u','flowscope','--','python3','/opt/flowscope-vm-verify.py','drain', stdout=subprocess.DEVNULL)
    final = state()
    status, audit = request('/__test__/audit')
    check(status == 200 and not final['outbox'], 'HTTP recovery flushes all pending reports')
    check(final['integrity'] == 'ok', 'Final collector SQLite integrity')
    for index, direction in ((1,'rx_bytes'),(2,'tx_bytes')):
        expected = int(final['snapshots'][0][index]) - int(baseline['snapshots'][0][index])
        check(int(audit['totals'][direction]) == expected, 'NIC delta exactly equals API '+direction)
    address_status, address_data = request('/api/v2/flowscope/machine-addresses')
    check(address_status == 200, 'Actual Admin IPv4 endpoint available')
    ip_rows = address_data['data']['machines']
    result = {'result':'PASS','version':'2.2.0','checks':checks,'total_checks':len(checks),
              'collector_id':final['id'],'sequence':final['sequence'],'pending':len(final['outbox']),
              'totals':audit['totals'],'backups':backups,'ipv4_reported':bool(ip_rows),
              'scope':'Authorized native Linux VM; trusted local fixture CA; synthetic Admin; native routes with file SQLite, not production deployment'}
    serialized = json.dumps(result, indent=2)
    check(token not in serialized, 'Evidence omits machine credentials')
    (ROOT/'oneclick220-results.json').write_text(serialized)
    print('PASS oneclick VM; checks='+str(len(checks))+'; pending=0; no credentials in evidence', flush=True)
finally:
    sudo('systemctl','disable','--now','flowscope-collector', stdout=subprocess.DEVNULL)
    api.terminate()
    api.wait(timeout=10)
    server.shutdown()
    # Drop-in/CA remain scoped to the disabled test collector for reproducibility.
