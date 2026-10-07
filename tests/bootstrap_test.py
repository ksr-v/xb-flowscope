"""One-command installer tests; never touches host service/config/state."""

import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock

# pwd is Linux-only; bootstrap is testable on Windows without implementing accounts.
if os.name != 'posix':
    sys.modules.setdefault('pwd', types.SimpleNamespace(getpwnam=lambda name: types.SimpleNamespace(pw_uid=999)))
ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('flowscope_bootstrap', ROOT / 'collector/bootstrap.py')
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)


class BootstrapTests(unittest.TestCase):
    def test_https_origin_and_redirect_refusal(self):
        self.assertEqual(b.origin('https://panel.example.com/'), 'https://panel.example.com')
        for value in ('http://panel.example.com', 'https://u:p@panel.example.com', 'https://panel.example.com/a',
                      'https://panel.example.com/?secret=x', 'https://panel.example.com/#x', 'https://panel.example.com\n',
                      'https://panel.example.com:bad', 'https://panel.example.com\\evil'):
            with self.subTest(value=value), self.assertRaises(b.InstallError):
                b.origin(value)
        self.assertIsNone(b.NoRedirect().redirect_request(None, None, None, None, None, None))

    def test_default_route_and_manual_nic(self):
        with mock.patch.object(b.subprocess, 'check_output', return_value=b'[{"dev":"ens33"},{"dev":"ens33"}]'), mock.patch.object(Path, 'is_file', return_value=True), mock.patch.object(Path, 'exists', return_value=True):
            self.assertEqual(b.interfaces(), ['ens33'])
            self.assertEqual(b.interfaces('ens33,eth1'), ['ens33','eth1'])
        for raw in (b'[]', b'[{"dev":"ens33"},{"dev":"eth1"}]', b'not json'):
            with mock.patch.object(b.subprocess, 'check_output', return_value=raw), self.assertRaises(b.InstallError):
                b.interfaces()
        for value in ('lo', 'eth0,eth0', '../eth0', 'eth0;id', '', 'eth0,'):
            with self.subTest(value=value), self.assertRaises(b.InstallError):
                b.interfaces(value)
        with mock.patch.object(Path, 'is_file', return_value=False), self.assertRaises(b.InstallError):
            b.interfaces('ens33')

    def test_ipv6_default_fallback(self):
        with mock.patch.object(b.subprocess, 'check_output', side_effect=[b'[]',b'[{"dev":"ens33"}]']), mock.patch.object(Path, 'is_file', return_value=True), mock.patch.object(Path, 'exists', return_value=True):
            self.assertEqual(b.interfaces(), ['ens33'])
        with mock.patch.object(b.subprocess, 'check_output', side_effect=[b'[{"dev":"eth0"}]',b'[{"dev":"eth1"}]']), self.assertRaises(b.InstallError):
            b.interfaces()
        with mock.patch.object(b.subprocess, 'check_output', return_value=b'[{"dev":"wg0"}]'), mock.patch.object(Path,'exists',return_value=False), self.assertRaises(b.InstallError):
            b.interfaces()

    @unittest.skipUnless(os.name == 'posix', 'POSIX private-file checks')
    def test_existing_identity_and_token_privacy(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / 'config.json'
            config.write_text(json.dumps({'panel_url':'https://panel.example.com','machine_id':41,'interfaces':['ens33'],'token':'synthetic-private'}))
            config.chmod(0o600)
            with mock.patch.object(b,'CONFIG_PATH',config), mock.patch.object(b.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=os.geteuid())):
                self.assertEqual(b.existing_config('https://panel.example.com',41,None)['interfaces'], ['ens33'])
                for panel, machine in (('https://other.example.com',41),('https://panel.example.com',42)):
                    with self.assertRaises(b.InstallError) as error:
                        b.existing_config(panel,machine,None)
                    self.assertNotIn('synthetic-private',str(error.exception))
                config.chmod(0o644)
                with self.assertRaises(b.InstallError):
                    b.existing_config('https://panel.example.com',41,None)

    def test_first_install_and_reinstall_preserve_state(self):
        for old in (None, {'panel_url':'https://panel.example.com','machine_id':41,'token':'synthetic-private','interfaces':['ens33'],
                           'state_file':'/var/lib/flowscope-collector/state.sqlite3','sample_interval':17}):
            bundle = {'protocol':1,'machine_id':41,'panel_url':'https://panel.example.com','token':'synthetic-private', 'files':{},'sha256':{}}
            for name in b.FILES:
                content = b'fixed trusted panel fixture'
                bundle['files'][name] = base64.b64encode(content).decode()
                bundle['sha256'][name] = hashlib.sha256(content).hexdigest()
            module = types.SimpleNamespace(check_host_namespace=mock.Mock(), LinuxReader=mock.Mock(), Config=types.SimpleNamespace(from_dict=mock.Mock()))
            fake_spec = types.SimpleNamespace(name='test_fake_collector', loader=types.SimpleNamespace(exec_module=mock.Mock()))
            args = argparse.Namespace(panel='https://panel.example.com',machine_id=41,ticket='a'*64,interfaces=None)
            with mock.patch.object(b.sys,'platform','linux'), mock.patch.object(b.os,'geteuid',return_value=0,create=True), \
                    mock.patch.object(b.shutil,'which',return_value='/test/tool'), mock.patch.object(Path,'is_symlink',return_value=False), \
                    mock.patch.object(Path,'exists',return_value=False), mock.patch.object(b,'existing_config',return_value=old), \
                    mock.patch.object(b,'interfaces',return_value=['ens33']), mock.patch.object(b,'redeem',return_value=bundle), \
                    mock.patch.object(b.importlib.util,'spec_from_file_location',return_value=fake_spec), \
                    mock.patch.object(b.importlib.util,'module_from_spec',return_value=module), mock.patch.object(b.subprocess,'run') as commands:
                b.run(args)
                installer = commands.call_args_list[0].args[0]
                self.assertEqual('--config' in installer, old is None)
                self.assertEqual(commands.call_args_list[-2].args[0], ['systemctl','restart','flowscope-collector'])
                if old:
                    self.assertEqual(module.Config.from_dict.call_args.args[0]['sample_interval'],17)
                self.assertTrue(module.check_host_namespace.called)
                commands.reset_mock()
                first = b.FILES[0]
                original_digest = bundle['sha256'][first]
                bundle['sha256'][first] = '0' * 64
                with self.assertRaises(b.InstallError):
                    b.run(args)
                commands.assert_not_called()
                bundle['sha256'][first] = original_digest
                bundle['machine_id'] = 42
                with self.assertRaises(b.InstallError):
                    b.run(args)
                commands.assert_not_called()
                bundle['machine_id'] = 41
                bundle['files']['../../outside'] = 'not allowed'
                with self.assertRaises(b.InstallError):
                    b.run(args)
                commands.assert_not_called()
                del bundle['files']['../../outside']
                if old:
                    bundle['token'] = 'different-synthetic-token'
                    with self.assertRaises(b.InstallError) as error:
                        b.run(args)
                    self.assertNotIn(bundle['token'], str(error.exception))
                    commands.assert_not_called()

    def test_orphan_state_refused_before_exchange(self):
        with tempfile.TemporaryDirectory() as directory:
            absent = Path(directory) / 'missing-config.json'
            actual_exists = Path.exists
            def exists(path):
                return path.as_posix() == '/var/lib/flowscope-collector/state.sqlite3' or actual_exists(path)
            with mock.patch.object(b,'CONFIG_PATH',absent), mock.patch.object(Path,'exists',exists), self.assertRaises(b.InstallError) as error:
                b.existing_config('https://panel.example.com',41,None)
            self.assertIn('Orphaned collector state', str(error.exception))

    def test_exchange_errors_are_secret_free(self):
        for failure in (OSError('synthetic-private'), ValueError('synthetic-private')):
            opener = mock.Mock()
            opener.open.side_effect = failure
            with mock.patch.object(b.urllib.request,'build_opener',return_value=opener), self.assertRaises(b.InstallError) as error:
                b.redeem('https://panel.example.com','synthetic-private')
            self.assertNotIn('synthetic-private',str(error.exception))

    def test_exchange_user_agent_and_http_status(self):
        opener = mock.Mock()
        opener.open.side_effect = b.urllib.error.HTTPError('https://private.example',403,'synthetic-private',{},None)
        with mock.patch.object(b.urllib.request,'build_opener',return_value=opener), self.assertRaises(b.InstallError) as error:
            b.redeem('https://panel.example.com','synthetic-private')
        request = opener.open.call_args.args[0]
        self.assertEqual(request.get_header('User-agent'), 'FlowScope-Installer/2.3.0')
        self.assertIn('HTTP 403',str(error.exception))
        self.assertNotIn('synthetic-private',str(error.exception))
        self.assertNotIn('private.example',str(error.exception))


if __name__ == '__main__':
    unittest.main(verbosity=2)
