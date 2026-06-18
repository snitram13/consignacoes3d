<?php
require_once __DIR__ . '/includes/auth.php';

if (!db_installed()) redirect('install.php');
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $pass     = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        redirect('index.php');
    }
    $error = 'Utilizador ou palavra-passe incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Entrar · <?= esc(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="centered-page">
<div class="login-card">
  <div class="login-icon">📦</div>
  <h2><?= esc(APP_NAME) ?></h2>
  <p>Entre para aceder aos seus dados.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Utilizador</label><input type="text" name="username" required autofocus autocomplete="username"></div>
    <div class="field"><label>Palavra-passe</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary" type="submit">Entrar</button>
  </form>
</div>
</body>
</html>
