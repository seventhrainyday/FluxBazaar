<?php
declare(strict_types=1);
// 节点端：Xray 服务端配置生成 + 一键部署脚本生成
// 单端口多用户：一个 VLESS+Reality inbound 承载全部买家，
// 靠 Xray StatsService 按用户（UUID）统计流量，守护进程每 30 秒拉取增量上报。
require_once __DIR__ . '/common.php';

class Agent {
    /**
     * 生成 Xray 服务端配置
     * @param array $node  节点行（含 port / sni / private_key / public_key / short_id）
     * @param array $users [['uuid'=>...], ...] 仅 active 用户
     */
    public static function xray_config(array $node, array $users): array {
        $clients = [];
        foreach ($users as $u) {
            $clients[] = ['id' => $u['uuid'], 'email' => $u['uuid'], 'flow' => 'xtls-rprx-vision'];
        }
        return [
            'log' => ['loglevel' => 'warning'],
            'stats' => new stdClass(),
            'api' => ['tag' => 'api', 'services' => ['StatsService']],
            'inbounds' => [
                [
                    'tag' => 'api',
                    'listen' => '127.0.0.1',
                    'port' => 10085,
                    'protocol' => 'dokodemo-door',
                    'settings' => ['address' => '127.0.0.1'],
                ],
                [
                    'tag' => 'vless-reality',
                    'listen' => '0.0.0.0',
                    'port' => (int)$node['port'],
                    'protocol' => 'vless',
                    'settings' => ['clients' => $clients, 'decryption' => 'none'],
                    'streamSettings' => [
                        'network' => 'tcp',
                        'security' => 'reality',
                        'realitySettings' => [
                            'show' => false,
                            'dest' => $node['sni'] . ':443',
                            'xver' => 0,
                            'serverNames' => [$node['sni']],
                            'privateKey' => $node['private_key'],
                            'shortIds' => [$node['short_id']],
                        ],
                    ],
                    'sniffing' => ['enabled' => true, 'destOverride' => ['http', 'tls']],
                ],
            ],
            'outbounds' => [['protocol' => 'freedom', 'tag' => 'direct']],
            'routing' => [
                'rules' => [
                    ['type' => 'field', 'inboundTag' => ['api'], 'outboundTag' => 'api'],
                ],
            ],
        ];
    }

    /**
     * 生成 sing-box 服务端配置（单端口多用户）
     * 关键：每个 VLESS 用户必须填 name（源码里 name 为空则 metadata.User 不写，统计失效），
     * 且 name 必须列在 experimental.v2ray_api.stats.users 里，否则不计数。
     * @param array $node  节点行
     * @param array $users [['uuid'=>...], ...] 仅 active 用户（uuid 同时用作 name）
     */
    public static function singbox_config(array $node, array $users): array {
        $names = [];
        $vusers = [];
        foreach ($users as $u) {
            $names[] = $u['uuid'];
            $vusers[] = ['name' => $u['uuid'], 'uuid' => $u['uuid'], 'flow' => 'xtls-rprx-vision'];
        }
        return [
            'log' => ['level' => 'warning'],
            'experimental' => [
                'v2ray_api' => [
                    'listen' => '127.0.0.1:10085',
                    'stats' => ['enabled' => true, 'users' => $names],
                ],
            ],
            'inbounds' => [
                [
                    'type' => 'vless',
                    'tag' => 'vless-reality',
                    'listen' => '0.0.0.0',
                    'listen_port' => (int)$node['port'],
                    'users' => $vusers,
                    'tls' => [
                        'enabled' => true,
                        'server_name' => $node['sni'],
                        'reality' => [
                            'enabled' => true,
                            'handshake' => ['server' => $node['sni'], 'server_port' => 443],
                            'private_key' => $node['private_key'],
                            'short_id' => [$node['short_id']],
                        ],
                    ],
                ],
            ],
            'outbounds' => [['type' => 'direct', 'tag' => 'direct']],
            'route' => ['final' => 'direct'],
        ];
    }

    /** 生成节点一键部署脚本（bash，按节点 core 选择 Xray / sing-box 模板） */
    public static function install_script(array $node, string $platform): string {
        $tpl = ($node['core'] ?? 'xray') === 'singbox'
            ? self::singbox_install_tpl()
            : self::xray_install_tpl();
        $replace = [
            '{PLATFORM}' => $platform,
            '{NODE_ID}' => (string)$node['id'],
            '{API_KEY}' => $node['api_key'],
            '{NODE_NAME}' => $node['name'],
        ];
        if (($node['core'] ?? 'xray') === 'singbox') {
            $replace['{STATS_PB2}'] = file_get_contents(__DIR__ . '/../agent/grpc/stats_pb2.py');
            $replace['{STATS_PB2_GRPC}'] = file_get_contents(__DIR__ . '/../agent/grpc/stats_pb2_grpc.py');
        }
        return str_replace(array_keys($replace), array_values($replace), $tpl);
    }

    private static function xray_install_tpl(): string {
        $tpl = <<<'EOS'
#!/usr/bin/env bash
# ============================================================
# FluxBazaar 流量节点一键部署脚本（平台自动生成，请勿手工改动）
# 节点：{NODE_NAME}（ID {NODE_ID}）
# 平台：{PLATFORM}
#
# 脚本会：安装 Xray-core → 写入节点守护进程 →
#         注册 systemd 服务 → 守护进程自动完成：
#         同步单端口多用户 VLESS+Reality 配置 /
#         Xray StatsService 按用户记账 / 每 30 秒上报流量
# ============================================================
set -euo pipefail
[ "$(id -u)" = "0" ] || { echo "请使用 root 运行此脚本"; exit 1; }

PLATFORM="{PLATFORM}"
NODE_ID="{NODE_ID}"
API_KEY="{API_KEY}"

echo "== [1/4] 安装 Xray-core =="
if ! command -v xray >/dev/null 2>&1; then
  # GitHub 访问困难可换代理：https://gh-proxy.com/https://github.com/...
  bash <(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)
fi
command -v python3 >/dev/null 2>&1 || { echo "需要 python3，请先安装"; exit 1; }
mkdir -p /usr/local/etc/xray /usr/local/bin /var/lib/fluxbazaar

echo "== [2/4] 写入节点守护进程 =="
cat > /usr/local/bin/flux-agent.py <<'PYEOF'
#!/usr/bin/env python3
"""FluxBazaar 节点守护进程（Xray 单端口版）：同步配置 + StatsService 按用户记账 + 每 30 秒上报平台"""
import hashlib, json, os, re, subprocess, time, urllib.request

PLATFORM = os.environ["FLUX_PLATFORM"].rstrip("/")
NODE_ID = os.environ["FLUX_NODE_ID"]
API_KEY = os.environ["FLUX_API_KEY"]
CONF = "/usr/local/etc/xray/config.json"
STATE = "/var/lib/fluxbazaar/counters.json"
XRAY_API = "127.0.0.1:10085"

def api(path, data=None):
    url = PLATFORM + "/api/" + path
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(req, timeout=20))

def sh(cmd):
    subprocess.run(cmd, shell=True)

def sync_users():
    d = api("sync.php?node_id=" + NODE_ID + "&api_key=" + API_KEY + "&format=json")
    return d.get("users", [])

def sync_config():
    cfg = api("agent_config.php?node_id=" + NODE_ID + "&api_key=" + API_KEY)
    blob = json.dumps(cfg, indent=2).encode()
    old = open(CONF, "rb").read() if os.path.exists(CONF) else b""
    if hashlib.sha256(blob).hexdigest() != hashlib.sha256(old).hexdigest():
        with open(CONF, "wb") as f:
            f.write(blob)
        r = subprocess.run(["xray", "-test", "-config", CONF], capture_output=True)
        if r.returncode == 0:
            sh("systemctl restart xray")
            print("xray 配置已更新", flush=True)
        else:
            print("xray 配置校验失败: " + r.stderr.decode()[:300], flush=True)

def query_stats():
    """拉取 Xray StatsService，返回 {uuid: {'up': bytes, 'down': bytes}}（累计值）"""
    out = subprocess.run(["xray", "api", "statsquery", "--server=" + XRAY_API],
                         capture_output=True, text=True).stdout
    cur = {}
    for m in re.finditer(r'name:\s*"user>>>([^">]+)>>>traffic>>>(uplink|downlink)"\s*\nvalue:\s*(\d+)', out):
        uuid, direction, val = m.group(1), m.group(2), int(m.group(3))
        cur.setdefault(uuid, {"up": 0, "down": 0})
        cur[uuid]["up" if direction == "uplink" else "down"] = val
    return cur

def load_state():
    try:
        return json.load(open(STATE))
    except Exception:
        return {}

def save_state(cur):
    with open(STATE, "w") as f:
        json.dump(cur, f)

def main():
    print("flux-agent 启动（Xray 单端口版）", flush=True)
    while True:
        try:
            users = sync_users()
            valid = {u["uuid"] for u in users}
            sync_config()
            cur = query_stats()
            last = load_state()
            usage = []
            for uuid, c in cur.items():
                if uuid not in valid:
                    continue  # 已熔断/删除用户不再上报
                l = last.get(uuid, {"up": 0, "down": 0})
                du = c["up"] - l["up"]
                dd = c["down"] - l["down"]
                if du < 0:
                    du = c["up"]    # xray 重启导致计数器归零
                if dd < 0:
                    dd = c["down"]
                if du + dd > 0:
                    usage.append({"uuid": uuid, "bytes": du + dd})
            if usage:
                api("report.php", {"node_id": NODE_ID, "api_key": API_KEY, "usage": usage})
                print("已上报 %d 个用户" % len(usage), flush=True)
            save_state(cur)
        except Exception as e:
            print("本轮异常: " + repr(e), flush=True)
        time.sleep(30)

main()
PYEOF
chmod +x /usr/local/bin/flux-agent.py

echo "== [3/4] 注册 systemd 服务 =="
cat > /etc/systemd/system/flux-agent.service <<'SVCEOF'
[Unit]
Description=FluxBazaar 流量节点守护进程
After=network-online.target xray.service
Wants=network-online.target

[Service]
Type=simple
Environment=FLUX_PLATFORM={PLATFORM}
Environment=FLUX_NODE_ID={NODE_ID}
Environment=FLUX_API_KEY={API_KEY}
ExecStart=/usr/bin/python3 /usr/local/bin/flux-agent.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SVCEOF

systemctl daemon-reload
systemctl enable --now xray
systemctl enable --now flux-agent.service

echo "== [4/4] 完成 =="
echo "查看状态：systemctl status flux-agent"
echo "查看日志：journalctl -u flux-agent -f"
EOS;
        return $tpl;
    }

    private static function singbox_install_tpl(): string {
        $tpl = <<<'EOS'
#!/usr/bin/env bash
# ============================================================
# FluxBazaar 流量节点一键部署脚本（sing-box 内核，平台自动生成）
# 节点：{NODE_NAME}（ID {NODE_ID}）
# 平台：{PLATFORM}
#
# 脚本会：安装 sing-box → 安装 gRPC 依赖 → 写入节点守护进程 →
#         注册 systemd 服务 → 守护进程自动完成：
#         同步单端口多用户 VLESS+Reality 配置 /
#         V2Ray API 按用户记账 / 每 30 秒上报流量
# 注意：sing-box 的 VLESS 用户必须带 name 字段（平台用买家 UUID 填充），
#       否则按用户统计不生效。
# ============================================================
set -euo pipefail
[ "$(id -u)" = "0" ] || { echo "请使用 root 运行此脚本"; exit 1; }

PLATFORM="{PLATFORM}"
NODE_ID="{NODE_ID}"
API_KEY="{API_KEY}"

echo "== [1/5] 安装 sing-box（含 V2Ray API 编译版） =="
# 官方二进制默认不带 V2Ray API（单端口按用户计费必需），探测缺失则从源码编译
need_build=0
if ! command -v sing-box >/dev/null 2>&1; then
  need_build=1
else
  echo '{"experimental":{"v2ray_api":{"listen":"127.0.0.1:19999"}}}' > /tmp/sb_probe.json
  if ! sing-box check -c /tmp/sb_probe.json >/dev/null 2>&1; then need_build=1; fi
  rm -f /tmp/sb_probe.json
fi
if [ "$need_build" = "1" ]; then
  echo "未检测到带 V2Ray API 的 sing-box，从源码编译（一次性，约几分钟）..."
  if ! command -v go >/dev/null 2>&1; then
    echo "安装 Go 工具链..."
    arch=amd64; [ "$(uname -m)" = "aarch64" ] && arch=arm64
    # sing-box 1.14.x 需要 Go >= 1.25.5
    curl -fsSL "https://go.dev/dl/go1.27.2.linux-${arch}.tar.gz" -o /tmp/go.tgz
    rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tgz
    rm -f /tmp/go.tgz
    export PATH="$PATH:/usr/local/go/bin"
  fi
  go install -tags "with_v2ray_api with_utls" github.com/sagernet/sing-box/cmd/sing-box@v1.14.3
  install -m 755 "$(go env GOPATH)/bin/sing-box" /usr/local/bin/sing-box
  hash -r
fi
sing-box version | head -1
command -v python3 >/dev/null 2>&1 || { echo "需要 python3，请先安装"; exit 1; }
command -v pip3 >/dev/null 2>&1 || { echo "需要 pip3，请先安装 python3-pip"; exit 1; }
mkdir -p /etc/sing-box /usr/local/bin /var/lib/fluxbazaar /usr/local/bin/flux_grpc

echo "== [2/5] 安装 gRPC 依赖 =="
if ! python3 -c "import grpc" 2>/dev/null; then
  pip3 install -q grpcio 2>/dev/null || pip3 install -q --break-system-packages grpcio
fi

echo "== [3/5] 写入 gRPC 桩文件 =="
cat > /usr/local/bin/flux_grpc/stats_pb2.py <<'PBEOF'
{STATS_PB2}
PBEOF
cat > /usr/local/bin/flux_grpc/stats_pb2_grpc.py <<'PBEOF'
{STATS_PB2_GRPC}
PBEOF

echo "== [4/5] 写入节点守护进程 =="
cat > /usr/local/bin/flux-agent.py <<'PYEOF'
#!/usr/bin/env python3
"""FluxBazaar 节点守护进程（sing-box 单端口版）：同步配置 + V2Ray API 按用户记账 + 每 30 秒上报平台"""
import hashlib, json, os, re, subprocess, sys, time, urllib.request

sys.path.insert(0, "/usr/local/bin/flux_grpc")
import grpc
import stats_pb2, stats_pb2_grpc

PLATFORM = os.environ["FLUX_PLATFORM"].rstrip("/")
NODE_ID = os.environ["FLUX_NODE_ID"]
API_KEY = os.environ["FLUX_API_KEY"]
CONF = "/etc/sing-box/config.json"
STATE = "/var/lib/fluxbazaar/counters.json"
V2RAY_API = "127.0.0.1:10085"

def api(path, data=None):
    url = PLATFORM + "/api/" + path
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(req, timeout=20))

def sh(cmd):
    subprocess.run(cmd, shell=True)

def sync_users():
    d = api("sync.php?node_id=" + NODE_ID + "&api_key=" + API_KEY + "&format=json")
    return d.get("users", [])

def sync_config():
    cfg = api("agent_config.php?node_id=" + NODE_ID + "&api_key=" + API_KEY)
    blob = json.dumps(cfg, indent=2).encode()
    old = open(CONF, "rb").read() if os.path.exists(CONF) else b""
    if hashlib.sha256(blob).hexdigest() != hashlib.sha256(old).hexdigest():
        with open(CONF, "wb") as f:
            f.write(blob)
        r = subprocess.run(["sing-box", "check", "-c", CONF], capture_output=True)
        if r.returncode == 0:
            sh("systemctl restart sing-box")
            print("sing-box 配置已更新", flush=True)
        else:
            print("sing-box 配置校验失败: " + r.stderr.decode()[:300], flush=True)

def query_stats():
    """经 V2Ray API 拉取按用户流量（累计值），返回 {uuid: {'up': bytes, 'down': bytes}}"""
    ch = grpc.insecure_channel(V2RAY_API)
    try:
        stub = stats_pb2_grpc.StatsServiceStub(ch)
        resp = stub.QueryStats(stats_pb2.QueryStatsRequest(pattern="user>>>", reset=False), timeout=10)
    finally:
        ch.close()
    cur = {}
    for s in resp.stat:
        m = re.match(r"user>>>([^>]+)>>>traffic>>>(uplink|downlink)$", s.name)
        if not m:
            continue
        uuid, direction = m.group(1), m.group(2)
        cur.setdefault(uuid, {"up": 0, "down": 0})
        cur[uuid]["up" if direction == "uplink" else "down"] = s.value
    return cur

def load_state():
    try:
        return json.load(open(STATE))
    except Exception:
        return {}

def save_state(cur):
    with open(STATE, "w") as f:
        json.dump(cur, f)

def main():
    print("flux-agent 启动（sing-box 单端口版）", flush=True)
    while True:
        try:
            users = sync_users()
            valid = {u["uuid"] for u in users}
            sync_config()
            cur = query_stats()
            last = load_state()
            usage = []
            for uuid, c in cur.items():
                if uuid not in valid:
                    continue  # 已熔断/删除用户不再上报
                l = last.get(uuid, {"up": 0, "down": 0})
                du = c["up"] - l["up"]
                dd = c["down"] - l["down"]
                if du < 0:
                    du = c["up"]    # sing-box 重启导致计数器归零
                if dd < 0:
                    dd = c["down"]
                if du + dd > 0:
                    usage.append({"uuid": uuid, "bytes": du + dd})
            if usage:
                api("report.php", {"node_id": NODE_ID, "api_key": API_KEY, "usage": usage})
                print("已上报 %d 个用户" % len(usage), flush=True)
            save_state(cur)
        except Exception as e:
            print("本轮异常: " + repr(e), flush=True)
        time.sleep(30)

main()
PYEOF
chmod +x /usr/local/bin/flux-agent.py

echo "== [5/5] 注册 systemd 服务 =="
cat > /etc/systemd/system/sing-box.service <<'SVCEOF'
[Unit]
Description=sing-box service
Documentation=https://sing-box.sagernet.org
After=network.target nss-lookup.target network-online.target

[Service]
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_BIND_SERVICE CAP_SYS_PTRACE CAP_DAC_READ_SEARCH
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE CAP_SYS_PTRACE CAP_DAC_READ_SEARCH
ExecStart=/usr/local/bin/sing-box run -c /etc/sing-box/config.json
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=10s
LimitNOFILE=infinity

[Install]
WantedBy=multi-user.target
SVCEOF
cat > /etc/systemd/system/flux-agent.service <<'SVCEOF'
[Unit]
Description=FluxBazaar 流量节点守护进程
After=network-online.target sing-box.service
Wants=network-online.target

[Service]
Type=simple
Environment=FLUX_PLATFORM={PLATFORM}
Environment=FLUX_NODE_ID={NODE_ID}
Environment=FLUX_API_KEY={API_KEY}
ExecStart=/usr/bin/python3 /usr/local/bin/flux-agent.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SVCEOF

systemctl daemon-reload
systemctl enable --now sing-box
systemctl enable --now flux-agent.service

echo "== 完成 =="
echo "查看状态：systemctl status flux-agent"
echo "查看日志：journalctl -u flux-agent -f"
EOS;
        return $tpl;
    }
}
