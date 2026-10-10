<?php
declare(strict_types=1);
// 节点服务端配置下发（守护进程调用）：/api/agent_config.php?node_id=1&api_key=xxx
// 按节点 core 返回 Xray 或 sing-box 配置
require __DIR__ . '/../lib/common.php';
require __DIR__ . '/../lib/Agent.php';

$node = get_node((int)($_GET['node_id'] ?? 0));
if (!$node || !hash_equals((string)$node['api_key'], (string)($_GET['key'] ?? $_GET['api_key'] ?? ''))) {
    json_out(['error' => 'forbidden'], 403);
}
$st = db()->prepare("SELECT uuid FROM node_users WHERE node_id = ? AND status = 'active' ORDER BY id");
$st->execute([$node['id']]);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

if (($node['core'] ?? 'xray') === 'singbox') {
    json_out(Agent::singbox_config($node, $users));
}
json_out(Agent::xray_config($node, $users));
