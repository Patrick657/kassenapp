-- Festkasse — MySQL 8 / MariaDB Schema
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)   NOT NULL,
  category      VARCHAR(60)    NOT NULL DEFAULT 'Speisen',
  price_cents   INT UNSIGNED   NOT NULL,
  cost_cents    INT UNSIGNED   NOT NULL DEFAULT 0,
  stock         INT            NOT NULL DEFAULT 0,
  stock_min     INT            NOT NULL DEFAULT 0,
  sort_order    INT            NOT NULL DEFAULT 0,
  archived_at   DATETIME       NULL,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (category), INDEX (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_uuid    CHAR(36)     NULL UNIQUE,
  receipt_no     VARCHAR(20)  NOT NULL UNIQUE,
  sold_at        DATETIME     NOT NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents    INT UNSIGNED NOT NULL,
  payment        ENUM('cash','card') NOT NULL,
  given_cents    INT UNSIGNED NOT NULL DEFAULT 0,
  change_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  voided_at      DATETIME     NULL,
  INDEX (sold_at), INDEX (payment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NULL,
  name         VARCHAR(120) NOT NULL,
  unit_cents   INT UNSIGNED NOT NULL,
  cost_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  qty          INT UNSIGNED NOT NULL,
  FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  INDEX (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at  DATETIME NOT NULL,
  type         ENUM('sale','in','out','close','delivery') NOT NULL,
  amount_cents INT      NOT NULL,
  note         VARCHAR(255) NOT NULL DEFAULT '',
  sale_id      INT UNSIGNED NULL,
  product_id   INT UNSIGNED NULL,
  qty          INT NULL,
  FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE SET NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  INDEX (occurred_at), INDEX (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS z_reports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no           INT UNSIGNED NOT NULL UNIQUE,
  closed_at    DATETIME     NOT NULL,
  from_sale_at DATETIME     NULL,
  sales_count  INT UNSIGNED NOT NULL,
  cash_cents   INT UNSIGNED NOT NULL,
  card_cents   INT UNSIGNED NOT NULL,
  total_cents  INT UNSIGNED NOT NULL,
  drawer_cents INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key`  VARCHAR(60) PRIMARY KEY,
  value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_sessions (
  token_hash  CHAR(64)  PRIMARY KEY,
  created_at  DATETIME  NOT NULL,
  expires_at  DATETIME  NOT NULL,
  revoked_at  DATETIME  NULL,
  ip          VARBINARY(16) NULL,
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks failed/ok attempts for both the access code and the delete code (same rate-limit bucket per IP).
CREATE TABLE IF NOT EXISTS access_attempts (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tried_at DATETIME NOT NULL,
  ip       VARBINARY(16) NULL,
  ok       TINYINT(1) NOT NULL,
  INDEX (tried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  happened_at DATETIME NOT NULL,
  action      VARCHAR(60)  NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  ip          VARBINARY(16) NULL,
  INDEX (happened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`key`, value) VALUES
  ('shop_name',        'Festkasse'),
  ('track_stock',      '1'),
  ('warn_low',         '1'),
  ('card_enabled',     '1'),
  ('require_code',     '1'),
  ('access_ttl_sec',   '7200'),
  ('access_code_hash', ''),
  ('delete_code_hash', '');
