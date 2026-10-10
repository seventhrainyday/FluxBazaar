<?php
// 收益与提现（卖家）
$page_title = '收益与提现 · ' . SITE_NAME;
$u = $user;
$avail = balance_micro((int)$u['id']);
$frozen = frozen_micro((int)$u['id']);
$paid = db()->prepare("SELECT COALESCE(SUM(-amount_micro),0) FROM ledger WHERE user_id = ? AND type = 'payout'");
$paid->execute([$u['id']]);
$po = db()->prepare('SELECT * FROM payouts WHERE seller_id = ? ORDER BY id DESC LIMIT 20');
$po->execute([$u['id']]);
$payouts = $po->fetchAll(PDO::FETCH_ASSOC);
$stName = ['pending' => '待审核', 'done' => '已打款', 'rejected' => '已驳回'];
?>
<div class="card">
  <h2>收益与提现</h2>
  <div class="stat">
    <div><span class="mut">可提现</span><b style="color:var(--ok)">¥<?= money_fmt($avail) ?></b></div>
    <div><span class="mut">冻结中（<?= FREEZE_DAYS ?> 天）</span><b style="color:var(--warn)">¥<?= money_fmt($frozen) ?></b></div>
    <div><span class="mut">累计已提</span><b>¥<?= money_fmt((int)$paid->fetchColumn()) ?></b></div>
  </div>
  <p class="mut">分成比例 <?= (int)(REVENUE_SHARE * 100) ?>% · 收益冻结 <?= FREEZE_DAYS ?> 天后解冻 · 最低提现 ¥<?= money_fmt(MIN_PAYOUT_MICRO) ?></p>
  <form method="post" style="max-width:420px">
    <input type="hidden" name="a" value="payout_request">
    <label>提现金额（元）</label>
    <div style="display:flex;gap:8px"><input type="number" name="amount" min="10" step="0.01" required><button class="btn" style="width:110px;flex:none">申请提现</button></div>
  </form>
</div>
<div class="card">
  <h3>提现记录</h3>
  <table><tr><th>时间</th><th>金额</th><th>状态</th></tr>
  <?php foreach ($payouts as $x): ?>
  <tr><td class="mut"><?= h($x['created_at']) ?></td><td>¥<?= money_fmt((int)$x['amount_micro']) ?></td><td><?= h($stName[$x['status']] ?? $x['status']) ?></td></tr>
  <?php endforeach; ?></table>
  <?php if (!$payouts): ?><p class="mut">暂无提现记录。</p><?php endif; ?>
</div>
