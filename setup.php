<?php
// First-run setup: creates the tables, loads the 200 assets and the first administrator.
// It only works while there are no users yet, so it is safe to leave in place, but you can delete it once you have signed in.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$msg = []; $err = null;
try {
    $pdo = db();
} catch (Throwable $ex) {
    exit('Could not connect to the database. Check the details in app/config.php. (' . e($ex->getMessage()) . ')');
}
$hasTables = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
$userCount = $hasTables ? (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
if ($userCount > 0) { http_response_code(403); exit('Setup is already done. Sign in at login.php. You can delete setup.php.'); }

function run_sql_file(PDO $pdo, string $file): void
{
    $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($file));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) $pdo->exec($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $un = strtolower(trim((string)($_POST['username'] ?? '')));
    $fn = trim((string)($_POST['full_name'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $un)) $err = 'Usernames need 3–60 letters, numbers, dots, dashes or underscores.';
    elseif ($fn === '') $err = 'Enter your full name.';
    elseif (strlen($pw) < 10) $err = 'The password needs at least 10 characters.';
    else {
        if (!$hasTables) run_sql_file($pdo, __DIR__ . '/sql/schema.sql');
        if (!empty($_POST['seed']) && (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn() === 0) run_sql_file($pdo, __DIR__ . '/sql/seed.sql');
        $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,?)")->execute([$un, $fn, password_hash($pw, PASSWORD_DEFAULT), ROLE_SUPER]);   // the first account is the Super Admin (recovery account)
        flash('Setup complete. Sign in with the account you just created.');
        redirect('login.php');
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set up · <?= e(cfg('app_name')) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<?= brand_head() ?>
<link rel="stylesheet" href="assets/style.css?v=10"></head>
<body class="login"><main class="loginbox">
  <div class="brand"><?= brand_logo() ?><div class="sysline"><?= brand_mark() ?><div class="brandtxt"><b><?= e(brand_product()) ?></b><small>First-time setup</small></div></div></div>
  <h1>Create the Super Admin</h1>
  <p class="desc">This first account is the Super Admin: it can unlock and re-enable administrators if they are ever locked out. The database connection works. <?= $hasTables ? 'The tables already exist.' : 'The tables will be created now.' ?></p>
  <?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form"><?= csrf_field() ?>
    <label for="un"><span>Username</span><input id="un" name="username" required value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username"></label>
    <label for="fn"><span>Full name</span><input id="fn" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>"></label>
    <label for="pw"><span>Password (10+ characters)</span><input id="pw" name="password" type="password" minlength="10" required autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="seed" value="1" checked> Load the 200 assets from the asset register (Sep 2026)</label>
    <button class="btn dark wide">Finish setup</button>
  </form>
</main></body></html>
