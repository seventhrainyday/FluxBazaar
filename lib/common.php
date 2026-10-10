<?php
declare(strict_types=1);
// 公共库：DB / 会话 / 工具函数
session_start();
require __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
        // 轻量迁移：老库补列
        $cols = $pdo->query('PRAGMA table_info(nodes)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('core', $cols, true)) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN core TEXT NOT NULL DEFAULT 'xray'");
        }
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 微元 → 元字符串（4 位小数） */
function money_fmt(int $micro): string { return number_format($micro / 1000000, 4, '.', ''); }

function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function rand_hex(int $bytes): string { return bin2hex(random_bytes($bytes)); }

/** 生成 Reality X25519 密钥对（base64url），与 xray x25519 格式兼容 */
function reality_keypair(): array {
    $kp = sodium_crypto_box_keypair();
    $b64u = fn($b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    return [$b64u(sodium_crypto_box_secretkey($kp)), $b64u(sodium_crypto_box_publickey($kp))];
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: ?p=login'); exit; }
    return $u;
}
/** 可用余额（微元）：已到账的 ledger 合计 */
function balance_micro(int $uid): int {
    $st = db()->prepare("SELECT COALESCE(SUM(amount_micro),0) FROM ledger WHERE user_id = ? AND available_at <= datetime('now')");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}
/** 冻结中金额（微元） */
function frozen_micro(int $uid): int {
    $st = db()->prepare("SELECT COALESCE(SUM(amount_micro),0) FROM ledger WHERE user_id = ? AND available_at > datetime('now')");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}
function add_ledger(int $uid, string $type, int $amount, ?int $node_id = null, string $ref = '', string $note = '', ?string $available_at = null): void {
    $st = db()->prepare("INSERT INTO ledger(user_id,type,amount_micro,node_id,ref,note,available_at) VALUES(?,?,?,?,?,?,COALESCE(?,datetime('now')))");
    $st->execute([$uid, $type, $amount, $node_id, $ref, $note, $available_at]);
}
function get_node(int $id): ?array {
    $st = db()->prepare('SELECT * FROM nodes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function assert_owner(?array $node, array $user): void {
    if (!$node) throw new Exception('节点不存在');
    if ($node['seller_id'] != $user['id'] && !$user['is_admin']) throw new Exception('无权操作此节点');
}
function redirect(string $url): void { header('Location: ' . $url); exit; }
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
/** 项目根 URL（兼容 api/ 子目录调用） */
function base_url(): string {
    $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return "$s://$host$dir";
}
function flash(string $msg, bool $err = false): void { $_SESSION[$err ? 'flash_err' : 'flash'] = $msg; }
