<?php
// 一键部署脚本展示
$page_title = '一键部署 · ' . SITE_NAME;
$u = $user;
$n = get_node((int)($_GET['id'] ?? 0));
if (!$n || ($n['seller_id'] != $u['id'] && !$u['is_admin'])) { flash('无权限', true); redirect('?p=seller'); }
$script = Agent::install_script($n, base_url());
?>
<div class="card">
  <h2>一键部署：<?= h($n['name']) ?></h2>
  <p class="mut">在节点服务器上以 <b>root</b> 执行下面这行命令：</p>
  <code>curl -fsSL "<?= h(base_url()) ?>/?p=deploy_raw&id=<?= $n['id'] ?>&key=<?= h($n['api_key']) ?>" | bash</code>
  <p class="mut" style="margin-top:12px">脚本内容（已按本节点生成，可直接复制执行）：</p>
  <textarea class="code" readonly onclick="this.select()"><?= h($script) ?></textarea>
  <div class="mut" style="margin-top:12px">
    <?php if (($n['core'] ?? 'xray') === 'singbox'): ?>
    脚本会自动完成：① 安装 sing-box + gRPC 依赖 ② 写入节点守护进程并注册 systemd 服务 ③ 守护进程每轮：从平台同步用户列表 → 生成单端口多用户 VLESS+Reality 配置（<code>sing-box check</code> 校验后生效）→ 经 V2Ray API 按用户拉取流量 → 每 30 秒上报。<br>
    Reality 密钥对由平台用 X25519（libsodium）生成，格式与 <code>sing-box generate reality-keypair</code> 兼容；short-id 为 16 位 hex。
    <?php else: ?>
    脚本会自动完成：① 安装 Xray-core ② 写入节点守护进程并注册 systemd 服务 ③ 守护进程每轮：从平台同步用户列表 → 生成单端口多用户 VLESS+Reality 配置（<code>xray -test</code> 校验后生效）→ 经 Xray StatsService 按用户拉取流量 → 每 30 秒上报。<br>
    Reality 密钥对由平台用 X25519（libsodium）生成，格式与 <code>xray x25519</code> 兼容；short-id 为 16 位 hex。
    <?php endif; ?>
  </div>
</div>
