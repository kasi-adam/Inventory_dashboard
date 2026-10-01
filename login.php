<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'index.php');
// Only allow local, relative redirects after sign-in.
if (!preg_match('#^/?[A-Za-z0-9_\-./]*\.php(\?[^\s]*)?$#', $next) || str_contains($next, '//')) $next = 'index.php';
if (current_user()) redirect($next);

$error = null; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $r = attempt_login($username, (string)($_POST['password'] ?? ''));
    if ($r === true) redirect($next);
    $error = $r;
}
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · <?= e(cfg('app_name')) ?></title>
<script>try{var t=localStorage.getItem("nedco-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<?= brand_head() ?>
<link rel="stylesheet" href="assets/style.css?v=11">
</head>
<body class="auth">
<div class="authwrap">
  <div class="authside">
    <svg class="authart" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="0.5" stroke-linejoin="round"><path d="M5 2h9.6L21 8.4V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M13.6 5.6 7.4 13.2h3.9l-1.3 5.2 6.4-8h-3.9l1.1-4.8z"/></g></svg>
    <div class="brand"><?= brand_logo() ?><div class="sysline"><?= brand_mark() ?><div class="brandtxt"><b><?= e(brand_product()) ?></b><small><?= e(cfg('org_line')) ?></small></div></div></div>
    <div class="authtext">
      <?= brand_mark() ?>
      <h2><?= brand_title_html() ?></h2>
      <p>Keep track of ICT assets, their condition and where they are.</p>
    </div>
    <p class="authfoot">Authorised users only.</p>
  </div>
  <main class="authmain">
    <div class="authcard">
      <h1>Sign in</h1>
      <p class="desc">Use the username and password given to you by your administrator.</p>
      <?php if ($f): ?><div class="flash <?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form" id="loginform">
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
        <label for="username"><span>Username</span><input id="username" name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
        <label for="password"><span>Password</span><input id="password" name="password" type="password" autocomplete="current-password" required></label>
        <button class="btn dark wide" type="submit">Sign in</button>
      </form>
      <p class="fine">Forgot your password? Ask an administrator to reset it.<br><!--Sign-ins are recorded.--> Repeated failed attempts will temporarily lock the account.</p>
    </div>
  </main>
</div>
<script src="assets/app.js?v=9"></script>
<script src="assets/login.js?v=9"></script>
<script src="assets/login-bg.js?v=1"></script>
</body>
</html>
