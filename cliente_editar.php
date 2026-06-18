<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$c = get_client((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);
if (!$c) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $nif   = trim($_POST['nif'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $commP = str_replace(',', '.', trim((string)($_POST['comm'] ?? '')));
    $comm  = ($commP === '') ? null : (is_numeric($commP) && $commP >= 0 && $commP <= 100 ? ((float)$commP) / 100 : null);

    if ($name === '') {
        $error = 'O nome do cliente é obrigatório.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'O email indicado não é válido.';
    } else {
        $st = db()->prepare('UPDATE clients SET name = ?, nif = ?, phone = ?, email = ?, commission = ? WHERE id = ? AND user_id = ?');
        $st->execute([$name, $nif, $phone, $email, $comm, $c['id'], $u['id']]);
        redirect('cliente.php?id=' . (int)$c['id']);
    }
    // mantém os valores submetidos no formulário em caso de erro
    $c = array_merge($c, ['name' => $name, 'nif' => $nif, 'phone' => $phone, 'email' => $email, 'commission' => $comm]);
}

$commVal = ($c['commission'] === null || $c['commission'] === '')
    ? ''
    : rtrim(rtrim(number_format((float)$c['commission'] * 100, 1, '.', ''), '0'), '.');

$pageTitle = 'Editar cliente';
$backUrl = 'cliente.php?id=' . (int)$c['id'];
$backLabel = 'Voltar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
<form method="post" action="cliente_editar.php?id=<?= (int)$c['id'] ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

  <div class="section-title">Dados do cliente</div>
  <div class="field"><label>Nome</label><input type="text" name="name" value="<?= esc($c['name']) ?>" placeholder="Nome da loja ou pessoa" required></div>
  <div class="row2">
    <div class="field"><label>NIF</label><input type="text" name="nif" inputmode="numeric" value="<?= esc($c['nif']) ?>" placeholder="123456789"></div>
    <div class="field"><label>Telefone</label><input type="tel" name="phone" value="<?= esc($c['phone']) ?>" placeholder="912 345 678"></div>
  </div>
  <div class="field"><label>Email do cliente</label><input type="email" name="email" value="<?= esc($c['email']) ?>" placeholder="cliente@email.com"></div>
  <div class="field"><label>Comissão para este cliente (%) <span class="hint">— deixe vazio para usar a padrão</span></label><input type="number" name="comm" min="0" max="100" step="0.5" inputmode="decimal" value="<?= esc($commVal) ?>" placeholder="<?= esc(rtrim(rtrim(number_format((float)$u['commission'] * 100, 1, '.', ''), '0'), '.')) ?>"></div>

  <button class="btn btn-primary" type="submit" style="margin-top:16px">💾 Guardar alterações</button>
  <a class="btn btn-ghost" href="cliente.php?id=<?= (int)$c['id'] ?>">Cancelar</a>
</form>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
