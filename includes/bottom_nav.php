<?php /* Barra de navegação inferior — define $tab ('home'|'novo'|'perfil') antes de incluir */
$tab = $tab ?? ''; ?>
<div class="tabbar-spacer"></div>
<nav class="tabbar">
  <a class="tab-item <?= $tab === 'home' ? 'active' : '' ?>" href="index.php"><span class="tab-ico">🏠</span>Início</a>
  <a class="tab-item <?= $tab === 'produtos' ? 'active' : '' ?>" href="produtos.php"><span class="tab-ico">🏷️</span>Produtos</a>
  <a class="tab-item tab-new <?= $tab === 'novo' ? 'active' : '' ?>" href="cliente_novo.php"><span class="tab-new-btn">＋</span>Novo</a>
  <a class="tab-item <?= $tab === 'perfil' ? 'active' : '' ?>" href="perfil.php"><span class="tab-ico">👤</span>Perfil</a>
</nav>
