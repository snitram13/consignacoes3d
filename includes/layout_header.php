<?php
/* Espera: $pageTitle (string), $backUrl (string|null), $backLabel (string|null)
   Opcional: $topbarAction (HTML extra à direita) */
$u = current_user();
$brand = $u && $u['brand'] ? $u['brand'] : APP_NAME;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#1a1a1a">
<title><?= esc($pageTitle ?? APP_NAME) ?> · <?= esc($brand) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page">
  <header class="topbar">
    <?php if (!empty($backUrl)): ?>
      <a class="topbar-back" href="<?= esc($backUrl) ?>">‹ <?= esc($backLabel ?? 'Voltar') ?></a>
    <?php endif; ?>
    <div class="topbar-title"><?= esc($pageTitle ?? '') ?></div>
    <?= $topbarAction ?? '' ?>
  </header>
  <main class="content">
