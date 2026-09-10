-- Festkasse · MySQL 8 Startschema
SET NAMES utf8mb4;
SET time_zone = '+01:00';

CREATE TABLE products (
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

CREATE TABLE sales (
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

CREATE TABLE sale_items (
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

CREATE TABLE cash_movements (
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

CREATE TABLE z_reports (
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

CREATE TABLE settings (
  `key`  VARCHAR(60) PRIMARY KEY,
  value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_sessions (
  token_hash  CHAR(64)  PRIMARY KEY,
  created_at  DATETIME  NOT NULL,
  expires_at  DATETIME  NOT NULL,
  revoked_at  DATETIME  NULL,
  ip          VARBINARY(16) NULL,
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_attempts (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tried_at DATETIME NOT NULL,
  ip       VARBINARY(16) NULL,
  ok       TINYINT(1) NOT NULL,
  INDEX (tried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grundeinstellungen (access_code_hash beim Setup per password_hash('1234', PASSWORD_DEFAULT) setzen)
INSERT INTO settings (`key`, value) VALUES
  ('shop_name',        'Festkasse'),
  ('track_stock',      '1'),
  ('warn_low',         '1'),
  ('card_enabled',     '1'),
  ('require_code',     '1'),
  ('access_ttl_sec',   '7200'),
  ('access_code_hash', ''),
  ('delete_code_hash', '');

CREATE TABLE audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  happened_at DATETIME NOT NULL,
  action     VARCHAR(60)  NOT NULL,   -- product.delete, data.purge, data.reset
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  ip         VARBINARY(16) NULL,
  INDEX (happened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Beispielartikel
INSERT INTO products (name, category, price_cents, cost_cents, stock, stock_min, sort_order) VALUES
  ('Bratwurst im Brötchen','Speisen',350,120,120,20,1),
  ('Currywurst mit Pommes','Speisen',650,240, 80,15,2),
  ('Pommes rot/weiß','Speisen',300, 80,150,25,3),
  ('Schnitzelbrötchen','Speisen',500,210, 60,10,4),
  ('Gulaschsuppe','Speisen',450,160, 50,10,5),
  ('Käsespätzle','Speisen',700,250, 40, 8,6),
  ('Bier 0,5 l','Getränke',400,110,240,40,7),
  ('Radler 0,5 l','Getränke',400,115, 90,20,8),
  ('Weinschorle','Getränke',450,140, 70,15,9),
  ('Cola 0,33 l','Getränke',250, 70,180,30,10),
  ('Wasser 0,5 l','Getränke',200, 40,200,30,11),
  ('Kaffee','Getränke',250, 35,100,20,12),
  ('Kuchen (Stück)','Süßes',300, 90, 45,10,13),
  ('Waffel','Süßes',350,100, 55,12,14),
  ('Eis am Stiel','Süßes',200, 60, 90,20,15);
