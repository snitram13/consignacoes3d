<?php
/* Página PÚBLICA do recibo (aberta pelo link do WhatsApp).
   Não exige sessão — o acesso é autorizado por um token no URL (?id=..&t=..),
   que impede adivinhar recibos de outros. Só de leitura. */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/recibo_view.php';

function recibo_erro(string $msg, int $http = 404): void {
    http_response_code($http);
    ?><!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Recibo · <?= esc(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css"></head>
    <body class="centered-page"><div class="login-card">
      <div class="login-icon">📄</div>
      <h2><?= esc(APP_NAME) ?></h2>
      <p><?= esc($msg) ?></p>
    </div></body></html><?php
    exit;
}

$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['t'] ?? '');

try {
    $data = $id ? recibo_load($id) : null;
    $m = $data['m'] ?? null;
    $u = $m ? recibo_user((int)$m['user_id']) : null;
} catch (Throwable $e) {
    recibo_erro('Serviço temporariamente indisponível. Tente de novo daqui a um minuto.', 503);
}
if (!$data || !$m || !$u) recibo_erro('Recibo não encontrado ou já removido.');

/* Autorização por token (assinatura estável do recibo) */
if ($token === '' || !hash_equals(receipt_token($id, $u), $token)) {
    recibo_erro('Link inválido ou expirado. Peça um novo link ao fornecedor.', 403);
}

$rate     = (float)$m['comm_rate'];
$isAcerto = $m['type'] === 'acerto';
$titulo   = $isAcerto ? 'Recibo de Consignação · Acerto' : 'Recibo de Consignação · Entrega';
$marca    = $u['brand'] ?: ($u['name'] ?: APP_NAME);
$pdfName  = 'Recibo_' . preg_replace('/[^\w\-]+/', '_', $m['rec_id']) . '_' . date('d-m-Y', strtotime($m['mov_date'])) . '.pdf';
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
    <div class="topbar-title"><?= esc($marca) ?></div>
  </header>
  <main class="recibo-scroll">
    <?= via($m, $u, $data['vendidos'], $data['repostos'], $data['stock'], $data['entregues'], $rate, $titulo, 'Via do Cliente', 'via--cliente') ?>
  </main>
  <footer class="recibo-actions">
    <button class="btn btn-secondary" id="print-btn" onclick="imprimirRecibo()">🖨️ Imprimir</button>
    <button class="btn btn-primary" id="pdf-btn" onclick="guardarPDF()">📥 Guardar PDF</button>
  </footer>
</div>
<div id="toast" style="position:fixed;left:50%;bottom:90px;transform:translateX(-50%);max-width:88%;background:#1a1a1a;color:#fff;padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.4;box-shadow:0 8px 30px rgba(0,0,0,.3);z-index:9999;display:none;text-align:center"></div>
<script>
const PDF_NAME = <?= json_encode($pdfName) ?>;

let toastTimer = null;
function toast(msg, ms = 4000) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.style.display = 'none'; }, ms);
}

function imprimirRecibo() {
  try {
    toast('A abrir a impressão… escolha "Guardar como PDF" se quiser o ficheiro.', 5000);
    window.print();
  } catch (e) {
    toast('O navegador bloqueou a impressão. Use o menu → Imprimir.', 6000);
  }
}

/* Carrega a biblioteca de PDF só quando é precisa */
let html2pdfLoading = null;
function loadHtml2pdf() {
  if (window.html2pdf) return Promise.resolve();
  if (html2pdfLoading) return html2pdfLoading;
  html2pdfLoading = new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js';
    s.onload = res;
    s.onerror = () => { html2pdfLoading = null; rej(new Error('sem ligação à internet')); };
    document.head.appendChild(s);
  });
  return html2pdfLoading;
}

async function guardarPDF() {
  const btn = document.getElementById('pdf-btn');
  btn.disabled = true;
  const label = btn.textContent;
  btn.textContent = '⏳ A gerar…';
  toast('A gerar o recibo em PDF…', 8000);
  try {
    await loadHtml2pdf();
    const el = document.querySelector('.via--cliente');
    const blob = await html2pdf().set({
      margin: 8,
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, backgroundColor: '#ffffff', useCORS: true },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    }).from(el).outputPdf('blob');
    const file = new File([blob], PDF_NAME, { type: 'application/pdf' });

    // No telemóvel: usa a partilha nativa (guardar em Ficheiros, reenviar, etc.)
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: PDF_NAME }); toast('Recibo pronto.', 3000); return; }
      catch (e) { if (e.name === 'AbortError') { return; } }
    }
    // Caso contrário: descarrega o ficheiro
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = PDF_NAME;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    toast('Recibo PDF guardado (' + PDF_NAME + ').', 6000);
  } catch (e) {
    console.warn(e);
    toast('Não foi possível gerar o PDF (sem internet?). Use "Imprimir" → Guardar como PDF.', 7000);
  } finally {
    btn.disabled = false;
    btn.textContent = label;
  }
}
</script>
</body>
</html>
