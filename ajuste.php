<?php
/* Reavaliação de preços do stock que já está em consignação.
   Usa-se quando o preço combinado muda sem a mercadoria se mexer — produto
   encalhado, promoção, saldo. Gera um movimento assinado (o cliente fica com
   prova de que o preço da ficha mudou) e atualiza o preço do stock em loja. */
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$c = get_client((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);
if (!$c) redirect('index.php');

$products = get_client_products((int)$c['id']);
if (!$products) redirect('cliente.php?id=' . (int)$c['id']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* a data tem de chegar em Y-m-d — o Postgres rejeita qualquer outra coisa */
    $date = (string)($_POST['date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) $date = date('Y-m-d');
    $sig       = $_POST['signature'] ?? '';
    $toCatalog = ($_POST['catalog'] ?? '') === '1';

    /* O formulário envia apenas [{id, price}]. O nome, a quantidade e o preço
       antigo são lidos da base de dados — nunca aceites do pedido. */
    $sent = json_decode($_POST['items'] ?? '[]', true);
    $newPrices = [];
    if (is_array($sent)) {
        foreach ($sent as $it) {
            $pid = (int)($it['id'] ?? 0);
            $pr  = (float)($it['price'] ?? -1);
            if ($pid > 0 && $pr >= 0 && $pr <= 999999) $newPrices[$pid] = round($pr, 2);
        }
    }

    /* só as linhas cujo preço mudou de facto (e que pertencem a este cliente) */
    $changes = [];
    foreach ($products as $p) {
        $pid = (int)$p['id'];
        if (!isset($newPrices[$pid])) continue;
        $old = round((float)$p['price'], 2);
        $new = $newPrices[$pid];
        if (abs($new - $old) < 0.005) continue;
        $changes[] = ['id' => $pid, 'name' => $p['name'], 'qty' => (int)$p['qty'], 'old' => $old, 'new' => $new];
    }

    if (!$changes) {
        $error = 'Nenhum preço foi alterado. Indique o novo preço de pelo menos um produto.';
    } elseif (!valid_signature($sig)) {
        $error = 'Assinatura em falta. Recolha a assinatura do cliente.';
    } else {
        $rate = comm_rate($c, $u);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stm = $pdo->prepare('INSERT INTO movements (client_id, type, mov_date, rec_id, comm_rate, signature) VALUES (?,?,?,?,?,?)');
            $stm->execute([$c['id'], 'ajuste', $date, gen_rec_id(), $rate, $sig]);
            $movId = db_last_id('movements');

            /* 'antes' e 'depois' são gravados intercalados — o recibo emparelha-os
               por posição, o que aguenta dois lotes do mesmo produto a preços
               diferentes (o nome por si não os distinguiria). */
            $sti = $pdo->prepare('INSERT INTO movement_items (movement_id, kind, name, qty, price) VALUES (?,?,?,?,?)');
            $upp = $pdo->prepare('UPDATE products SET price = ? WHERE id = ? AND client_id = ?');
            foreach ($changes as $ch) {
                $sti->execute([$movId, 'antes',  $ch['name'], $ch['qty'], $ch['old']]);
                $sti->execute([$movId, 'depois', $ch['name'], $ch['qty'], $ch['new']]);
                $upp->execute([$ch['new'], $ch['id'], $c['id']]);
            }

            /* o preço novo pode coincidir com outro lote do mesmo produto — funde
               as linhas iguais (mesmo nome+preço) para a ficha não ficar repetida */
            $merged = [];
            foreach (get_client_products((int)$c['id']) as $p) {
                $k = mb_strtolower($p['name']) . '|' . number_format((float)$p['price'], 2, '.', '');
                if (isset($merged[$k])) $merged[$k]['qty'] += (int)$p['qty'];
                else $merged[$k] = ['name' => $p['name'], 'qty' => (int)$p['qty'], 'price' => (float)$p['price']];
            }
            $merged = array_values($merged);

            $pdo->prepare('DELETE FROM products WHERE client_id = ?')->execute([$c['id']]);
            $stp = $pdo->prepare('INSERT INTO products (client_id, name, qty, price) VALUES (?,?,?,?)');
            foreach ($merged as $p) {
                $stp->execute([$c['id'], $p['name'], $p['qty'], $p['price']]);
            }

            /* ficha que fica em loja depois da reavaliação (sai no recibo) */
            foreach ($merged as $p) {
                $sti->execute([$movId, 'stock', $p['name'], $p['qty'], $p['price']]);
            }

            $pdo->prepare('UPDATE clients SET last_date = ? WHERE id = ?')->execute([$date, $c['id']]);

            $pdo->commit();

            /* preço de tabela para as próximas entregas */
            if ($toCatalog) {
                foreach ($changes as $ch) {
                    catalog_upsert((int)$u['id'], $ch['name'], (float)$ch['new']);
                }
            }

            redirect('recibo.php?id=' . $movId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Erro ao guardar: ' . $e->getMessage();
        }
    }
}

$rate = comm_rate($c, $u);
$totalAtual = 0;
foreach ($products as $p) $totalAtual += $p['qty'] * $p['price'];

$pageTitle = 'Reavaliar preços';
$backUrl = 'cliente.php?id=' . (int)$c['id'];
$backLabel = 'Voltar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<form method="post" id="aj-form" action="ajuste.php?id=<?= (int)$c['id'] ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <input type="hidden" name="items" id="aj-items">
  <input type="hidden" name="signature" id="aj-signature">

  <div class="steps">
    <div class="step active"><span class="step-n">1</span>Preços</div>
    <div class="step-line"></div>
    <div class="step"><span class="step-n">2</span>Assinatura</div>
  </div>

  <div class="section-title">Novos preços em <?= esc($c['name']) ?></div>
  <p class="hint" style="margin:-4px 0 12px">A mercadoria não se mexe — só muda o preço de venda. Deixe em branco (ou igual) o que não quer alterar.</p>
  <div class="field"><label>Data da visita</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>

  <hr>
  <div class="section-title">Stock em loja · atual <?= fmt($totalAtual) ?></div>
  <div class="panel">
    <?php foreach ($products as $p): ?>
      <div class="prod-row" style="display:block">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
          <span style="font-weight:600"><?= esc($p['name']) ?></span>
          <span class="hint" style="margin:0;white-space:nowrap"><?= (int)$p['qty'] ?> un. × <?= fmt($p['price']) ?></span>
        </div>
        <div class="row2" style="margin-top:8px;align-items:center">
          <div class="field" style="margin:0">
            <label>Novo preço €</label>
            <input type="number" class="aj-price" min="0" step="0.01" inputmode="decimal"
                   data-id="<?= (int)$p['id'] ?>"
                   data-qty="<?= (int)$p['qty'] ?>"
                   data-old="<?= esc(number_format((float)$p['price'], 2, '.', '')) ?>"
                   data-name="<?= esc($p['name']) ?>"
                   value="<?= esc(number_format((float)$p['price'], 2, '.', '')) ?>"
                   oninput="recalc()">
          </div>
          <div class="hint" id="aj-delta-<?= (int)$p['id'] ?>" style="margin:0;font-weight:600"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="run-total" id="aj-total" style="display:none">
    <span id="aj-total-label"></span><strong id="aj-total-val"></strong>
  </div>

  <label class="hint" style="display:flex;gap:8px;align-items:flex-start;margin:14px 0 0">
    <input type="checkbox" name="catalog" value="1" checked style="margin-top:2px">
    <span>Atualizar também o preço no catálogo, para as próximas entregas saírem já ao preço novo.</span>
  </label>

  <button type="button" class="btn btn-primary" style="margin-top:20px" onclick="goSig()">✍️ Avançar para assinatura</button>
  <a class="btn btn-ghost" href="cliente.php?id=<?= (int)$c['id'] ?>">Cancelar</a>
</form>

<?php require __DIR__ . '/includes/sig_overlay.php'; ?>
<script>
const CLIENT_NAME = <?= json_encode($c['name']) ?>;
const COMM_PCT    = <?= json_encode(comm_pct($rate)) ?>;
const COMM_RATE   = <?= json_encode($rate) ?>;

function priceInputs() {
  return Array.from(document.querySelectorAll('.aj-price'));
}

/* Estado de uma linha: campo vazio ou inválido = preço inalterado */
function lineState(el) {
  const old = parseFloat(el.dataset.old);
  const qty = parseInt(el.dataset.qty, 10);
  const raw = el.value.trim().replace(',', '.');
  const v = parseFloat(raw);
  const now = (raw === '' || isNaN(v)) ? old : Math.max(0, v);
  return {
    id: parseInt(el.dataset.id, 10),
    name: el.dataset.name,
    qty, old, now,
    changed: Math.abs(now - old) >= 0.005
  };
}

function recalc() {
  let antes = 0, agora = 0, n = 0;
  priceInputs().forEach(el => {
    const s = lineState(el);
    antes += s.qty * s.old;
    agora += s.qty * s.now;
    const d = document.getElementById('aj-delta-' + s.id);
    if (s.changed) {
      n++;
      const diff = s.qty * (s.now - s.old);
      d.textContent = (diff > 0 ? '+' : '−') + fmtEUR(Math.abs(diff)) + ' na ficha';
      d.style.color = diff > 0 ? 'var(--green)' : 'var(--red)';
    } else {
      d.textContent = 'sem alteração';
      d.style.color = '';
    }
  });

  const box = document.getElementById('aj-total');
  if (n > 0) {
    box.style.display = 'flex';
    document.getElementById('aj-total-label').textContent =
      n + ' preço' + (n === 1 ? '' : 's') + ' alterado' + (n === 1 ? '' : 's') + ' · em loja ' + fmtEUR(antes) + ' →';
    document.getElementById('aj-total-val').textContent = fmtEUR(agora);
  } else {
    box.style.display = 'none';
  }
}

function goSig() {
  const changed = priceInputs().map(lineState).filter(s => s.changed);
  if (!changed.length) { alert('Indique o novo preço de pelo menos um produto.'); return; }

  const todas = priceInputs().map(lineState);
  const antes = todas.reduce((s, p) => s + p.qty * p.old, 0);
  const agora = todas.reduce((s, p) => s + p.qty * p.now, 0);

  const rows = changed.map(s =>
    '<div class="sig-resumo-row"><span>' + s.qty + '× ' + escHtml(s.name) + '</span>' +
    '<span>' + fmtEUR(s.old) + ' → <strong>' + fmtEUR(s.now) + '</strong></span></div>'
  ).join('');

  Sig.open({
    title: 'Confirmar novos preços',
    instructionHtml: 'Mostre este ecrã ao cliente <strong>' + escHtml(CLIENT_NAME) + '</strong>.<br>' +
      'Assine para confirmar os novos preços de venda do stock em loja.',
    resumoHtml: '<div class="sig-resumo-head">Alteração de preços</div>' + rows +
      '<div class="sig-resumo-row"><span>Valor em loja antes</span><span>' + fmtEUR(antes) + '</span></div>' +
      '<div class="sig-resumo-total"><span>Valor em loja agora</span><span>' + fmtEUR(agora) + '</span></div>' +
      '<div class="sig-resumo-row"><span>Comissão ' + COMM_PCT + '% se tudo vender</span><span>' + fmtEUR(agora * COMM_RATE) + '</span></div>',
    onConfirm(dataUrl) {
      document.getElementById('aj-items').value =
        JSON.stringify(changed.map(s => ({ id: s.id, price: s.now })));
      document.getElementById('aj-signature').value = dataUrl;
      document.getElementById('aj-form').submit();
    }
  });
}

recalc();
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
