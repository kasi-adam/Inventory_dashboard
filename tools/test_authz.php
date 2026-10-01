<?php
// Runs the Super Admin / Admin / User authorization scenarios against the REAL application code and database, using throw-away
// accounts (zz_sa, zz_sa2, zz_ad1, zz_ad2, zz_us1, zz_us2), then deletes them. Run from a terminal on the server:
//   php tools/test_authz.php
// It calls the same server-side functions users.php calls for every POST (account_action_apply, account_role_creatable) and the
// real attempt_login() / require_login(), so a "crafted request" here is exactly what a crafted browser request reaches.
// "Time passing" for the lock-out is simulated by moving locked_until into the past, as tools/test_lockout.php does.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();   // lets session_regenerate_id() work while we print results
require __DIR__ . '/../app/bootstrap.php';
$CONFIG['allow_multiple_super_admins'] = false;   // the default policy; a later block switches it on to test that mode
$CONFIG['admins_can_grant_admin'] = true;

$PW = 'correct-horse-battery'; $pdo = db(); $bad = 0;
$names = ['zz_sa', 'zz_sa2', 'zz_ad1', 'zz_ad2', 'zz_us1', 'zz_us2'];
$in = "'" . implode("','", $names) . "'";
$cleanup = function () use ($pdo, $in) {
    foreach (['users', 'auth_log'] as $tb) $pdo->exec("DELETE FROM $tb WHERE username IN ($in)");
    $pdo->exec("DELETE FROM account_lock_log WHERE username IN ($in) OR actor IN ($in)");
};
$t = function (string $name, bool $ok) use (&$bad) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; if (!$ok) $bad++; };
$mk = function (string $u, string $role) use ($pdo, $PW): int {
    $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$u, 'Test ' . $u, password_hash($PW, PASSWORD_DEFAULT), $role]);
    return (int)$pdo->lastInsertId();
};
$row    = fn(int $id) => $pdo->query("SELECT * FROM users WHERE id = $id")->fetch();
$snap   = fn(int $id) => implode('|', array_map(fn($k) => (string)$row($id)[$k], ['role', 'active', 'lock_level', 'failed_count', 'locked_until', 'password_hash']));
$status = fn(int $id) => account_status($row($id));
$do     = fn(int $by, int $on, string $act, ?string $pw = null) => account_action_apply(['id' => $by, 'username' => $row($by)['username']], $on, $act, $pw);
$expire = fn(int $id) => $pdo->exec("UPDATE users SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = $id");
$fail   = fn(string $u) => attempt_login($u, 'wrong-password');
$denied = fn(array $r) => $r[0] === false;
$events = fn(string $ev, string $actor) => (int)$pdo->query("SELECT COUNT(*) FROM account_lock_log WHERE event = '$ev' AND actor = '$actor'")->fetchColumn();

$cleanup();
$sa  = $mk('zz_sa',  ROLE_SUPER); $ad1 = $mk('zz_ad1', ROLE_ADMIN); $ad2 = $mk('zz_ad2', ROLE_ADMIN);
$us1 = $mk('zz_us1', ROLE_USER);  $us2 = $mk('zz_us2', ROLE_USER);
$ALL = ACCOUNT_ACTIONS;

echo "--- Access by role (real sign-in, real require_login) ---\n";
$t('1  Super Admin signs in; is_admin() and is_super_admin() are true', attempt_login('zz_sa', $PW) === true && require_login()['role'] === ROLE_SUPER && is_admin() && is_super_admin());
$t('2  Admin signs in; is_admin() true, is_super_admin() false', attempt_login('zz_ad1', $PW) === true && require_login()['role'] === ROLE_ADMIN && is_admin() && !is_super_admin());
$t('3  User signs in; is_admin() false, is_super_admin() false (admin pages answer 403)', attempt_login('zz_us1', $PW) === true && require_login()['role'] === ROLE_USER && !is_admin() && !is_super_admin());
$_SESSION['user'] = ['id' => $us1, 'username' => 'zz_us1', 'name' => 'x', 'role' => ROLE_SUPER]; $_SESSION['last_seen'] = time();   // forged session role
$u = require_login();
$t('4  forged session role "super_admin" on a User is overwritten from the database', $u['role'] === ROLE_USER && !is_admin() && !is_super_admin());

echo "--- Admin tries to manage a Super Admin (every action, crafted outside the UI) ---\n";
$before = $snap($sa); $allDenied = true;
foreach ($ALL as $act) $allDenied = $allDenied && $denied($do($ad1, $sa, $act, $act === 'reset' ? 'a-brand-new-password' : null));
$t('5  every action by an Admin on the Super Admin is denied', $allDenied);
$t('6  the Super Admin row is unchanged (role, active, lock state, password)', $snap($sa) === $before);
$t('7  denied attempts are recorded in account_lock_log', $events('denied_disable', 'zz_ad1') === 1 && $events('denied_demote', 'zz_ad1') === 1 && $events('denied_reset', 'zz_ad1') === 1);

echo "--- Admin tries to manage another Admin ---\n";
$before = $snap($ad2); $allDenied = true;
foreach ($ALL as $act) $allDenied = $allDenied && $denied($do($ad1, $ad2, $act, $act === 'reset' ? 'a-brand-new-password' : null));
$t('8  every action by an Admin on another Admin is denied (disable, enable, unlock, promote, demote, reset...)', $allDenied);
$t('9  the other Admin row is unchanged', $snap($ad2) === $before);

echo "--- User tries to manage accounts ---\n";
$before = $snap($ad1) . $snap($sa) . $snap($us2); $allDenied = true;
foreach ([$sa, $ad1, $us2] as $target) foreach ($ALL as $act) $allDenied = $allDenied && $denied($do($us1, $target, $act, $act === 'reset' ? 'a-brand-new-password' : null));
$t('10 every action by a User on Super Admin / Admin / User is denied', $allDenied);
$t('11 nothing changed', $snap($ad1) . $snap($sa) . $snap($us2) === $before);
$t('12 a User may not create any account', !account_role_creatable(['role' => ROLE_USER], ROLE_USER) && !account_role_creatable(['role' => ROLE_USER], ROLE_ADMIN));

echo "--- Self-protection and role creation ---\n";
$t('13 Admin cannot disable or demote themselves', $denied($do($ad1, $ad1, 'disable')) && $denied($do($ad1, $ad1, 'demote')));
$t('14 Super Admin cannot disable or demote themselves', $denied($do($sa, $sa, 'disable')) && $denied($do($sa, $sa, 'demote_super')) && $row($sa)['role'] === ROLE_SUPER && (int)$row($sa)['active'] === 1);
$t('15 an Admin cannot create Super Admin accounts; a Super Admin cannot either while multiple Super Admins are off', !account_role_creatable(['role' => ROLE_ADMIN], ROLE_SUPER) && !account_role_creatable(['role' => ROLE_SUPER], ROLE_SUPER));
$t('16 Admin may create Users and (existing behaviour) Admins; Super Admin may create both', account_role_creatable(['role' => ROLE_ADMIN], ROLE_USER) && account_role_creatable(['role' => ROLE_ADMIN], ROLE_ADMIN) && account_role_creatable(['role' => ROLE_SUPER], ROLE_ADMIN));
$t('17 unknown action and unknown account are rejected', $denied($do($sa, $us1, 'make_me_root')) && $denied($do($sa, 999999999, 'unlock')));
$pdo->exec("UPDATE users SET role = 'super_admi' WHERE id = $us2");   // a truncated / unrecognised role value
$t('18 an unrecognised role has no privileges and cannot be managed (fails closed)', role_rank('super_admi') === 0 && $denied($do($sa, $us2, 'disable')) && $denied($do($us2, $us1, 'disable')));
$pdo->exec("UPDATE users SET role = 'viewer' WHERE id = $us2");

echo "--- Super Admin recovery of an Admin (real lock-out, Stage 1 rules unchanged) ---\n";
$fail('zz_ad2'); $fail('zz_ad2'); $r3 = $fail('zz_ad2');
$t('19 three failures lock the Admin (1 hour): Stage 1 lock-out still works', $r3 === LOGIN_MSG_LOCKED && $status($ad2) === 'LOCKED_1H');
$t('20 another Admin cannot unlock them; still LOCKED_1H', $denied($do($ad1, $ad2, 'unlock')) && $status($ad2) === 'LOCKED_1H');
$t('21 Super Admin unlocks the Admin: ACTIVE, counters cleared, can sign in', $do($sa, $ad2, 'unlock')[0] === true && $status($ad2) === 'ACTIVE' && (int)$row($ad2)['lock_level'] === 0 && attempt_login('zz_ad2', $PW) === true);
$fail('zz_ad2'); $fail('zz_ad2'); $fail('zz_ad2'); $expire($ad2);
$t('22 1h lock expires by itself, next failure gives 2 h (Stage 1)', $status($ad2) === 'ACTIVE' && $fail('zz_ad2') === LOGIN_MSG_LOCKED && $status($ad2) === 'LOCKED_2H');
$expire($ad2);
$t('23 2h lock expires, next failure DISABLES the Admin (Stage 1)', $fail('zz_ad2') === LOGIN_MSG_DISABLED && $status($ad2) === 'DISABLED');
$t('24 another Admin cannot re-enable the disabled Admin', $denied($do($ad1, $ad2, 'enable')) && $status($ad2) === 'DISABLED');
$t('25 Super Admin re-enables the Admin: ACTIVE, lock state reset, can sign in', $do($sa, $ad2, 'enable')[0] === true && $status($ad2) === 'ACTIVE' && (int)$row($ad2)['lock_level'] === 0 && (int)$row($ad2)['failed_count'] === 0 && attempt_login('zz_ad2', $PW) === true);
$t('26 Super Admin can disable an Admin (and then they cannot sign in)', $do($sa, $ad2, 'disable')[0] === true && $status($ad2) === 'DISABLED' && attempt_login('zz_ad2', $PW) === LOGIN_MSG_DISABLED);
$do($sa, $ad2, 'enable');
$t('27 Super Admin can demote and promote an Admin', $do($sa, $ad2, 'demote')[0] === true && $row($ad2)['role'] === ROLE_USER && $do($sa, $ad2, 'promote')[0] === true && $row($ad2)['role'] === ROLE_ADMIN);
$t('28 Super Admin can reset an Admin password; the new password works', $do($sa, $ad2, 'reset', 'another-new-password')[0] === true && attempt_login('zz_ad2', 'another-new-password') === true);

echo "--- Admin manages ordinary Users ---\n";
$fail('zz_us1'); $fail('zz_us1'); $fail('zz_us1');
$t('29 a User is locked after 3 failures', $status($us1) === 'LOCKED_1H');
$t('30 Admin unlocks the User: ACTIVE and can sign in', $do($ad1, $us1, 'unlock')[0] === true && $status($us1) === 'ACTIVE' && attempt_login('zz_us1', $PW) === true);
$t('31 Admin can disable and re-enable a User', $do($ad1, $us1, 'disable')[0] === true && $status($us1) === 'DISABLED' && $do($ad1, $us1, 'enable')[0] === true && $status($us1) === 'ACTIVE');
$t('32 Admin can reset a User password', $do($ad1, $us1, 'reset', 'yet-another-password')[0] === true && attempt_login('zz_us1', 'yet-another-password') === true);
$t('33 Super Admin can disable and re-enable a User too', $do($sa, $us1, 'disable')[0] === true && $do($sa, $us1, 'enable')[0] === true);
$t('34 an Admin cannot change roles of Users beyond the existing promote, and cannot make anyone a Super Admin', $denied($do($ad1, $us1, 'demote')) && $denied($do($ad1, $us1, 'promote_super')));

echo "--- Existing authentication ---\n";
$t('35 wrong password gives the generic message; right password signs in', attempt_login('zz_us2', 'nope-nope-nope') === LOGIN_MSG_FAIL && attempt_login('zz_us2', $PW) === true);
$t('36 the Super Admin still signs in after all of the above, unchanged', attempt_login('zz_sa', $PW) === true && $row($sa)['role'] === ROLE_SUPER && (int)$row($sa)['active'] === 1);

echo "--- Audit trail ---\n";
$t('37 Super Admin unlock / enable / disable / demote / promote / pw_reset are recorded with the actor',
    $events('unlock', 'zz_sa') >= 1 && $events('enable', 'zz_sa') >= 1 && $events('admin_disable', 'zz_sa') >= 1 && $events('demote', 'zz_sa') === 1 && $events('promote', 'zz_sa') === 1 && $events('pw_reset', 'zz_sa') === 1);
$t('38 Admin actions on Users are recorded with the actor', $events('unlock', 'zz_ad1') === 1 && $events('enable', 'zz_ad1') >= 1);
$t('39 the lock-out risk score is not inflated by manual actions (only lock_1h / lock_2h / disabled count)', (int)$pdo->query("SELECT COUNT(*) FROM account_lock_log WHERE event IN ('lock_1h','lock_2h','disabled') AND actor IS NOT NULL")->fetchColumn() === 0);

echo "--- Multiple Super Admins ENABLED (non-default) ---\n";
$CONFIG['allow_multiple_super_admins'] = true;
$sa2 = $mk('zz_sa2', ROLE_SUPER);
$t('40 Super Admin can create a Super Admin; an Admin still cannot', account_role_creatable(['role' => ROLE_SUPER], ROLE_SUPER) && !account_role_creatable(['role' => ROLE_ADMIN], ROLE_SUPER));
$t('41 an Admin still cannot touch either Super Admin', $denied($do($ad1, $sa2, 'disable')) && $denied($do($ad1, $sa2, 'reset', 'a-brand-new-password')) && $denied($do($ad1, $sa2, 'promote_super')));
$t('42 Super Admins can disable / re-enable each other', $do($sa, $sa2, 'disable')[0] === true && $status($sa2) === 'DISABLED' && $do($sa, $sa2, 'enable')[0] === true && $status($sa2) === 'ACTIVE');
$t('43 a disabled Super Admin cannot act', ($do($sa, $sa2, 'disable')[0] === true) && $denied($do($sa2, $ad1, 'disable')));
$do($sa, $sa2, 'enable');
$t('44 Super Admin can make an Admin a Super Admin, and back; nobody can do it to themselves', $do($sa, $ad1, 'promote_super')[0] === true && $row($ad1)['role'] === ROLE_SUPER && $do($sa, $ad1, 'demote_super')[0] === true && $row($ad1)['role'] === ROLE_ADMIN && $denied($do($sa2, $sa2, 'demote_super')));
$CONFIG['allow_multiple_super_admins'] = false;
$t('45 switching it off again: Super Admins cannot manage each other, promote_super is refused', $denied($do($sa, $sa2, 'disable')) && $denied($do($sa, $ad1, 'promote_super')));

$cleanup();
echo $bad ? "\n$bad check(s) FAILED\n" : "\nAll checks passed\n";
exit($bad ? 1 : 0);
