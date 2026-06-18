<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();
csrf_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');

$c = get_client((int)($_POST['id'] ?? 0), (int)$u['id']);
if ($c) {
    // sem FK em cascata garantida em todos os ambientes: apaga manualmente por ordem
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM movements WHERE client_id = ?');
        $st->execute([$c['id']]);
        $movIds = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($movIds) {
            $in = implode(',', array_fill(0, count($movIds), '?'));
            $pdo->prepare("DELETE FROM movement_items WHERE movement_id IN ($in)")->execute($movIds);
        }
        $pdo->prepare('DELETE FROM movements WHERE client_id = ?')->execute([$c['id']]);
        $pdo->prepare('DELETE FROM products WHERE client_id = ?')->execute([$c['id']]);
        $pdo->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?')->execute([$c['id'], $u['id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
    }
}
redirect('index.php');
