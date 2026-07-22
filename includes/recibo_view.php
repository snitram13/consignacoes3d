<?php
/* Renderização partilhada do recibo.
   Usada por:
     • recibo.php  → vista do fornecedor (2 vias: cliente + fornecedor)
     • r.php       → página pública (link do WhatsApp), só a via do cliente */

require_once __DIR__ . '/functions.php';

/* Carrega movimento + cliente + itens agrupados por tipo.
   Se $userId for indicado, exige que o movimento pertença a esse utilizador
   (usado na área autenticada). Devolve null se não existir / não pertencer. */
function recibo_load(int $movementId, ?int $userId = null): ?array {
    $sql = '
        SELECT m.*, c.name AS c_name, c.nif AS c_nif, c.phone AS c_phone,
               c.email AS c_email, c.id AS c_id, c.user_id AS user_id
        FROM movements m
        JOIN clients c ON c.id = m.client_id
        WHERE m.id = ?' . ($userId !== null ? ' AND c.user_id = ?' : '');
    $st = db()->prepare($sql);
    $st->execute($userId !== null ? [$movementId, $userId] : [$movementId]);
    $m = $st->fetch();
    if (!$m) return null;

    $sti = db()->prepare('SELECT * FROM movement_items WHERE movement_id = ? ORDER BY id');
    $sti->execute([$m['id']]);
    $items = $sti->fetchAll();

    return [
        'm'          => $m,
        'vendidos'   => array_values(array_filter($items, fn($i) => $i['kind'] === 'vendido')),
        'devolvidos' => array_values(array_filter($items, fn($i) => $i['kind'] === 'devolvido')),
        'repostos'   => array_values(array_filter($items, fn($i) => $i['kind'] === 'reposto')),
        'stock'      => array_values(array_filter($items, fn($i) => $i['kind'] === 'stock')),
        'entregues'  => array_values(array_filter($items, fn($i) => $i['kind'] === 'entregue')),
    ];
}

/* Carrega o utilizador (fornecedor) dono de um movimento — para a página pública,
   que não tem sessão iniciada. */
function recibo_user(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    return $u ?: null;
}

/* Uma via do recibo (HTML). */
function via(array $m, array $u, array $vendidos, array $devolvidos, array $repostos, array $stockItems, array $entregues, float $rate, string $titulo, string $copyLabel, string $cls): string {
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
        if ($devolvidos) {
            $val = 0;
            foreach ($devolvidos as $i) $val += $i['qty'] * $i['price'];
            $h .= '<div class="via-sectitle">↩️ Devolvido ao fornecedor nesta visita</div>' . $table($devolvidos, []);
            $h .= '<div class="via-totals"><div class="via-trow big"><span>Valor devolvido</span><span>' . fmt($val) . '</span></div></div>';
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
