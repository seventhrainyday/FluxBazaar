<?php
// 买家中心
$page_title = '买家中心 · ' . SITE_NAME;
$u = $user;
$bal = balance_micro((int)$u['id']);
$base = base_url();
$tok = $u['sub_token'];
$led = db()->prepare('SELECT * FROM ledger WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$led->execute([$u['id']]);
$rows = $led->fetchAll(PDO::FETCH_ASSOC);
$types = ['recharge' => '充值', 'usage' => '流量扣费', 'earning' => '节点收益', 'payout' => '提现', 'suspend' => '熔断'];
?>
<div class="card">
  <h2>买家中心</h2>
  <div class="stat"><div><span class="mut">可用余额</span><b style="color:var(--ok)">¥<?= money_fmt($bal) ?></b></div></div>
  <form method="post" style="margin-top:12px;max-width:420px">
    <input type="hidden" name="a" value="recharge">
    <label>模拟充值（元，演示环境无真实支付）</label>
    <div style="display:flex;gap:8px"><input type="number" name="amount" min="1" max="10000" step="0.01" value="50" required><button class="btn" style="width:110px;flex:none">充值</button></div>
  </form>
</div>

<div class="card">
  <h3>我的专属订阅链接</h3>
  <p class="mut">导入客户端即一键聚合全网在售节点，新节点上架 / 下架自动同步。余额不足时自动熔断。</p>
  <code><?= h($base) ?>/api/sub.php?token=<?= h($tok) ?></code>
  <div class="rowbtns" style="margin-top:12px">
    <a class="btn small" href="api/sub.php?token=<?= h($tok) ?>">Clash 订阅</a>
    <a class="btn small ghost" href="api/sub.php?token=<?= h($tok) ?>&format=singbox">Sing-box JSON</a>
    <a class="btn small ghost" href="api/sub.php?token=<?= h($tok) ?>&format=uri">URI 列表</a>
    <form method="post" onsubmit="return confirm('重置后旧链接立即失效，确定？')">
      <input type="hidden" name="a" value="reset_token">
      <button class="btn small danger">重置订阅凭证</button>
    </form>
  </div>
</div>

<div class="card">
  <h3>账单明细</h3>
  <table>
    <tr><th>时间</th><th>类型</th><th>金额（元）</th><th>备注</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mut"><?= h($r['created_at']) ?></td>
      <td><?= h($types[$r['type']] ?? $r['type']) ?></td>
      <td style="color:<?= $r['amount_micro'] < 0 ? 'var(--bad)' : 'var(--ok)' ?>"><?= $r['amount_micro'] < 0 ? '' : '+' ?>¥<?= money_fmt((int)$r['amount_micro']) ?></td>
      <td class="mut"><?= h($r['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (!$rows): ?><p class="mut">暂无账单。</p><?php endif; ?>
</div>
