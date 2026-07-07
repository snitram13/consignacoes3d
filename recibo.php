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

/* ── WhatsApp: envia o PDF do recibo como ficheiro (partilha nativa no telemóvel).
      O link público fica como alternativa (computador / se a partilha falhar). ── */
$reciboUrl = recibo_public_url((int)$m['id'], $u);
$waNumber  = wa_number($m['c_phone'] ?? '');
$remetente = $u['brand'] ?: ($u['name'] ?: APP_NAME);
$waText    = 'Olá ' . $m['c_name'] . "! 👋\n"
    . 'Segue o recibo da consignação — Nº ' . $m['rec_id'] . ' (' . fmt_date($m['mov_date']) . ").\n\n"
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
const WA_TEXT    = <?= json_encode($waText) ?>;      // legenda que acompanha o PDF
const RECIBO_URL = <?= json_encode($reciboUrl) ?>;   // link público (alternativa no computador)
const PDF_NAME   = <?= json_encode('Recibo_' . preg_replace('/[^\w\-]+/', '_', $m['rec_id']) . '_' . date('d-m-Y', strtotime($m['mov_date'])) . '.pdf') ?>;

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

/* carrega a biblioteca de PDF só quando é precisa */
let html2pdfLoading = null;
function loadHtml2pdf() {
  if (window.html2pdf) return Promise.resolve();
  if (html2pdfLoading) return html2pdfLoading;
  html2pdfLoading = new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js';
    s.onload = res;
    s.onerror = () => { html2pdfLoading = null; rej(new Error('sem ligação à internet ou CDN indisponível')); };
    document.head.appendChild(s);
  });
  return html2pdfLoading;
}

/* gera o PDF (só a via do cliente) a partir do próprio recibo no ecrã */
async function gerarPDF() {
  await loadHtml2pdf();
  const el = document.querySelector('.via--cliente');
  const blob = await html2pdf().set({
    margin: 8,
    image: { type: 'jpeg', quality: 0.95 },
    html2canvas: { scale: 2, backgroundColor: '#ffffff', useCORS: true },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
  }).from(el).outputPdf('blob');
  return new File([blob], PDF_NAME, { type: 'application/pdf' });
}

/* pré-gera o PDF: o Safari recusa a partilha se houver demora após o toque */
let pdfPromise = null;
function prepararPDF() {
  if (!pdfPromise) {
    pdfPromise = gerarPDF().catch(e => {
      pdfPromise = null;
      console.warn('Não foi possível gerar o PDF do recibo:', e);
      return null;
    });
  }
  return pdfPromise;
}

function descarregarPDF(pdf) {
  const url = URL.createObjectURL(pdf);
  const a = document.createElement('a');
  a.href = url; a.download = PDF_NAME;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/* Enviar por WhatsApp: partilha o FICHEIRO PDF real. No telemóvel abre a folha
   de partilha → escolhes o WhatsApp e o contacto, e vai o PDF anexado.
   (O WhatsApp não permite anexar ficheiro E escolher o número por um site — só
   a API paga; por isso o contacto é escolhido por ti.) No computador, descarrega
   o PDF e abre o WhatsApp com o link como alternativa. */
async function enviarWhatsApp() {
  const btn = document.getElementById('wa-btn');
  const label = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ A preparar o PDF…';
  toast('A gerar o recibo em PDF…', 8000);

  const pdf = await prepararPDF();

  btn.disabled = false;
  btn.textContent = label;

  // Telemóvel: partilha o ficheiro PDF real → escolhe o WhatsApp e o contacto
  if (pdf && navigator.canShare && navigator.canShare({ files: [pdf] })) {
    try {
      await navigator.share({ files: [pdf], text: WA_TEXT });
      toast('Escolha o WhatsApp e o contacto para enviar o PDF.', 5000);
      return;
    } catch (e) {
      if (e.name === 'AbortError') return;   // o utilizador cancelou
    }
  }

  // Computador (sem partilha de ficheiros): descarrega o PDF e abre o WhatsApp
  if (pdf) {
    descarregarPDF(pdf);
    toast('PDF descarregado (' + PDF_NAME + '). A abrir o WhatsApp — arraste o PDF para a conversa.', 8000);
  } else {
    toast('Não foi possível gerar o PDF (sem internet?). A abrir o WhatsApp com o link.', 6000);
  }
  const waUrl = (WA_NUMBER ? ('https://wa.me/' + WA_NUMBER) : 'https://wa.me/')
    + '?text=' + encodeURIComponent(WA_TEXT + '\n\nRecibo online: ' + RECIBO_URL);
  setTimeout(() => window.open(waUrl, '_blank'), pdf ? 1200 : 200);
}

/* pré-gera o PDF assim que a página abre, para o envio ser imediato ao toque */
prepararPDF();
</script>
</body>
</html>
