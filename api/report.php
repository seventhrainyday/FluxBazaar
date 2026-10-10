<?php
declare(strict_types=1);
// 流量上报接口（守护进程每 30 秒调用）：POST JSON {node_id, api_key, usage:[{uuid, bytes}]}
require __DIR__ . '/../lib/common.php';
require __DIR__ . '/../lib/Billing.php';

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['error' => 'bad json'], 400);

$node = get_node((int)($in['node_id'] ?? 0));
if (!$node || !hash_equals((string)$node['api_key'], (string)($in['api_key'] ?? ''))) {
    json_out(['error' => 'forbidden'], 403);
}
$usages = $in['usage'] ?? [];
if (!is_array($usages)) json_out(['error' => 'bad usage'], 400);

$results = Billing::settle($node, $usages);
json_out(['ok' => true, 'results' => $results]);
