<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = trim($_POST['brand'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $nif   = trim($_POST['nif'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $commP = str_replace(',', '.', (string)($_POST['comm'] ?? ''));
    $comm  = is_numeric($commP) && $commP >= 0 && $commP <= 100 ? ((float)$commP) / 100 : (float)$u['commission'];

    $logo = $u['logo'];
    if (!empty($_POST['remove_logo'])) {
        $logo = null;
    } elseif (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $raw = file_get_contents($_FILES['logo']['tmp_name']);
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($raw) : false;
        if ($img) {
            // redimensiona para máx. 256px e guarda como PNG base64
            $w = imagesx($img); $h = imagesy($img);
            $max = 256;
            $scale = min(1, $max / max($w, $h));
            $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagepng($dst); $png = ob_get_clean();
            imagedestroy($img); imagedestroy($dst);
            $logo = 'data:image/png;base64,' . base64_encode($png);
        } else {
            $info = @getimagesize($_FILES['logo']['tmp_name']);
            if ($info && strlen($raw) < 400000) {
                $logo = 'data:' . $info['mime'] . ';base64,' . base64_encode($raw);
            } else {
                $msg = 'Imagem inválida ou demasiado grande (máx. ~400 KB sem GD).';
            }
        }
    }

    $st = db()->prepare('UPDATE users SET brand=?, name=?, nif=?, phone=?, email=?, commission=?, logo=? WHERE id=?');
    $st->execute([$brand, $name, $nif, $phone, $email, $comm, $logo, $u['id']]);
    if (!$msg) redirect('index.php');

    // recarrega para mostrar estado atual junto do aviso
    $stu = db()->prepare('SELECT * FROM users WHERE id=?');
    $stu->execute([$u['id']]);
    $u = $stu->fetch();
}

$pageTitle = 'Os meus dados';
$backUrl = 'index.php';
$backLabel = 'Voltar';
require __DIR__ . '/includes/layout_header.php';
?>
<?php if ($msg): ?><div class="alert alert-error"><?= esc($msg) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="section-title">Identidade</div>
  <div class="logo-row">
    <div class="logo-preview">
      <?php if ($u['logo']): ?><img src="<?= esc($u['logo']) ?>" alt="logo"><?php else: ?>📦<?php endif; ?>
    </div>
    <div style="flex:1">
      <label class="btn btn-secondary" style="margin:0;cursor:pointer">📷 Carregar logótipo
        <input type="file" name="logo" accept="image/*" style="display:none" onchange="this.form.querySelector('.logo-hint').textContent=this.files[0]?this.files[0].name:''">
      </label>
      <div class="logo-hint" style="font-size:12px;color:var(--text3);margin-top:4px"></div>
      <?php if ($u['logo']): ?>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text2);margin-top:8px">
        <input type="checkbox" name="remove_logo" value="1" style="width:auto"> Remover logótipo
      </label>
      <?php endif; ?>
    </div>
  </div>
  <div class="field"><label>Nome / marca (aparece no topo)</label><input type="text" name="brand" value="<?= esc($u['brand']) ?>" placeholder="Consignações de Impressões 3D"></div>
  <hr>
  <p class="hint">Estes dados aparecem em todas as fichas e recibos.</p>
  <div class="field"><label>Nome</label><input type="text" name="name" value="<?= esc($u['name']) ?>" placeholder="O seu nome completo"></div>
  <div class="field"><label>NIF</label><input type="text" name="nif" inputmode="numeric" value="<?= esc($u['nif']) ?>" placeholder="123456789"></div>
  <div class="field"><label>Telefone</label><input type="tel" name="phone" value="<?= esc($u['phone']) ?>" placeholder="912 345 678"></div>
  <div class="field"><label>O meu email (cópia das fichas)</label><input type="email" name="email" value="<?= esc($u['email']) ?>" placeholder="meu@email.com"></div>
  <div class="field"><label>Comissão padrão (%)</label><input type="number" name="comm" min="0" max="100" step="0.5" inputmode="decimal" value="<?= esc(rtrim(rtrim(number_format((float)$u['commission'] * 100, 1, '.', ''), '0'), '.')) ?>"></div>
  <button class="btn btn-primary" type="submit">Guardar</button>
</form>
<hr>
<div class="section-title">Sessão</div>
<a class="btn btn-ghost" href="logout.php">Terminar sessão</a>
<?php $tab = 'perfil'; require __DIR__ . '/includes/bottom_nav.php'; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
