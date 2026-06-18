<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_DRIVER === 'sqlite') {
            if (!is_dir(dirname(DB_SQLITE_PATH))) {
                mkdir(dirname(DB_SQLITE_PATH), 0775, true);
            }
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS
            );
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

/* Verifica se a base de dados já foi instalada */
function db_installed(): bool {
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Exception $e) {
        return false;
    }
}
