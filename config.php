<?php
declare(strict_types=1);
// ============ FluxBazaar · 站点配置 ============
define('SITE_NAME', 'FluxBazaar · 流量集市');
define('DB_PATH', __DIR__ . '/data/fluxbazaar.sqlite');

define('REVENUE_SHARE', 0.90);          // 卖家分成比例（90%）
define('FREEZE_DAYS', 30);              // 卖家收益冻结天数
define('MIN_PAYOUT_MICRO', 10_000_000); // 最低提现：10 元（微元）

// 金额单位：微元（1 元 = 1,000,000 微元），保证 4 位小数精度
