<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$me['id']]);
    $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $rep = (string)($_POST['repeat'] ?? '');
    if (!password_verify($cur, (string)$st->fetchColumn())) flash('Your current password is not right.', 'err');
    elseif (strlen($new) < 10) flash('The new password needs at least 10 characters.', 'err');
    elseif ($new !== $rep) flash('The two new passwords do not match.', 'err');
    else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        flash('Password changed.');
    }
    redirect('account.php');
}
$acct = db()->prepare('SELECT active FROM users WHERE id = ?');
$acct->execute([$me['id']]);
$isActive = (bool)$acct->fetchColumn();
$isAdmin = is_admin();   // ADMIN or SUPER ADMIN
page_start('Account', 'account');
?>
  <section class="hello"><div><h1>Account</h1><p>Your details and sign-in password.</p></div></section>
  <div class="g2">
    <section class="panel">
      <div class="ph"><h2><?= icon('users') ?>Your details</h2></div>
      <dl class="facts">
        <div><dt>Name</dt><dd><?= e($me['name']) ?></dd></div>
        <div><dt>Username</dt><dd><?= e($me['username']) ?></dd></div>
        <div><dt>Role</dt><dd><?= role_badge($me['role']) ?></dd></div>
        <div><dt>Status</dt><dd><?= $isActive ? pill('Active', 'good') : pill('Disabled', 'warn') ?></dd></div>
      </dl>
      <div class="access">
        <h3>What you can do</h3>
        <ul>
          <li>View the dashboard, assets and locations</li>
          <li>Search, filter and export CSV files</li>
          <li>Change your own password</li>
          <?php if ($isAdmin): ?>
          <li>Add, edit and delete assets</li>
          <li>Import data</li>
          <li>Manage users and review security activity</li>
          <?php if (is_super_admin()): ?><li>Unlock and re-enable administrator accounts, and change administrators’ roles</li><?php endif; ?>
          <?php endif; ?>
        </ul>
        <?php if (!$isAdmin): ?><p class="desc">Adding or editing assets, importing data and managing users are for administrators. Need one of these? Ask an administrator.</p><?php endif; ?>
      </div>
    </section>
    <form class="panel form" method="post">
      <?= csrf_field() ?>
      <div class="ph"><h2><?= icon('key') ?>Change password</h2></div>
      <label for="current"><span>Current password</span><input id="current" name="current" type="password" required autocomplete="current-password"></label>
      <label for="new"><span>New password (10+ characters)</span><input id="new" name="new" type="password" minlength="10" required autocomplete="new-password"></label>
      <label for="repeat"><span>Repeat new password</span><input id="repeat" name="repeat" type="password" minlength="10" required autocomplete="new-password"></label>
      <div class="formbar"><button class="btn dark">Change password</button></div>
    </form>
  </div>
<?php page_end();
