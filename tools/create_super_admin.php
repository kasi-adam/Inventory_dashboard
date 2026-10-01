<?php
// Creates a Super Admin, promotes an existing account to Super Admin, or RECOVERS the Super Admin (re-enables it, clears any
// lock-out, optionally resets its password). Run from a terminal on the server; it needs the database login in app/config.php,
// so it is the break-glass path if the Super Admin account itself is ever locked or disabled.
//   php tools/create_super_admin.php <username> ["<Full Name>"]
// - New username: needs a full name and a password.
// - Existing username: promoted to Super Admin if it is not one already; password is only changed if you type one.
// By default there can be only ONE Super Admin ('allow_multiple_super_admins' => false in app/config.php). Asking for a second
// one is refused, but you can always recover the existing one.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$CONFIG = require __DIR__ . '/../app/config.php';
$c = $CONFIG['db'];
$pdo = new PDO($c['dsn'] ?? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']), $c['user'] ?? null, $c['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
[$_, $un, $fn] = $argv + [null, null, null];
if (!$un) exit("Usage: php tools/create_super_admin.php <username> [\"<Full Name>\"]\n");
$un = strtolower($un);

// 'super_admin' is 11 characters; the original column was VARCHAR(10). Widen it (safe to repeat).
$col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if (preg_match('/^varchar\((\d+)\)/i', (string)$col['Type'], $m) && (int)$m[1] < 20) {
    $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'viewer'");
    echo "Widened users.role to VARCHAR(20).\n";
}
$multiple = (bool)($CONFIG['allow_multiple_super_admins'] ?? false);
$others = $pdo->prepare("SELECT username FROM users WHERE role = 'super_admin' AND username <> ?");
$others->execute([$un]);
if (($other = $others->fetchColumn()) !== false && !$multiple) {
    exit("Refused: '$other' is already the Super Admin, and 'allow_multiple_super_admins' is false in app/config.php.\nRun this command with '$other' to recover that account, or enable multiple Super Admins first.\n");
}

$st = $pdo->prepare('SELECT id, role FROM users WHERE username = ?'); $st->execute([$un]); $row = $st->fetch();
echo $row ? 'Password (10+ characters, leave empty to keep the current one): ' : 'Password (10+ characters): ';
if (PHP_OS_FAMILY === 'Windows') {
    $pw = trim((string)fgets(STDIN));
} else {
    system('stty -echo 2>/dev/null');
    $pw = trim((string)fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
}
if ($pw !== '' && strlen($pw) < 10) exit("Password too short.\n");
$log = fn(?int $id, string $ev) => $pdo->prepare('INSERT INTO account_lock_log (user_id, username, event, actor, ip, created_at) VALUES (?,?,?,?,?,?)')->execute([$id, $un, $ev, 'cli', '127.0.0.1', date('Y-m-d H:i:s')]);

$pdo->beginTransaction();   // all or nothing: a role that does not read back correctly is rolled back
if ($row) {
    $id = (int)$row['id'];
    $pdo->prepare("UPDATE users SET role = 'super_admin', active = 1, failed_count = 0, lock_level = 0, locked_at = NULL, locked_until = NULL WHERE id = ?")->execute([$id]);
    if ($pw !== '') $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    $msg = $row['role'] === 'super_admin' ? "Recovered Super Admin $un: enabled, lock-out cleared" . ($pw !== '' ? ', password reset' : '') . ".\n" : "Promoted $un to Super Admin (enabled, lock-out cleared).\n";
} else {
    if (!$fn || $pw === '') exit("A new account needs a full name and a password.\n");
    $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,'super_admin')")->execute([$un, $fn, password_hash($pw, PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    $msg = "Created Super Admin $un.\n";
}
// Read it back: if the role column could not be widened, a longer value could have been cut short.
$st = $pdo->prepare('SELECT role FROM users WHERE id = ?'); $st->execute([$id]);
if ($st->fetchColumn() !== 'super_admin') { $pdo->rollBack(); exit("Nothing was changed: the role could not be stored. Run sql/migrate_super_admin.sql, then repeat this command.\n"); }
$log($id, 'cli_super_admin');
$pdo->commit();
echo $msg;
