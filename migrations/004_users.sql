-- Multi-user POS logins: which staff member is using a given register, and which article
-- groups they're allowed to sell (e.g. keep the merch station out of the food/drinks menu).
-- Purely a workflow control — it is independent of and does not replace the access/delete
-- codes that gate Verwaltung (see access_sessions / access_attempts).

CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  pin_hash    VARCHAR(255) NOT NULL,
  role        ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which article groups a 'cashier'-role user may sell. Unused for 'admin'-role users, who always
-- see everything. Cascades on either side: deleting a user or a group just drops the assignment.
CREATE TABLE IF NOT EXISTS user_categories (
  user_id     INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, category_id),
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which staff member is currently logged in at a given device. Long-lived on purpose ("bis
-- manuell gewechselt") — a festival register stays logged in as one station's user all day.
CREATE TABLE IF NOT EXISTS pos_sessions (
  token_hash  CHAR(64)     PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL,
  revoked_at  DATETIME     NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
