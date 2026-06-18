<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ── CSRF ── */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '">';
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
            http_response_code(403);
            exit('Sessão expirada. Volte atrás e tente novamente.');
        }
    }
}

/* ── Sessão ── */
function current_user(): ?array {
    static $user = null;
    if ($user === null && !empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}

function require_login(): array {
    if (!db_installed()) {
        redirect('install.php');
    }
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    ensure_catalog_table();
    return $u;
}

/* ── Catálogo de produtos (lista global por utilizador) ── */

/* Cria a tabela do catálogo se ainda não existir (migração automática) */
function ensure_catalog_table(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();
    if (DB_DRIVER === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(160) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $done = true;
}

/* Devolve o catálogo do utilizador, ordenado por nome */
function get_catalog(int $userId): array {
    ensure_catalog_table();
    $st = db()->prepare('SELECT * FROM catalog WHERE user_id = ? ORDER BY name COLLATE NOCASE');
    try {
        $st->execute([$userId]);
    } catch (Exception $e) {
        // MySQL não conhece COLLATE NOCASE
        $st = db()->prepare('SELECT * FROM catalog WHERE user_id = ? ORDER BY name');
        $st->execute([$userId]);
    }
    return $st->fetchAll();
}

/* Quantos clientes têm este produto (por nome) em stock de consignação */
function product_usage_count(int $userId, string $name): int {
    $st = db()->prepare('SELECT COUNT(DISTINCT c.id) FROM products p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.qty > 0 AND LOWER(p.name) = LOWER(?)');
    $st->execute([$userId, $name]);
    return (int)$st->fetchColumn();
}

/* Mapa nome(minúsculas) → nº de clientes em que está em stock — para a página do catálogo */
function catalog_usage_map(int $userId): array {
    $st = db()->prepare('SELECT LOWER(p.name) AS k, COUNT(DISTINCT c.id) AS n FROM products p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.qty > 0 GROUP BY LOWER(p.name)');
    $st->execute([$userId]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['k']] = (int)$r['n'];
    return $map;
}

/* Insere o produto no catálogo se ainda não existir; atualiza o preço se for novo valor */
function catalog_upsert(int $userId, string $name, float $price): void {
    ensure_catalog_table();
    $name = trim($name);
    if ($name === '') return;
    $st = db()->prepare('SELECT id FROM catalog WHERE user_id = ? AND LOWER(name) = LOWER(?)');
    $st->execute([$userId, $name]);
    $row = $st->fetch();
    if ($row) {
        if ($price > 0) {
            db()->prepare('UPDATE catalog SET price = ? WHERE id = ?')->execute([$price, $row['id']]);
        }
    } else {
        db()->prepare('INSERT INTO catalog (user_id, name, price) VALUES (?,?,?)')->execute([$userId, $name, $price]);
    }
}

/* Busca um cliente garantindo que pertence ao utilizador */
function get_client(int $id, int $userId): ?array {
    $st = db()->prepare('SELECT * FROM clients WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    return $st->fetch() ?: null;
}

function get_client_products(int $clientId): array {
    $st = db()->prepare('SELECT * FROM products WHERE client_id = ? ORDER BY id');
    $st->execute([$clientId]);
    return $st->fetchAll();
}
