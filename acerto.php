<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$c = get_client((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);
if (!$c) redirect('index.php');

$products = get_client_products((int)$c['id']);
$catalog = get_catalog((int)$u['id']);
$rate = comm_rate($c, $u);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?: date('Y-m-d');
    $sig  = $_POST['signature'] ?? '';

    /* vendas: [{name, vendeu, devolveu, price}] — o mesmo payload traz vendas e devoluções */
    $vendasRaw = json_decode($_POST['vendas'] ?? '[]', true) ?: [];
    $vendas = [];
    $devolucoes = [];
    foreach ($vendasRaw as $v) {
        $name  = trim((string)($v['name'] ?? ''));
        $sold  = (int)($v['vendeu'] ?? 0);
        $ret   = (int)($v['devolveu'] ?? 0);
        $price = (float)($v['price'] ?? 0);
        if ($name === '' || $price < 0) continue;
        if ($sold > 0) $vendas[]     = ['name' => $name, 'qty' => $sold, 'price' => $price];
        if ($ret  > 0) $devolucoes[] = ['name' => $name, 'qty' => $ret,  'price' => $price];
    }
    /* stock que permanece (existente − vendido, ajustável) */
    $stockFica = array_values(array_filter(parse_products_json($_POST['stock_fica'] ?? ''), fn($p) => $p['qty'] > 0));
    /* reposição: novos produtos que o cliente deseja */
    $reposicao = array_values(array_filter(parse_products_json($_POST['reposicao'] ?? ''), fn($p) => $p['qty'] > 0));

    if (!valid_signature($sig)) {
        $error = 'Assinatura em falta. Recolha a assinatura do cliente.';
    } else {
        /* stock final na loja = o que fica + reposição (junta produtos iguais) */
        $final = [];
        foreach (array_merge($stockFica, $reposicao) as $p) {
            $k = mb_strtolower($p['name']) . '|' . number_format($p['price'], 2, '.', '');
            if (isset($final[$k])) $final[$k]['qty'] += $p['qty'];
            else $final[$k] = $p;
        }
        $final = array_values($final);

        $totalSold = 0;
        foreach ($vendas as $v) $totalSold += $v['qty'] * $v['price'];
        $commVal = $totalSold * $rate;
        $netVal  = $totalSold - $commVal;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stm = $pdo->prepare('INSERT INTO movements (client_id, type, mov_date, rec_id, comm_rate, total_sold, commission_value, net_value, signature) VALUES (?,?,?,?,?,?,?,?,?)');
            $stm->execute([$c['id'], 'acerto', $date, gen_rec_id(), $rate, $totalSold, $commVal, $netVal, $sig]);
            $movId = db_last_id('movements');

            $sti = $pdo->prepare('INSERT INTO movement_items (movement_id, kind, name, qty, price) VALUES (?,?,?,?,?)');
            foreach ($vendas as $v)     $sti->execute([$movId, 'vendido', $v['name'], $v['qty'], $v['price']]);
            foreach ($devolucoes as $d) $sti->execute([$movId, 'devolvido', $d['name'], $d['qty'], $d['price']]);
            foreach ($reposicao as $p)  $sti->execute([$movId, 'reposto', $p['name'], $p['qty'], $p['price']]);
            foreach ($final as $p)      $sti->execute([$movId, 'stock', $p['name'], $p['qty'], $p['price']]);

            $pdo->prepare('DELETE FROM products WHERE client_id = ?')->execute([$c['id']]);
            $stp = $pdo->prepare('INSERT INTO products (client_id, name, qty, price) VALUES (?,?,?,?)');
            foreach ($final as $p) $stp->execute([$c['id'], $p['name'], $p['qty'], $p['price']]);

            $pdo->prepare('UPDATE clients SET last_date = ? WHERE id = ?')->execute([$date, $c['id']]);

            $pdo->commit();

            /* regista no catálogo os produtos usados (reposição + stock final) */
            foreach (array_merge($reposicao, $final) as $p) {
                catalog_upsert((int)$u['id'], $p['name'], (float)$p['price']);
            }
            redirect('recibo.php?id=' . $movId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Erro ao guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Registar visita';
$backUrl = 'cliente.php?id=' . (int)$c['id'];
$backLabel = 'Voltar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<form method="post" id="ac-form" action="acerto.php?id=<?= (int)$c['id'] ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <input type="hidden" name="vendas" id="ac-vendas-input">
  <input type="hidden" name="stock_fica" id="ac-stock-input">
  <input type="hidden" name="reposicao" id="ac-repo-input">
  <input type="hidden" name="signature" id="ac-signature">

  <div class="steps">
    <div class="step active"><span class="step-n">1</span>Vendas</div>
    <div class="step-line"></div>
    <div class="step active"><span class="step-n">2</span>Reposição</div>
    <div class="step-line"></div>
    <div class="step"><span class="step-n">3</span>Assinatura</div>
  </div>

  <div class="field"><label>Data da visita</label><input type="date" name="date" id="ac-date" value="<?= date('Y-m-d') ?>"></div>

  <div class="section-title">① O que foi vendido e devolvido?</div>
  <p class="hint">Para cada produto, indique quanto <strong>vendeu</strong> e quanto o cliente <strong>devolveu</strong>. O que fica em loja é calculado automaticamente.</p>
  <div id="acerto-vendas"></div>
  <div id="acerto-resumo" style="display:none;margin-bottom:4px"></div>

  <hr>
  <div class="section-title">② Reposição — produtos que o cliente deseja</div>
  <p class="hint">Produtos novos ou mais unidades que vai deixar nesta visita. Ficam registados à parte no recibo.</p>
  <div id="repo-list"></div>
  <div class="run-total" id="repo-total" style="display:none"><span id="repo-total-count"></span><strong id="repo-total-val"></strong></div>
  <div class="add-box" style="margin-top:6px">
    <div class="field" style="margin:0 0 10px">
      <label>Escolher do catálogo</label>
      <select id="ac-pick" onchange="pickFromCatalog(this,'ac-pname','ac-pprice')">
        <option value="">— selecionar produto —</option>
        <?php foreach ($catalog as $cp): ?>
          <option value="<?= esc($cp['name']) ?>" data-price="<?= esc(number_format((float)$cp['price'], 2, '.', '')) ?>"><?= esc($cp['name']) ?> · <?= fmt($cp['price']) ?></option>
        <?php endforeach; ?>
        <option value="__new__">➕ Novo produto (não está no catálogo)…</option>
      </select>
    </div>
    <div class="row3">
      <div class="field" style="margin:0"><label>Produto</label><input type="text" id="ac-pname" placeholder="Nome"></div>
      <div class="field" style="margin:0"><label>Qtd</label><input type="number" id="ac-pqty" min="1" value="1" inputmode="numeric"></div>
      <div class="field" style="margin:0"><label>Preço €</label><input type="number" id="ac-pprice" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
    </div>
    <p class="hint" style="margin:8px 0 0">Produtos novos ficam registados no catálogo automaticamente.</p>
    <button type="button" class="btn btn-secondary" style="margin-top:10px" onclick="addRepo()">+ Adicionar à reposição</button>
  </div>

  <a class="btn btn-ghost" href="cliente.php?id=<?= (int)$c['id'] ?>" style="margin-top:20px">Cancelar</a>
  <div class="sticky-cta-spacer"></div>
</form>

<div class="sticky-cta">
  <div class="cta-info">
    <span class="cta-label">Recebe agora</span>
    <span class="cta-value" id="cta-net">0,00€</span>
  </div>
  <button type="button" class="cta-btn" onclick="goSig()">✍️ Assinatura</button>
</div>

<?php require __DIR__ . '/includes/sig_overlay.php'; ?>
<script>
const RATE = <?= json_encode($rate) ?>;
const RATE_PCT = <?= json_encode(comm_pct($rate)) ?>;
const CLIENT_NAME = <?= json_encode($c['name']) ?>;
let vendas = <?= json_encode(array_map(fn($p) => ['name' => $p['name'], 'qty' => (int)$p['qty'], 'price' => (float)$p['price'], 'vendeu' => 0, 'devolveu' => 0], $products)) ?>;
let stock  = <?= json_encode(array_map(fn($p) => ['name' => $p['name'], 'qty' => (int)$p['qty'], 'price' => (float)$p['price']], $products)) ?>;
let repo   = []; // reposição: novos produtos que o cliente deseja

/* ── ① vendas e devoluções ── */
function renderVendas() {
  document.getElementById('acerto-vendas').innerHTML = vendas.map((p, i) =>
    '<div class="acerto-prod">' +
      '<div class="acerto-prod-name">' + escHtml(p.name) + ' <span class="acerto-prod-sub">— ' + p.qty + ' em loja</span></div>' +
      '<div class="acerto-row"><span class="acerto-label">Vendeu:</span>' +
        '<div class="acerto-ctrl">' +
          '<button type="button" class="acerto-qbtn" onclick="chVenda(' + i + ',-1)">−</button>' +
          '<span class="acerto-num" id="av' + i + '">' + p.vendeu + '</span>' +
          '<button type="button" class="acerto-qbtn" onclick="chVenda(' + i + ',1)">+</button>' +
          '<span class="acerto-info">un. = <strong id="avv' + i + '">' + fmtEUR(p.vendeu * p.price) + '</strong></span>' +
        '</div></div>' +
      '<div class="sale-bar"><div class="sale-fill" id="sb' + i + '" style="width:' + (p.qty ? (p.vendeu / p.qty * 100) : 0) + '%"></div></div>' +
      '<div class="acerto-row" style="margin-top:10px"><span class="acerto-label">Devolveu:</span>' +
        '<div class="acerto-ctrl">' +
          '<button type="button" class="acerto-qbtn" onclick="chDevolve(' + i + ',-1)">−</button>' +
          '<span class="acerto-num" id="ad' + i + '">' + p.devolveu + '</span>' +
          '<button type="button" class="acerto-qbtn" onclick="chDevolve(' + i + ',1)">+</button>' +
          '<span class="acerto-info">↩️ ao fornecedor</span>' +
        '</div></div>' +
      '<div class="acerto-fica">Fica em loja: <strong id="nf' + i + '">' + (p.qty - p.vendeu - p.devolveu) + '</strong> un.</div>' +
    '</div>'
  ).join('') || '<p class="hint">Sem produtos em loja. Use a reposição abaixo para deixar produtos.</p>';
  updateResumo();
}

/* Fica em loja = em loja − vendido − devolvido (mantém stock[] pronto para submeter) */
function syncFica(i) {
  const fica = vendas[i].qty - vendas[i].vendeu - vendas[i].devolveu;
  stock[i].qty = fica;
  const el = document.getElementById('nf' + i);
  if (el) el.textContent = fica;
}

function chVenda(i, d) {
  const max = vendas[i].qty - vendas[i].devolveu;
  vendas[i].vendeu = Math.max(0, Math.min(max, vendas[i].vendeu + d));
  document.getElementById('av' + i).textContent = vendas[i].vendeu;
  document.getElementById('avv' + i).innerHTML = fmtEUR(vendas[i].vendeu * vendas[i].price);
  document.getElementById('sb' + i).style.width = (vendas[i].qty ? (vendas[i].vendeu / vendas[i].qty * 100) : 0) + '%';
  syncFica(i);
  updateResumo();
}

function chDevolve(i, d) {
  const max = vendas[i].qty - vendas[i].vendeu;
  vendas[i].devolveu = Math.max(0, Math.min(max, vendas[i].devolveu + d));
  document.getElementById('ad' + i).textContent = vendas[i].devolveu;
  syncFica(i);
  updateResumo();
}

function updateResumo() {
  const total = vendas.reduce((s, p) => s + p.vendeu * p.price, 0);
  const com = total * RATE, net = total - com;
  const cta = document.getElementById('cta-net');
  cta.textContent = fmtEUR(net);
  cta.style.transform = 'scale(1.12)';
  setTimeout(() => { cta.style.transform = ''; }, 150);
  const devolvidas = vendas.filter(p => p.devolveu > 0);
  const el = document.getElementById('acerto-resumo');
  if (total > 0 || devolvidas.length) {
    let html = '<div class="resumo-box"><div class="resumo-title">💰 Acerto desta visita</div>';
    if (total > 0) {
      html += vendas.filter(p => p.vendeu > 0).map(p =>
        '<div class="resumo-row"><span>' + p.vendeu + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.vendeu * p.price) + '</span></div>'
      ).join('') +
      '<div class="resumo-com"><span>Comissão (' + RATE_PCT + '%)</span><span>− ' + fmtEUR(com) + '</span></div>' +
      '<div class="resumo-net"><span>→ Recebe agora</span><span>' + fmtEUR(net) + '</span></div>';
    } else {
      html += '<div class="resumo-row"><span>Sem vendas nesta visita</span><span>' + fmtEUR(0) + '</span></div>';
    }
    if (devolvidas.length) {
      html += '<div class="resumo-devtitle">↩️ Devolvido ao fornecedor</div>' +
        devolvidas.map(p =>
          '<div class="resumo-row"><span>' + p.devolveu + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.devolveu * p.price) + '</span></div>'
        ).join('');
    }
    html += '</div>';
    el.style.display = 'block';
    el.innerHTML = html;
  } else el.style.display = 'none';
}

/* ── catálogo: preenche nome + preço ao escolher ── */
function pickFromCatalog(sel, nameId, priceId) {
  const o = sel.options[sel.selectedIndex];
  const nameEl = document.getElementById(nameId);
  const priceEl = document.getElementById(priceId);
  if (o.value === '__new__') { nameEl.value = ''; priceEl.value = ''; nameEl.focus(); return; }
  if (!o.value) return;
  nameEl.value = o.value;
  const pr = o.getAttribute('data-price');
  if (pr) priceEl.value = parseFloat(pr).toFixed(2);
  document.getElementById('ac-pqty').focus();
}

/* ── ③ reposição ── */
function renderRepo() {
  document.getElementById('repo-list').innerHTML = repo.map((p, i) =>
    '<div class="prod-item"><div><div class="prod-item-info">🆕 ' + escHtml(p.name) + '</div>' +
    '<div class="prod-item-sub">' + p.qty + ' un. × ' + fmtEUR(p.price) + ' = ' + fmtEUR(p.qty * p.price) + '</div></div>' +
    '<button type="button" class="remove-btn" onclick="repo.splice(' + i + ',1);renderRepo()">×</button></div>'
  ).join('');
  const totalEl = document.getElementById('repo-total');
  if (repo.length) {
    const totalQty = repo.reduce((s, p) => s + p.qty, 0);
    const totalVal = repo.reduce((s, p) => s + p.qty * p.price, 0);
    totalEl.style.display = 'flex';
    document.getElementById('repo-total-count').textContent = totalQty + ' unidade' + (totalQty === 1 ? '' : 's') + ' a repor';
    document.getElementById('repo-total-val').textContent = fmtEUR(totalVal);
  } else totalEl.style.display = 'none';
}

function addRepo() {
  const name = document.getElementById('ac-pname').value.trim();
  const qty = parseInt(document.getElementById('ac-pqty').value) || 0;
  const priceInput = document.getElementById('ac-pprice').value;
  let price = parseFloat(priceInput) || 0;
  // se for um produto já existente e o preço ficou vazio, usa o preço conhecido
  if (priceInput === '') {
    const known = vendas.find(v => v.name.toLowerCase() === name.toLowerCase());
    if (known) price = known.price;
  }
  if (!name || qty <= 0) { alert('Preencha o nome e a quantidade.'); return; }
  repo.push({ name, qty, price });
  document.getElementById('ac-pname').value = '';
  document.getElementById('ac-pqty').value = '1';
  document.getElementById('ac-pprice').value = '';
  document.getElementById('ac-pick').value = '';
  renderRepo();
}

/* ── assinatura ── */
function goSig() {
  const total = vendas.reduce((s, p) => s + p.vendeu * p.price, 0);
  const net = total - total * RATE;
  let resumo = '';
  if (vendas.some(p => p.vendeu > 0)) {
    resumo += vendas.filter(p => p.vendeu > 0).map(p =>
      '<div class="sig-resumo-row"><span>Vendido: ' + p.vendeu + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.vendeu * p.price) + '</span></div>'
    ).join('');
    resumo += '<div class="sig-resumo-total"><span>Recebe agora</span><span>' + fmtEUR(net) + '</span></div>';
  }
  const devolvidas = vendas.filter(p => p.devolveu > 0);
  if (devolvidas.length) {
    resumo += '<div class="sig-resumo-head" style="margin-top:8px">↩️ Devolvido ao fornecedor</div>' +
      devolvidas.map(p => '<div class="sig-resumo-row"><span>' + p.devolveu + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.devolveu * p.price) + '</span></div>').join('');
  }
  if (repo.length) {
    resumo += '<div class="sig-resumo-head" style="margin-top:8px">Reposição nesta visita</div>' +
      repo.map(p => '<div class="sig-resumo-row"><span>🆕 ' + p.qty + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.qty * p.price) + '</span></div>').join('');
  }
  const ficam = stock.filter(p => p.qty > 0);
  if (ficam.length) {
    resumo += '<div class="sig-resumo-head" style="margin-top:8px">Stock que permanece</div>' +
      ficam.map(p => '<div class="sig-resumo-row"><span>' + p.qty + '× ' + escHtml(p.name) + '</span><span>' + fmtEUR(p.qty * p.price) + '</span></div>').join('');
  }
  Sig.open({
    title: 'Confirmar acerto',
    instructionHtml: 'Mostre este ecrã ao cliente <strong>' + escHtml(CLIENT_NAME) + '</strong>.<br>Assine para confirmar o acerto.',
    resumoHtml: resumo,
    onConfirm(dataUrl) {
      document.getElementById('ac-vendas-input').value = JSON.stringify(vendas);
      document.getElementById('ac-stock-input').value = JSON.stringify(stock);
      document.getElementById('ac-repo-input').value = JSON.stringify(repo);
      document.getElementById('ac-signature').value = dataUrl;
      document.getElementById('ac-form').submit();
    }
  });
}

renderVendas();
renderRepo();
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
