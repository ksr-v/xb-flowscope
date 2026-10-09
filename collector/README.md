# FlowScope Linux Collector 2.3.0

FlowScope 面板插件 2.4.0 可从新版 Xboard-Node 自动获取 IPv4；只查看 IP 不必安装或升级采集器。现有 2.2/2.3 采集器的 IPv4 请求头继续作为兼容来源，2.0/2.1 采集器继续上报流量。本次未修改采集器源码、协议、Token、身份、序号及 SQLite outbox；2.4.0 插件随附的采集器保持 2.3.0 标识。

## 交互式安装 / 卸载

面板生成的短命令在 Bash SSH 终端执行，从 `/dev/tty` 选择 1 对接/升级或 2 卸载；凭证单独从标准输入传入。安装清理已知旧程序缓存与 `.new` 残留并保留配置/SQLite/待上报队列。成功提示“安装成功，已完成对接配置”，首次流量仍需等待上报。

卸载必须输入 `UNINSTALL` 确认；删除本机程序、服务/drop-in/自启链接、配置、数据库与未上报流量、专用账号/组，不删除面板历史。异常路径、挂载点、非专用账号或其他进程会拒绝。不会擦除系统日志、Shell 历史或用户备份。卸载不做自动数据备份，确认前自行保存所需数据。

## v2.3.0 出口 IPv4 与升级

生产采集器在上报线程通过 `https://api-ipv4.ip.sb/ip` 查询出口 IPv4，自定义 FlowScope User-Agent、验证 TLS、拒绝跳转、绕过环境代理，不传输 Token。成功缓存一小时，失败五分钟后重试，缓存两小时后不再发送。IPv4 通过独立请求头发送给面板，不修改积压 payload 或其摘要；升级不需要清空 SQLite。查询失败不阻断流量计量。

先升级面板插件，再重新生成一键安装命令升级本机采集器。完整旧安装保留配置和状态；首次安装自动配置；仅程序残留可补装，但孤立数据库、身份冲突、异常软链接/权限应保留现场并手动排查，禁止删除数据库“解决”。多出口时查询的是访问 IP.SB 的出口 IPv4。

独立的物理服务器网卡采集器，Python **3.9+**，仅使用标准库。它不修改 Xboard、Xboard-Node 或其它插件，不读取代理 `u/d`，不要求安装哪吒。

`rx_bytes` 来自宿主机 `/sys/class/net/<接口>/statistics/rx_bytes`，表示网卡接收（入站）；`tx_bytes` 表示发送（出站）。这里只统计配置中明确选定的接口，不自动发现、自动排除或合计其它网卡。所选接口的全部流量都会计入，不等同服务商账单。

## 前提与接口选择

- 在真实 Linux 宿主机上以本包提供的 systemd 服务运行；不要在 Docker/Podman、独立 network namespace 中运行。脚本检查容器文件标记、环境及 cgroup；可读取 PID 1 网络 namespace 时严格比较 device+inode，不同则拒绝。
- 非 root 用户可能因 procfs/ptrace 权限无法读取 `/proc/1/ns/net`：仅此 `PermissionError` 情况会输出受限探测警告并依赖上述标记检查和无 `PrivateNetwork` 的宿主机 unit，不要求 root/CAP_SYS_PTRACE。其它 procfs 错误仍拒绝。**这是保守防误部署检查，不是宿主机 namespace 的完整证明**；隐藏容器等仍须部署方核实。
- 需要 `/usr/bin/python3` 3.9+、SQLite 标准库、systemd、`useradd/groupadd/getent/install`。运行账户为独立、无登录权限的 `flowscope`，无需 root 或网络管理 capability。
- Xboard 上安装并启用 FlowScope **2.3.0**；使用“物理服务器管理”中对应机器的 ID 和机器 token。不要使用节点 ID、用户 token 或管理员登录 token。
- 必须设置 `interfaces` allowlist，1..32 个不同的 ASCII 接口名。例如 `['ens3']`（配置 JSON 中使用双引号）。先在主机运行 `ip -br link`、`ip route` 确认实际接口。
- bond 主接口与其 slave、bridge 与其成员、veth 两端可能重复计算同一流量，**不要同时选取重叠接口**。多张不重叠的物理网卡可以明确列出。脚本不根据名称判断“物理”或替你选择计费接口。
- 一台物理机器只部署一个采集器。相同 state 目录由 Linux `flock` 防止双进程；后端也只允许一个 collector_id 绑定机器。不要用不同 state 目录运行第二份。

## 本地安装

v2.3.0 推荐从 **服务器管理 → 编辑服务器 → FlowScope** 生成一键安装命令。命令通过 HTTPS 下载面板的 `bootstrap.py`，使用短期单次凭证兑换配置和固定文件包，自动安装/启动/设为开机自启。长期机器 Token 不在 shell 命令或 URL 中。一键安装需要信任面板（以 root 执行其代码），并已具备 curl、Python 3.9+、systemd、iproute2；不自动安装系统依赖。

默认合并 IPv4/IPv6 默认路由，仅唯一且带设备引用的出口自动选择；多出口或虚拟/聚合接口请在界面指定。已有安装默认保留原网卡和私有配置，跨面板/机器或配置冲突拒绝覆盖。凭证兑换一次即失效，后续失败需生成新命令。以下手动方式继续支持。

使用经过信任的本地发布包，不使用 `curl | sh`，安装脚本不会下载任何东西。独立发布包顶层目录为 `flowscope-collector-2.3.0/`；插件 ZIP 内也附带 `Flowscope/collector/`。以下命令在包含 `install.sh` 的目录执行。

1. 准备配置文件，权限设为 `0600`，用编辑器填写 URL、机器身份和真实网卡名：

   ```sh
   install -m 0600 config.example.json ./my-collector.json
   nano ./my-collector.json
   ```

2. 本地安装（以实际绝对路径替换示例）：

   ```sh
   sudo bash ./install.sh --config /absolute/path/my-collector.json
   sudo -u flowscope /usr/bin/python3 /opt/flowscope-collector/flowscope_collector.py --check
   sudo systemctl enable --now flowscope-collector
   sudo systemctl status flowscope-collector
   sudo journalctl -u flowscope-collector -n 50
   ```

安装路径固定如下：

| 内容 | 路径 / 权限 |
| --- | --- |
| 采集程序 | `/opt/flowscope-collector/flowscope_collector.py`，root 所有 |
| 私有配置 | `/etc/flowscope-collector.json`，flowscope 所有，`0600` |
| 持久状态目录 | `/var/lib/flowscope-collector`，flowscope 所有，`0700` |
| SQLite 状态 | `/var/lib/flowscope-collector/state.sqlite3`，`0600` |
| 服务 | `/etc/systemd/system/flowscope-collector.service` |

安装脚本只部署自身文件和上述路径，不启动/重启服务，不覆盖不同的现有配置，不删除 DB、WAL 或历史。首次安装必须提供 `--config`。升级可以不带 `--config`；如提供，它必须与已安装配置字节一致。

```sh
sudo bash ./install.sh
sudo systemctl restart flowscope-collector
```

升级会更新程序及本包 unit；自定义 systemd 设置请放在 drop-in 中。不要卸载/删除 state 来升级。现有配置或 DB 所有者异常时应人工核对，安装脚本不会递归修改历史文件。

## 配置

见 [config.example.json](config.example.json)：

- `panel_url`：面板基址，如 `https://panel.example.com`；可包含部署子路径，不含账号密码、query 或 fragment。默认只接受 HTTPS。只有 loopback IP / `localhost` 可使用 HTTP，供本地测试；生产不要使用此例外。
- `machine_id`：正整数物理机器 ID。
- `token`：该机器当前 token，1..256 字符；只在 POST JSON body 中传送，不放 URL，不存进 outbox。
- `interfaces`：显式、不重复的接口列表。
- `state_file`：默认上述固定路径。本安装脚本/unit 不支持其它路径；手工部署需自行调整权限、锁路径及 `ReadWritePaths`，不能复用其它机器状态。
- `sample_interval`：每次采样间隔秒数，默认 `10`，范围 1..3600。
- `report_interval`：补报循环间隔秒数，默认 `30`，范围 1..3600；每轮按顺序最多发送100条，启动立即尝试补报。
- `request_timeout`：HTTP socket 超时秒数，默认 `10`，范围 1..60；网络发送在独立线程中，不阻塞采样。该超时不是整个慢速响应的严格总时限。

未知配置字段直接拒绝，因此不存在关闭 TLS 校验的隐藏选项。HTTPS 使用系统 CA 并校验 hostname；自签名证书应正确部署受信任 CA，不应关闭验证。任何 3xx 重定向都不跟随，token 不会转发到重定向目标；`panel_url` 必须直接指向正确的面板位置。

更换 token 或接口后，保留原 state 并重启服务以加载配置：

```sh
sudoedit /etc/flowscope-collector.json
sudo systemctl restart flowscope-collector
```

state 绑定最初的 `panel_url + machine_id`。修改机器或 URL 别名会 fail closed，不会自动清空/迁移状态；先人工核对后端绑定及完整 outbox。不要把一台机器的 state 复制给另一台。

## 采样、重启与准确性边界

首次安装只建立 baseline，不计入安装前网卡历史，也不生成第一条报告。默认约10秒后产生 sequence `1`，约30秒补报；首次后台可见时间还取决于网络及面板可用性。

每次有效采样在同一个 SQLite 事务中写入：每网卡 baseline、boot_id、结束时间、连续序号、完整 outbox。SQLite 使用 `journal_mode=WAL` 和 `synchronous=FULL`，只有事务成功才推进 baseline。磁盘满/IO 错误、任一配置网卡缺失或读数无效时，整次采样不推进；恢复后可从原 baseline 续算。实际持久性仍依赖文件系统/硬件正确执行 fsync。

| 场景 | 行为 |
| --- | --- |
| 同一 boot 的进程/服务重启 | 使用已持久化 baseline 计算增量，不从0重置 |
| 主机 boot_id 改变 | 已知接口的新启动累计数计入，设置 `boot_changed=true`；旧启动尾段存在不可恢复采样缺口 |
| 同一接口同一 boot 单方向 counter 下降 | 只对该方向计入当前新 counter；另方向正常相减，`resets` 按发生 reset 的接口计数 |
| 同名接口 ifindex 改变 | 视为新接口 generation，仅建立 baseline、不导入历史；`resets` 加1标记未知缺口 |
| allowlist 新增接口 | 新接口首次仅建立 baseline，既有接口照常相减 |
| allowlist 移除后重新加入接口 | 重新加入时重新建立 baseline，不导入未被监控期间的历史 |
| wall clock 回退或与上次相同 | 等到时间超过已持久化 end；不推进 baseline/sequence，不丢弃累计字节 |

计数未下降不能证明没有发生接口重建或 driver reset；同一 ifindex 被复用且新计数已超过旧值等异常无法完全识别。RX/TX 及多网卡读取也不是内核原子快照，存在读取时差。接口在采样中 ifindex 或 boot_id 改变会拒绝该次采样。

“重启不丢”精确定义为：**已提交采样且尚未 ACK 的报告可持久补报**。突然断电前尚未采样的尾段无法恢复；断电与网卡 reset 不应承诺零损失。采样间隔越长，潜在尾段缺口越大。服务器重启前已成功接收的重复报告由后端幂等 ACK，不会重复累计。

报告是严格连续的 `[started_at, ended_at)` 增量区间。后端按区间时长比例分摊跨日流量并保持整数字节守恒；这不是边界时刻的真实逐字节测量。停机/离线/时钟回退导致长区间时，各日/周期分配是估计，总量也可能有 reset/断电缺口。最大允许单区间366天，超出或整数字节/序号上限时保留状态停止推进，需人工处理，不自动截断。

## 上报、安全与故障处理

固定协议入口：`POST {panel_url}/api/v2/flowscope/collect`。`protocol=1`，稳定32位 lowercase hex `collector_id`，从1开始连续 decimal-string sequence；RX/TX 增量为 decimal string，避免 JSON/JavaScript 整数精度损失。读取 `/proc/sys/kernel/random/boot_id`，每接口记录 ifindex。

只有 `{data:{accepted:true,sequence:"当前序号",duplicate:布尔值}}` 的 matching ACK 才能删除对应队首 outbox；ACK 不匹配、响应过大/非 JSON、传输失败不会删除。服务端接受后本地提交 ACK 之前崩溃会重发同一条，后端按重复报告 ACK。

- 断网、5xx、429、401/403、404：保留 outbox，下一轮重试。FlowScope 暂时禁用再启用可自动补报；token 更新必须更新配置并重启，内存中旧 token 不会自动变化。机器禁用、权限问题需先恢复机器状态。
- 409 sequence conflict：暂停发送，继续持久采样，人工检查机器 collector 绑定/顺序。**绝不自动丢弃 outbox、重置 sequence 或创建新 collector_id**。
- 400/405/413/422、任何重定向：暂停发送，修复协议/部署/配置后重启。日志只输出状态码及固定短原因，不输出 token、请求 JSON、完整响应或异常 URL。
- 报告线程异常退出会令主进程退出非0，systemd `Restart=on-failure` 重启；不会静默留下只采样不上报的进程。
- 暂停发送或长期离线时 outbox 会占用更多磁盘，不自动删历史。监控磁盘、服务状态和日志。磁盘满后无法继续持久采样，不能宣称仍具备断电保护。

不要通过删除 state、恢复陈旧 DB、修改 sequence 或切换 collector_id“修复”错误。陈旧备份与后端 cursor 不一致可能引起冲突或重复序号下的数据遗漏。备份应先停止采集服务并一致备份整个 state 目录；恢复必须同时人工核对后端绑定和最后已确认序号。

## 命令与验证状态

```sh
python3 flowscope_collector.py --version
python3 flowscope_collector.py --config /private/config.json --check
python3 flowscope_collector.py --config /private/config.json --once
```

`--check` 仅检查配置、网卡和 namespace，不发送 HTTP、不创建/修改 state。`--once` 采样一次并尝试顺序补报；首次 baseline 无报告，仍有待补报条目时退出非0。普通启动运行两个独立采样/发送循环。

仓库中测试入口：

```sh
python tests/collector_test.py
```

测试使用临时 workspace、模拟 Linux sysfs/proc、真实 SQLite 事务及 loopback HTTP 重定向 fixture，覆盖持久恢复、ACK 丢失/提交失败、幂等补报、逐NIC reset、接口变更、时钟回退、HTTP校验及线程异常退出。

验证范围以发布包中的 `TEST_REPORT.md` 为准。v2.0.0 已有独立的 Ubuntu NIC/systemd/重启实测记录，不能冒充 v2.3.0 新增一键安装链路已实测。`tests/bootstrap_test.py` 覆盖 HTTPS/重定向、默认出口/IPv6/多网卡、配置冲突保护和安装/重复安装流程；跨平台模拟不能替代 Linux 实机验收。
