<?php

/* Escapa HTML */
function esc($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Formata valor monetário: 1234.5 → 1 234,50€ */
function fmt($v): string {
    return number_format((float)$v, 2, ',', ' ') . '€';
}

/* Formata data Y-m-d → d/m/Y */
function fmt_date($d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : '—';
}

/* Comissão efetiva de um cliente (cliente → utilizador → padrão) */
function comm_rate(?array $client, array $user): float {
    if ($client && $client['commission'] !== null && $client['commission'] !== '') {
        return (float)$client['commission'];
    }
    if ($user['commission'] !== null && $user['commission'] !== '') {
        return (float)$user['commission'];
    }
    return COMM_DEFAULT;
}

/* Percentagem para mostrar: 0.2 → "20" · 0.125 → "12,5" */
function comm_pct(float $r): string {
    $p = number_format($r * 100, 1, ',', '');
    return rtrim(rtrim($p, '0'), ',');
}

/* Gera número de recibo */
function gen_rec_id(): string {
    return 'RC-' . substr((string)round(microtime(true) * 1000), -7);
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/* Lê e valida um array de produtos enviado como JSON pelo formulário */
function parse_products_json(?string $json): array {
    $arr = json_decode($json ?? '[]', true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $p) {
        $name  = trim((string)($p['name'] ?? ''));
        $qty   = (int)($p['qty'] ?? 0);
        $price = (float)($p['price'] ?? 0);
        if ($name === '' || $qty < 0 || $price < 0) continue;
        $out[] = ['name' => $name, 'qty' => $qty, 'price' => $price];
    }
    return $out;
}

/* Valida que a assinatura é um data-url PNG */
function valid_signature(?string $sig): bool {
    return is_string($sig) && strpos($sig, 'data:image/png;base64,') === 0 && strlen($sig) < 800000;
}
