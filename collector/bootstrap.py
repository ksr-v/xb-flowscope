#!/usr/bin/env python3
"""FlowScope one-command installer. Trust your panel; this runs its code as root."""

import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import ssl
import stat
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.error
import urllib.request


FILES = ('flowscope_collector.py', 'install.sh', 'flowscope-collector.service')
NIC = re.compile(r'[A-Za-z0-9_.:@-]{1,64}\Z', re.ASCII)
CONFIG_PATH = Path('/etc/flowscope-collector.json')


class InstallError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def origin(value):
    try:
        parsed = urllib.parse.urlsplit(value)
        parsed.port
    except ValueError:
        raise InstallError('Invalid panel HTTPS origin') from None
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment or parsed.path not in ('', '/') or any(ord(c) <= 32 for c in value) or '\\' in value:
        raise InstallError('A direct HTTPS panel origin is required; TLS verification cannot be disabled')
    return value.rstrip('/')


def interfaces(explicit=None):
    if explicit is not None:
        names = explicit.split(',')
    else:
        try:
            routes = json.loads(subprocess.check_output(['ip', '-j', 'route', 'show', 'default'], stderr=subprocess.DEVNULL))
            routes += json.loads(subprocess.check_output(['ip', '-j', '-6', 'route', 'show', 'default'], stderr=subprocess.DEVNULL))
            names = sorted(set(row['dev'] for row in routes if row.get('dev')))
        except (OSError, ValueError, KeyError, subprocess.CalledProcessError):
            raise InstallError('Cannot detect default-route NIC; select interfaces in the panel') from None
        if len(names) != 1:
            raise InstallError('No unique default-route NIC; select interfaces in the panel and regenerate the command')
        if not (Path('/sys/class/net') / names[0] / 'device').exists():
            raise InstallError('Default route uses a virtual/aggregate NIC; select the intended accounting NIC explicitly')
    if not 1 <= len(names) <= 32 or len(set(names)) != len(names) or any(not NIC.fullmatch(name) or name == 'lo' for name in names):
        raise InstallError('Select 1..32 distinct non-loopback NIC names')
    for name in names:
        if not all((Path('/sys/class/net') / name / 'statistics' / item).is_file() for item in ('rx_bytes', 'tx_bytes')):
            raise InstallError('A selected NIC is unavailable; credential has not been redeemed')
    return names


def existing_config(panel, machine_id, explicit):
    path = CONFIG_PATH
    if not path.exists() and not path.is_symlink():
        if Path('/var/lib/flowscope-collector/state.sqlite3').exists():
            raise InstallError('Orphaned collector state exists; preserve it and review configuration manually')
        return None
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600:
        raise InstallError('Existing config must be a regular private 0600 file')
    try:
        if info.st_uid != pwd.getpwnam('flowscope').pw_uid:
            raise InstallError('Existing configuration ownership must be reviewed manually')
        old = json.loads(path.read_text())
    except (OSError, ValueError, KeyError):
        raise InstallError('Existing configuration is unreadable; it was not replaced') from None
    if old.get('panel_url') != panel or old.get('machine_id') != machine_id:
        raise InstallError('Installed collector belongs to another panel/machine; config and state were preserved')
    if explicit is not None and old.get('interfaces') != interfaces(explicit):
        raise InstallError('Existing NIC configuration differs; change it explicitly instead of overwriting')
    return old


def redeem(panel, ticket):
    request = urllib.request.Request(panel + '/api/v2/flowscope/install/redeem',
        data=json.dumps({'ticket': ticket}).encode(), method='POST',
        headers={'Content-Type': 'application/json', 'Accept': 'application/json',
                 'User-Agent': 'FlowScope-Installer/2.3.0'})
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        with opener.open(request, timeout=30) as response:
            if response.status != 200 or response.headers.get_content_type() != 'application/json':
                raise InstallError('Unexpected installation response; generate a new command')
            raw = response.read(1048577)
            if len(raw) > 1048576:
                raise InstallError('Installation response too large')
        result = json.loads(raw)['data']
        if not isinstance(result, dict):
            raise ValueError()
        return result
    except InstallError:
        raise
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
        raise InstallError('Installation exchange HTTP ' + str(status) + '; check panel access rules and credential validity; generate a new command after resolving the error') from None
    except Exception:
        # Never display HTTP bodies, request URLs, credentials or transport exceptions.
        raise InstallError('Installation exchange failed or credential expired/used; generate a new command') from None


def run(args):
    if sys.platform != 'linux' or os.geteuid() != 0 or sys.version_info < (3, 9):
        raise InstallError('Run with sudo on native Linux with Python 3.9+')
    os.umask(0o077)
    panel = origin(args.panel)
    if args.machine_id < 1 or not re.fullmatch('[a-f0-9]{64}', args.ticket):
        raise InstallError('Invalid installation command')
    if not all(shutil.which(name) for name in ('ip', 'bash', 'systemctl', 'getent', 'useradd', 'groupadd', 'install')):
        raise InstallError('Missing Linux installation tools; install Python3, iproute2 and systemd first')
    if Path('/.dockerenv').exists() or Path('/run/.containerenv').exists():
        raise InstallError('Install on the host, not inside a container')
    for target in ('/opt/flowscope-collector', '/var/lib/flowscope-collector', '/etc/flowscope-collector.json',
                   '/etc/systemd/system/flowscope-collector.service'):
        if Path(target).is_symlink():
            raise InstallError('Refusing a symlink at an installation target')
    old = existing_config(panel, args.machine_id, args.interfaces)
    names = interfaces(','.join(old['interfaces'])) if old else interfaces(args.interfaces)
    bundle = redeem(panel, args.ticket)
    if bundle.get('protocol') != 1 or bundle.get('machine_id') != args.machine_id or bundle.get('panel_url') != panel or not isinstance(bundle.get('token'), str) or not 1 <= len(bundle['token']) <= 256:
        raise InstallError('Installation identity mismatch; no existing state was changed')
    if old and old.get('token') != bundle['token']:
        raise InstallError('Machine token changed; update installed configuration explicitly; state was preserved')
    with tempfile.TemporaryDirectory(prefix='flowscope-bundle-') as directory:
        root = Path(directory)
        try:
            if set(bundle['files']) != set(FILES) or set(bundle['sha256']) != set(FILES):
                raise ValueError()
            for name in FILES:
                data = base64.b64decode(bundle['files'][name], validate=True)
                if not data or hashlib.sha256(data).hexdigest() != bundle['sha256'][name]:
                    raise ValueError()
                (root / name).write_bytes(data)
        except (KeyError, TypeError, ValueError):
            raise InstallError('Installation bundle failed validation') from None
        spec = importlib.util.spec_from_file_location('flowscope_bootstrap_collector', root / 'flowscope_collector.py')
        collector = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = collector
        spec.loader.exec_module(collector)
        collector.check_host_namespace()
        collector.LinuxReader(tuple(names)).read()
        config = old or {'panel_url': panel, 'machine_id': args.machine_id, 'token': bundle['token'],
            'interfaces': names, 'state_file': '/var/lib/flowscope-collector/state.sqlite3',
            'sample_interval': 10, 'report_interval': 30, 'request_timeout': 10}
        collector.Config.from_dict(config)
        command = ['bash', str(root / 'install.sh')]
        if Path('/opt/flowscope-collector/flowscope_collector.py').is_file():
            print('检测到已有采集器：正在清理旧程序缓存及临时残留，配置、历史和待上报队列将保留。', flush=True)
        if not old:
            prepared = root / 'prepared.json'
            prepared.write_text(json.dumps(config))
            command += ['--config', str(prepared)]
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['systemctl', 'enable', 'flowscope-collector'], check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['systemctl', 'restart', 'flowscope-collector'], check=True)
    subprocess.run(['systemctl', 'is-active', '--quiet', 'flowscope-collector'], check=True)
    print('安装成功，已完成对接配置；machine_id=' + str(args.machine_id) + '，网卡=' + ','.join(names) + '。服务已启动并设置开机自启，原有配置和数据已保留；请在 30–60 秒后检查面板首次上报。')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--panel', required=True)
    parser.add_argument('--machine-id', required=True, type=int)
    credential = parser.add_mutually_exclusive_group(required=True)
    credential.add_argument('--ticket', help=argparse.SUPPRESS)
    credential.add_argument('--ticket-stdin', action='store_true', help='Read the single-use credential privately from standard input')
    parser.add_argument('--interfaces')
    args = parser.parse_args()
    try:
        if args.ticket_stdin:
            args.ticket = sys.stdin.readline(66).strip()
        run(args)
    except Exception as error:
        print('安装失败：' + (str(error) if isinstance(error, InstallError) else 'Installation failed; state was preserved. Check local service status; no credentials displayed.'), file=sys.stderr)
        sys.exit(1)
