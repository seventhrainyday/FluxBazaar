<?php
// 节点大厅
$page_title = '节点大厅 · ' . SITE_NAME;
$nodes = db()->query(
    "SELECT n.*, u.username AS seller FROM nodes n JOIN users u ON u.id = n.seller_id
     WHERE n.status = 'active' ORDER BY n.is_free DESC, n.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <h2>节点大厅</h2>
  <p class="mut" style="margin-bottom:0">一个专属订阅链接，聚合全网在售节点，按实际使用 MB 扣费，用多少扣多少。公益免费节点随时可用。</p>
</div>
<div class="grid">
<?php foreach ($nodes as $n):
    $perMb = $n['is_free'] ? 0 : $n['price_gb_micro'] / 1024 / 1e6;
    $soldGb = $n['sold_bytes'] / 1073741824;
?>
  <div class="card" style="margin:0">
    <div><b><?= h($n['name']) ?></b>
      <?php if ($n['is_free']): ?><span class="badge free">公益免费</span><?php endif; ?>
    </div>
    <div class="mut"><?= h($n['region']) ?> · <?= h($n['ip']) ?> · 机主 <?= h($n['seller']) ?></div>
    <div style="margin:8px 0;font-size:22px;color:var(--ok)"><?= $n['is_free'] ? '¥0' : '¥' . number_format($perMb, 6) . ' <span class="mut" style="font-size:13px">/ MB</span>' ?></div>
    <div class="mut">已售 <?= number_format($soldGb, 2) ?> GB<?= $n['quota_gb'] > 0 ? ' · 剩余 ' . number_format(max(0, $n['quota_gb'] - $soldGb), 2) . ' GB' : ' · 不限量' ?></div>
    <?php if ($n['note']): ?><div class="mut" style="margin-top:6px"><?= h($n['note']) ?></div><?php endif; ?>
    <div class="rowbtns" style="margin-top:10px">
      <?php if ($n['nq_url']): ?><a class="btn small ghost" href="<?= h($n['nq_url']) ?>" target="_blank" rel="noopener">NQ 跑分</a><?php endif; ?>
      <?php if ($user): ?><a class="btn small ghost" href="api/sub.php?token=<?= h($user['sub_token']) ?>&format=uri&node=<?= $n['id'] ?>">复制单节点链接</a><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$nodes): ?><div class="card mut">暂无在售节点，等机主上架。</div><?php endif; ?>
