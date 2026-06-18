<?php
require_once __DIR__ . '/includes/auth.php';
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

/* uma via do recibo */
function via(array $m, array $u, array $vendidos, array $repostos, array $stockItems, array $entregues, float $rate, string $titulo, string $copyLabel, string $cls): string {
    $isAcerto = $m['type'] === 'acerto';
    $h = '<div class="via ' . esc($cls) . '">';
    if ($u['logo']) $h .= '<img class="via-logo" src="' . esc($u['logo']) . '" alt="logo">';
    $h .= '<span class="via-tag">' . esc($copyLabel) . '</span>';
    $h .= '<h1>' . esc($titulo) . '</h1>';
    $h .= '<div class="via-sub">Comissão de ' . comm_pct($rate) . '% sobre vendas</div>';
    $h .= '<div class="via-meta"><span>Nº ' . esc($m['rec_id']) . '</span><span>Data: ' . fmt_date($m['mov_date']) . '</span></div>';
    $h .= '<div class="via-parties">' .
        '<div class="via-party"><h2>Fornecedor</h2><p><strong>' . esc($u['name'] ?: '—') . '</strong></p><p>NIF: ' . esc($u['nif'] ?: '—') . '</p><p>Tel: ' . esc($u['phone'] ?: '—') . '</p></div>' .
        '<div class="via-party"><h2>Cliente</h2><p><strong>' . esc($m['c_name']) . '</strong></p><p>NIF: ' . esc($m['c_nif'] ?: '—') . '</p><p>Tel: ' . esc($m['c_phone'] ?: '—') . '</p></div></div>';

    $table = function (array $rows, array $cols) {
        $t = '<table><tr><th>Produto</th><th class="num">Qtd</th><th class="num">Preço</th><th class="num">Total</th></tr>';
        foreach ($rows as $r) {
            $t .= '<tr><td>' . esc($r['name']) . '</td><td class="num">' . (int)$r['qty'] . '</td><td class="num">' . fmt($r['price']) . '</td><td class="num">' . fmt($r['qty'] * $r['price']) . '</td></tr>';
        }
        return $t . '</table>';
    };

    if ($isAcerto) {
        if ($vendidos) {
            $h .= '<div class="via-sectitle">Vendido nesta visita</div>' . $table($vendidos, []);
            $h .= '<div class="via-totals">' .
                '<div class="via-trow"><span>Total vendido</span><span>' . fmt($m['total_sold']) . '</span></div>' .
                '<div class="via-trow"><span>Comissão (' . comm_pct($rate) . '%)</span><span>− ' . fmt($m['commission_value']) . '</span></div>' .
                '<div class="via-trow big"><span>Recebido pelo fornecedor</span><span>' . fmt($m['net_value']) . '</span></div></div>';
        } else {
            $h .= '<div class="via-sectitle">Vendas</div><p>Sem vendas nesta visita.</p>';
        }
        if ($repostos) {
            $val = 0;
            foreach ($repostos as $i) $val += $i['qty'] * $i['price'];
            $h .= '<div class="via-sectitle">Reposição nesta visita (novos produtos deixados)</div>' . $table($repostos, []);
            $h .= '<div class="via-totals"><div class="via-trow big"><span>Valor da reposição</span><span>' . fmt($val) . '</span></div></div>';
        }
        if ($stockItems) {
            $val = 0;
            foreach ($stockItems as $i) $val += $i['qty'] * $i['price'];
            $h .= '<div class="via-sectitle">Stock total em consignação após esta visita</div>' . $table($stockItems, []);
            $h .= '<div class="via-totals"><div class="via-trow big"><span>Valor em loja</span><span>' . fmt($val) . '</span></div></div>';
        }
    } else {
        $val = 0;
        foreach ($entregues as $i) $val += $i['qty'] * $i['price'];
        $h .= '<div class="via-sectitle">Produtos entregues em consignação</div>' . $table($entregues, []);
        $h .= '<div class="via-totals">' .
            '<div class="via-trow"><span>Valor total em loja</span><span>' . fmt($val) . '</span></div>' .
            '<div class="via-trow"><span>Comissão potencial (' . comm_pct($rate) . '%)</span><span>' . fmt($val * $rate) . '</span></div>' .
            '<div class="via-trow big"><span>A receber se tudo vender</span><span>' . fmt($val - $val * $rate) . '</span></div></div>';
    }

    if ($m['signature']) {
        $h .= '<div class="via-sig"><img src="' . esc($m['signature']) . '" alt="assinatura">' .
            '<div class="via-sig-info">Assinatura do cliente<br>' . esc($m['c_name']) . '</div></div>' .
            '<div class="via-valid">✓ Documento validado por assinatura em ' . esc(date('d/m/Y H:i', strtotime($m['created_at']))) . '</div>';
    } else {
        $h .= '<div class="via-sig"><div class="via-sig-info">Sem assinatura registada.</div></div>';
    }
    $h .= '<div class="via-foot">Documento gerado por ' . esc(APP_NAME) . ' · Nº ' . esc($m['rec_id']) . '</div></div>';
    return $h;
}
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
    <button class="btn btn-secondary" id="email-btn" onclick="emailRecibo()">📧 Enviar email</button>
    <a class="btn btn-primary btn-wide" href="cliente.php?id=<?= (int)$m['c_id'] ?>">✓ Concluir</a>
  </footer>
</div>
<div id="toast" style="position:fixed;left:50%;bottom:90px;transform:translateX(-50%);max-width:88%;background:#1a1a1a;color:#fff;padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.4;box-shadow:0 8px 30px rgba(0,0,0,.3);z-index:9999;display:none;text-align:center"></div>
<script>
const SUBJECT  = <?= json_encode($titulo . ' — ' . $m['c_name'] . ' — Nº ' . $m['rec_id']) ?>;
const BODY     = <?= json_encode($body) ?>;
const MAILTO   = <?= json_encode($mailto) ?>;
const PDF_NAME = <?= json_encode('Recibo_' . preg_replace('/[^\w\-]+/', '_', $m['rec_id']) . '_' . date('d-m-Y', strtotime($m['mov_date'])) . '.pdf') ?>;
const SMTP_ENABLED = <?= json_encode(BREVO_API_KEY !== '' || (SMTP_HOST !== '' && SMTP_USER !== '')) ?>;
const CLIENT_EMAIL = <?= json_encode($m['c_email'] ?? '') ?>;
const MOVEMENT_ID  = <?= (int)$m['id'] ?>;
const CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;

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

/* pré-gera o PDF: o Safari recusa a partilha se houver demora após o toque no botão */
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

/* 1ª opção: envio automático pelo servidor (SMTP) — email já com destinatário,
   assunto, corpo e o PDF anexado. Devolve true se enviou. */
async function enviarPorSMTP(pdf) {
  if (!SMTP_ENABLED || !pdf) return false;
  if (!CLIENT_EMAIL) {
    toast('Este cliente não tem email na ficha. Edite o cliente e adicione o email.', 6000);
    return false;
  }
  toast('A enviar o email para ' + CLIENT_EMAIL + '…', 8000);
  const fd = new FormData();
  fd.append('csrf', CSRF_TOKEN);
  fd.append('movement_id', MOVEMENT_ID);
  fd.append('body', BODY);
  fd.append('pdf', pdf, PDF_NAME);
  try {
    const r = await fetch('enviar_recibo.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.ok) {
      toast('✅ Email enviado para ' + data.to + ' com o PDF anexado.', 6000);
      return true;
    }
    console.warn('SMTP falhou:', data);
    if (data.code && data.code !== 'smtp_not_configured') {
      toast('Envio automático falhou: ' + (data.error || data.code) + '. A abrir o email como alternativa.', 6000);
    }
    return false;
  } catch (e) {
    console.warn('Erro de rede no envio SMTP:', e);
    return false;
  }
}

function descarregarPDF(pdf) {
  const url = URL.createObjectURL(pdf);
  const a = document.createElement('a');
  a.href = url; a.download = PDF_NAME;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

async function emailRecibo() {
  const btn = document.getElementById('email-btn');
  btn.disabled = true;
  btn.textContent = '⏳ A preparar…';
  toast('A gerar o recibo em PDF…', 8000);

  const pdf = await prepararPDF();

  btn.disabled = false;
  btn.textContent = '📧 Enviar email';

  // 1) envio automático pelo servidor (SMTP) — email completo com PDF anexado
  if (await enviarPorSMTP(pdf)) return;

  // 2) partilha nativa (telemóvel e Mac recente): abre o email/apps com o PDF anexado
  if (pdf && navigator.canShare && navigator.canShare({ files: [pdf] })) {
    try { await navigator.share({ files: [pdf], title: SUBJECT, text: BODY }); return; }
    catch (e) { if (e.name === 'AbortError') return; }
  }

  // 3) sem partilha (computador): descarrega o PDF e abre o programa de email
  if (pdf) {
    descarregarPDF(pdf);
    toast('Recibo PDF descarregado (' + PDF_NAME + '). A abrir o seu email — anexe o PDF descarregado.', 7000);
  } else {
    toast('Não foi possível gerar o PDF (sem internet?). A abrir só o email com o texto.', 6000);
  }
  // dá tempo ao download antes de trocar de contexto para o email
  setTimeout(() => { window.location.href = MAILTO; }, pdf ? 1200 : 200);
}

prepararPDF();
</script>
</body>
</html>
