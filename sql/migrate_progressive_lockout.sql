-- Progressive account lock-out: run ONCE on an existing database (phpMyAdmin → SQL, or: mysql -u USER -p DBNAME < sql/migrate_progressive_lockout.sql).
-- New installs already get these from schema.sql. Existing rows and passwords are untouched; every account starts ACTIVE with a clean slate.
-- (If you prefer a command that is safe to repeat, run: php tools/migrate_lockout.php)
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN failed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN lock_level   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN locked_at    DATETIME NULL,
  ADD COLUMN locked_until DATETIME NULL;

CREATE TABLE IF NOT EXISTS account_lock_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(60)  NOT NULL,
  event       VARCHAR(20)  NOT NULL,
  actor       VARCHAR(60)  NULL,
  ip          VARCHAR(45)  NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
