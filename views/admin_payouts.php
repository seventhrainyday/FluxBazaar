<?php
// 提现审核（管理员）
$page_title = '提现审核 · ' . SITE_NAME;
$rows = db()->query(
    "SELECT p.*, u.username FROM payouts p JOIN users u ON u.id = p.seller_id ORDER BY p.id DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);
$stName = ['pending' => '待审核', 'done' => '已打款', 'rejected' => '已驳回'];
?>
<div class="card">
  <h2>提现审核</h2>
  <table><tr><th>时间</th><th>卖家</th><th>金额</th><th>状态</th><th>操作</th></tr>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mut"><?= h($x['created_at']) ?></td>
    <td><?= h($x['username']) ?></td>
    <td>¥<?= money_fmt((int)$x['amount_micro']) ?></td>
    <td><?= h($stName[$x['status']] ?? $x['status']) ?></td>
    <td><?php if ($x['status'] === 'pending'): ?>
      <div class="rowbtns">
      <form method="post"><input type="hidden" name="a" value="payout_decide"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="decide" value="approve"><button class="btn small">批准打款</button></form>
      <form method="post"><input type="hidden" name="a" value="payout_decide"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="decide" value="reject"><button class="btn small danger">驳回</button></form>
      </div>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?></table>
  <?php if (!$rows): ?><p class="mut">暂无提现申请。</p><?php endif; ?>
</div>
