<?php
// Creates (or resets) an administrator account. Run from a terminal on the server:
//   php tools/create_admin.php <username> "<Full Name>"
// You will be asked for the password. On shared hosting without SSH, use setup.php instead.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$CONFIG = require __DIR__ . '/../app/config.php';
$c = $CONFIG['db'];
$pdo = new PDO($c['dsn'] ?? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']), $c['user'] ?? null, $c['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$_, $un, $fn] = $argv + [null, null, null];
if (!$un || !$fn) exit("Usage: php tools/create_admin.php <username> \"<Full Name>\"\n");
echo 'Password (10+ characters): ';
system('stty -echo 2>/dev/null'); $pw = trim((string)fgets(STDIN)); system('stty echo 2>/dev/null'); echo "\n";
if (strlen($pw) < 10) exit("Password too short.\n");
$st = $pdo->prepare('SELECT id FROM users WHERE username = ?'); $st->execute([strtolower($un)]);
if ($id = $st->fetchColumn()) {
    $pdo->prepare("UPDATE users SET password_hash = ?, role = IF(role = 'super_admin', role, 'admin'), active = 1, failed_count = 0, lock_level = 0, locked_at = NULL, locked_until = NULL, full_name = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $fn, $id]);
    echo "Reset password for $un (an existing Super Admin keeps that role; anyone else is made an admin).\n";
} else {
    $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,'admin')")->execute([strtolower($un), $fn, password_hash($pw, PASSWORD_DEFAULT)]);
    echo "Created admin $un.\n";
}
