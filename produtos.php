<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
        if ($name === '') {
            $error = 'Indique o nome do produto.';
        } else {
            // não duplicar
            $st = db()->prepare('SELECT id FROM catalog WHERE user_id = ? AND LOWER(name) = LOWER(?)');
            $st->execute([$u['id'], $name]);
            if ($st->fetch()) {
                $error = 'Já existe um produto com esse nome no catálogo.';
            } else {
                catalog_upsert((int)$u['id'], $name, max(0, $price));
                $ok = 'Produto adicionado ao catálogo.';
            }
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
        $st = db()->prepare('SELECT name FROM catalog WHERE id = ? AND user_id = ?');
        $st->execute([$id, $u['id']]);
        $cur = $st->fetch();
        if (!$cur) {
            $error = 'Produto não encontrado.';
        } elseif ($name === '') {
            $error = 'Indique o nome do produto.';
        } else {
            $oldName = $cur['name'];
            $renamed = mb_strtolower($name) !== mb_strtolower($oldName);
            // não permitir colidir com outro produto já existente no catálogo
            $dup = db()->prepare('SELECT id FROM catalog WHERE user_id = ? AND LOWER(name) = LOWER(?) AND id <> ?');
            $dup->execute([$u['id'], $name, $id]);
            if ($renamed && $dup->fetch()) {
                $error = 'Já existe outro produto com o nome "' . $name . '" no catálogo.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE catalog SET name = ?, price = ? WHERE id = ? AND user_id = ?')
                        ->execute([$name, max(0, $price), $id, $u['id']]);
                    $updated = 0;
                    if ($renamed) {
                        // propaga o novo nome ao stock atual dos clientes deste utilizador
                        $up = $pdo->prepare('UPDATE products SET name = ? WHERE LOWER(name) = LOWER(?) AND client_id IN (SELECT id FROM clients WHERE user_id = ?)');
                        $up->execute([$name, $oldName, $u['id']]);
                        $updated = $up->rowCount();
                    }
                    $pdo->commit();
                    $ok = $renamed
                        ? 'Produto atualizado. Nome alterado também em ' . $updated . ' registo(s) de stock dos clientes.'
                        : 'Preço atualizado.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Erro ao atualizar: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM catalog WHERE id = ? AND user_id = ?');
        $st->execute([$id, $u['id']]);
        $row = $st->fetch();
        if ($row) {
            $emUso = product_usage_count((int)$u['id'], $row['name']);
            if ($emUso > 0) {
                $error = 'Não pode remover "' . $row['name'] . '" — está em consignação em ' . $emUso . ' cliente' . ($emUso === 1 ? '' : 's') . '. Faça primeiro o acerto desse stock.';
            } else {
                db()->prepare('DELETE FROM catalog WHERE id = ? AND user_id = ?')->execute([$id, $u['id']]);
                $ok = 'Produto removido do catálogo.';
            }
        }
    }
}

$catalog = get_catalog((int)$u['id']);
$usage = catalog_usage_map((int)$u['id']);

$pageTitle = 'Catálogo de produtos';
$backUrl = 'index.php';
$backLabel = 'Início';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-ok" style="background:#e7f6ec;color:#1c6b33;border-radius:10px;padding:10px 12px;margin-bottom:12px"><?= esc($ok) ?></div><?php endif; ?>

<div class="section-title">Adicionar produto</div>
<div class="add-box">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="row3">
      <div class="field" style="margin:0;grid-column:span 2"><label>Nome do produto</label><input type="text" name="name" placeholder="ex.: Vaso geométrico" required></div>
      <div class="field" style="margin:0"><label>Preço €</label><input type="number" name="price" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
    </div>
    <button type="submit" class="btn btn-secondary" style="margin-top:10px">+ Adicionar ao catálogo</button>
  </form>
</div>

<div class="section-title">Produtos no catálogo <?= $catalog ? '(' . count($catalog) . ')' : '' ?></div>
<div class="panel">
  <?php if ($catalog): ?>
    <?php foreach ($catalog as $cp): $emUso = $usage[mb_strtolower($cp['name'])] ?? 0; ?>
      <details class="comm-edit" style="border-bottom:1px solid var(--line,#eee)">
        <summary class="prod-row" style="cursor:pointer;list-style:none">
          <span class="pr1"><?= esc($cp['name']) ?><?php if ($emUso > 0): ?> <span class="badge badge-green" style="font-size:11px">em <?= $emUso ?> cliente<?= $emUso === 1 ? '' : 's' ?></span><?php endif; ?></span>
          <span class="pr3"><?= fmt($cp['price']) ?></span>
          <span class="pr4">✏️</span>
        </summary>
        <form method="post" class="comm-form" style="padding:10px 0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" value="<?= (int)$cp['id'] ?>">
          <div class="row3">
            <div class="field" style="margin:0;grid-column:span 2"><label>Nome</label><input type="text" name="name" value="<?= esc($cp['name']) ?>"></div>
            <div class="field" style="margin:0"><label>Preço €</label><input type="number" name="price" min="0" step="0.01" inputmode="decimal" value="<?= esc(number_format((float)$cp['price'], 2, '.', '')) ?>"></div>
          </div>
          <div class="row2" style="margin-top:10px">
            <button type="submit" class="btn btn-primary">Guardar</button>
          </div>
        </form>
        <?php if ($emUso > 0): ?>
          <p class="hint" style="margin:8px 0 4px;color:#b3261e">🔒 Não pode remover: está em consignação em <?= $emUso ?> cliente<?= $emUso === 1 ? '' : 's' ?>. Faça primeiro o acerto desse stock.</p>
        <?php else: ?>
          <form method="post" onsubmit="return confirm('Remover <?= esc($cp['name']) ?> do catálogo?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$cp['id'] ?>">
            <button type="submit" class="btn btn-danger" style="margin-top:8px">🗑️ Remover</button>
          </form>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="hint" style="padding:8px 0;margin:0">Catálogo vazio. Adicione produtos acima — ou eles ficam registados automaticamente quando os usar numa entrega ou acerto.</p>
  <?php endif; ?>
</div>

<?php $tab = 'produtos'; require __DIR__ . '/includes/bottom_nav.php'; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
