-- NEDCo Finance ICT Fleet — MySQL / MariaDB schema
-- Import with:  mysql -u USER -p DBNAME < sql/schema.sql   (or phpMyAdmin → Import)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS assets (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial_number      VARCHAR(64)  NULL,
  category           VARCHAR(40)  NOT NULL,
  brand              VARCHAR(40)  NOT NULL DEFAULT 'Other',
  model              VARCHAR(120) NOT NULL DEFAULT '',
  status             VARCHAR(20)  NOT NULL DEFAULT 'In Use',      -- In Use | In Repair | Damaged
  location           VARCHAR(60)  NOT NULL,
  user_name          VARCHAR(120) NOT NULL DEFAULT '',
  os_family          VARCHAR(60)  NOT NULL DEFAULT 'Not applicable',
  ram_gb             SMALLINT UNSIGNED NULL,
  storage_gb         INT UNSIGNED NULL,
  purchase_date      DATE NULL,
  maint_schedule     VARCHAR(20)  NOT NULL DEFAULT 'Quarterly',   -- Quarterly | Biannually | Annually
  last_maintenance   DATE NULL,
  last_audit         DATE NULL,
  risk_rating        VARCHAR(10)  NOT NULL DEFAULT 'Low',         -- kept for the score; not shown on the dashboard
  business_impact    VARCHAR(10)  NOT NULL DEFAULT 'Low',
  criticality        VARCHAR(10)  NOT NULL DEFAULT 'Low',
  security_class     VARCHAR(40)  NOT NULL DEFAULT 'Confidential / Private',
  backup             TINYINT(1)   NOT NULL DEFAULT 1,
  security_controls  TINYINT(1)   NOT NULL DEFAULT 1,
  remote_access      TINYINT(1)   NOT NULL DEFAULT 0,
  network_zone       VARCHAR(40)  NOT NULL DEFAULT 'Corporate LAN',
  cia_class          VARCHAR(20)  NOT NULL DEFAULT '',
  completeness       TINYINT UNSIGNED NOT NULL DEFAULT 0,         -- % of register fields filled in
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by         VARCHAR(60) NULL,
  KEY idx_location (location),
  KEY idx_serial (serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(60)  NOT NULL UNIQUE,
  full_name      VARCHAR(120) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           VARCHAR(20)  NOT NULL DEFAULT 'viewer',          -- super_admin | admin | viewer (viewer = ordinary USER)
  active         TINYINT(1)   NOT NULL DEFAULT 1,                 -- 0 = DISABLED (by an administrator, or by the progressive lock-out)
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  DATETIME NULL,
  failed_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,             -- consecutive failed sign-ins counted towards the next lock
  lock_level     TINYINT UNSIGNED NOT NULL DEFAULT 0,             -- 0 none | 1 = 1-hour lock (active or expired) | 2 = 2-hour lock | 3 = disabled by lock-out
  locked_at      DATETIME NULL,                                   -- when the latest lock / disable started
  locked_until   DATETIME NULL                                    -- when the latest temporary lock ends (NULL if none)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every sign-in attempt, successful or not. Used for lock-outs and the Sign-in activity page.
CREATE TABLE IF NOT EXISTS auth_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(60)  NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  user_agent  VARCHAR(255) NOT NULL DEFAULT '',
  success     TINYINT(1)   NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_ip_time (ip, created_at),
  KEY idx_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lock-out audit trail: lock_1h | lock_2h | disabled (actor NULL = the system), unlock | enable (actor = administrator).
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
