<?php
declare(strict_types=1);
// 节点用户同步接口（守护进程调用）：/api/sync.php?node_id=1&api_key=xxx&format=json|tsv
require __DIR__ . '/../lib/common.php';

$node = get_node((int)($_GET['node_id'] ?? 0));
if (!$node || !hash_equals((string)$node['api_key'], (string)($_GET['api_key'] ?? ''))) {
    json_out(['error' => 'forbidden'], 403);
}
$st = db()->prepare("SELECT uuid, port FROM node_users WHERE node_id = ? AND status = 'active' ORDER BY port");
$st->execute([$node['id']]);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['format'] ?? 'json') === 'tsv') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($users as $u) echo $u['uuid'] . "\t" . $u['port'] . "\n";
    exit;
}
json_out(['users' => $users]);
