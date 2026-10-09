# FlowScope 2.4.0 交付及升级

日期：2026-10-09（Asia/Shanghai）。已完成本地实现、构建和测试；未发布、推送或部署生产。

## 实现及文件

Node 正常对接后自动查询 IP.SB 并报告出口 IPv4。复用机器地址表、机器身份、原生 Client、插件生命周期、Admin 地址组件和采集器请求头。IP 独立于周期流量、额度、RX/TX、Python 安装与在线状态。

FlowScope 新增 Services/AddressService.php、Controllers/AddressController.php 和非破坏性迁移 2026_10_09_000005_add_address_sources.php；修改 CollectorService、TrafficController、routes/api.php、Plugin.php/config.json、flowscope.js/.css。扩展既有测试/脚本并新增 tests/node-receiver.php 作为 Go/PHP 真实路由适配；更新 README、DEVELOPMENT、IMPLEMENTATION_PLAN、TEST_REPORT 和 collector/README。

Node 仅修改 internal/panel/client.go，新增 address.go、address_test.go，更新 README 并新增 FLOWSCOPE-IPV4.md。未修改 Xboard 核心、SmartExpiry、qrcodeextend、代理内核或依赖。

FlowScope 完整文件清单（均相对于仓库根目录）：

- 业务：FlowScope/Controllers/AddressController.php、FlowScope/Controllers/TrafficController.php、FlowScope/Services/AddressService.php、FlowScope/Services/CollectorService.php、FlowScope/database/migrations/2026_10_09_000005_add_address_sources.php、FlowScope/routes/api.php、FlowScope/Plugin.php、FlowScope/config.json、FlowScope/resources/assets/flowscope.js、FlowScope/resources/assets/flowscope.css。
- 测试/打包：scripts/package.php、scripts/test.ps1、scripts/test.sh、tests/backend.php、tests/bootstrap.php、tests/integration.php、tests/ipv4.php、tests/package.php、tests/release.py、tests/ui-browser.mjs、tests/upgrade-install.php、tests/node-receiver.php。
- 文档：README.md、DEVELOPMENT.md、IMPLEMENTATION_PLAN.md、TEST_REPORT.md、collector/README.md、docs/UPGRADE_v2.4.0.md。

新增一个 POST API /api/v2/flowscope/node-address。既有机器地址表增加四个 nullable 字段：node_ipv4/node_updated_at/collector_ipv4/collector_updated_at。没有新表、运行依赖、Token、配置页、常驻进程或端口。

## 身份及来源规则

machine mode 验证机器专属 Token，伪造机器或子节点不能跨机器更新。node mode 的原生 server token 是面板级共享凭据，无法识别哪个持有者发送某个 node_id；不新增凭据就不能承诺逐节点密码隔离。实现将其限定为节点级有期限缓存，禁止直接写机器地址表；服务端按 exact node ID 与当前数据库关系展示到机器，无可靠关系时在节点管理对应行显示。需要机器级隔离时使用现有 machine mode。

统一优先级：24 小时内 Node 优先；Node 过期用 2 小时内采集器；都过期时机器表保留最新有效结果并标“最后已知”。非法值不覆盖有效地址，旧 observation age 不覆盖新值；不使用客户端绝对时间。旧采集器无检测时间字段，保留有效请求的服务端接收时间语义。节点缓存到期或清空后显示待获取，正常 Node 自动补报。

Node 每 12 小时探测，失败 5 分钟后再试；每小时向各面板刷新。接口不存在/旧版/禁用负缓存 10 分钟，认证失败 15 分钟，429/5xx/超时有有限退避。恢复检查由下一次原生认证成功请求触发，另加页面约一分钟刷新。无需重绑。HTTPS IP.SB 请求强制 IPv4、验证证书、绕过环境代理；面板上报沿用配置协议，既有 HTTP 不被自动改成 HTTPS。IP 表示访问 IP.SB 的宿主机出口，未必是监听地址或代理用户出口。

| 组合 | 行为 |
| --- | --- |
| 新 Node + 新插件，无采集器 | 自动 IP，流量开关可关闭 |
| 新 Node + 新插件，有采集器 | 统一合并，机器地址不重复 |
| 旧 Node + 新插件，有旧采集器 | 原流量正常；支持 IP 的采集器继续提供 IP |
| 旧 Node + 新插件，无采集器 | 待获取，升级 Node 后自动生效 |
| 新 Node + 旧/无/禁用插件 | Node 原功能正常，有限重试 |
| 插件后安装/升级/重新启用 | 自动重新发现，无需重绑 |
| 停止或主动卸载采集器 | Node 继续更新 IP；周期流量无新采样 |

## 新安装及存量升级

全新安装：在后台上传 dist/flowscope-2.4.0.zip、安装并启用；用原方式对接新版 Node。无需 Python、额外 Token 或流量开关。按站点原方式重载常驻 PHP 并刷新 Admin。

有采集器：直接上传插件升级，勿卸载。保留原采集器，正常原位升级 Node；无需重装或升级采集器。已有 Token、身份、序号、SQLite outbox、历史流量、额度和周期不迁移或清空。

无采集器：上传插件并原位升级 Node 二进制，重启其已有服务。无需重装、重绑或安装采集器。现有 install.sh upgrade 保留配置，amd64 本地文件示例：

~~~sh
sudo bash install.sh upgrade --version v1.13-orphan.2 \
  --binary ./xboard-node-linux-amd64 --xbctl-binary ./xbctl-linux-amd64
~~~

arm64 替换文件后缀。已有私有分发源也可走原 xbctl upgrade 路线；本次没有创建远程 Release，不要假设远程已有该版本。全新 Node 安装沿用原 --mode node 或 --mode machine 参数。

升级顺序任意：先 Node 则收到 404 后自动重试；先插件则原采集器继续工作；仅升级插件仍兼容旧采集器。双方支持后自动开启。插件暂不启用、Node 暂离线也等待后续自动恢复。

验证流量解耦：服务器编辑中关闭周期流量并保存，返回列表确认 IPv4 继续显示，地址提示可查看来源和最近检测时间。没有采集器、没有 RX/TX 也显示 Node 地址。后端与浏览器实测覆盖该路径。

## 回滚及服务影响

升级前保存插件、数据库和原 Node 二进制。原安装器健康检查失败会走既有回滚；也可用保存的旧二进制走相同 upgrade 路线回退，保留 config.yml。核对原安装器输出的备份路径和目标版本。

插件恢复升级前 2.3.0 文件，重载 PHP 并刷新即可；新增 nullable 字段可保留，旧代码仍使用原 ipv4/updated_at。如确需清理，仅回滚本次 000005 迁移，并先确认最新批次：删除来源元数据，保留原地址、流量和设置。不要以卸载插件替代常规回滚；原生卸载按最新迁移批次执行，随历史安装路径而不同。实测覆盖 2.0.0/2.1.1/2.3.0 升级和最新批次回滚。

Node 原位升级会重启已有服务，可能中断代理连接，需维护窗口。新增 IP 查询/上报异步执行，失败不返回原心跳、用户同步和流量报告错误；不承诺生产零中断。PHP 重载也遵循原部署维护方式。

## 实测与未验证范围

详见 TEST_REPORT.md。已执行原有 Node 套件、实际 Go/PHP 路由联调、工作站直连 IP.SB、FlowScope 原有回归/存量升级/双来源/鉴权、真实 Admin 浏览器及最终上传 ZIP；Linux amd64/arm64 用固定依赖构建。

未执行生产部署、生产 MySQL/MariaDB/Octane/Nginx 联调、新版 Linux/原生 ARM64 实机安装升级、真实代理连接连续性、裸机网络及硬断电。Windows 跳过采集器 3 项及 bootstrap 1 项 POSIX 检查；历史 VM 结果没有替代本次通过。
