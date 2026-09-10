-- Artikelgruppen as a managed entity, plus per-article stock tracking.
-- Safe to run on an already-populated database: additive only, backfills from existing data.

CREATE TABLE IF NOT EXISTS categories (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(60)  NOT NULL UNIQUE,
  sort_order          INT          NOT NULL DEFAULT 0,
  track_stock_default TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill one category row per distinct category name already used by products
-- (covers both the seed data and whatever articles were entered before this migration).
INSERT IGNORE INTO categories (name, sort_order)
SELECT category, 0 FROM (SELECT DISTINCT category FROM products) AS existing;

-- products.track_stock is added by Migrator::migrateToV2() (src/Migrator.php), not here:
-- "ADD COLUMN IF NOT EXISTS" needs MySQL 8.0.29+ / fails as a syntax error on older MySQL
-- (common on shared hosting), so the column's existence is checked in PHP instead via
-- information_schema, which every supported MySQL/MariaDB version understands.
