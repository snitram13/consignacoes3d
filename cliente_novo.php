<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $date  = $_POST['date'] ?: date('Y-m-d');
    $prods = parse_products_json($_POST['products'] ?? '');
    $prods = array_values(array_filter($prods, fn($p) => $p['qty'] > 0));
    $sig   = $_POST['signature'] ?? '';
    $commP = str_replace(',', '.', (string)($_POST['comm'] ?? ''));
    $comm  = is_numeric($commP) && $commP >= 0 && $commP <= 100 ? ((float)$commP) / 100 : null;

    if ($name === '' || !$prods) {
        $error = 'Preencha o nome do cliente e pelo menos um produto.';
    } elseif (!valid_signature($sig)) {
        $error = 'Assinatura em falta. Recolha a assinatura do cliente.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO clients (user_id, name, nif, phone, email, commission, last_date) VALUES (?,?,?,?,?,?,?)');
            $st->execute([
                $u['id'], $name,
                trim($_POST['nif'] ?? ''), trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''),
                $comm, $date,
            ]);
            $clientId = (int)$pdo->lastInsertId();

            $stp = $pdo->prepare('INSERT INTO products (client_id, name, qty, price) VALUES (?,?,?,?)');
            foreach ($prods as $p) {
                $stp->execute([$clientId, $p['name'], $p['qty'], $p['price']]);
            }

            $rate = $comm ?? (float)$u['commission'];
            $stm = $pdo->prepare('INSERT INTO movements (client_id, type, mov_date, rec_id, comm_rate, signature) VALUES (?,?,?,?,?,?)');
            $stm->execute([$clientId, 'entrega', $date, gen_rec_id(), $rate, $sig]);
            $movId = (int)$pdo->lastInsertId();

            $sti = $pdo->prepare('INSERT INTO movement_items (movement_id, kind, name, qty, price) VALUES (?,?,?,?,?)');
            foreach ($prods as $p) {
                $sti->execute([$movId, 'entregue', $p['name'], $p['qty'], $p['price']]);
            }

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
$defaultComm = rtrim(rtrim(number_format((float)$u['commission'] * 100, 1, '.', ''), '0'), '.');
$pageTitle = 'Novo cliente';
$backUrl = 'index.php';
$backLabel = 'Cancelar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<form method="post" id="nc-form">
  <?= csrf_field() ?>
  <input type="hidden" name="products" id="nc-products">
  <input type="hidden" name="signature" id="nc-signature">

  <div class="steps">
    <div class="step active"><span class="step-n">1</span>Dados</div>
    <div class="step-line"></div>
    <div class="step active"><span class="step-n">2</span>Produtos</div>
    <div class="step-line"></div>
    <div class="step"><span class="step-n">3</span>Assinatura</div>
  </div>

  <div class="section-title">Dados do cliente</div>
  <div class="field"><label>Nome</label><input type="text" name="name" id="nc-name" placeholder="Nome da loja ou pessoa"></div>
  <div class="row2">
    <div class="field"><label>NIF</label><input type="text" name="nif" inputmode="numeric" placeholder="123456789"></div>
    <div class="field"><label>Telefone</label><input type="tel" name="phone" placeholder="912 345 678"></div>
  </div>
  <div class="field"><label>Email do cliente</label><input type="email" name="email" placeholder="cliente@email.com"></div>
  <div class="field"><label>Data de entrega</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
  <div class="field"><label>Comissão para este cliente (%)</label><input type="number" name="comm" min="0" max="100" step="0.5" inputmode="decimal" value="<?= esc($defaultComm) ?>"></div>

  <hr>
  <div class="section-title">Produtos em consignação</div>
  <div id="nc-prod-list"></div>
  <div class="run-total" id="nc-total" style="display:none"><span id="nc-total-count"></span><strong id="nc-total-val"></strong></div>
  <div class="add-box">
    <div class="field" style="margin:0 0 10px">
      <label>Escolher do catálogo</label>
      <select id="nc-pick" onchange="pickFromCatalog(this,'nc-pname','nc-pprice')">
        <option value="">— selecionar produto —</option>
        <?php foreach ($catalog as $cp): ?>
          <option value="<?= esc($cp['name']) ?>" data-price="<?= esc(number_format((float)$cp['price'], 2, '.', '')) ?>"><?= esc($cp['name']) ?> · <?= fmt($cp['price']) ?></option>
        <?php endforeach; ?>
        <option value="__new__">➕ Novo produto (não está no catálogo)…</option>
      </select>
    </div>
    <div class="row3">
      <div class="field" style="margin:0"><label>Produto</label><input type="text" id="nc-pname" placeholder="Nome"></div>
      <div class="field" style="margin:0"><label>Qtd</label><input type="number" id="nc-pqty" min="1" value="1" inputmode="numeric"></div>
      <div class="field" style="margin:0"><label>Preço €</label><input type="number" id="nc-pprice" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
    </div>
    <p class="hint" style="margin:8px 0 0">Produtos novos ficam registados no catálogo automaticamente.</p>
    <button type="button" class="btn btn-secondary" style="margin-top:10px" onclick="addProd()">+ Adicionar produto</button>
  </div>

  <button type="button" class="btn btn-primary" style="margin-top:20px" onclick="goSig()">✍️ Avançar para assinatura</button>
</form>

<?php require __DIR__ . '/includes/sig_overlay.php'; ?>
<script>
let prods = [];

function renderProds() {
  document.getElementById('nc-prod-list').innerHTML = prods.map((p, i) =>
    '<div class="prod-item"><div><div class="prod-item-info">' + escHtml(p.name) + '</div>' +
    '<div class="prod-item-sub">' + p.qty + ' un. × ' + fmtEUR(p.price) + ' = ' + fmtEUR(p.qty * p.price) + '</div></div>' +
    '<button type="button" class="remove-btn" onclick="prods.splice(' + i + ',1);renderProds()">×</button></div>'
  ).join('');
  const totalEl = document.getElementById('nc-total');
  if (prods.length) {
    const totalQty = prods.reduce((s, p) => s + p.qty, 0);
    const totalVal = prods.reduce((s, p) => s + p.qty * p.price, 0);
    totalEl.style.display = 'flex';
    document.getElementById('nc-total-count').textContent = totalQty + ' unidade' + (totalQty === 1 ? '' : 's') + ' · ' + prods.length + ' produto' + (prods.length === 1 ? '' : 's');
    document.getElementById('nc-total-val').textContent = fmtEUR(totalVal);
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
  document.getElementById('nc-pqty').focus();
}

function addProd() {
  const name = document.getElementById('nc-pname').value.trim();
  const qty = parseInt(document.getElementById('nc-pqty').value) || 0;
  const price = parseFloat(document.getElementById('nc-pprice').value) || 0;
  if (!name || qty <= 0) { alert('Preencha o nome e a quantidade.'); return; }
  prods.push({ name, qty, price });
  document.getElementById('nc-pname').value = '';
  document.getElementById('nc-pqty').value = '1';
  document.getElementById('nc-pprice').value = '';
  document.getElementById('nc-pick').value = '';
  renderProds();
}

function goSig() {
  const name = document.getElementById('nc-name').value.trim();
  if (!name) { alert('Introduza o nome do cliente.'); return; }
  if (!prods.length) { alert('Adicione pelo menos um produto.'); return; }
  const total = prods.reduce((s, p) => s + p.qty * p.price, 0);
  const rows = prods.map(p =>
    '<div class="sig-resumo-row"><span>' + p.qty + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.qty * p.price) + '</span></div>'
  ).join('');
  Sig.open({
    title: 'Confirmar entrega',
    instructionHtml: 'Mostre este ecrã ao cliente <strong>' + escHtml(name) + '</strong>.<br>Assine para confirmar a receção dos produtos.',
    resumoHtml: '<div class="sig-resumo-head">Produtos em consignação</div>' + rows +
      '<div class="sig-resumo-total"><span>Total em loja</span><span>' + fmtEUR(total) + '</span></div>',
    onConfirm(dataUrl) {
      document.getElementById('nc-products').value = JSON.stringify(prods);
      document.getElementById('nc-signature').value = dataUrl;
      document.getElementById('nc-form').submit();
    }
  });
}
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
