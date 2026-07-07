<?php
/* PÁGINA TEMPORÁRIA DE RESET — apaga dados de teste (mantém 'users').
   É removida logo após o uso. Protegida por token. */
require_once __DIR__ . '/includes/db.php';

if (($_GET['t'] ?? '') !== 'fe1a38765523ed50e9043cd7d3198d39a2698f3a') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$out = [];
/* apagar em ordem filho→pai (mantém a tabela users) */
foreach (['movement_items','movements','products','clients','catalog'] as $t) {
    try { $n = $pdo->exec("DELETE FROM $t"); $out[] = "$t: apagadas $n linha(s)"; }
    catch (Throwable $e) { $out[] = "$t: ERRO ".$e->getMessage(); }
}
$out[] = '--- contagens finais ---';
foreach (['users','clients','products','movements','movement_items','catalog'] as $t) {
    try { $c = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); $out[] = "$t = $c"; }
    catch (Throwable $e) { $out[] = "$t = ERRO"; }
}
echo implode("\n", $out), "\n";
