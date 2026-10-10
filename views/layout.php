<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($page_title ?? SITE_NAME) ?></title>
<style>
:root{--bg:#0f1420;--card:#182030;--line:#2a3550;--txt:#e8edf7;--mut:#93a0b8;--acc:#4da3ff;--ok:#3ddc84;--warn:#ffb020;--bad:#ff5d5d}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.7 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
a{color:var(--acc);text-decoration:none}
.wrap{max-width:1080px;margin:0 auto;padding:0 16px}
header{border-bottom:1px solid var(--line);background:#0c111c;position:sticky;top:0;z-index:10}
.nav{display:flex;gap:18px;align-items:center;min-height:58px;flex-wrap:wrap;padding:6px 0}
.nav .logo{font-weight:700;font-size:17px;color:#fff}
.nav .sp{flex:1}.nav .bal{color:var(--ok);font-size:13px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}
.btn{display:inline-block;background:var(--acc);color:#04101f;border:0;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;font-size:14px}
.btn.ghost{background:transparent;color:var(--acc);border:1px solid var(--acc)}
.btn.danger{background:transparent;color:var(--bad);border:1px solid var(--bad)}
.btn.small{padding:4px 10px;font-size:13px}
input,select,textarea{width:100%;background:#0c1220;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:9px 12px;margin:4px 0 12px;font-size:14px}
input[type=checkbox]{width:auto;margin-right:6px}
label{color:var(--mut);font-size:13px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{color:var(--mut);font-weight:600;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.badge{display:inline-block;font-size:12px;padding:2px 10px;border-radius:20px;background:#24304a;color:var(--acc)}
.badge.free{background:#123a24;color:var(--ok)}
.badge.pause{background:#3a2a12;color:var(--warn)}
.mut{color:var(--mut);font-size:13px}
code{background:#0c1220;padding:2px 6px;border-radius:6px;font-size:13px;word-break:break-all}
textarea.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;min-height:420px}
.flash{background:#123a24;border:1px solid var(--ok);padding:10px 14px;border-radius:8px;margin:12px 0}
.flash.err{background:#3a1212;border-color:var(--bad)}
.stat{display:flex;gap:32px;flex-wrap:wrap}.stat b{font-size:22px;display:block}
.rowbtns{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.rowbtns form{margin:0}
h2,h3{margin-top:0}
</style>
</head>
<body>
<header><div class="wrap nav">
<a class="logo" href="?p=hall">🌊 <?= h(SITE_NAME) ?></a>
<a href="?p=hall">节点大厅</a>
<?php if ($user): ?>
<a href="?p=buyer">买家中心</a>
<a href="?p=seller">卖家中心</a>
<?php if ($user['is_admin']): ?><a href="?p=admin_payouts">提现审核</a><?php endif; ?>
<span class="sp"></span>
<span class="bal">余额 ¥<?= money_fmt(balance_micro((int)$user['id'])) ?></span>
<span class="mut"><?= h($user['username']) ?></span>
<a href="?p=logout">退出</a>
<?php else: ?>
<span class="sp"></span><a href="?p=login">登录</a><a href="?p=register">注册</a>
<?php endif; ?>
</div></header>
<div class="wrap">
<?php if (!empty($_SESSION['flash'])): ?><div class="flash"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['flash_err'])): ?><div class="flash err"><?= h($_SESSION['flash_err']) ?></div><?php unset($_SESSION['flash_err']); endif; ?>
<?= $content ?>
<p class="mut" style="margin:30px 0">FluxBazaar · 按 MB 计费的去中心化流量集市（MVP 演示版）</p>
</div>
</body>
</html>
