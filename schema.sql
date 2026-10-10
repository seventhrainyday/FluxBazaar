-- Taiju PHP · 流量集市 MVP 数据库结构（SQLite）
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  is_admin      INTEGER DEFAULT 0,
  sub_token     TEXT UNIQUE,                 -- 买家专属订阅凭证
  created_at    TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS nodes (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  seller_id       INTEGER NOT NULL,
  name            TEXT NOT NULL,
  region          TEXT DEFAULT '',
  ip              TEXT NOT NULL,             -- 公网 IP（NAT 机需配合端口转发）
  port            INTEGER NOT NULL,          -- 单端口多用户：所有买家共用此端口（按用户记账）
  core            TEXT NOT NULL DEFAULT 'xray', -- 节点内核：xray | singbox
  sni             TEXT NOT NULL DEFAULT 'www.microsoft.com',
  private_key     TEXT NOT NULL,             -- Reality X25519 私钥（base64url）
  public_key      TEXT NOT NULL,             -- Reality X25519 公钥（base64url）
  short_id        TEXT NOT NULL,             -- Reality short-id（16 位 hex）
  price_gb_micro  INTEGER NOT NULL DEFAULT 50000,  -- 流量单价：微元/GB（默认 0.05 元/GB）
  is_free         INTEGER DEFAULT 0,         -- 公益免费
  quota_gb        INTEGER DEFAULT 0,         -- 总限额 GB，0=不限
  sold_bytes      INTEGER DEFAULT 0,         -- 已售字节（累计上报）
  status          TEXT DEFAULT 'active',     -- active=在售 paused=暂停
  api_key         TEXT NOT NULL,             -- 节点上报鉴权密钥
  nq_url          TEXT DEFAULT '',           -- NodeQuality 跑分报告链接
  note            TEXT DEFAULT '',
  created_at      TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS node_users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  node_id    INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  uuid       TEXT NOT NULL,                  -- 该买家在该节点的 VLESS UUID
  port       INTEGER NOT NULL,               -- 该买家在该节点的专属端口
  status     TEXT DEFAULT 'active',          -- active=正常 suspended=欠费熔断
  created_at TEXT DEFAULT (datetime('now')),
  UNIQUE(node_id, user_id)
);

CREATE TABLE IF NOT EXISTS ledger (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL,
  type         TEXT NOT NULL,                -- recharge 充值 / usage 扣费 / earning 收益 / payout 提现 / suspend 熔断记录
  amount_micro INTEGER NOT NULL,             -- 微元（1 元 = 1,000,000），扣费为负
  node_id      INTEGER,
  ref          TEXT DEFAULT '',
  note         TEXT DEFAULT '',
  available_at TEXT DEFAULT (datetime('now')), -- 收益冻结：到期前不计入可用余额
  created_at   TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ledger_user ON ledger(user_id);

CREATE TABLE IF NOT EXISTS payouts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  seller_id    INTEGER NOT NULL,
  amount_micro INTEGER NOT NULL,
  status       TEXT DEFAULT 'pending',       -- pending 待审核 / done 已打款 / rejected 已驳回
  created_at   TEXT DEFAULT (datetime('now'))
);
