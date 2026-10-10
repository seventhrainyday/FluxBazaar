# FluxBazaar · 流量集市

按量付费的去中心化流量集市（对标 vpsnat「泰聚」功能实现的 PHP 演示程序）。
买家一个订阅链接聚合全网节点、按 MB 计费；卖家上架节点、一键脚本部署、拿 90% 分成。

节点端双内核可选（上架时二选一）：
- **Xray-core**（默认，推荐）：官方二进制自带 StatsService，一键脚本开箱即用；
- **sing-box**：内存占用更小，但官方二进制默认不含 V2Ray API，
  一键脚本会自动用 `-tags with_v2ray_api` 从源码编译（一次性，约几分钟）。

## 功能对照

| 泰聚功能 | 本实现 |
|---|---|
| 专属订阅链接，全协议聚合 | ✅ `/api/sub.php?token=xxx`，支持 Clash/Mihomo YAML、Sing-box JSON、纯 URI 三种格式 |
| 按 MB 计费，30 秒采集 | ✅ 节点守护进程每 30 秒上报，平台按 `(上行+下行)/MB × 单价` 结算，精度 ¥0.0000 |
| 余额不足自动熔断 | ✅ 上报时余额不够 → 该用户在该节点立即停用，守护进程下次同步剔除；订阅侧余额为 0 时只下发免费节点 |
| 卖家一键部署 | ✅ 上架后自动生成 `curl \| bash` 一键脚本：装 Xray-core → 同步单端口多用户 VLESS+Reality 配置 → StatsService 记账 → systemd 守护上报 |
| 90% 分成 | ✅ 买家扣费即按 90% 计入卖家收益 |
| 公益免费节点 | ✅ 上架时勾选，全平台 0 元使用 |
| 节点限额 / 已售公示 | ✅ 大厅显示已售 / 剩余 GB，超限额自动下架 |
| NQ 跑分展示 | ✅ 上架表单可填 NodeQuality 报告链接，大厅展示 |
| 收益冻结 / 提现 | ✅ 收益冻结 30 天，满 ¥10 可申请提现，管理员审核 |
| 真实支付 / 退款 | ❌ 演示用「模拟充值」按钮，生产需对接支付网关 |
| 防滥用（RFW 那种） | ❌ 未实现，生产需另做行为风控 |
| NAT 穿透 | ❌ 上架要求填公网 IP:端口，NAT 机需自行做端口映射（和泰聚页面现状一致，也没写） |

## 快速开始

```bash
cd fluxbazaar
php -S 127.0.0.1:8080
# 浏览器打开 http://127.0.0.1:8080
```

1. 注册第一个账号 → 自动成为管理员；再注册一个当买家。
2. 卖家：「卖家中心 → 上架新节点」，填 IP、起始端口、单价（默认 ¥0.05/GB）。
3. 卖家：「部署脚本」页复制一键命令，到节点服务器 root 执行。
4. 买家：「买家中心」模拟充值 → 复制订阅链接导入客户端。
5. 守护进程每 30 秒上报，买家中心「账单明细」可看到扣费，卖家「收益与提现」可看到 90% 分成（冻结 30 天）。

## 架构

```
浏览器/客户端
  ├─ index.php + views/        Web：大厅 / 买家 / 卖家 / 部署 / 提现 / 审核
  ├─ api/sub.php               买家订阅（token 鉴权）
  └─ api/sync.php              节点拉取用户列表（api_key 鉴权）
     api/report.php            节点上报流量 → 计费引擎结算
     api/agent_config.php      节点拉取服务端配置（按内核返回 Xray / sing-box JSON）
lib/
  ├─ common.php                PDO(SQLite)/会话/金额(微元)/Reality密钥
  ├─ Billing.php               计费引擎：扣费→分成→熔断
  ├─ Subscription.php          订阅生成：Clash YAML / Sing-box JSON / URI
  ├─ Agent.php                 双内核配置生成 + 一键部署脚本模板（Xray / sing-box 分支）
  └─ ../agent/grpc/            sing-box 分支用的 gRPC 桩（stats_pb2*，部署时下发到节点）
data/fluxbazaar.sqlite         SQLite 数据库（自动创建）
```

### 计费原理（关键设计）

**单端口多用户**，两种内核都是"一个 VLESS+Reality inbound 承载全部买家"，
区别只在按用户记账的实现：

**Xray-core（默认）**
- 原生 `StatsService` 按用户（`user>>><uuid>>>traffic>>>(uplink|downlink)`）累计流量；
- 守护进程用 `xray api statsquery` 拉取、算增量（Xray 重启导致计数器归零时自动重建基线）。

**sing-box**
- 走 `experimental.v2ray_api`，计数器格式与 Xray 一致（`user>>><name>>>traffic>>>`）；
- 两个硬性要求（读过 sing-box 源码确认）：VLESS 用户必须填 `name` 字段
  （name 为空时 `metadata.User` 不写，统计直接失效，平台用买家 UUID 填充），
  且 name 必须列在 `v2ray_api.stats.users` 里；
- 官方二进制默认**不含** V2Ray API（一键脚本会自动 `-tags with_v2ray_api` 源码编译）；
- 查询没有 `xray api` 这种现成 CLI，守护进程自带 gRPC 客户端
  （`agent/grpc/stats_pb2*.py`，部署时下发到节点，`pip install grpcio`）。

公共部分：守护进程每 30 秒上报增量 → 平台按上报字节结算：
买家扣费 → 卖家 +90%（冻结 30 天）→ 余额不足则该用户 `suspended`，
下次同步配置时直接从服务端客户端列表剔除（熔断更彻底）。
买家订阅某节点时，平台为其分配专属 UUID（`node_users` 表），
订阅链接里每个节点只有 UUID 不同、端口相同。

### Reality 密钥

平台用 libsodium X25519（`sodium_crypto_box_keypair`）生成密钥对，base64url 编码，
格式与 `xray x25519` 兼容；short-id 为 16 位 hex。

### 从 v1（多端口版）升级

v1 用的是"每用户一端口 + iptables 记账"，v2 起改为 Xray 单端口。
`nodes` 表的 `base_port` 改为 `port`，直接删除 `data/taiju.sqlite` 重建即可
（演示库无保留价值；`node_users` 的端口唯一约束已取消）。

## 生产化 TODO

- 对接真实支付（充值）与打款（提现），钱包余额退款政策落文字；
- 防滥用：挖矿/BT/发包等行为识别与自动拦截（对标泰聚 RFW）；
- 管理员节点质检、IP 被封责任条款；
- CSRF token、API 限流、HTTPS；

## 测试

```bash
./test.sh   # 需先 php -S 127.0.0.1:18090
```

覆盖：注册/登录 → 上架节点 → 订阅三种格式 → 用户同步 → 配置下发(JSON合法性)
→ 上报 100MB 计费（买家扣费/卖家 90%）→ 余额不足熔断 → 提现申请与审核。
