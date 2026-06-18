<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
session_start();

$err = '';
$installed = db_installed();

if ($installed) {
    $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        header('Location: login.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $pass2    = $_POST['password2'] ?? '';
    $name     = trim($_POST['name'] ?? '');

    if ($username === '' || strlen($pass) < 6) {
        $err = 'Indique um utilizador e uma palavra-passe com pelo menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $err = 'As palavras-passe não coincidem.';
    } else {
        try {
            $pdo = db();
            if (DB_DRIVER === 'sqlite') {
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    name TEXT DEFAULT '',
                    nif TEXT DEFAULT '',
                    phone TEXT DEFAULT '',
                    email TEXT DEFAULT '',
                    brand TEXT DEFAULT '',
                    logo TEXT,
                    commission REAL DEFAULT 0.20,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS clients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    nif TEXT DEFAULT '',
                    phone TEXT DEFAULT '',
                    email TEXT DEFAULT '',
                    commission REAL,
                    last_date TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    qty INTEGER NOT NULL DEFAULT 0,
                    price REAL NOT NULL DEFAULT 0,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS movements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    type TEXT NOT NULL,
                    mov_date TEXT NOT NULL,
                    rec_id TEXT NOT NULL,
                    comm_rate REAL NOT NULL,
                    total_sold REAL DEFAULT 0,
                    commission_value REAL DEFAULT 0,
                    net_value REAL DEFAULT 0,
                    signature TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS movement_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    movement_id INTEGER NOT NULL,
                    kind TEXT NOT NULL,
                    name TEXT NOT NULL,
                    qty INTEGER NOT NULL,
                    price REAL NOT NULL,
                    FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS catalog (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    price REAL NOT NULL DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );");
            } elseif (DB_DRIVER === 'pgsql') {
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(60) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    name VARCHAR(120) DEFAULT '',
                    nif VARCHAR(20) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    email VARCHAR(120) DEFAULT '',
                    brand VARCHAR(120) DEFAULT '',
                    logo TEXT,
                    commission REAL DEFAULT 0.20,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS clients (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    name VARCHAR(120) NOT NULL,
                    nif VARCHAR(20) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    email VARCHAR(120) DEFAULT '',
                    commission REAL,
                    last_date DATE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS products (
                    id SERIAL PRIMARY KEY,
                    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
                    name VARCHAR(160) NOT NULL,
                    qty INTEGER NOT NULL DEFAULT 0,
                    price REAL NOT NULL DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS movements (
                    id SERIAL PRIMARY KEY,
                    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
                    type VARCHAR(10) NOT NULL,
                    mov_date DATE NOT NULL,
                    rec_id VARCHAR(20) NOT NULL,
                    comm_rate REAL NOT NULL,
                    total_sold REAL DEFAULT 0,
                    commission_value REAL DEFAULT 0,
                    net_value REAL DEFAULT 0,
                    signature TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS movement_items (
                    id SERIAL PRIMARY KEY,
                    movement_id INTEGER NOT NULL REFERENCES movements(id) ON DELETE CASCADE,
                    kind VARCHAR(10) NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    qty INTEGER NOT NULL,
                    price REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS catalog (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    name VARCHAR(160) NOT NULL,
                    price REAL NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );");
            } else {
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(60) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    name VARCHAR(120) DEFAULT '',
                    nif VARCHAR(20) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    email VARCHAR(120) DEFAULT '',
                    brand VARCHAR(120) DEFAULT '',
                    logo MEDIUMTEXT,
                    commission DECIMAL(6,4) DEFAULT 0.2000,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                CREATE TABLE IF NOT EXISTS clients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    name VARCHAR(120) NOT NULL,
                    nif VARCHAR(20) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    email VARCHAR(120) DEFAULT '',
                    commission DECIMAL(6,4) NULL,
                    last_date DATE NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                CREATE TABLE IF NOT EXISTS products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    qty INT NOT NULL DEFAULT 0,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                CREATE TABLE IF NOT EXISTS movements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    type VARCHAR(10) NOT NULL,
                    mov_date DATE NOT NULL,
                    rec_id VARCHAR(20) NOT NULL,
                    comm_rate DECIMAL(6,4) NOT NULL,
                    total_sold DECIMAL(10,2) DEFAULT 0,
                    commission_value DECIMAL(10,2) DEFAULT 0,
                    net_value DECIMAL(10,2) DEFAULT 0,
                    signature MEDIUMTEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                CREATE TABLE IF NOT EXISTS movement_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    movement_id INT NOT NULL,
                    kind VARCHAR(10) NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    qty INT NOT NULL,
                    price DECIMAL(10,2) NOT NULL,
                    FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                CREATE TABLE IF NOT EXISTS catalog (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $st = $pdo->prepare('INSERT INTO users (username, password_hash, name, commission) VALUES (?,?,?,?)');
            $st->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name, COMM_DEFAULT]);

            $_SESSION['uid'] = db_last_id('users');
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $err = 'Erro ao instalar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Instalação · <?= esc(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="centered-page">
<div class="login-card">
    <div class="login-icon">📦</div>
    <h2><?= esc(APP_NAME) ?></h2>
    <p>Primeira utilização — crie a sua conta de acesso.<br>
       Banco de dados: <strong><?= esc(DB_DRIVER === 'sqlite' ? 'SQLite' : 'MySQL (' . DB_NAME . ')') ?></strong></p>
    <?php if ($err): ?><div class="alert alert-error"><?= esc($err) ?></div><?php endif; ?>
    <form method="post">
        <div class="field"><label>O seu nome</label><input type="text" name="name" value="<?= esc($_POST['name'] ?? '') ?>" placeholder="Nome completo"></div>
        <div class="field"><label>Utilizador</label><input type="text" name="username" value="<?= esc($_POST['username'] ?? '') ?>" placeholder="ex.: andre" required autofocus></div>
        <div class="field"><label>Palavra-passe (mín. 6)</label><input type="password" name="password" required></div>
        <div class="field"><label>Repetir palavra-passe</label><input type="password" name="password2" required></div>
        <button class="btn btn-primary" type="submit">Instalar e entrar</button>
    </form>
</div>
</body>
</html>
