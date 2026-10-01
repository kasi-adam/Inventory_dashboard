<?php
declare(strict_types=1);

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Missing app/config.php. Copy app/config.sample.php to app/config.php and add your database details.');
}
$CONFIG = require $cfgFile;
date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');
require_once __DIR__ . '/brand.php';

/* ---------- session ---------- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('nedcofleet');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/* ---------- database ---------- */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = $CONFIG['db'];
        $dsn = $c['dsn'] ?? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'] ?? null, $c['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        lockout_schema_ensure($pdo);
        role_schema_ensure($pdo);
    }
    return $pdo;
}

/** The Super Admin role value ('super_admin', 11 characters) does not fit the original users.role VARCHAR(10).
 *  Widen it once, automatically, like lockout_schema_ensure() does. Existing data is untouched. If the database user may not
 *  alter tables, run sql/migrate_super_admin.sql instead. Until then a longer value cannot be stored safely and the
 *  authorization code treats any unrecognised role as having no privileges (fails closed). */
function role_schema_ensure(PDO $pdo): void
{
    if (!empty($_SESSION['role_schema_ok'])) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if (!$col) return;   // no users table yet: setup.php creates everything from schema.sql
        if (preg_match('/^varchar\\((\\d+)\\)/i', (string)$col['Type'], $m) && (int)$m[1] < 20) {
            $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'viewer'");
        }
        $_SESSION['role_schema_ok'] = true;
    } catch (Throwable $e) {
        // Leave the schema alone; see sql/migrate_super_admin.sql.
    }
}

/** Progressive lock-out needs four extra users columns and the account_lock_log table. If they are missing (an upgraded
 *  install that has not run the migration yet), add them once, automatically. It never touches existing data, and it
 *  does nothing if the database user is not allowed to alter tables (then run sql/migrate_progressive_lockout.sql). */
function lockout_schema_ensure(PDO $pdo): void
{
    if (!empty($_SESSION['lockout_schema_ok'])) return;
    try {
        $have = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
        if (!$have) return;   // no users table yet: setup.php creates everything from schema.sql
        foreach (['failed_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'lock_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                  'locked_at' => 'DATETIME NULL', 'locked_until' => 'DATETIME NULL'] as $col => $def) {
            if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS account_lock_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, username VARCHAR(60) NOT NULL,
            event VARCHAR(20) NOT NULL, actor VARCHAR(60) NULL, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_user_time (username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $_SESSION['lockout_schema_ok'] = true;
    } catch (Throwable $e) {
        // Leave the schema alone; the app reports the real database error if a column is still missing.
    }
}

/* ---------- helpers ---------- */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function cfg(string $k, $default = null) { global $CONFIG; return $CONFIG[$k] ?? $default; }
function q(string $k, string $default = ''): string { $v = $_GET[$k] ?? $default; return is_string($v) ? trim($v) : $default; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function url(string $page, array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $page . ($params ? '?' . http_build_query($params) : '');
}
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
}

/* ---------- authentication ---------- */
require_once __DIR__ . '/authz.php';   // role hierarchy and the account-management policy (SUPER ADMIN > ADMIN > USER)
function current_user(): ?array { return $_SESSION['user'] ?? null; }
/** Administrative access: ADMIN or SUPER ADMIN. (Super Admin has every existing administrator function.) The role in the
 *  session is refreshed from the database on every request by require_login(). */
function is_admin(): bool { return role_rank(current_user()['role'] ?? '') >= 2; }
function is_super_admin(): bool { return role_rank(current_user()['role'] ?? '') === 3; }

function require_login(): array
{
    $u = current_user();
    $idle = (int)cfg('idle_timeout_minutes', 60) * 60;
    if ($u && isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        session_unset();
        flash('You were signed out after a period of inactivity. Sign in again.', 'warn');
        $u = null;
    }
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        redirect('login.php?next=' . urlencode($next));
    }
    // Re-read role and active flag so demotions/disabling take effect immediately, not at next sign-in.
    $st = db()->prepare('SELECT full_name, role, active FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row || !(int)$row['active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your account is no longer active. Contact an administrator.', 'warn');
        redirect('login.php');
    }
    $_SESSION['user']['role'] = $u['role'] = $row['role'];
    $_SESSION['user']['name'] = $u['name'] = $row['full_name'];
    $_SESSION['last_seen'] = time();
    return $u;
}
function require_admin(): array
{
    $u = require_login();
    if (role_rank($u['role']) < 2) {   // ADMIN or SUPER ADMIN only
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access restricted</title>' . brand_head()
            . '<script>try{var t=localStorage.getItem("nedco-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}</script>'
            . '<link rel="stylesheet" href="assets/style.css?v=10"></head><body class="auth"><main class="authmain" style="min-height:100vh"><div class="authcard">'
            . brand_logo() . '<h1>Access restricted</h1><p class="desc">Only administrators can open this page.</p>'
            . '<div><a class="btn dark" href="index.php">Back to the dashboard</a></div></div></main></body></html>');
    }
    return $u;
}

/* ---------- sign-in protection ----------
 * 1) Progressive ACCOUNT lock-out (per user, stored on the users row, decided on the server):
 *      ACTIVE ──3 consecutive failures──▶ LOCKED_1H ──(expires)──next failure──▶ LOCKED_2H ──(expires)──next failure──▶ DISABLED
 *    A successful sign-in, or an administrator unlocking / re-enabling the account, returns it to ACTIVE and clears the counters.
 * 2) IP throttle (unchanged idea): too many failed sign-ins from one IP address in a short window. It protects
 *    against guessing across many usernames, which an account lock cannot (unknown usernames have no account to lock).
 */
const LOGIN_MSG_FAIL     = 'That username and password do not match.';
const LOGIN_MSG_LOCKED   = 'Your account is temporarily locked due to repeated failed login attempts. Please try again after the lock period expires or contact an administrator.';
const LOGIN_MSG_DISABLED = 'Your account has been disabled. Please contact an administrator.';

/** Lock-out settings (defaults = the agreed policy: 3 failures → 1 h, then 2 h, then disabled). */
function lock_policy(): array
{
    return ['threshold' => max(1, (int)cfg('lockout_threshold', 3)),
            'l1' => max(1, (int)cfg('lockout_level1_minutes', 60)),
            'l2' => max(1, (int)cfg('lockout_level2_minutes', 120))];
}

/** ACTIVE | LOCKED_1H | LOCKED_2H | DISABLED, derived from the stored columns and the clock.
 *  An expired lock reads as ACTIVE, but users.lock_level is kept so the NEXT failure escalates. */
function account_status(array $u, ?int $now = null): string
{
    $now ??= time();
    if (!(int)$u['active']) return 'DISABLED';
    $lvl = (int)($u['lock_level'] ?? 0);
    if ($lvl > 0 && !empty($u['locked_until']) && strtotime((string)$u['locked_until']) > $now) return $lvl >= 2 ? 'LOCKED_2H' : 'LOCKED_1H';
    return 'ACTIVE';
}

/** Too many failed sign-ins from this IP address in the window? (4× max_failed_logins, because an office shares one public IP.) */
function ip_throttled(): bool
{
    $since = date('Y-m-d H:i:s', time() - (int)cfg('lockout_minutes', 15) * 60);
    $st = db()->prepare('SELECT COUNT(*) FROM auth_log WHERE success = 0 AND created_at >= ? AND ip = ?');
    $st->execute([$since, client_ip()]);
    return (int)$st->fetchColumn() >= (int)cfg('max_failed_logins', 5) * 4;
}
function log_auth(?int $userId, string $username, bool $ok, ?string $at = null): void
{
    db()->prepare('INSERT INTO auth_log (user_id, username, ip, user_agent, success, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, mb_substr($username, 0, 60), client_ip(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $ok ? 1 : 0, $at ?? date('Y-m-d H:i:s')]);
}
/** Audit trail of lock-out changes. event: lock_1h | lock_2h | disabled | unlock | enable. actor = administrator username, NULL = the system. */
function log_lock_event(?int $userId, string $username, string $event, ?string $actor = null, ?string $at = null): void
{
    db()->prepare('INSERT INTO account_lock_log (user_id, username, event, actor, ip, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, mb_substr($username, 0, 60), $event, $actor === null ? null : mb_substr($actor, 0, 60), client_ip(), $at ?? date('Y-m-d H:i:s')]);
}
/** Back to ACTIVE with a clean slate (used by successful sign-in code paths and by the administrator's unlock / enable). */
function account_clear_lock_state(int $userId): void
{
    db()->prepare('UPDATE users SET failed_count = 0, lock_level = 0, locked_at = NULL, locked_until = NULL WHERE id = ?')->execute([$userId]);
}

/** Record one failed password for an ACTIVE, not-currently-locked account and apply the policy. Returns the message to show.
 *  Must run inside attempt_login()'s transaction (the users row is already locked FOR UPDATE). */
function register_failed_login(array $u, string $at, int $now): string
{
    $pol = lock_policy(); $id = (int)$u['id']; $lvl = (int)$u['lock_level'];
    if ($lvl >= 2) {            // the 2-hour lock has expired and the user failed again → disable until an administrator re-enables
        db()->prepare('UPDATE users SET active = 0, lock_level = 3, failed_count = 0, locked_at = ?, locked_until = NULL WHERE id = ?')->execute([$at, $id]);
        log_lock_event($id, $u['username'], 'disabled', null, $at);
        return LOGIN_MSG_DISABLED;
    }
    if ($lvl === 1) {           // the 1-hour lock has expired and the user failed again → 2 hours
        $until = date('Y-m-d H:i:s', $now + $pol['l2'] * 60);
        db()->prepare('UPDATE users SET lock_level = 2, failed_count = 0, locked_at = ?, locked_until = ? WHERE id = ?')->execute([$at, $until, $id]);
        log_lock_event($id, $u['username'], 'lock_2h', null, $at);
        return LOGIN_MSG_LOCKED;
    }
    $n = (int)$u['failed_count'] + 1;
    if ($n >= $pol['threshold']) {   // 3rd consecutive failure → 1 hour
        $until = date('Y-m-d H:i:s', $now + $pol['l1'] * 60);
        db()->prepare('UPDATE users SET lock_level = 1, failed_count = 0, locked_at = ?, locked_until = ? WHERE id = ?')->execute([$at, $until, $id]);
        log_lock_event($id, $u['username'], 'lock_1h', null, $at);
        return LOGIN_MSG_LOCKED;
    }
    db()->prepare('UPDATE users SET failed_count = ? WHERE id = ?')->execute([$n, $id]);
    return LOGIN_MSG_FAIL;
}

function attempt_login(string $username, string $password): string|true
{
    if ($username === '' || $password === '') return 'Enter your username and password.';
    if (ip_throttled()) return 'Too many failed attempts. Wait ' . (int)cfg('lockout_minutes', 15) . ' minutes and try again.';
    $pdo = db();
    $pdo->beginTransaction();   // the users row is locked until commit, so parallel guesses cannot dodge the counter
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE username = ? FOR UPDATE');
        $st->execute([$username]);
        $u = $st->fetch();
        $now = time(); $at = date('Y-m-d H:i:s', $now);
        if (!$u) { log_auth(null, $username, false, $at); $pdo->commit(); return LOGIN_MSG_FAIL; }

        $state = account_status($u, $now);
        if ($state === 'LOCKED_1H' || $state === 'LOCKED_2H') { $pdo->commit(); return LOGIN_MSG_LOCKED; }   // refused before the password is even checked; not counted

        $ok = password_verify($password, (string)$u['password_hash']);
        if ($state === 'DISABLED') {   // only someone who knows the password learns the account is disabled
            log_auth(null, $username, false, $at);
            $pdo->commit();
            return $ok ? LOGIN_MSG_DISABLED : LOGIN_MSG_FAIL;
        }
        if (!$ok) {
            log_auth((int)$u['id'], $u['username'], false, $at);
            $msg = register_failed_login($u, $at, $now);
            $pdo->commit();
            return $msg;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        // Success: reset the progressive state.
        $pdo->prepare('UPDATE users SET last_login_at = ?, failed_count = 0, lock_level = 0, locked_at = NULL, locked_until = NULL WHERE id = ?')->execute([$at, $u['id']]);
        log_auth((int)$u['id'], $u['username'], true, $at);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['full_name'], 'role' => $u['role']];
    $_SESSION['last_seen'] = time();
    return true;
}
