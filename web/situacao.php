<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Muda a situação de uma prova (POST id, acao): cancelar, ativar ou excluir.
 * Só prova cancelada pode ser excluída. Depois volta para a página da prova, ou para a
 * lista se ela foi excluída.
 */

iniciar_pagina();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}
$prova = buscar_prova((int)($_POST['id'] ?? 0));
$id = $prova['id'];

switch ($_POST['acao'] ?? '') {
    case 'cancelar':
        cancelar_prova($prova);
        break;
    case 'ativar':
        ativar_prova($prova);
        break;
    case 'excluir':
        excluir_prova($prova);
        redirecionar('index.php?excluida=1');
        // redirecionar() encerra a requisição
    default:
        throw new ErroDoUsuario('Ação desconhecida.', 400);
}
redirecionar(($prova['status'] === 'rascunho' ? 'gabarito.php' : 'prova.php') . "?id=$id");
