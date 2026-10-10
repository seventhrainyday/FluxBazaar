<?php
declare(strict_types=1);
// Web 入口：路由 + 表单动作
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/Billing.php';
require __DIR__ . '/lib/Subscription.php';
require __DIR__ . '/lib/Agent.php';

$p = $_GET['p'] ?? 'hall';

if ($p === 'logout') { session_destroy(); redirect('?p=hall'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        handle_action($_POST['a'] ?? '');
    } catch (Exception $e) {
        flash('操作失败：' . $e->getMessage(), true);
    }
    redirect('?p=' . $p . (isset($_GET['id']) ? '&id=' . (int)$_GET['id'] : ''));
}

$user = current_user();

// 一键脚本直链（curl | bash 用，凭节点 api_key 鉴权，不走 layout）
if ($p === 'deploy_raw') {
    $n = get_node((int)($_GET['id'] ?? 0));
    if (!$n || !hash_equals((string)$n['api_key'], (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo Agent::install_script($n, base_url());
    exit;
}

$needLogin = ['buyer', 'seller', 'seller_node', 'deploy', 'earnings', 'admin_payouts'];
if (in_array($p, $needLogin, true)) $user = require_login();
if ($p === 'admin_payouts' && !$user['is_admin']) { flash('无权限', true); redirect('?p=hall'); }

$routes = [
    'hall' => 'hall', 'login' => 'login', 'register' => 'register',
    'buyer' => 'buyer', 'seller' => 'seller', 'seller_node' => 'seller_node',
    'deploy' => 'deploy', 'earnings' => 'earnings', 'admin_payouts' => 'admin_payouts',
];
if (!isset($routes[$p])) $p = 'hall';

$page_title = SITE_NAME;
try {
    ob_start();
    include __DIR__ . '/views/' . $routes[$p] . '.php';
    $content = ob_get_clean();
} catch (Exception $e) {
    ob_end_clean();
    flash($e->getMessage(), true);
    redirect('?p=hall');
}
include __DIR__ . '/views/layout.php';

// ---------------- 表单动作 ----------------
function handle_action(string $a): void {
    $db = db();
    switch ($a) {
        case 'register': {
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) throw new Exception('用户名需为 3-20 位字母数字下划线');
            if (strlen($password) < 6) throw new Exception('密码至少 6 位');
            $isFirst = $db->query('SELECT COUNT(*) FROM users')->fetchColumn() == 0;
            $st = $db->prepare('INSERT INTO users(username,password_hash,is_admin,sub_token) VALUES(?,?,?,?)');
            try {
                $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $isFirst ? 1 : 0, rand_hex(16)]);
            } catch (PDOException $e) { throw new Exception('用户名已存在'); }
            $_SESSION['uid'] = (int)$db->lastInsertId();
            flash($isFirst ? '注册成功，你是首个用户，已设为管理员' : '注册成功，已自动登录');
            break;
        }
        case 'login': {
            $st = $db->prepare('SELECT * FROM users WHERE username = ?');
            $st->execute([trim($_POST['username'] ?? '')]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash']))
                throw new Exception('用户名或密码错误');
            $_SESSION['uid'] = (int)$u['id'];
            flash('登录成功');
            break;
        }
        case 'recharge': { // 演示：模拟充值（生产环境请对接真实支付）
            $u = require_login();
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount < 1 || $amount > 10000) throw new Exception('充值金额需在 1-10000 元之间');
            add_ledger($u['id'], 'recharge', (int)round($amount * 1e6), null, '', '模拟充值');
            flash('充值成功 ¥' . number_format($amount, 2));
            break;
        }
        case 'reset_token': {
            $u = require_login();
            $db->prepare('UPDATE users SET sub_token = ? WHERE id = ?')->execute([rand_hex(16), $u['id']]);
            flash('订阅凭证已重置，旧链接立即失效');
            break;
        }
        case 'node_save': {
            $u = require_login();
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('节点名称必填');
            $ip = trim($_POST['ip'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new Exception('IP 地址无效');
            $port = (int)($_POST['port'] ?? 8443);
            if ($port < 1 || $port > 65535) throw new Exception('监听端口需在 1-65535 之间');
            $sni = trim($_POST['sni'] ?? '') ?: 'www.microsoft.com';
            $price_gb = max(0, (float)($_POST['price_gb'] ?? 0.05));
            $is_free = isset($_POST['is_free']) ? 1 : 0;
            $quota_gb = max(0, (int)($_POST['quota_gb'] ?? 0));
            $region = trim($_POST['region'] ?? '');
            $nq_url = trim($_POST['nq_url'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $core = (($_POST['core'] ?? 'xray') === 'singbox') ? 'singbox' : 'xray';
            if ($id > 0) {
                $n = get_node($id); assert_owner($n, $u);
                $db->prepare('UPDATE nodes SET name=?,region=?,sni=?,price_gb_micro=?,is_free=?,quota_gb=?,nq_url=?,note=?,core=? WHERE id=?')
                   ->execute([$name, $region, $sni, (int)round($price_gb * 1e6), $is_free, $quota_gb, $nq_url, $note, $core, $id]);
                flash('节点已更新');
            } else {
                [$priv, $pub] = reality_keypair();
                $db->prepare('INSERT INTO nodes(seller_id,name,region,ip,port,sni,private_key,public_key,short_id,price_gb_micro,is_free,quota_gb,api_key,nq_url,note,core) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$u['id'], $name, $region, $ip, $port, $sni, $priv, $pub, rand_hex(8), (int)round($price_gb * 1e6), $is_free, $quota_gb, rand_hex(16), $nq_url, $note, $core]);
                $nid = (int)$db->lastInsertId();
                flash('节点上架成功，复制一键脚本去服务器执行即可');
                redirect('?p=deploy&id=' . $nid);
            }
            break;
        }
        case 'node_toggle': {
            $u = require_login();
            $n = get_node((int)($_POST['id'] ?? 0)); assert_owner($n, $u);
            $to = $n['status'] === 'active' ? 'paused' : 'active';
            $db->prepare('UPDATE nodes SET status = ? WHERE id = ?')->execute([$to, $n['id']]);
            flash($to === 'active' ? '节点已上架' : '节点已暂停（不再出现在订阅中）');
            break;
        }
        case 'node_delete': {
            $u = require_login();
            $n = get_node((int)($_POST['id'] ?? 0)); assert_owner($n, $u);
            $db->prepare('DELETE FROM node_users WHERE node_id = ?')->execute([$n['id']]);
            $db->prepare('DELETE FROM nodes WHERE id = ?')->execute([$n['id']]);
            flash('节点已删除');
            break;
        }
        case 'payout_request': {
            $u = require_login();
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) throw new Exception('提现金额无效');
            $micro = (int)round($amount * 1e6);
            if ($micro < MIN_PAYOUT_MICRO) throw new Exception('最低提现 ¥' . money_fmt(MIN_PAYOUT_MICRO));
            if (balance_micro($u['id']) < $micro) throw new Exception('可提现余额不足（冻结中收益需到期后才可提）');
            $db->prepare('INSERT INTO payouts(seller_id,amount_micro) VALUES(?,?)')->execute([$u['id'], $micro]);
            flash('提现申请已提交，待管理员审核');
            break;
        }
        case 'payout_decide': {
            $u = require_login();
            if (!$u['is_admin']) throw new Exception('无权限');
            $po = $db->prepare('SELECT * FROM payouts WHERE id = ?');
            $po->execute([(int)($_POST['id'] ?? 0)]);
            $p = $po->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['status'] !== 'pending') throw new Exception('申请不存在或已处理');
            if (($_POST['decide'] ?? '') === 'approve') {
                if (balance_micro((int)$p['seller_id']) < (int)$p['amount_micro']) throw new Exception('卖家可用余额不足');
                add_ledger((int)$p['seller_id'], 'payout', -(int)$p['amount_micro'], null, '', '提现打款');
                $db->prepare("UPDATE payouts SET status = 'done' WHERE id = ?")->execute([$p['id']]);
                flash('已批准提现');
            } else {
                $db->prepare("UPDATE payouts SET status = 'rejected' WHERE id = ?")->execute([$p['id']]);
                flash('已驳回提现申请');
            }
            break;
        }
        default: throw new Exception('未知操作');
    }
}
