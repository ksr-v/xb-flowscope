# FlowScope 2.4.0

## v2.4.0 出口 IPv4 自动发现

启用本版插件并正常对接支持此功能的 Xboard-Node（本地构建版本标识 v1.13-orphan.2）后，服务器管理自动显示公网出口 IPv4。只查看 IP 无需 Python 采集器、周期流量开关或额外 Token。Node 首次认证成功、原地升级/重启及连接恢复后自动尝试；通过 HTTPS、IPv4 直连 IP.SB，成功缓存 12 小时、失败 5 分钟重试，不使用环境代理。进程内多实例共享探测，各面板使用自己的已有凭据独立上报。

复用既有机器地址表和显示组件：24 小时内 Node 结果优先；过期时使用 2 小时内的采集器结果；都过期则保留机器最后已知地址并标注。没有有效记录时显示“待获取”；地址提示显示来源和最近成功检测时间。旧采集器协议、SQLite outbox、历史流量和设置继续兼容，现有采集器无需升级或重装。

machine mode 使用机器专属 Token。旧 node mode 的原生 server token 是面板级共享凭据，没有逐节点隔离能力：结果仅缓存于节点范围，服务端按当前数据库关系展示；无可靠机器关联时只展示在节点管理对应行。该兼容缓存最长保留至检测后 24 小时，缓存丢失会等待自动补报。需要机器级凭据隔离时使用原生 machine mode。

见 [2.4.0 交付及升级说明](docs/UPGRADE_v2.4.0.md)。Node/插件可以任意先后升级；接口不存在/禁用时有限缓存并重试，双方支持后自动启用，无需重绑。成功上报后每小时刷新，404/旧接口约 10 分钟重新发现，另加原生认证请求间隔及页面约一分钟刷新。Node 上报沿用已配置的面板协议；HTTPS 保持证书校验。下方 2.2/2.3 内容为保留的功能沿革。

## v2.3.0 交互式管理

在服务器编辑页生成简短管理命令，在 **Bash SSH 终端**运行。菜单：`1. 对接面板 / 升级`、`2. 卸载采集器`、`0. 退出`。脚本来自当前面板并以 root 执行，请仅信任自己的 HTTPS 面板。对接凭证仍通过标准输入传递，10 分钟内单次有效，不进入 URL 或 root 安装进程参数；菜单从 `/dev/tty` 读取，不会把凭证误当选择。

对接前清理已知旧程序缓存和 `.new` 临时残留，不删除配置、数据库、历史或待上报队列。成功提示“安装成功，已完成对接配置”，随后等待面板首次上报；失败明确提示“安装失败”。入口完整下载后才执行，失败、空响应或半截下载不执行。

**卸载不可恢复本机未上报数据**：选择 2 后必须输入 `UNINSTALL` 才执行。清除 `/opt/flowscope-collector`、`/var/lib/flowscope-collector`、私有配置、systemd 单元及其 drop-in/自启链接和专用账号/组；面板历史不删除。路径异常、挂载点、共享账号或其他账号进程会拒绝继续。这里只保证本采集器的安装资源清理，不擦除系统 journal、Shell 历史、操作者额外制作的备份、测试 CA 或第三方资料，以免误删其他数据。取消卸载不删除任何数据。

从 v2.2.0 升级不新增数据库迁移，也无需为菜单专门升级各机采集器：先上传新版面板插件、重载常驻 PHP 并刷新，再生成新命令即可。需要卸载时面板的安装入口须可访问；插件禁用后不能下载管理入口。

## v2.2.0 功能

- 服务器管理：在“最后心跳 / 节点数”下展示机器出口 IPv4。由服务器采集器通过 [IP.SB IPv4 专用 API](https://ip.sb/api/) 查询 `https://api-ipv4.ip.sb/ip`，只接收公网 IPv4；不会展示 IPv6、内网地址或浏览器自身 IP。查询直接连接，不使用环境变量中的 HTTP 代理，不发送 Token 或机器信息。
- 查询成功缓存一小时，失败五分钟后重试，旧缓存最多保留两小时。查询有三秒请求超时，独立于采样线程；失败不阻断网卡计量或流量上报。界面没有记录时显示“暂无 IPv4”，旧上报标为“最后已知”。多出口/NAT 下显示访问 IP.SB 时的出口，不保证等于服务监听 IP。
- 周期流量卡片桌面端四列、平板两列、手机一列，继续按占用排序。
- 历史采集器路线：旧 Node 仍需支持 IPv4 的采集器提供地址。v2.4.0 搭配新版 Node 后无需升级采集器；原有流量采集继续兼容。
- 空白安装自动创建配置和服务。完整旧版安装只替换程序，保留私有配置、采集身份、SQLite 历史和待上报队列。只有程序残留、没有配置和数据库时可继续首次安装；数据库存在但配置丢失、机器身份不匹配、异常软链接或权限异常时停止，不清空数据、不自动重置绑定。失败后按提示检查并重新生成命令。

Xboard 独立扩展：精简 Admin 展示，并按**物理服务器网卡**统计月周期流量。配置入口是 **服务器管理 → 编辑服务器**，不是节点管理。一台服务器承载多个节点，只统计一次网卡流量。

v2 使用独立 Linux 采集器读取指定网卡 RX/TX 累计字节，先持久化、再补报。借鉴[哪吒网卡计数](https://github.com/nezhahq/agent/blob/main/pkg/monitor/nic/nic.go)及[快照/重启处理](https://github.com/nezhahq/nezha/blob/master/model/server.go)方式；不需要安装哪吒面板。不会再把代理 `u/d` 或网速积分当作网卡流量。

## 环境与兼容性

- 面板：PHP 8.2+ 64 位、Laravel 12，以及支持 `App\Models\ServerMachine` 的 Xboard 机器管理版本。
- 审计/测试目标：`master`，commit `f52adba714ad87fb3d02b3ad1b66410856e676da`，本地 checkout 包含机器管理功能。
- Admin：`assets/index-CEIYH7i8.js`，参考 SHA256 `0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666`。关键结构签名必须唯一；不兼容版本关闭 UI 集成，不改写原文件。后端接口独立可用。
- 每台被监控服务器：Linux、Python 3.9+（仅标准库）、systemd、可持久化的本地磁盘、能通过 HTTPS 访问面板。采集器须运行在宿主机网络命名空间。
- 不依赖 Xboard 统计队列；面板插件不修改 Xboard core、共享 Admin bundle 或其他插件。新版 Node 在其独立仓库提供自动 IPv4 上报。

## 1. 安装或升级面板插件

在 Xboard **插件管理**上传 dist/flowscope-2.4.0.zip，安装并启用；已有版本直接上传升级，**不要先卸载**。升级后按正常部署方式重载 Octane/常驻 PHP 工作进程，再强制刷新 Admin。只需要 IPv4 时插件步骤到此完成，正常对接新版 Node 即可；下面的采集器步骤仅用于周期网卡流量。

这是重大版本升级：旧节点设置仍保留，但不自动转成机器设置，也不把旧代理统计混入网卡历史。请在服务器编辑界面重新配置机器的额度、方向和周期。首次采集前显示等待采集，不会伪造过去整月的流量。

ZIP 根目录为 `Flowscope/`。手动安装路径是 `<Xboard>/plugins/Flowscope/`（Linux 大小写敏感），仓库源码目录仍叫 `FlowScope/`。原生 PluginManager 负责迁移，不要自行重复执行迁移。

## 2. 推荐：一键安装采集器

在 **服务器管理 → 编辑已有服务器 → FlowScope → 生成一键安装命令**，复制生成的命令到对应 Linux 宿主机执行即可。它会自动下载采集器、配置机器身份、选择网卡、启动服务并设置开机自启；不需要手填机器 Token。

- 机器必须已启用，面板须通过有效证书的 HTTPS 访问；服务器需有 `curl`、Python 3.9+、systemd 和 iproute2。安装器不自动修改系统软件源或安装 OS 依赖。
- 网卡栏留空时，合并 IPv4/IPv6 默认路由，仅在有唯一、带设备引用的默认出口时自动选择。多出口、隧道、bond/bridge 等情况请明确填写，例如 `ens33` 或 `eth0,eth1`，不要选择重叠接口。
- 命令有 **10 分钟、单次使用**限制；重新生成会使同一机器的旧命令失效。执行前不要分享命令。长期机器 Token 不出现在命令、URL 或数据库安装凭证表中，仅通过 HTTPS POST 兑换到服务器本地私有配置。
- 命令以 root 权限执行**你面板提供的代码**，请先确认域名是自己的可信面板。下载不跟随重定向，不关闭 TLS 校验，先下载到私有临时目录再执行，不使用管道直接执行。
- 同面板、同机器重复执行会更新程序、启动/重启服务，保留原配置、采集器身份、基线和待补报队列。已有网卡或 Token 不同、状态属于别的机器时拒绝覆盖，需人工核对；不要删除状态强行安装。
- 凭证已使用后安装失败或网络中断，请重新生成命令。首次统计通常在 30–60 秒后出现，仍需开启下方周期流量显示并单独保存。

已有 v2.0.0 直接上传 v2.3.0 升级，不要卸载。两版协议兼容，旧采集器可继续工作；需要更新采集程序时可在对应机器上执行新的一键命令。

### 手动安装（保留兼容方式）

先在面板“服务器管理”创建服务器，取得它的**机器 ID 和机器 Token**。使用原生服务器管理已有的查看 Token 功能；不要使用 Admin 登录 Token，也不要把任何 Token 发给别人。

将 `dist/flowscope-collector-2.3.0.tar.gz` 上传到对应 Linux 服务器，在本地解压；以下命令不下载或执行远程脚本：

```sh
tar -xzf flowscope-collector-2.3.0.tar.gz
cd flowscope-collector-2.3.0
cp config.example.json flowscope-collector.json
chmod 600 flowscope-collector.json
```

编辑 `flowscope-collector.json`，至少设置：

```json
{
  "panel_url": "https://panel.example.com",
  "machine_id": 41,
  "token": "替换为该服务器的机器Token",
  "interfaces": ["eth0"],
  "state_file": "/var/lib/flowscope-collector/state.sqlite3",
  "sample_interval": 10,
  "report_interval": 30,
  "request_timeout": 10
}
```

`interfaces` 必须按真实网卡修改，例如 `ens3`；可用 `ip -br link` 查看。不要同时选择 bond/bridge 与其下层接口，避免重复计数；不要盲目选择所有虚拟网卡。在容器内看到的网卡不一定是宿主机网卡。

检查并安装：

```sh
python3 flowscope_collector.py --config ./flowscope-collector.json --check
sudo bash install.sh --config "$(pwd)/flowscope-collector.json"
sudo systemctl enable --now flowscope-collector
sudo systemctl status flowscope-collector --no-pager
```

安装脚本部署独立服务，不自动启动。程序在 `/opt/flowscope-collector`，配置在 `/etc/flowscope-collector.json`（0600），状态在 `/var/lib/flowscope-collector`；本包的安装器和 systemd 服务要求保留默认 `state_file`，自定义路径需手工调整权限和服务配置。服务以独立非 root 用户运行。安装过程中不修改原节点程序、不删除采集状态。更多说明见 [collector/README.md](collector/README.md)。

一台机器只运行一个采集器。重复运行同一状态目录会被进程锁拒绝；面板也只接受一个采集器身份。重启后保留原状态目录，不要删除 SQLite、WAL 或 SHM 文件。临时断网只需恢复连接，不能通过清空状态来“修复”。

## 3. 设置周期流量

打开 **服务器管理 → 编辑已有服务器 → FlowScope / Traffic Cycle**：

- 默认关闭；开启后显示在仪表盘。
- 自定义名称留空时使用服务器原名。
- 额度支持 GB/TB，按二进制单位换算：1 GB = 1024³ bytes，1 TB = 1024⁴ bytes。
- 入站 = 所选网卡 RX；出站 = TX；双向 = RX + TX，各计一次。
- 周期起始日支持 1–31，以 Xboard 应用时区的本地午夜为界；短月临时夹到月末，不修改原配置。

FlowScope 有自己的保存按钮，与原生服务器保存相互独立。新服务器必须先通过原生界面创建，拿到 ID 后再配置。采集器即使在该机器监控显示关闭时仍可收集，显示开关不等于停止数据采集。

仪表盘按真实用量占比降序显示。超过额度仍显示真实百分比，进度条最多填满。未采集/采样过期会显示状态；统计从首个基线开始，不意味着已有完整当月历史。

## 重启、断网与精度边界

- 采集进程重启：读取保存的网卡基线继续计算。
- 服务器重启：识别 boot ID 变化，保留历史累计并接续新启动的计数。
- 断网/面板停止：已采样数据留在持久队列，恢复后按顺序补报；重试不会重复累计。
- 面板重启：已提交的日桶和采集游标保存在数据库，不依赖 PHP 进程内存。

不能保证突然断电前**尚未采样**的尾段零损失，也不能恢复首次安装前的网卡历史。默认 10 秒采样；跨午夜的区间按经过秒数分摊且字节总量守恒，并非精确逐包时间归属。长时间停止采集会扩大时间归属的不确定性。网卡数据包含全部选定接口流量，不等同服务商账单。

应用时区更改会影响日桶边界；已有数据不要直接换时区，需先规划历史迁移。状态文件及面板数据库应纳入备份。

## 升级、禁用、卸载与更换采集器

面板使用原生插件生命周期。禁用保留配置/流量，恢复原 Dashboard 和 Sidebar 展示；已打开页面约 15 秒检查状态并清理自有 UI。重新启用后刷新。原业务路由、权限、Controller、数据从未删除。

插件卸载使用 Xboard 原生迁移回滚，**可能删除 FlowScope 网卡历史**，不会删除 Xboard 核心数据。原生仅回滚最新批次：初装 v2.3.0 的四次迁移同批次会一起回滚；从 v2.0.0 直接升级后，最新批次含安装凭证表和地址表；从 v2.1.x 升级后，最新批次只含地址表，旧机器设置和流量表会留存；从 1.x 升级也可能留存旧节点设置表。需要保留历史时请禁用而非依赖卸载的批次细节，并先备份。远程采集器不会被面板卸载操作删除，应自行停止其服务。

采集器升级：停止服务，解压新包，在新目录执行 `sudo bash install.sh` 保留已安装配置及状态，再启动服务。不要用升级覆盖或删除状态文件。机器 Token 轮换后，更新安装后的配置再重启服务；待补报数据继续使用新 Token 发送。

仅在采集器状态确实丢失或迁移到另一台机器时，先停止旧采集器，再在服务器编辑中明确“重置采集器绑定”；历史累计保留，但新采集器从新基线开始。普通断网、服务重启不需要重置。

## 排障

- UI 不出现：检查 `/api/v2/flowscope/status` 是否 enabled/supported，版本是否 `2.3.0`；升级后重载常驻 PHP 工作进程并强制刷新。
- 保留 v1.0.1 的资源修复：loader 使用 `/assets/script`、`/assets/style`，避开部分代理对 `.js/.css` 路径的静态拦截，不要求改代理规则。
- 等待采集：确认服务正在运行、网卡名正确、宿主机可读网卡计数、机器处于启用状态，且机器 ID/Token 对应同一条服务器记录。
- 403：机器 Token 错误/已轮换，或原生机器被停用。不要改用 Admin Token。
- 409：采集器身份/序号/采样区间冲突。先排查重复进程或错误复用状态；不要直接删队列。
- 插件禁用时采集接口返回 404，采集器保留队列；若长期不使用，请停止采集服务，避免队列持续占磁盘。
- 不要分享配置全文、机器 Token、Admin Token 或包含它们的命令输出。正常日志只记录脱敏错误和 HTTP 状态。

## 开发、测试与打包

依赖 checkout 只读使用；测试 SQLite 与浏览器 fixture 只写本工作区。

```powershell
./scripts/test.ps1 -XboardPath 'D:/Xboard-Independent/source/Xboard'
node tests/ui-browser.mjs
```

浏览器测试需设置 `FLOWSCOPE_ADMIN_DIR` 指向受支持 Admin 目录，`FLOWSCOPE_BROWSER` 指向 Chrome/Chromium；PHP 可通过 `FLOWSCOPE_PHP` 指定。Linux 可用 `XBOARD_PATH=/path/to/Xboard sh scripts/test.sh`（实际执行情况见测试报告）。

构建：`php scripts/package.php`（zip 扩展）、`python scripts/package-collector.py`。验证实际 ZIP：`php tests/package.php`（PDO SQLite/mbstring/zip）；验证两个发布包：`python tests/release.py`。

完整架构见 [DEVELOPMENT.md](DEVELOPMENT.md)，审计与实施依据见 [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)，实际验证及未执行项目见 [TEST_REPORT.md](TEST_REPORT.md)。

v2.0.0 的 Ubuntu 虚拟机历史验证见 [虚拟机验证报告](docs/VM_TEST_REPORT.md)；不能当作 v2.3.0 新安装链路已通过的证据。当前版本实际执行范围以 [TEST_REPORT.md](TEST_REPORT.md) 为准。不保证关机前未采样尾段零丢失。无告警、自动封禁、计费变更或核心补丁。
