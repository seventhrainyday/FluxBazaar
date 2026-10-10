<?php
declare(strict_types=1);
// 买家专属订阅接口：/api/sub.php?token=xxx&format=clash|singbox|uri[&node=ID]
require __DIR__ . '/../lib/common.php';
require __DIR__ . '/../lib/Subscription.php';

$token = (string)($_GET['token'] ?? '');
$st = db()->prepare('SELECT * FROM users WHERE sub_token = ?');
$st->execute([$token]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "订阅链接无效\n"; exit; }

$nodes = Subscription::buyer_nodes($user);
$nodeFilter = (int)($_GET['node'] ?? 0);
if ($nodeFilter > 0) $nodes = array_values(array_filter($nodes, fn($n) => (int)$n['id'] === $nodeFilter));

// 余额不足 → 付费节点熔断，仅保留公益免费节点
$lowBalance = balance_micro((int)$user['id']) <= 0;
if ($lowBalance) $nodes = array_values(array_filter($nodes, fn($n) => $n['is_free']));

$format = $_GET['format'] ?? 'clash';
switch ($format) {
    case 'singbox':
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="taiju.json"');
        echo Subscription::singbox_json($nodes);
        break;
    case 'uri':
        header('Content-Type: text/plain; charset=utf-8');
        if ($lowBalance) echo "# 余额不足，仅显示公益免费节点，充值后解锁全部节点\n";
        echo Subscription::uri_list($nodes);
        break;
    default:
        header('Content-Type: text/yaml; charset=utf-8');
        header('Content-Disposition: attachment; filename="taiju.yaml"');
        if ($lowBalance) echo "# 余额不足，仅显示公益免费节点，充值后解锁全部节点\n";
        echo Subscription::clash_yaml($nodes);
}
