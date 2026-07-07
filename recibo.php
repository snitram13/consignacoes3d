<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recibo_view.php';
$u = require_login();

$st = db()->prepare('
    SELECT m.*, c.name AS c_name, c.nif AS c_nif, c.phone AS c_phone, c.email AS c_email, c.id AS c_id
    FROM movements m
    JOIN clients c ON c.id = m.client_id
    WHERE m.id = ? AND c.user_id = ?
');
$st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
$m = $st->fetch();
if (!$m) redirect('index.php');

$sti = db()->prepare('SELECT * FROM movement_items WHERE movement_id = ? ORDER BY id');
$sti->execute([$m['id']]);
$items = $sti->fetchAll();

$vendidos  = array_values(array_filter($items, fn($i) => $i['kind'] === 'vendido'));
$repostos  = array_values(array_filter($items, fn($i) => $i['kind'] === 'reposto'));
$stockItems = array_values(array_filter($items, fn($i) => $i['kind'] === 'stock'));
$entregues = array_values(array_filter($items, fn($i) => $i['kind'] === 'entregue'));

$rate = (float)$m['comm_rate'];
$isAcerto = $m['type'] === 'acerto';
$titulo = $isAcerto ? 'Recibo de Consignação · Acerto' : 'Recibo de Consignação · Entrega';

/* email com o conteúdo do recibo */
$linhas = [];
if ($isAcerto) {
    if ($vendidos) {
        $linhas[] = 'VENDIDO NESTA VISITA';
        foreach ($vendidos as $i) $linhas[] = '  ' . $i['qty'] . '× ' . $i['name'] . ': ' . fmt($i['qty'] * $i['price']);
        $linhas[] = 'Total vendido: ' . fmt($m['total_sold']);
        $linhas[] = 'Comissão (' . comm_pct($rate) . '%): ' . fmt($m['commission_value']);
        $linhas[] = '→ Recebido: ' . fmt($m['net_value']);
        $linhas[] = '';
    }
    if ($repostos) {
        $linhas[] = 'REPOSIÇÃO NESTA VISITA';
        foreach ($repostos as $i) $linhas[] = '  ' . $i['qty'] . '× ' . $i['name'] . ' = ' . fmt($i['qty'] * $i['price']);
        $linhas[] = '';
    }
    if ($stockItems) {
        $linhas[] = 'STOCK QUE PERMANECE EM CONSIGNAÇÃO';
        foreach ($stockItems as $i) $linhas[] = '  ' . $i['qty'] . '× ' . $i['name'] . ' = ' . fmt($i['qty'] * $i['price']);
    }
} else {
    $linhas[] = 'PRODUTOS ENTREGUES EM CONSIGNAÇÃO';
    foreach ($entregues as $i) $linhas[] = '  ' . $i['qty'] . '× ' . $i['name'] . ' = ' . fmt($i['qty'] * $i['price']);
}
$body = $titulo . "\nNº " . $m['rec_id'] . ' · ' . fmt_date($m['mov_date']) . "\n\n" .
    "Fornecedor: " . ($u['name'] ?: '—') . ' (NIF ' . ($u['nif'] ?: '—') . ")\n" .
    "Cliente: " . $m['c_name'] . ' (NIF ' . ($m['c_nif'] ?: '—') . ")\n\n" .
    implode("\n", $linhas);
$mailto = 'mailto:' . rawurlencode($m['c_email'] ?? '') .
    '?subject=' . rawurlencode($titulo . ' — ' . $m['c_name'] . ' — Nº ' . $m['rec_id']) .
    '&body=' . rawurlencode($body) .
    ($u['email'] ? '&cc=' . rawurlencode($u['email']) : '');

/* ── WhatsApp: link direto para o número da ficha do cliente, com a ligação do recibo ── */
$reciboUrl = recibo_public_url((int)$m['id'], $u);
$waNumber  = wa_number($m['c_phone'] ?? '');
$remetente = $u['brand'] ?: ($u['name'] ?: APP_NAME);
$waText    = 'Olá ' . $m['c_name'] . "! 👋\n"
    . 'Segue o recibo da consignação — Nº ' . $m['rec_id'] . ' (' . fmt_date($m['mov_date']) . ").\n"
    . 'Ver e guardar o PDF: ' . $reciboUrl . "\n\n"
    . $remetente;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= esc($isAcerto ? 'Recibo de acerto' : 'Recibo de entrega') ?> · <?= esc($m['rec_id']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="recibo-page">
  <header class="topbar recibo-header">
    <a class="topbar-back" href="cliente.php?id=<?= (int)$m['c_id'] ?>">‹ Fechar</a>
    <div class="topbar-title"><?= esc($isAcerto ? 'Recibo de acerto' : 'Recibo de entrega') ?></div>
  </header>
  <main class="recibo-scroll">
    <?= via($m, $u, $vendidos, $repostos, $stockItems, $entregues, $rate, $titulo, 'Via do Cliente', 'via--cliente') ?>
    <div class="via-cut">··············· cortar ·······················</div>
    <?= via($m, $u, $vendidos, $repostos, $stockItems, $entregues, $rate, $titulo, 'Via do Fornecedor', 'via--fornecedor') ?>
  </main>
  <footer class="recibo-actions">
    <button class="btn btn-secondary" id="print-btn" onclick="imprimirRecibo()">🖨️ Imprimir / PDF</button>
    <button class="btn btn-whatsapp" id="wa-btn" onclick="enviarWhatsApp()">📲 Enviar por WhatsApp</button>
    <a class="btn btn-primary btn-wide" href="cliente.php?id=<?= (int)$m['c_id'] ?>">✓ Concluir</a>
  </footer>
</div>
<div id="toast" style="position:fixed;left:50%;bottom:90px;transform:translateX(-50%);max-width:88%;background:#1a1a1a;color:#fff;padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.4;box-shadow:0 8px 30px rgba(0,0,0,.3);z-index:9999;display:none;text-align:center"></div>
<script>
const WA_NUMBER  = <?= json_encode($waNumber) ?>;   // número do cliente já normalizado (só dígitos + indicativo)
const WA_TEXT    = <?= json_encode($waText) ?>;      // mensagem com o link do recibo
const RECIBO_URL = <?= json_encode($reciboUrl) ?>;

/* mensagem visível no ecrã (não depende de alert, que pode estar bloqueado) */
let toastTimer = null;
function toast(msg, ms = 4000) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.style.display = 'none'; }, ms);
}

/* impressão / guardar como PDF — caminho nativo, funciona offline */
function imprimirRecibo() {
  try {
    toast('A abrir a janela de impressão… escolha "Guardar como PDF" se quiser o ficheiro.', 5000);
    window.print();
  } catch (e) {
    toast('O seu navegador bloqueou a impressão. Use o menu do navegador → Imprimir.', 6000);
  }
}

/* Envio pelo WhatsApp: abre a conversa no número da ficha do cliente com uma
   mensagem já preenchida + o link do recibo (o cliente abre e guarda o PDF).
   Se o cliente não tiver telefone, abre o WhatsApp para escolher o contacto. */
function enviarWhatsApp() {
  const base = WA_NUMBER ? ('https://wa.me/' + WA_NUMBER) : 'https://wa.me/';
  const url  = base + '?text=' + encodeURIComponent(WA_TEXT);
  if (!WA_NUMBER) {
    toast('Este cliente não tem telefone na ficha — escolha o contacto no WhatsApp. O link do recibo já vai na mensagem.', 7000);
  } else {
    toast('A abrir o WhatsApp do cliente com o link do recibo…', 5000);
  }
  window.open(url, '_blank');
}
</script>
</body>
</html>
