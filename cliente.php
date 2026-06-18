<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$c = get_client((int)($_GET['id'] ?? 0), (int)$u['id']);
if (!$c) redirect('index.php');

/* atualizar comissão deste cliente */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comm'])) {
    $commP = str_replace(',', '.', (string)$_POST['comm']);
    if (is_numeric($commP) && $commP >= 0 && $commP <= 100) {
        $st = db()->prepare('UPDATE clients SET commission = ? WHERE id = ? AND user_id = ?');
        $st->execute([((float)$commP) / 100, $c['id'], $u['id']]);
    }
    redirect('cliente.php?id=' . (int)$c['id']);
}

$products = get_client_products((int)$c['id']);
$rate = comm_rate($c, $u);
$totalVal = 0;
foreach ($products as $p) $totalVal += $p['qty'] * $p['price'];
$totalCom = $totalVal * $rate;

$st = db()->prepare('SELECT * FROM movements WHERE client_id = ? ORDER BY id DESC');
$st->execute([$c['id']]);
$movements = $st->fetchAll();

/* corpo do email (ficha) */
$stockTxt = implode("\n", array_map(fn($p) => $p['qty'] . '× ' . $p['name'] . ' (' . fmt($p['price']) . '/un.) = ' . fmt($p['qty'] * $p['price']), $products));
$body = 'Ficha de Consignação — ' . $c['name'] . "\nData: " . fmt_date($c['last_date']) . "\n\n" .
    "--- FORNECEDOR ---\n" . ($u['name'] ?: '—') . "\nNIF: " . ($u['nif'] ?: '—') . "\nTel: " . ($u['phone'] ?: '—') . "\n\n" .
    "--- CLIENTE ---\n" . $c['name'] . "\nNIF: " . ($c['nif'] ?: '—') . "\nTel: " . ($c['phone'] ?: '—') . "\n\n" .
    "--- STOCK EM CONSIGNAÇÃO ---\n" . $stockTxt .
    "\nValor total em loja: " . fmt($totalVal) .
    "\nComissão potencial (" . comm_pct($rate) . "%): " . fmt($totalCom);
$mailto = 'mailto:' . rawurlencode($c['email'] ?? '') .
    '?subject=' . rawurlencode('Consignação ' . $c['name'] . ' — ' . fmt_date($c['last_date'])) .
    '&body=' . rawurlencode($body) .
    ($u['email'] ? '&cc=' . rawurlencode($u['email']) : '');

$pageTitle = $c['name'];
$backUrl = 'index.php';
$backLabel = 'Clientes';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="profile-head">
  <div class="avatar avatar-lg"><?= esc(mb_substr(trim($c['name']), 0, 1)) ?></div>
  <div>
    <div style="font-size:18px;font-weight:700"><?= esc($c['name']) ?></div>
    <div class="profile-chips">
      <?php if ($c['nif']): ?><span class="pchip">🪪 <?= esc($c['nif']) ?></span><?php endif; ?>
      <?php if ($c['phone']): ?><a class="pchip" href="tel:<?= esc(preg_replace('/\s+/', '', $c['phone'])) ?>">📞 <?= esc($c['phone']) ?></a><?php endif; ?>
      <?php if ($c['email']): ?><a class="pchip" href="mailto:<?= esc($c['email']) ?>">✉️ <?= esc($c['email']) ?></a><?php endif; ?>
    </div>
  </div>
</div>

<div class="section-title">Stock em consignação</div>
<div class="panel">
  <?php if ($products): ?>
    <div class="prod-header"><span class="ph1">Produto</span><span class="ph2">Qtd</span><span class="ph3">Valor</span><span class="ph4">Com.</span></div>
    <?php foreach ($products as $p): $v = $p['qty'] * $p['price']; ?>
      <div class="prod-row">
        <span class="pr1"><?= esc($p['name']) ?></span>
        <span class="pr2"><?= (int)$p['qty'] ?></span>
        <span class="pr3"><?= fmt($v) ?></span>
        <span class="pr4"><?= fmt($v * $rate) ?></span>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="hint" style="padding:8px 0;margin:0">Sem produtos em consignação.</p>
  <?php endif; ?>
</div>
<div class="mini-hero">
  <div class="mh-label">A receber (se tudo vender)</div>
  <div class="mh-value"><?= fmt($totalVal - $totalCom) ?></div>
  <div class="hero-chips">
    <span class="hero-chip">🏪 Em loja: <?= fmt($totalVal) ?></span>
    <span class="hero-chip">✂️ Comissão <?= comm_pct($rate) ?>%: <?= fmt($totalCom) ?></span>
  </div>
</div>

<?php if ($movements): ?>
<div class="section-title">Histórico</div>
<div class="panel">
  <?php foreach (array_slice($movements, 0, 15) as $m): ?>
    <div class="hist-item">
      <div class="hist-ico"><?= $m['type'] === 'acerto' ? '🤝' : '📦' ?></div>
      <div style="flex:1">
        <?php if ($m['signature']): ?><span class="badge-valid">✓ Validado</span> <?php endif; ?>
        <span class="hist-desc"><?= $m['type'] === 'acerto' ? 'Acerto · recebido ' . fmt($m['net_value']) : 'Entrega inicial' ?> · Nº <?= esc($m['rec_id']) ?></span>
        <?php if ($m['signature']): ?><br><img class="hist-sig" src="<?= esc($m['signature']) ?>" alt="assinatura"><?php endif; ?>
        <br><a class="hist-recibo-btn" href="recibo.php?id=<?= (int)$m['id'] ?>">🧾 Ver recibo</a>
      </div>
      <span class="hist-date"><?= fmt_date($m['mov_date']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-title">Ações</div>
<a class="btn btn-secondary" href="cliente_editar.php?id=<?= (int)$c['id'] ?>">✏️ Editar dados do cliente</a>
<details class="comm-edit">
  <summary class="btn btn-secondary">✏️ Comissão deste cliente: <?= comm_pct($rate) ?>%</summary>
  <form method="post" class="comm-form">
    <?= csrf_field() ?>
    <div class="field" style="margin:10px 0"><label>Nova comissão (%)</label>
      <input type="number" name="comm" min="0" max="100" step="0.5" inputmode="decimal" value="<?= esc(rtrim(rtrim(number_format($rate * 100, 1, '.', ''), '0'), '.')) ?>">
    </div>
    <button class="btn btn-primary" type="submit">Guardar comissão</button>
  </form>
</details>
<a class="btn btn-primary" href="acerto.php?id=<?= (int)$c['id'] ?>">📤 Registar visita / acerto</a>
<a class="btn btn-secondary" href="<?= esc($mailto) ?>">📧 Enviar ficha por email</a>
<form method="post" action="excluir_cliente.php" onsubmit="return confirm('Eliminar <?= esc($c['name']) ?>?\nEsta ação não pode ser desfeita.')">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <button class="btn btn-danger" type="submit">🗑️ Eliminar cliente</button>
</form>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
