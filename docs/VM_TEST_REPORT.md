# FlowScope 2.0.0 — 本地虚拟机验证

日期：2026-10-07（Asia/Shanghai）。测试目标 `192.168.128.129` 由用户明确授权；源码传输范围另行确认。不读取或传输生产 `.env`、Token、私钥或数据库。

## 环境与边界

Ubuntu 24.04.3 LTS / VMware / Linux 6.8.0-71-generic / 原生 systemd / ext4；Python 3.12.3、PHP 8.3.6、Node 18.19.1。网卡 `ens33`，MAC `00:0c:29:9d:05:c9`；与 `machine2-codex-x` 的 VMX 一致。接口是虚拟机所见的真实内核网卡，不是容器 sysfs 或模拟计数。

隔离目录 `/home/codex/flowscope-vm-test`。API 使用真实插件路由、中间件、Controller、Service、迁移及 Xboard 机器模型，连接持久化文件 SQLite，WAL + synchronous FULL。API 仅监听 `127.0.0.1:18080`；机器上报使用随机生成的假测试 Token，Admin 仅用于 fixture 的本地模拟身份。这不是完整生产 Xboard 部署，不是生产 Sanctum、MySQL、Octane 或 Nginx 验收。

采集器使用实际安装器、原装 systemd unit、独立 `flowscope` 用户（UID 999），配置/DB 权限 0600、状态目录 0700。测试加快为每 2 秒采样、每 1 秒上报；发布默认值未改。

## 已执行结果

| 项目 | 实际结果 |
| --- | --- |
| Linux 全套测试 | backend 210、integration 123、collector 57/57（零跳过）、protocol-e2e 2/2 全过。Windows 跳过的 POSIX 权限、symlink、flock 项已在 Linux 执行。 |
| Linux 构建及原生 ZIP 生命周期 | package 162 checks（本次 VM 初始快照）、release 2/2 全过。 |
| 安装 / 只读检查 / 覆盖安装 | 不自动启动；`--check` 不发送数据且不创建状态。覆盖安装保留配置、状态和停止状态。原装 service 的非 root、零 capabilities、NoNewPrivileges、ProtectSystem=strict 等约束实际启用。 |
| 真实网卡双向统计 | 通过 SSH/SCP 双向传输测试数据；统计所有选定 NIC 字节（含协议开销和其他流量），不是只按文件大小猜测流量。初次冻结对账 RX=27495508 / TX=26808222，精确相等。 |
| 接收端中断 | 停止测试 API（SSH/网卡仍通），队列从 10 条增长到 31 条。API 历史不变，持久化 outbox 与网卡差值守恒。 |
| 进程强制终止 | 主进程 PID 11130 被 SIGKILL；systemd 10 秒后自动恢复 PID 11145，NRestarts=1。原 10 条队列内容逐项不变，身份/序号接续。 |
| 补报 / 重复 ACK | 31 条积压全部接受，pending=0；重新发送最新报告得到 duplicate=true，日档/游标不变。 |
| API 进程重启 / 再次 init | 文件 DB 历史、游标和累计值不变；没有重建、清空或重新绑定。 |
| 正常 VM 重启 | 重启前 pending=3，sequence 49；自动恢复后 sequence 66、pending=0、boot_changes=1。新 boot 已提交 RX=23411 / TX=13991 与原历史及队列精确相加。 |
| 非正常强制重启 | 接收端停用、采集器 SIGSTOP，保留 3 条队列及 49472-byte 未关闭 WAL，执行 `systemctl reboot --force --force` 跳过正常进程/文件系统关闭。恢复后 sequence 69→83，pending=0，boot_changes=2，SQLite quick_check=ok；新 boot RX=22967 / TX=13475 与旧历史及队列精确相加。 |

`systemctl kill -s SIGKILL` 首次返回 auxiliary-process 错误，但 journal 明确证明主进程收到信号 9 并自动重启；结果依据实际 PID/journal，不把命令返回码误当整项验证成功。

## 精确对账方法

在采集进程停止或冻结、接收端没有新的写入时读取两个 DB 的一致快照。每项均采用任意精度整数，不用 SQL 浮点 SUM：

```text
同 boot / 同 ifindex：
当前 API 总量 + 当前待报增量
  = 起点 API 总量 + 起点待报增量 + 当前 NIC 快照 - 起点 NIC 快照

跨 boot：
当前 API 总量 + 当前待报增量
  = 重启前 API 总量 + 重启前待报增量 + 新 boot 当前已提交 NIC 快照
```

全部 10 个复核检查通过。RX、TX 分开相等；队列从 API 已确认序号的下一条起连续，collector ID 始终 `72d260d0cedb492494096f122490fc71`。最终 sequence=83，RX=1058170518、TX=58964800、合计=1117135318 bytes，pending=0。

Boot ID 依次为 `6c67dc57-d89f-4f12-bc7a-be66b7e4d44a`、`080e4a28-f018-4dcb-95a9-f4bc016e44c5`、`913426ec-9b6c-4fa4-acf4-2406513d4cf1`。

## 未声称通过的项目

- 宿主机直接断电 / VMware hard power-off：未执行。`vmrun.exe` 缺少 VIX.DLL；没有修改宿主安装或任何其他 VM。非正常 guest 重启不能冒充物理断电测试。
- 关机前尚未采样尾段：无法恢复；正常停止目前也没有最终采样钩子。跨 boot 等式证明保留已提交记录，不证明尾段逐字节零丢失。
- 生产 Xboard 登录、Redis、MySQL 锁、Octane 长驻进程、Nginx 静态后缀规则和生产 HTTPS：没有在本 VM 完整部署，仍按既有自动化/浏览器覆盖与部署边界说明。
- 裸机网卡驱动、云服务商计费以及其他虚拟化/存储 fsync 实现：不在本次结论范围内。

## 证据与结束状态

本地证据在 `tests/.runtime/vm/evidence/`，包含各阶段快照、`vm-results.json`、服务属性及 journals；Linux 测试日志在 `tests/.runtime/vm/linux-logs/`。工具在 `tests/vm/`。证据不含 Token；fixture 私密配置不进入发布包。

两个本次创建的测试服务 `flowscope-collector` / `flowscope-vm-api` 已停止并禁用，测试数据和安装文件保留以便复核。未删除任何原有 VM 文件或服务，未改动生产 checkout。本轮未发现发布代码问题，不升版本；v2.0.0 补充验证工具和文档后重新打包。
