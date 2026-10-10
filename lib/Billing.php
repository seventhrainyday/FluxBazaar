<?php
declare(strict_types=1);
// 计费引擎：节点上报流量 → 买家扣费 → 卖家分成 → 余额不足熔断
require_once __DIR__ . '/common.php';

class Billing {
    /**
     * @param array $node  节点行
     * @param array $usages [['uuid'=>..., 'bytes'=>...], ...]
     * @return array 每条结果
     */
    public static function settle(array $node, array $usages): array {
        $db = db();
        $out = [];
        // 元/MB 单价（微元）
        $priceMb = $node['is_free'] ? 0 : (int)round($node['price_gb_micro'] / 1024);

        foreach ($usages as $u) {
            $uuid  = (string)($u['uuid'] ?? '');
            $bytes = max(0, (int)($u['bytes'] ?? 0));
            if ($uuid === '' || $bytes <= 0) { $out[] = ['uuid' => $uuid, 'ok' => false, 'msg' => 'invalid']; continue; }

            $st = $db->prepare('SELECT nu.*, u.id AS uid FROM node_users nu JOIN users u ON u.id = nu.user_id WHERE nu.node_id = ? AND nu.uuid = ?');
            $st->execute([$node['id'], $uuid]);
            $nu = $st->fetch(PDO::FETCH_ASSOC);
            if (!$nu)              { $out[] = ['uuid' => $uuid, 'ok' => false, 'msg' => 'unknown user']; continue; }
            if ($nu['status'] !== 'active') { $out[] = ['uuid' => $uuid, 'ok' => false, 'msg' => 'suspended']; continue; }

            // 累计节点已售流量（用于限额公示）
            $db->prepare('UPDATE nodes SET sold_bytes = sold_bytes + ? WHERE id = ?')->execute([$bytes, $node['id']]);

            // 公益免费节点 / 机主自用：不计费
            if ($node['is_free'] || (int)$nu['uid'] === (int)$node['seller_id']) {
                $out[] = ['uuid' => $uuid, 'ok' => true, 'charged_micro' => 0, 'note' => 'free'];
                continue;
            }

            $cost = (int)round($bytes / 1048576 * $priceMb);
            $bal  = balance_micro((int)$nu['uid']);

            // 余额不足 → 自动熔断：该用户在此节点停用，守护进程下次同步即剔除
            if ($cost > 0 && $bal < $cost) {
                $db->prepare("UPDATE node_users SET status = 'suspended' WHERE id = ?")->execute([$nu['id']]);
                add_ledger((int)$nu['uid'], 'suspend', 0, (int)$node['id'], '', "余额不足自动熔断：节点 {$node['name']}");
                $out[] = ['uuid' => $uuid, 'ok' => false, 'msg' => 'insufficient -> suspended'];
                continue;
            }

            if ($cost > 0) {
                add_ledger((int)$nu['uid'], 'usage', -$cost, (int)$node['id'], '',
                    sprintf('节点 %s 流量 %.2f MB', $node['name'], $bytes / 1048576));
                $earn = (int)round($cost * REVENUE_SHARE);
                $freeze = date('Y-m-d H:i:s', time() + FREEZE_DAYS * 86400);
                add_ledger((int)$node['seller_id'], 'earning', $earn, (int)$node['id'], '',
                    sprintf('节点 %s 收益（%.0f%%）', $node['name'], REVENUE_SHARE * 100), $freeze);
            }
            $out[] = ['uuid' => $uuid, 'ok' => true, 'charged_micro' => $cost];
        }
        return $out;
    }
}
