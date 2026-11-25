<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Destino do QR code da página da prova (teste): abrir.php?prova=<id> abre a prova no
 * aplicativo, pelo endereço leituraoptica://prova/<id>. Não exige login nem mostra nada da
 * prova: quem abre os dados é o app, com o login do professor.
 *
 * O QR aponta para esta página, e não direto para leituraoptica://, porque muitas câmeras
 * de Android não abrem endereços de app; um link http qualquer câmera abre.
 */

$id = filter_var($_GET['prova'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$destino = $id ? "leituraoptica://prova/$id" : null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Abrir no aplicativo | Panorama da Prova</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
<main class="abrir-app">
  <h1>Panorama da Prova</h1>
  <?php if (!$destino): ?>
    <p class="erro" role="alert">Este QR code não indica uma prova. Gere-o de novo na página da prova, no painel.</p>
  <?php else: ?>
    <p>Abrindo a prova no aplicativo…</p>
    <p><a class="botao" href="<?= e($destino) ?>">Abrir no aplicativo</a></p>
    <p class="dica">Se nada acontecer, confira se o aplicativo Panorama da Prova está instalado neste celular.
      O Expo Go não abre este link: é preciso o app instalado (development build).</p>
    <script>window.location.href = <?= json_encode($destino) ?>;</script>
  <?php endif; ?>
</main>
</body>
</html>
