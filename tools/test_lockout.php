<?php
// Runs the progressive lock-out scenarios against the REAL application code and database using a throw-away user
// (zz_lock_test), then deletes it. Run from a terminal on the server:   php tools/test_lockout.php
// "Time passing" is simulated by moving locked_until into the past in the database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../app/bootstrap.php';
$U = 'zz_lock_test'; $PW = 'correct-horse-battery'; $pdo = db(); $bad = 0;
$row = fn() => $pdo->query("SELECT * FROM users WHERE username = '$U'")->fetch();
$st  = fn() => account_status($row());
$expire = fn() => $pdo->exec("UPDATE users SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE username = '$U'");   // pretend the lock period has passed
$t = function (string $name, bool $ok) use (&$bad) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; if (!$ok) $bad++; };
$cleanup = function () use ($pdo, $U) { foreach (['users', 'auth_log', 'account_lock_log'] as $tb) $pdo->exec("DELETE FROM $tb WHERE username = '$U'"); };
$cleanup();
$pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, 'Lock Test', ?, 'viewer')")->execute([$U, password_hash($PW, PASSWORD_DEFAULT)]);
$id = (int)$row()['id'];
$fail = fn() => attempt_login($U, 'wrong-password');

$t('1  one failed attempt: generic message, ACTIVE', $fail() === LOGIN_MSG_FAIL && $st() === 'ACTIVE' && (int)$row()['failed_count'] === 1);
$t('2  two failed attempts: still ACTIVE', $fail() === LOGIN_MSG_FAIL && $st() === 'ACTIVE' && (int)$row()['failed_count'] === 2);
$t('3  third failure: LOCKED_1H', $fail() === LOGIN_MSG_LOCKED && $st() === 'LOCKED_1H');
$until = $row()['locked_until'];
$t('4  login during 1h lock refused (even correct password), expiry unchanged', attempt_login($U, $PW) === LOGIN_MSG_LOCKED && $fail() === LOGIN_MSG_LOCKED && $row()['locked_until'] === $until);
$expire();
$t('5  1h lock expires by itself: ACTIVE', $st() === 'ACTIVE');
$t('6  next failure: LOCKED_2H', $fail() === LOGIN_MSG_LOCKED && $st() === 'LOCKED_2H');
$t('7  login during 2h lock refused', attempt_login($U, $PW) === LOGIN_MSG_LOCKED);
$expire();
$t('8  2h lock expires by itself: ACTIVE', $st() === 'ACTIVE');
$t('9  next failure: DISABLED', $fail() === LOGIN_MSG_DISABLED && $st() === 'DISABLED');
$t('10 disabled account cannot log in (correct password -> disabled message; wrong -> generic)', attempt_login($U, $PW) === LOGIN_MSG_DISABLED && $fail() === LOGIN_MSG_FAIL);
account_clear_lock_state($id); $pdo->exec("UPDATE users SET active = 1 WHERE id = $id"); log_lock_event($id, $U, 'enable', 'test');
$t('12 re-enabled account: ACTIVE and can sign in', $st() === 'ACTIVE' && attempt_login($U, $PW) === true);
for ($i = 0; $i < 3; $i++) $fail();
$t('11 locked again (1h), admin unlock returns it to ACTIVE', $st() === 'LOCKED_1H' && (function () use ($id, $st, $U) { account_clear_lock_state($id); log_lock_event($id, $U, 'unlock', 'test'); return $st() === 'ACTIVE'; })());
$fail(); $fail();
$t('13 success resets progressive state', attempt_login($U, $PW) === true && (int)$row()['failed_count'] === 0 && (int)$row()['lock_level'] === 0);
$ev = $pdo->query("SELECT GROUP_CONCAT(event ORDER BY id) FROM account_lock_log WHERE username = '$U'")->fetchColumn();
$t('audit: ' . $ev, str_starts_with((string)$ev, 'lock_1h,lock_2h,disabled,enable,lock_1h,unlock'));
$cleanup();
echo $bad ? "\n$bad check(s) FAILED\n" : "\nAll checks passed\n";
exit($bad ? 1 : 0);
