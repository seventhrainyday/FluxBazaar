<?php
// 上架 / 编辑节点
$page_title = '上架节点 · ' . SITE_NAME;
$u = $user;
$id = (int)($_GET['id'] ?? 0);
$n = $id ? get_node($id) : null;
if ($n) assert_owner($n, $u);
$v = fn($k, $d = '') => h($n[$k] ?? $d);
?>
<div class="card" style="max-width:640px">
  <h2><?= $n ? '编辑节点' : '上架新节点' ?></h2>
  <form method="post">
    <input type="hidden" name="a" value="node_save">
    <?php if ($n): ?><input type="hidden" name="id" value="<?= $n['id'] ?>"><?php endif; ?>
    <label>节点名称 *（示例：香港BGP优化 / 台湾家宽原生IP）</label>
    <input name="name" required value="<?= $v('name') ?>">
    <div style="display:flex;gap:12px">
      <div style="flex:1"><label>地区</label><input name="region" value="<?= $v('region', '中国香港') ?>"></div>
      <div style="flex:1"><label>公网 IP *（NAT 机需先做好端口映射）</label><input name="ip" required value="<?= $v('ip') ?>" <?= $n ? 'disabled' : '' ?>></div>
    </div>
    <div style="display:flex;gap:12px">
      <div style="flex:1"><label>监听端口 *（单端口多用户，改动需重部署）</label><input type="number" name="port" min="1" max="65535" value="<?= $v('port', '8443') ?>" <?= $n ? 'disabled' : '' ?>></div>
      <div style="flex:1"><label>REALITY 伪装域名 *（须支持 TLS 1.3）</label><input name="sni" value="<?= $v('sni', 'www.microsoft.com') ?>"></div>
    </div>
    <label>节点内核 *（决定一键脚本装哪个核心，均单端口多用户、按用户记账）</label>
    <select name="core">
      <option value="xray" <?= ($n['core'] ?? 'xray') === 'xray' ? 'selected' : '' ?>>Xray-core（推荐：自带 xray api，部署最省事）</option>
      <option value="singbox" <?= ($n['core'] ?? '') === 'singbox' ? 'selected' : '' ?>>sing-box（更省内存，守护进程需 gRPC 依赖）</option>
    </select>
    <div style="display:flex;gap:12px">
      <div style="flex:1"><label>流量单价（元/GB）*</label><input type="number" name="price_gb" min="0" step="0.001" value="<?= $n ? money_fmt((int)$n['price_gb_micro']) : '0.0500' ?>"></div>
      <div style="flex:1"><label>总限额（GB，0=不限）</label><input type="number" name="quota_gb" min="0" value="<?= $v('quota_gb', '0') ?>"></div>
    </div>
    <label><input type="checkbox" name="is_free" <?= ($n['is_free'] ?? 0) ? 'checked' : '' ?>> 公益免费（勾选后单价固定为 0，全平台免费使用）</label>
    <label>NQ 测试报告链接（可选，NodeQuality 跑分，大厅展示给买家）</label>
    <input name="nq_url" value="<?= $v('nq_url') ?>" placeholder="https://...">
    <label>备注（示例：适合流媒体解锁、原生双栈、带宽500M…）</label>
    <input name="note" value="<?= $v('note') ?>">
    <button class="btn"><?= $n ? '保存修改' : '立即上架' ?></button>
    <a class="btn ghost" href="?p=seller">取消</a>
  </form>
  <?php if ($n): ?><p class="mut">IP / 监听端口 / Reality 密钥上架后不可修改（改动需重新部署节点）。</p><?php endif; ?>
</div>
