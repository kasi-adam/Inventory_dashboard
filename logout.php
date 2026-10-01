<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('You are signed out.');
}
redirect('login.php');
