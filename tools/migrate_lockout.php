<?php
// Adds the progressive lock-out columns and audit table if they are missing. Safe to run more than once.
//   php tools/migrate_lockout.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
$CONFIG = require __DIR__ . '/../app/config.php';
$c = $CONFIG['db'];
$pdo = new PDO($c['dsn'] ?? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']), $c['user'] ?? null, $c['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$have = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['failed_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'lock_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'locked_at' => 'DATETIME NULL', 'locked_until' => 'DATETIME NULL'] as $col => $def) {
    if (in_array($col, $have, true)) { echo "users.$col already exists\n"; continue; }
    $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
    echo "Added users.$col\n";
}
$pdo->exec("CREATE TABLE IF NOT EXISTS account_lock_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, username VARCHAR(60) NOT NULL, event VARCHAR(20) NOT NULL,
  actor VARCHAR(60) NULL, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, KEY idx_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "account_lock_log table is in place.\n";
