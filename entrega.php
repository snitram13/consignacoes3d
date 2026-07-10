<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$c = get_client((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);
if (!$c) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date  = $_POST['date'] ?: date('Y-m-d');
    $prods = array_values(array_filter(parse_products_json($_POST['products'] ?? ''), fn($p) => $p['qty'] > 0));
    $sig   = $_POST['signature'] ?? '';

    if (!$prods) {
        $error = 'Adicione pelo menos um produto para registar a entrega.';
    } elseif (!valid_signature($sig)) {
        $error = 'Assinatura em falta. Recolha a assinatura do cliente.';
    } else {
        $rate = comm_rate($c, $u);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            /* movimento de entrega (com assinatura, prova de receção) */
            $stm = $pdo->prepare('INSERT INTO movements (client_id, type, mov_date, rec_id, comm_rate, signature) VALUES (?,?,?,?,?,?)');
            $stm->execute([$c['id'], 'entrega', $date, gen_rec_id(), $rate, $sig]);
            $movId = db_last_id('movements');

            $sti = $pdo->prepare('INSERT INTO movement_items (movement_id, kind, name, qty, price) VALUES (?,?,?,?,?)');
            foreach ($prods as $p) {
                $sti->execute([$movId, 'entregue', $p['name'], $p['qty'], $p['price']]);
            }

            /* junta ao stock existente (mesmo nome+preço soma quantidades) */
            $merged = [];
            foreach (get_client_products((int)$c['id']) as $p) {
                $k = mb_strtolower($p['name']) . '|' . number_format((float)$p['price'], 2, '.', '');
                $merged[$k] = ['name' => $p['name'], 'qty' => (int)$p['qty'], 'price' => (float)$p['price']];
            }
            foreach ($prods as $p) {
                $k = mb_strtolower($p['name']) . '|' . number_format((float)$p['price'], 2, '.', '');
                if (isset($merged[$k])) $merged[$k]['qty'] += $p['qty'];
                else $merged[$k] = $p;
            }
            $merged = array_values($merged);

            $pdo->prepare('DELETE FROM products WHERE client_id = ?')->execute([$c['id']]);
            $stp = $pdo->prepare('INSERT INTO products (client_id, name, qty, price) VALUES (?,?,?,?)');
            foreach ($merged as $p) {
                $stp->execute([$c['id'], $p['name'], $p['qty'], $p['price']]);
            }

            $pdo->prepare('UPDATE clients SET last_date = ? WHERE id = ?')->execute([$date, $c['id']]);

            $pdo->commit();

            /* regista no catálogo os produtos entregues */
            foreach ($prods as $p) {
                catalog_upsert((int)$u['id'], $p['name'], (float)$p['price']);
            }

            redirect('recibo.php?id=' . $movId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Erro ao guardar: ' . $e->getMessage();
        }
    }
}

$catalog = get_catalog((int)$u['id']);
$hasStock = (bool)get_client_products((int)$c['id']);
$pageTitle = 'Registar entrega';
$backUrl = 'cliente.php?id=' . (int)$c['id'];
$backLabel = 'Voltar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<form method="post" id="en-form" action="entrega.php?id=<?= (int)$c['id'] ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <input type="hidden" name="products" id="en-products">
  <input type="hidden" name="signature" id="en-signature">

  <div class="steps">
    <div class="step active"><span class="step-n">1</span>Produtos</div>
    <div class="step-line"></div>
    <div class="step"><span class="step-n">2</span>Assinatura</div>
  </div>

  <div class="section-title">Entrega para <?= esc($c['name']) ?></div>
  <?php if ($hasStock): ?>
    <p class="hint" style="margin:-4px 0 12px">Este cliente já tem stock — os produtos abaixo são <strong>somados</strong> ao que já está em loja.</p>
  <?php endif; ?>
  <div class="field"><label>Data de entrega</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>

  <hr>
  <div class="section-title">Produtos em consignação</div>
  <div id="en-prod-list"></div>
  <div class="run-total" id="en-total" style="display:none"><span id="en-total-count"></span><strong id="en-total-val"></strong></div>
  <div class="add-box">
    <div class="field" style="margin:0 0 10px">
      <label>Escolher do catálogo</label>
      <select id="en-pick" onchange="pickFromCatalog(this,'en-pname','en-pprice')">
        <option value="">— selecionar produto —</option>
        <?php foreach ($catalog as $cp): ?>
          <option value="<?= esc($cp['name']) ?>" data-price="<?= esc(number_format((float)$cp['price'], 2, '.', '')) ?>"><?= esc($cp['name']) ?> · <?= fmt($cp['price']) ?></option>
        <?php endforeach; ?>
        <option value="__new__">➕ Novo produto (não está no catálogo)…</option>
      </select>
    </div>
    <div class="row3">
      <div class="field" style="margin:0"><label>Produto</label><input type="text" id="en-pname" placeholder="Nome"></div>
      <div class="field" style="margin:0"><label>Qtd</label><input type="number" id="en-pqty" min="1" value="1" inputmode="numeric"></div>
      <div class="field" style="margin:0"><label>Preço €</label><input type="number" id="en-pprice" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
    </div>
    <p class="hint" style="margin:8px 0 0">Produtos novos ficam registados no catálogo automaticamente.</p>
    <button type="button" class="btn btn-secondary" style="margin-top:10px" onclick="addProd()">+ Adicionar produto</button>
  </div>

  <button type="button" class="btn btn-primary" style="margin-top:20px" onclick="goSig()">✍️ Avançar para assinatura</button>
  <a class="btn btn-ghost" href="cliente.php?id=<?= (int)$c['id'] ?>">Cancelar</a>
</form>

<?php require __DIR__ . '/includes/sig_overlay.php'; ?>
<script>
const CLIENT_NAME = <?= json_encode($c['name']) ?>;
let prods = [];

function renderProds() {
  document.getElementById('en-prod-list').innerHTML = prods.map((p, i) =>
    '<div class="prod-item"><div><div class="prod-item-info">' + escHtml(p.name) + '</div>' +
    '<div class="prod-item-sub">' + p.qty + ' un. × ' + fmtEUR(p.price) + ' = ' + fmtEUR(p.qty * p.price) + '</div></div>' +
    '<button type="button" class="remove-btn" onclick="prods.splice(' + i + ',1);renderProds()">×</button></div>'
  ).join('');
  const totalEl = document.getElementById('en-total');
  if (prods.length) {
    const totalQty = prods.reduce((s, p) => s + p.qty, 0);
    const totalVal = prods.reduce((s, p) => s + p.qty * p.price, 0);
    totalEl.style.display = 'flex';
    document.getElementById('en-total-count').textContent = totalQty + ' unidade' + (totalQty === 1 ? '' : 's') + ' · ' + prods.length + ' produto' + (prods.length === 1 ? '' : 's');
    document.getElementById('en-total-val').textContent = fmtEUR(totalVal);
  } else totalEl.style.display = 'none';
}

function pickFromCatalog(sel, nameId, priceId) {
  const o = sel.options[sel.selectedIndex];
  const nameEl = document.getElementById(nameId);
  const priceEl = document.getElementById(priceId);
  if (o.value === '__new__') { nameEl.value = ''; priceEl.value = ''; nameEl.focus(); return; }
  if (!o.value) return;
  nameEl.value = o.value;
  const pr = o.getAttribute('data-price');
  if (pr) priceEl.value = parseFloat(pr).toFixed(2);
  document.getElementById('en-pqty').focus();
}

function addProd() {
  const name = document.getElementById('en-pname').value.trim();
  const qty = parseInt(document.getElementById('en-pqty').value) || 0;
  const price = parseFloat(document.getElementById('en-pprice').value) || 0;
  if (!name || qty <= 0) { alert('Preencha o nome e a quantidade.'); return; }
  prods.push({ name, qty, price });
  document.getElementById('en-pname').value = '';
  document.getElementById('en-pqty').value = '1';
  document.getElementById('en-pprice').value = '';
  document.getElementById('en-pick').value = '';
  renderProds();
}

function goSig() {
  if (!prods.length) { alert('Adicione pelo menos um produto.'); return; }
  const total = prods.reduce((s, p) => s + p.qty * p.price, 0);
  const rows = prods.map(p =>
    '<div class="sig-resumo-row"><span>' + p.qty + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.qty * p.price) + '</span></div>'
  ).join('');
  Sig.open({
    title: 'Confirmar entrega',
    instructionHtml: 'Mostre este ecrã ao cliente <strong>' + escHtml(CLIENT_NAME) + '</strong>.<br>Assine para confirmar a receção dos produtos.',
    resumoHtml: '<div class="sig-resumo-head">Produtos em consignação</div>' + rows +
      '<div class="sig-resumo-total"><span>Total em loja</span><span>' + fmtEUR(total) + '</span></div>',
    onConfirm(dataUrl) {
      document.getElementById('en-products').value = JSON.stringify(prods);
      document.getElementById('en-signature').value = dataUrl;
      document.getElementById('en-form').submit();
    }
  });
}
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
