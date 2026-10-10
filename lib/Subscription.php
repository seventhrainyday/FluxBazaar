<?php
declare(strict_types=1);
// 订阅生成：一个订阅链接聚合全网在售节点
require_once __DIR__ . '/common.php';

class Subscription {
    /**
     * 买家可见节点：自动为买家在每个在售节点开通专属 UUID（懒创建）。
     * 单端口多用户：所有买家共用节点的同一个端口，Xray 按 UUID 分用户记账。
     * @return array 节点行（附加 _uuid / _port）
     */
    public static function buyer_nodes(array $user): array {
        $db = db();
        $nodes = $db->query(
            "SELECT n.* FROM nodes n WHERE n.status = 'active'
             AND (n.quota_gb = 0 OR n.sold_bytes < n.quota_gb * 1073741824)
             ORDER BY n.is_free DESC, n.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($nodes as $n) {
            $st = $db->prepare('SELECT * FROM node_users WHERE node_id = ? AND user_id = ?');
            $st->execute([$n['id'], $user['id']]);
            $nu = $st->fetch(PDO::FETCH_ASSOC);
            if (!$nu) {
                $uuid = uuid4();
                $db->prepare('INSERT INTO node_users(node_id,user_id,uuid,port) VALUES(?,?,?,?)')
                   ->execute([$n['id'], $user['id'], $uuid, $n['port']]);
                $nu = ['uuid' => $uuid, 'port' => $n['port'], 'status' => 'active'];
            }
            if ($nu['status'] !== 'active') continue; // 熔断用户不下发
            $n['_uuid'] = $nu['uuid'];
            $n['_port'] = (int)$nu['port'];
            $list[] = $n;
        }
        return $list;
    }

    public static function vless_uri(array $n): string {
        $q = http_build_query([
            'encryption' => 'none', 'flow' => 'xtls-rprx-vision', 'security' => 'reality',
            'sni' => $n['sni'], 'fp' => 'chrome', 'pbk' => $n['public_key'],
            'sid' => $n['short_id'], 'type' => 'tcp',
        ]);
        return "vless://{$n['_uuid']}@{$n['ip']}:{$n['_port']}?{$q}#" . rawurlencode($n['name']);
    }

    private static function yq(string $s): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** Clash / Mihomo 订阅 */
    public static function clash_yaml(array $nodes): string {
        $y = "# FluxBazaar 订阅 · 更新时间 " . date('Y-m-d H:i:s') . "\nproxies:\n";
        foreach ($nodes as $n) {
            $y .= "  - name: " . self::yq($n['name']) . "\n";
            $y .= "    type: vless\n";
            $y .= "    server: {$n['ip']}\n";
            $y .= "    port: {$n['_port']}\n";
            $y .= "    uuid: {$n['_uuid']}\n";
            $y .= "    network: tcp\n";
            $y .= "    tls: true\n";
            $y .= "    udp: true\n";
            $y .= "    flow: xtls-rprx-vision\n";
            $y .= "    servername: " . self::yq($n['sni']) . "\n";
            $y .= "    client-fingerprint: chrome\n";
            $y .= "    reality-opts:\n";
            $y .= "      public-key: {$n['public_key']}\n";
            $y .= "      short-id: {$n['short_id']}\n";
        }
        return $y;
    }

    /** Sing-box 客户端订阅 */
    public static function singbox_json(array $nodes): string {
        $outbounds = [];
        foreach ($nodes as $n) {
            $outbounds[] = [
                'type' => 'vless', 'tag' => $n['name'],
                'server' => $n['ip'], 'server_port' => $n['_port'],
                'uuid' => $n['_uuid'], 'flow' => 'xtls-rprx-vision',
                'tls' => [
                    'enabled' => true, 'server_name' => $n['sni'],
                    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                    'reality' => ['enabled' => true, 'public_key' => $n['public_key'], 'short_id' => $n['short_id']],
                ],
            ];
        }
        return json_encode(['outbounds' => $outbounds], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** 纯 URI 列表 */
    public static function uri_list(array $nodes): string {
        return implode("\n", array_map([self::class, 'vless_uri'], $nodes)) . "\n";
    }
}
