#!/usr/bin/env bash
# Local-only installer. Does not download code, start services, overwrite config, or delete state.
set -euo pipefail

usage() {
  printf '%s\n' 'Usage: sudo bash collector/install.sh [--config /absolute/prepared.json]' \
    'First install requires --config. Upgrades may omit it and keep installed configuration.' \
    'If --config is supplied on upgrade it must be identical to the installed config.' \
    'State is preserved. Service start/restart is an explicit operator action.'
}

prepared_config=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --config)
      [ "$#" -ge 2 ] || { usage >&2; exit 2; }
      prepared_config=$2
      shift 2
      ;;
    --help|-h) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
done
[ "$(id -u)" -eq 0 ] || { printf '%s\n' 'Run this installer with sudo/root.' >&2; exit 1; }
[ "$(uname -s)" = Linux ] || { printf '%s\n' 'A native Linux host is required.' >&2; exit 1; }
[ -x /usr/bin/python3 ] || { printf '%s\n' '/usr/bin/python3 (3.9+) is required.' >&2; exit 1; }
/usr/bin/python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)' || {
  printf '%s\n' 'Python 3.9+ is required.' >&2; exit 1;
}

source_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
install_dir=/opt/flowscope-collector
config_file=/etc/flowscope-collector.json
state_dir=/var/lib/flowscope-collector
unit_file=/etc/systemd/system/flowscope-collector.service
for target in "$install_dir" "$install_dir/flowscope_collector.py" "$install_dir/flowscope_collector.py.new" \
              "$config_file" "$state_dir" "$unit_file" "$install_dir/__pycache__"; do
  [ ! -L "$target" ] || { printf '%s\n' 'Refusing a symlink at an installation target.' >&2; exit 1; }
done
for source_file in "$source_dir/flowscope_collector.py" "$source_dir/flowscope-collector.service"; do
  [ -f "$source_file" ] && [ ! -L "$source_file" ] || {
    printf '%s\n' 'Collector package is incomplete or contains a symlink source.' >&2; exit 1;
  }
done
if [ -e "$install_dir" ]; then
  [ -d "$install_dir" ] && [ "$(stat -c '%u' -- "$install_dir")" = 0 ] \
    && [ "$(stat -c '%a' -- "$install_dir")" = 755 ] || {
    printf '%s\n' 'Existing installation directory must be root-owned with mode 0755.' >&2; exit 1;
  }
fi
if [ -n "$prepared_config" ]; then
  case "$prepared_config" in /*) ;; *) printf '%s\n' '--config must be an absolute path.' >&2; exit 1 ;; esac
  [ -f "$prepared_config" ] && [ ! -L "$prepared_config" ] || {
    printf '%s\n' 'Prepared config must be a regular non-symlink file.' >&2; exit 1;
  }
  [ "$(stat -c '%a' -- "$prepared_config")" = 600 ] || {
    printf '%s\n' 'Prepared config must have mode 0600 (chmod 600 FILE).' >&2; exit 1;
  }
fi
if [ -e "$config_file" ]; then
  [ -f "$config_file" ] || { printf '%s\n' 'Installed config is not a regular file.' >&2; exit 1; }
  if [ -n "$prepared_config" ] && ! cmp -s -- "$prepared_config" "$config_file"; then
    printf '%s\n' 'Existing config differs; it was NOT overwritten. Edit it explicitly, then retry without --config.' >&2
    exit 1
  fi
elif [ -z "$prepared_config" ]; then
  printf '%s\n' 'First installation requires --config /absolute/prepared.json.' >&2
  exit 1
fi

# Validate without displaying secret values. The bundled unit grants only this state directory.
validation_config=${prepared_config:-$config_file}
/usr/bin/python3 - "$source_dir" "$validation_config" <<'PY'
import json
from pathlib import Path
import sys
sys.path.insert(0, sys.argv[1])
from flowscope_collector import Config, CollectorError
try:
    with open(sys.argv[2], encoding="utf-8") as handle:
        config = Config.from_dict(json.load(handle))
    if config.state_file != Path("/var/lib/flowscope-collector/state.sqlite3"):
        raise CollectorError("bundled installer requires state_file /var/lib/flowscope-collector/state.sqlite3")
except (OSError, ValueError):
    print("Configuration is not readable valid JSON; no secrets displayed.", file=sys.stderr)
    sys.exit(1)
except CollectorError as error:
    print(str(error), file=sys.stderr)
    sys.exit(1)
PY

getent group flowscope >/dev/null || groupadd --system flowscope
id flowscope >/dev/null 2>&1 || useradd --system --gid flowscope --home-dir "$state_dir" \
  --no-create-home --shell /usr/sbin/nologin flowscope
[ "$(id -u flowscope)" -ne 0 ] || {
  printf '%s\n' 'Refusing flowscope account with uid 0; a non-root service account is required.' >&2; exit 1;
}
[ "$(getent group flowscope | cut -d: -f3)" -ne 0 ] || {
  printf '%s\n' 'Refusing flowscope group with gid 0; a dedicated non-root group is required.' >&2; exit 1;
}
# Clean only known program/temp residue. Never remove config, SQLite, WAL or outbox.
if [ -f "$install_dir/flowscope_collector.py" ]; then
  printf '%s\n' '检测到已有采集器：清理旧程序缓存和临时残留，保留配置与采集数据。'
fi
if [ -e "$install_dir/flowscope_collector.py.new" ]; then
  [ -f "$install_dir/flowscope_collector.py.new" ] || { printf '%s\n' '安装失败：旧临时程序不是普通文件，请手动检查。' >&2; exit 1; }
  rm -f -- "$install_dir/flowscope_collector.py.new"
fi
if [ -d "$install_dir/__pycache__" ]; then
  for cached in "$install_dir"/__pycache__/flowscope_collector.*.pyc; do
    [ -e "$cached" ] || [ -L "$cached" ] || continue
    [ -f "$cached" ] && [ ! -L "$cached" ] || { printf '%s\n' '安装失败：旧缓存路径异常，请手动检查。' >&2; exit 1; }
    rm -f -- "$cached"
  done
  rmdir -- "$install_dir/__pycache__" 2>/dev/null || true
fi
install -d -o root -g root -m 0755 -- "$install_dir"
install -d -o flowscope -g flowscope -m 0700 -- "$state_dir"
if [ ! -e "$config_file" ]; then
  install -o flowscope -g flowscope -m 0600 -- "$prepared_config" "$config_file"
fi
# Existing config/state ownership is intentionally not changed recursively.
install -o root -g root -m 0755 -- "$source_dir/flowscope_collector.py" "$install_dir/flowscope_collector.py.new"
mv -f -- "$install_dir/flowscope_collector.py.new" "$install_dir/flowscope_collector.py"
install -o root -g root -m 0644 -- "$source_dir/flowscope-collector.service" "$unit_file"
systemctl daemon-reload
printf '%s\n' 'Installed collector 2.3.0; configuration and existing state were preserved.' \
  'Validate on the host (no HTTP/state mutation):' \
  '  sudo -u flowscope /usr/bin/python3 /opt/flowscope-collector/flowscope_collector.py --check' \
  'First start (explicit): sudo systemctl enable --now flowscope-collector' \
  'Upgrade/reconfigure (explicit): sudo systemctl restart flowscope-collector' \
  'Inspect: sudo systemctl status flowscope-collector; sudo journalctl -u flowscope-collector -n 50' \
  'Never delete /var/lib/flowscope-collector to repair a sequence error.'
