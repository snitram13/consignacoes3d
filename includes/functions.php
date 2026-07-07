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

/* Último ID inserido (compatível com Postgres, que precisa do nome da sequência) */
function db_last_id(string $table): int {
    $pdo = db();
    if (DB_DRIVER === 'pgsql') {
        return (int)$pdo->lastInsertId($table . '_id_seq');
    }
    return (int)$pdo->lastInsertId();
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

/* ── WhatsApp / links de recibo ── */

/* Normaliza um telefone para o formato do wa.me: só dígitos, com indicativo.
   • remove espaços, traços, parênteses, '+' …
   • '00xx…'  (prefixo internacional) → 'xx…'
   • número local PT (9 dígitos começado por 9) → acrescenta o indicativo (351)
   Se já vier com indicativo, é respeitado. Devolve '' se não houver dígitos. */
function wa_number(?string $raw, ?string $defaultCC = null): string {
    $cc = $defaultCC ?? (defined('DEFAULT_CC') ? DEFAULT_CC : '351');
    $n = preg_replace('/\D+/', '', (string)($raw ?? ''));
    if ($n === '') return '';
    if (str_starts_with($n, '00')) $n = substr($n, 2);           // 00xx… → xx…
    if (strlen($n) === 9 && $n[0] === '9') $n = $cc . $n;         // local PT → +indicativo
    return $n;
}

/* Assinatura estável de um link público de recibo (impede adivinhar recibos de outros).
   Usa APP_SECRET se existir; senão deriva do hash da conta (secreto e já em BD). */
function receipt_token(int $movementId, array $u): string {
    $key = (defined('APP_SECRET') && APP_SECRET !== '')
        ? APP_SECRET
        : (string)($u['password_hash'] ?? APP_NAME);
    return substr(hash_hmac('sha256', 'recibo:' . $movementId, $key), 0, 24);
}

/* URL base do pedido atual (aguenta o proxy do Render, que usa X-Forwarded-Proto). */
function base_url(): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
        ?? ((($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'http'));
    $proto = explode(',', $proto)[0];               // "https,http" → "https"
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

/* Link público (com token) para a página do recibo — o que vai na mensagem de WhatsApp. */
function recibo_public_url(int $movementId, array $u): string {
    return base_url() . '/r.php?id=' . $movementId . '&t=' . receipt_token($movementId, $u);
}
