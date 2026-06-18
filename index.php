<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$st = db()->prepare('
    SELECT c.*,
           COALESCE(SUM(p.qty), 0) AS total_qty,
           COALESCE(SUM(p.qty * p.price), 0) AS total_val
    FROM clients c
    LEFT JOIN products p ON p.client_id = c.id
    WHERE c.user_id = ?
    GROUP BY c.id
    ORDER BY c.name
');
$st->execute([$u['id']]);
$clients = $st->fetchAll();

$totalVal = 0; $totalCom = 0; $active = 0;
foreach ($clients as $c) {
    $totalVal += $c['total_val'];
    $totalCom += $c['total_val'] * comm_rate($c, $u);
    if ($c['total_qty'] > 0) $active++;
}

$brand = $u['brand'] ?: 'Consignações';
$primeiroNome = $u['name'] ? explode(' ', trim($u['name']))[0] : '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#1a1a1a">
<title><?= esc($brand) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page">
  <header class="topbar topbar-home">
    <div class="brand">
      <div class="brand-logo"><?php if ($u['logo']): ?><img src="<?= esc($u['logo']) ?>" alt="logo"><?php else: ?>📦<?php endif; ?></div>
      <div>
        <div class="brand-name"><?= esc($brand) ?></div>
        <div class="brand-tagline"><?= $primeiroNome ? 'Olá, ' . esc($primeiroNome) . ' 👋' : 'Toque em Perfil para configurar' ?></div>
      </div>
    </div>
  </header>
  <main class="content">

    <?php if ($clients): ?>
    <div class="hero">
      <div class="hero-label">Valor em loja</div>
      <div class="hero-value"><?= fmt($totalVal) ?></div>
      <div class="hero-chips">
        <span class="hero-chip">👥 <?= $active ?> cliente<?= $active == 1 ? '' : 's' ?> ativo<?= $active == 1 ? '' : 's' ?></span>
        <span class="hero-chip">💰 A receber: <?= fmt($totalVal - $totalCom) ?></span>
      </div>
    </div>

    <div class="search-box">
      <input type="search" id="search" placeholder="Pesquisar cliente…" autocomplete="off">
    </div>
    <?php endif; ?>

    <div id="client-list">
    <?php if (!$clients): ?>
      <div class="empty">
        <div class="empty-icon">🗂️</div>
        <p>Ainda sem clientes.<br>Adicione o primeiro abaixo.</p>
        <a class="btn btn-primary" style="margin-top:18px;max-width:240px;margin-left:auto;margin-right:auto" href="cliente_novo.php">＋ Novo cliente</a>
      </div>
    <?php else: foreach ($clients as $c): ?>
      <a class="card card-link" href="cliente.php?id=<?= (int)$c['id'] ?>" data-name="<?= esc(mb_strtolower($c['name'])) ?>">
        <div class="card-row">
          <div class="card-main">
            <div class="avatar"><?= esc(mb_substr(trim($c['name']), 0, 1)) ?></div>
            <div>
              <div class="card-name"><?= esc($c['name']) ?></div>
              <div class="card-meta">NIF: <?= esc($c['nif'] ?: '—') ?> · <?= esc($c['phone'] ?: '—') ?></div>
            </div>
          </div>
          <?php if ($c['total_qty'] > 0): ?>
            <span class="badge badge-green"><?= (int)$c['total_qty'] ?> un.</span>
          <?php else: ?>
            <span class="badge badge-gray">Sem stock</span>
          <?php endif; ?>
        </div>
        <div class="card-foot"><span>Em loja: <strong><?= fmt($c['total_val']) ?></strong></span><span>Última visita: <?= fmt_date($c['last_date']) ?></span></div>
      </a>
    <?php endforeach; endif; ?>
    </div>
    <div class="search-empty" id="search-empty" style="display:none">
      <div class="empty"><div class="empty-icon">🔍</div><p>Nenhum cliente encontrado.</p></div>
    </div>

    <?php $tab = 'home'; require __DIR__ . '/includes/bottom_nav.php'; ?>
  </main>
</div>
<script>
const search = document.getElementById('search');
if (search) {
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    let visiveis = 0;
    document.querySelectorAll('#client-list .card-link').forEach(c => {
      const mostra = !q || c.dataset.name.includes(q);
      c.style.display = mostra ? '' : 'none';
      if (mostra) visiveis++;
    });
    document.getElementById('search-empty').style.display = visiveis ? 'none' : 'block';
  });
}
</script>
</body>
</html>
