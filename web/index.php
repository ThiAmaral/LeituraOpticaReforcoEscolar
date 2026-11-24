<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

iniciar_pagina();

$lista = listar_provas($_GET);
['provas' => $provas, 'filtros' => $filtros, 'escolhidos' => $escolhidos, 'pagina' => $pagina,
    'paginas' => $paginas, 'por_pagina' => $porPagina, 'total' => $total] = $lista;
$temProvas = (bool)db()->query('SELECT 1 FROM provas LIMIT 1')->fetchColumn();

/** Link desta lista mantendo os filtros e a quantidade por página. */
function link_lista(array $escolhidos, int $porPagina, int $pagina): string
{
    return url('index.php', $escolhidos + [
        'por_pagina' => $porPagina !== ITENS_POR_PAGINA_PADRAO ? $porPagina : null,
        'pagina' => $pagina > 1 ? $pagina : null,
    ]);
}

$janela = janela_de_paginas($pagina, $paginas);
$primeiroItem = $total ? ($pagina - 1) * $porPagina + 1 : 0;
$ultimoItem = min($total, $pagina * $porPagina);

cabecalho('Provas', 'provas');
?>

<?php if ($temProvas): ?>
<form method="get" class="filtros" role="search" aria-label="Filtrar provas">
  <?php foreach ($filtros as $chave => $f): ?>
    <label><?= e($f['rotulo']) ?>
      <select name="<?= e($chave) ?>">
        <option value="">Todos</option>
        <?php foreach ($f['opcoes'] as $valor => $rotulo): ?>
          <option value="<?= e((string)$valor) ?>" <?= ($escolhidos[$chave] ?? null) === (string)$valor ? 'selected' : '' ?>><?= e((string)$rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endforeach; ?>
  <?php if ($porPagina !== ITENS_POR_PAGINA_PADRAO): ?><input type="hidden" name="por_pagina" value="<?= $porPagina ?>"><?php endif; ?>
  <button type="submit">Filtrar</button>
  <?php if ($escolhidos): ?><a class="limpar" href="<?= e(link_lista([], $porPagina, 1)) ?>">Limpar filtros</a><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($provas): ?>
<div class="lista-provas">
  <table class="tabela">
    <thead><tr><th>Prova</th><th>Ano letivo</th><th class="num">Turmas</th><th class="num">Folhas lidas</th><th>Criada em</th></tr></thead>
    <tbody>
    <?php foreach ($provas as $p): ?>
      <tr>
        <td>
          <?php if ($p['status'] === 'rascunho'): ?>
            <a href="gabarito.php?id=<?= $p['id'] ?>"><?= e($p['nome']) ?></a>
            <a class="selo atencao" href="gabarito.php?id=<?= $p['id'] ?>">Gabarito a revisar</a>
          <?php else: ?>
            <a href="prova.php?id=<?= $p['id'] ?>"><?= e($p['nome']) ?></a>
          <?php endif; ?>
        </td>
        <td><?= e($p['ano'] . ' · ' . rotulo_edicao($p['edicao'])) ?></td>
        <td class="num"><?= $p['turmas'] ?></td>
        <td class="num"><?= $p['folhas'] ?></td>
        <td><?= e(date('d/m/Y', strtotime($p['criado_em']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="rodape-tabela">
    <p class="contagem"><?= $primeiroItem ?>–<?= $ultimoItem ?> de <?= $total ?> <?= $total === 1 ? 'prova' : 'provas' ?></p>

    <nav class="paginacao" aria-label="Páginas da lista">
      <?php if ($pagina > 1): ?>
        <a href="<?= e(link_lista($escolhidos, $porPagina, 1)) ?>" aria-label="Primeira página" title="Primeira página">&lt;</a>
      <?php else: ?>
        <span class="desativado" aria-hidden="true">&lt;</span>
      <?php endif; ?>

      <?php if ($janela['antes']): ?>
        <a href="<?= e(link_lista($escolhidos, $porPagina, $janela['antes'])) ?>" aria-label="Página <?= $janela['antes'] ?>" title="Página <?= $janela['antes'] ?>">…</a>
      <?php endif; ?>

      <?php for ($n = $janela['inicio']; $n <= $janela['fim']; $n++): ?>
        <?php if ($n === $pagina): ?>
          <span class="atual" aria-current="page"><?= $n ?></span>
        <?php else: ?>
          <a href="<?= e(link_lista($escolhidos, $porPagina, $n)) ?>" aria-label="Página <?= $n ?>"><?= $n ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($janela['depois']): ?>
        <a href="<?= e(link_lista($escolhidos, $porPagina, $janela['depois'])) ?>" aria-label="Página <?= $janela['depois'] ?>" title="Página <?= $janela['depois'] ?>">…</a>
      <?php endif; ?>

      <?php if ($pagina < $paginas): ?>
        <a href="<?= e(link_lista($escolhidos, $porPagina, $paginas)) ?>" aria-label="Última página (<?= $paginas ?>)" title="Última página (<?= $paginas ?>)">&gt;</a>
      <?php else: ?>
        <span class="desativado" aria-hidden="true">&gt;</span>
      <?php endif; ?>
    </nav>

    <form method="get" class="por-pagina">
      <?php foreach ($escolhidos as $chave => $valor): ?><input type="hidden" name="<?= e($chave) ?>" value="<?= e($valor) ?>"><?php endforeach; ?>
      <label>Itens por página
        <select name="por_pagina" onchange="this.form.submit()">
          <?php foreach (ITENS_POR_PAGINA as $n): ?>
            <option value="<?= $n ?>" <?= $n === $porPagina ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="secundario">Mostrar</button></noscript>
    </form>
  </div>
</div>
<?php elseif ($temProvas): ?>
<p class="vazio">Nenhuma prova encontrada com esses filtros. <a href="index.php">Ver todas</a></p>
<?php else: ?>
<p class="vazio">Nenhuma prova cadastrada ainda. Em <a href="nova-prova.php">Nova prova</a>, informe o caderno, a edição e o
  número de questões; depois preencha o gabarito e libere a prova. As folhas dos alunos são enviadas pelo aplicativo.</p>
<?php endif; ?>

<?php rodape();
