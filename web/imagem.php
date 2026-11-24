<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Imagem anotada de uma folha (?folha=<id>). Fica em dados/imagens, fora da pasta
// pública: só sai por aqui, para quem está logado.
iniciar_pagina();
$st = db()->prepare('SELECT imagem_debug FROM folhas WHERE id = ?');
$st->execute([(int)($_GET['folha'] ?? 0)]);
$arquivo = arquivo_da_imagem($st->fetchColumn() ?: null);
if (!$arquivo) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($arquivo));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($arquivo);
