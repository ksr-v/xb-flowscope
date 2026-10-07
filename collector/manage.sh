#!/usr/bin/env bash
# Rendered by the panel with quoted non-secret connection settings.
set -eu
panel=__FLOWSCOPE_PANEL__
machine_id=__FLOWSCOPE_MACHINE__
interfaces=__FLOWSCOPE_INTERFACES__
IFS= read -r ticket || ticket=''
[ "$(id -u)" = 0 ] || { echo '安装失败：请使用 sudo/root。' >&2; exit 1; }
[ -r /dev/tty ] && [ -w /dev/tty ] || { echo '安装失败：需要交互终端，请在 SSH 终端运行。' >&2; exit 1; }
printf '\n%s\n' 'FlowScope 采集器' '1. 对接面板 / 升级' '2. 卸载采集器' '0. 退出' >/dev/tty
printf '请选择 [1/2/0]：' >/dev/tty
IFS= read -r choice </dev/tty
case "$choice" in
  0) echo '已取消。'; exit 0 ;;
  1)
    umask 077
    d=$(mktemp -d /tmp/flowscope-install.XXXXXXXX)
    trap 'rm -f -- "$d/bootstrap.py"; rmdir -- "$d" 2>/dev/null || true' EXIT
    code=$(curl --proto =https --tlsv1.2 -fsS --max-time 60 --max-filesize 262144 --write-out '%{http_code}' --output "$d/bootstrap.py" "$panel/api/v2/flowscope/installer")
    [ "$code" = 200 ] || { echo '安装失败：下载采集器入口失败。' >&2; exit 1; }
    args=(--panel "$panel" --machine-id "$machine_id" --ticket-stdin)
    [ -z "$interfaces" ] || args+=(--interfaces "$interfaces")
    printf '%s\n' "$ticket" | /usr/bin/python3 "$d/bootstrap.py" "${args[@]}"
    ;;
  2)
    echo '警告：将删除本机 FlowScope 服务、程序、配置、数据库、未上报流量和专用账号。面板历史不删除。' >/dev/tty
    printf '确认卸载请输入 UNINSTALL（其他输入取消）：' >/dev/tty
    IFS= read -r confirm </dev/tty
    [ "$confirm" = UNINSTALL ] || { echo '已取消卸载，数据未删除。'; exit 0; }
    # Exact owned paths only. Never follow a substituted root or recurse across mounts.
    for target in /opt/flowscope-collector /var/lib/flowscope-collector /etc/flowscope-collector.json /etc/systemd/system/flowscope-collector.service /etc/systemd/system/flowscope-collector.service.d; do
      [ ! -L "$target" ] || { echo '卸载失败：安装路径存在软链接，请手动检查。' >&2; exit 1; }
      if [ -e "$target" ]; then
        [ "$(readlink -f -- "$target")" = "$target" ] || { echo '卸载失败：路径不是预期位置。' >&2; exit 1; }
        if command -v mountpoint >/dev/null && mountpoint -q -- "$target"; then
          echo '卸载失败：安装路径是挂载点，请手动检查。' >&2; exit 1
        fi
      fi
    done
    if entry=$(getent passwd flowscope); then
      IFS=: read -r account password uid gid gecos home shell <<< "$entry"
      [ "$uid" != 0 ] && [ "$home" = /var/lib/flowscope-collector ] && { [ "$shell" = /usr/sbin/nologin ] || [ "$shell" = /sbin/nologin ]; } || { echo '卸载失败：flowscope 账号不符合专用账号特征。' >&2; exit 1; }
    fi
    if entry=$(getent group flowscope); then
      IFS=: read -r group_name password group_id members <<< "$entry"
      [ "$group_id" != 0 ] && [ -z "$members" ] || { echo '卸载失败：flowscope 用户组不是空的专用组。' >&2; exit 1; }
    fi
    systemctl stop flowscope-collector.service 2>/dev/null || true
    if systemctl is-active --quiet flowscope-collector.service; then echo '卸载失败：服务未停止。' >&2; exit 1; fi
    if getent passwd flowscope >/dev/null && pgrep -u flowscope >/dev/null; then echo '卸载失败：专用账号仍有其他进程。' >&2; exit 1; fi
    systemctl disable flowscope-collector.service 2>/dev/null || true
    rm -f -- /etc/systemd/system/multi-user.target.wants/flowscope-collector.service /etc/systemd/system/flowscope-collector.service /etc/flowscope-collector.json
    rm -rf --one-file-system -- /opt/flowscope-collector /var/lib/flowscope-collector /etc/systemd/system/flowscope-collector.service.d
    systemctl daemon-reload
    systemctl reset-failed flowscope-collector.service 2>/dev/null || true
    if getent passwd flowscope >/dev/null; then userdel flowscope; fi
    if getent group flowscope >/dev/null; then groupdel flowscope; fi
    for target in /opt/flowscope-collector /var/lib/flowscope-collector /etc/flowscope-collector.json /etc/systemd/system/flowscope-collector.service /etc/systemd/system/flowscope-collector.service.d /etc/systemd/system/multi-user.target.wants/flowscope-collector.service; do
      [ ! -e "$target" ] && [ ! -L "$target" ] || { echo '卸载失败：仍有本机安装文件残留。' >&2; exit 1; }
    done
    echo '卸载完成：本机采集器程序、服务、配置、数据库和专用账号已清除；面板历史已保留。'
    ;;
  *) echo '安装失败：无效选项。' >&2; exit 1 ;;
esac
