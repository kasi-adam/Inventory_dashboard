<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'add') {
        $un = strtolower(trim((string)($_POST['username'] ?? '')));
        $fn = trim((string)($_POST['full_name'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        // The role is validated on the server against the signed-in user's own (database) role. An unknown value means an ordinary user.
        $want = (string)($_POST['role'] ?? '');
        $role = in_array($want, [ROLE_ADMIN, ROLE_SUPER], true) ? $want : ROLE_USER;
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $un)) flash('Usernames need 3–60 letters, numbers, dots, dashes or underscores.', 'err');
        elseif ($fn === '') flash('Enter the person’s full name.', 'err');
        elseif (strlen($pw) < 10) flash('Passwords need at least 10 characters.', 'err');
        elseif (!account_role_creatable($me, $role)) {   // e.g. an Administrator submitting role=super_admin by hand
            flash('You are not allowed to create an account with that role.', 'err');
            log_lock_event(null, $un, 'denied_add', $me['username']);
        } else {
            try {
                db()->prepare('INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,?)')->execute([$un, mb_substr($fn, 0, 120), password_hash($pw, PASSWORD_DEFAULT), $role]);
                log_lock_event((int)db()->lastInsertId(), $un, 'add_' . $role, $me['username']);
                flash("Added $un. Share the password with them privately; they can change it after signing in.");
            } catch (PDOException) { flash('That username is already taken.', 'err'); }
        }
    } elseif ($id && in_array($act, ACCOUNT_ACTIONS, true)) {
        // Every change to another account goes through the one server-side policy in app/authz.php. It re-reads both
        // accounts' roles from the database, so nothing the browser sends (ids, roles, hidden fields) can raise anyone's privileges.
        [$ok, $msg] = account_action_apply($me, $id, $act, $act === 'reset' ? (string)($_POST['password'] ?? '') : null);
        flash($msg, $ok ? 'ok' : 'err');
    }
    redirect('users.php');
}

$tab = q('tab') === 'activity' ? 'activity' : 'users';
$users = db()->query('SELECT id, username, full_name, role, active, created_at, last_login_at, lock_level, locked_until FROM users ORDER BY active DESC, full_name')->fetchAll();
$activeUsers = array_filter($users, fn($u) => $u['active']);
$admins = array_filter($activeUsers, fn($u) => role_rank($u['role']) >= 2);   // ADMIN and SUPER ADMIN
$activeSupers = count(array_filter($activeUsers, fn($u) => $u['role'] === ROLE_SUPER));
$actor = ['id' => $me['id'], 'role' => $me['role'], 'active' => 1];   // $me['role'] was just re-read from the database by require_admin()
if ($tab === 'activity') {
    $log = db()->query('SELECT username, ip, user_agent, success, created_at FROM auth_log ORDER BY id DESC LIMIT 50')->fetchAll();
    $since = date('Y-m-d H:i:s', time() - 7 * 86400);
    $st = db()->prepare('SELECT SUM(success = 1) ok, SUM(success = 0) bad, COUNT(DISTINCT CASE WHEN success = 1 THEN username END) people FROM auth_log WHERE created_at >= ?');
    $st->execute([$since]); $wk = $st->fetch();
}
function browser(string $ua): string
{
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $k => $n) if (str_contains($ua, $k)) {
        $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'Mac') ? 'Mac' : (str_contains($ua, 'Linux') ? 'Linux' : ''))));
        return $n . ($os ? " · $os" : '');
    }
    return $ua === '' ? '—' : 'Other';
}

page_start('User Management', 'users');
?>
  <section class="hello"><div><h1>User Management</h1><p>Control who can use the dashboard, and review how it is being accessed.</p></div></section>
  <div class="subtabs" role="tablist">
    <a href="users.php" role="tab" aria-selected="<?= $tab === 'users' ? 'true' : 'false' ?>">Users</a>
    <a href="users.php?tab=activity" role="tab" aria-selected="<?= $tab === 'activity' ? 'true' : 'false' ?>">Security Activity</a>
  </div>

<?php if ($tab === 'users'): ?>
  <section class="cards three">
    <div class="card"><div class="hd">Active users<?= icon('users') ?></div><div class="row"><div class="v"><?= count($activeUsers) ?></div></div><div class="s">Can sign in today</div></div>
    <div class="card"><div class="hd">Administrators<?= icon('shield') ?></div><div class="row"><div class="v"><?= count($admins) ?></div></div><div class="s">Can edit assets and manage users</div></div>
    <div class="card"><div class="hd">Disabled<?= icon('x') ?></div><div class="row"><div class="v"><?= count($users) - count($activeUsers) ?></div></div><div class="s">Cannot sign in</div></div>
  </section>

  <details class="panel addbox">
    <summary class="btn dark">Add a user</summary>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <p class="desc">Users can view every dashboard page, search, filter and export CSVs. Administrators can also add and edit assets, import data and manage users.</p>
      <div class="fgrid">
        <label for="un"><span>Username</span><input id="un" name="username" required pattern="[A-Za-z0-9._-]{3,60}" autocomplete="off" placeholder="e.g. a.mensah"></label>
        <label for="fn"><span>Full name</span><input id="fn" name="full_name" required placeholder="e.g. Ama Mensah"></label>
        <label for="pw"><span>Temporary password (10+ characters)</span><input id="pw" name="password" type="password" minlength="10" required autocomplete="new-password"></label>
        <label for="role"><span>Role</span><select id="role" name="role"><option value="<?= ROLE_USER ?>">User</option><?php if (account_role_creatable($me, ROLE_ADMIN)): ?><option value="<?= ROLE_ADMIN ?>">Administrator</option><?php endif; ?><?php if (account_role_creatable($me, ROLE_SUPER)): ?><option value="<?= ROLE_SUPER ?>">Super Admin</option><?php endif; ?></select></label>
      </div>
      <div class="formbar"><button class="btn dark">Add user</button><span class="muted">Share the password privately. They can change it after signing in.</span></div>
    </form>
  </details>

  <section class="panel" style="margin-top:14px">
    <div class="ph"><h2><?= icon('users') ?>Users</h2><span class="muted"><?= count($users) ?> in total</span></div>
    <div class="tbl"><table>
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Manage</th></tr></thead><tbody>
      <?php foreach ($users as $u): $self = (int)$u['id'] === $me['id']; ?>
        <tr class="<?= $u['active'] ? '' : 'off' ?>">
          <td><span class="who"><span class="av s"><?= e(initials($u['full_name'])) ?></span><span><?= e($u['full_name']) ?><?= $self ? ' <small class="muted">(you)</small>' : '' ?><br><small class="muted"><?= e($u['username']) ?></small></span></span></td>
          <td><?= role_badge($u['role']) ?></td>
          <?php $state = account_status($u); ?>
          <td><?= match ($state) {
              'LOCKED_1H' => pill('Locked — 1 hour', 'warn') . '<br><small class="muted">until ' . e(date('j M, H:i', strtotime($u['locked_until']))) . '</small>',
              'LOCKED_2H' => pill('Locked — 2 hours', 'warn') . '<br><small class="muted">until ' . e(date('j M, H:i', strtotime($u['locked_until']))) . '</small>',
              'DISABLED'  => pill('Disabled', 'warn'),
              default     => pill('Active', 'good') } ?></td>
          <td><?= $u['last_login_at'] ? e(date('j M Y, H:i', strtotime($u['last_login_at']))) : '<span class="muted">Never</span>' ?></td>
          <td class="mcell">
            <?php
              // Same policy function the server enforces on every POST: a control is drawn only if the server would accept it.
              $can = fn(string $act) => account_action_denied($actor, $u, $act, $activeSupers) === null;
              $locked = $state === 'LOCKED_1H' || $state === 'LOCKED_2H';
              $roleBtn = $can('demote') ? ['demote', 'Make ordinary user', 'Change ' . $u['full_name'] . ' to an ordinary user? They will lose administrator access.']
                : ($can('promote') ? ['promote', 'Make administrator', 'Make ' . $u['full_name'] . ' an administrator?']
                : ($can('demote_super') ? ['demote_super', 'Remove Super Admin role', 'Change ' . $u['full_name'] . ' from Super Admin to administrator?']
                : null));
              $superBtn = $can('promote_super') ? ['promote_super', 'Make Super Admin', 'Make ' . $u['full_name'] . ' a Super Admin?'] : null;
              $anyControl = $roleBtn || $superBtn || $can('disable') || $can('enable') || ($locked && $can('unlock')) || $can('reset');
            ?>
            <?php if (!$anyControl): ?>
              <span class="muted"><?= $u['role'] === ROLE_SUPER ? 'Protected' : 'Super Admin only' ?></span>
            <?php else: ?>
            <details class="manage"><summary class="btn sm">Manage</summary>
              <div class="mbox">
                <?php if ($roleBtn || $superBtn || $can('disable') || $can('enable')): ?>
                <form method="post" class="mrow"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <?php foreach (array_filter([$roleBtn, $superBtn]) as [$ra, $rl, $rc]): ?><button class="btn sm" name="act" value="<?= e($ra) ?>" data-confirm="<?= e($rc) ?>"><?= e($rl) ?></button><?php endforeach; ?>
                  <?php if ($can('disable')): ?><button class="btn sm danger" name="act" value="disable" data-confirm="Disable <?= e($u['full_name']) ?>? They will be signed out and cannot sign in.">Disable account</button><?php endif; ?>
                  <?php if ($can('enable')): ?><button class="btn sm" name="act" value="enable">Enable account</button><?php endif; ?></form>
                <?php endif; ?>
                <?php if ($locked && $can('unlock')): ?>
                <form method="post" class="mrow"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm dark" name="act" value="unlock">Unlock account</button></form>
                <?php endif; ?>
                <?php if ($can('reset')): ?>
                <form method="post" class="mrow"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="act" value="reset">
                  <input type="password" name="password" minlength="10" required placeholder="New password (10+ characters)" autocomplete="new-password" aria-label="New password for <?= e($u['username']) ?>"><button class="btn sm dark">Reset password</button></form>
                <?php endif; ?>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="5" class="empty">No users yet. Use “Add a user” to create one.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>

<?php else: ?>
  <section class="cards two">
    <div class="card"><div class="hd">Successful sign-ins, last 7 days<?= icon('key') ?></div><div class="row"><div class="v"><?= (int)$wk['ok'] ?></div></div><div class="s"><?= (int)$wk['people'] ?> different people</div></div>
    <div class="card"><div class="hd">Failed attempts, last 7 days<?= icon('alert') ?></div><div class="row"><div class="v <?= (int)$wk['bad'] ? 't-crit' : '' ?>"><?= (int)$wk['bad'] ?></div></div><div class="s"><?= lock_policy()['threshold'] ?> failures lock an account; repeat failures extend the lock, then disable it</div></div>
  </section>

  <section class="panel" style="margin-top:14px">
    <div class="ph"><h2><?= icon('shield') ?>Security Activity</h2><span class="muted">Latest 50 sign-in attempts · <a href="security.php">Open the Security page for trends, filters and the risk indicator →</a></span></div>
    <div class="tbl"><table>
      <thead><tr><th>When</th><th>Username</th><th>Result</th><th>IP address</th><th>Browser</th></tr></thead><tbody>
      <?php foreach ($log as $l): ?>
        <tr><td><?= e(date('j M Y, H:i:s', strtotime($l['created_at']))) ?></td><td><?= e($l['username']) ?></td>
          <td><?= $l['success'] ? pill('Signed in', 'good') : pill('Failed', 'crit') ?></td><td class="mono"><?= e($l['ip']) ?></td><td><?= e(browser($l['user_agent'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="5" class="empty">No sign-in attempts have been recorded yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>
<?php endif; ?>
<?php page_end();
