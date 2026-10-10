<?php
// 卖家中心
$page_title = '卖家中心 · ' . SITE_NAME;
$u = $user;
$st = db()->prepare("SELECT * FROM nodes WHERE seller_id = ? ORDER BY id DESC");
$st->execute([$u['id']]);
$nodes = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="rowbtns" style="justify-content:space-between">
    <h2 style="margin:0">卖家中心</h2>
    <span><a class="btn small" href="?p=seller_node">＋ 上架新节点</a>
    <a class="btn small ghost" href="?p=earnings">收益与提现</a></span>
  </div>
  <p class="mut">上架后复制一键部署脚本，在服务器上以 root 执行，守护进程会自动完成 Xray 配置、流量记账与 30 秒上报。分成 <?= (int)(REVENUE_SHARE * 100) ?>%，收益冻结 <?= FREEZE_DAYS ?> 天后可提现。</p>
</div>

<div class="card">
<table>
  <tr><th>节点</th><th>接入</th><th>单价</th><th>已售 / 限额</th><th>状态</th><th>操作</th></tr>
  <?php foreach ($nodes as $n):
      $cnt = db()->prepare('SELECT COUNT(*) FROM node_users WHERE node_id = ? AND status = ?');
      $cnt->execute([$n['id'], 'active']);
  ?>
  <tr>
    <td><b><?= h($n['name']) ?></b> <span class="badge"><?= ($n['core'] ?? 'xray') === 'singbox' ? 'sing-box' : 'Xray' ?></span><br><span class="mut"><?= h($n['region']) ?> · <?= $cnt->fetchColumn() ?> 个用户</span></td>
    <td class="mut"><?= h($n['ip']) ?>:<?= $n['port'] ?><br><?= $cnt->fetchColumn() ?> 个用户</td>
    <td><?= $n['is_free'] ? '<span class="badge free">公益免费</span>' : '¥' . money_fmt((int)$n['price_gb_micro']) . ' / GB' ?></td>
    <td class="mut"><?= number_format($n['sold_bytes'] / 1073741824, 2) ?> GB<?= $n['quota_gb'] > 0 ? ' / ' . $n['quota_gb'] . ' GB' : '' ?></td>
    <td><?= $n['status'] === 'active' ? '<span class="badge">在售</span>' : '<span class="badge pause">暂停</span>' ?></td>
    <td><div class="rowbtns">
      <a class="btn small" href="?p=deploy&id=<?= $n['id'] ?>">部署脚本</a>
      <a class="btn small ghost" href="?p=seller_node&id=<?= $n['id'] ?>">编辑</a>
      <form method="post"><input type="hidden" name="a" value="node_toggle"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button class="btn small ghost"><?= $n['status'] === 'active' ? '暂停' : '上架' ?></button></form>
      <form method="post" onsubmit="return confirm('删除节点及全部用户授权，确定？')"><input type="hidden" name="a" value="node_delete"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button class="btn small danger">删除</button></form>
    </div></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$nodes): ?><p class="mut">还没有节点，点右上「上架新节点」开始。</p><?php endif; ?>
</div>
