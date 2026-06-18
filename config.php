<?php
/* ─────────────────────────────────────────────
   CONFIGURAÇÃO DA APLICAÇÃO

   • LOCAL  → os valores reais ficam em config.local.php
             (não vai para o Git; mantém os segredos no teu PC)
   • RENDER → os valores vêm de Variáveis de Ambiente
             (definidas no painel do Render)
   ───────────────────────────────────────────── */

// Carrega segredos locais, se existirem (NÃO versionado)
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Helper: variável de ambiente → senão, valor por omissão
$envv = function (string $k, string $default = ''): string {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $default;
};

// ── Base de dados ──
// Supabase/Render dão um único DATABASE_URL (postgres://user:pass@host:porta/dbname).
// Se existir, é o caminho mais simples — preenche tudo automaticamente.
$dbUrl = getenv('DATABASE_URL');
if ($dbUrl && !defined('DB_DRIVER')) {
    $p = parse_url($dbUrl);
    $scheme = $p['scheme'] ?? 'pgsql';
    define('DB_DRIVER', ($scheme === 'postgres' || $scheme === 'postgresql') ? 'pgsql' : $scheme);
    if (!defined('DB_HOST') && isset($p['host'])) define('DB_HOST', $p['host']);
    if (!defined('DB_PORT') && isset($p['port'])) define('DB_PORT', (int)$p['port']);
    if (!defined('DB_NAME') && isset($p['path'])) define('DB_NAME', ltrim($p['path'], '/'));
    if (!defined('DB_USER') && isset($p['user'])) define('DB_USER', urldecode($p['user']));
    if (!defined('DB_PASS') && isset($p['pass'])) define('DB_PASS', urldecode($p['pass']));
}

if (!defined('DB_DRIVER'))      define('DB_DRIVER', $envv('DB_DRIVER', 'sqlite'));   // 'pgsql', 'mysql' ou 'sqlite'
if (!defined('DB_HOST'))        define('DB_HOST', $envv('DB_HOST', 'localhost'));
if (!defined('DB_PORT'))        define('DB_PORT', (int)$envv('DB_PORT', DB_DRIVER === 'pgsql' ? '5432' : '3306'));
if (!defined('DB_NAME'))        define('DB_NAME', $envv('DB_NAME', DB_DRIVER === 'pgsql' ? 'postgres' : 'consignacoes'));
if (!defined('DB_USER'))        define('DB_USER', $envv('DB_USER', DB_DRIVER === 'pgsql' ? 'postgres' : 'root'));
if (!defined('DB_PASS'))        define('DB_PASS', $envv('DB_PASS', ''));
if (!defined('DB_SQLITE_PATH')) define('DB_SQLITE_PATH', $envv('DB_SQLITE_PATH', __DIR__ . '/data/consignacoes.sqlite'));
if (!defined('DB_SSLMODE'))     define('DB_SSLMODE', $envv('DB_SSLMODE', 'require')); // Supabase exige 'require'

// ── Geral ──
if (!defined('APP_NAME'))     define('APP_NAME', $envv('APP_NAME', 'Consignações 3D'));
if (!defined('COMM_DEFAULT')) define('COMM_DEFAULT', (float)$envv('COMM_DEFAULT', '0.20')); // comissão padrão (20%)

// ── Email (SMTP) ──
if (!defined('SMTP_HOST'))      define('SMTP_HOST', $envv('SMTP_HOST', ''));        // ex.: 'smtp.gmail.com'
if (!defined('SMTP_PORT'))      define('SMTP_PORT', (int)$envv('SMTP_PORT', '587'));
if (!defined('SMTP_USER'))      define('SMTP_USER', $envv('SMTP_USER', ''));        // ex.: 'oseuemail@gmail.com'
if (!defined('SMTP_PASS'))      define('SMTP_PASS', $envv('SMTP_PASS', ''));        // palavra-passe de app
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', $envv('SMTP_FROM_NAME', '')); // vazio = usa marca/nome do perfil
