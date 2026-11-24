<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Página da prova liberada: resultados das folhas enviadas pelo aplicativo.
 * Rascunho vai para o gabarito: só prova liberada recebe folhas e tem relatório.
 */

iniciar_pagina();
$prova = buscar_prova((int)($_GET['id'] ?? 0));
$id = $prova['id'];
if ($prova['status'] === 'rascunho') {
    redirecionar("gabarito.php?id=$id");
}
$layout = $prova['layout'];
$gabarito = $prova['gabarito'];
$aviso = isset($_GET['liberada']) ? 'Prova liberada. As folhas dos alunos já podem ser enviadas pelo aplicativo.' : null;

$questoes = $prova['questoes'];
$relatorio = relatorio_prova($prova, (string)($_GET['turma'] ?? ''));
[
    'turma' => $filtro, 'turmas' => $turmas, 'resumo' => $resumo, 'lidas' => $lidas, 'falhas' => $falhas,
    'visiveis' => $visiveis, 'disciplinas' => $disciplinas, 'niveis' => $niveis, 'estatisticas' => $estatisticas,
    'prioridades' => $prioridades, 'matriz' => $matriz, 'comparadas' => $comparadas, 'iguais' => $iguais,
    'divergentes' => $divergentes, 'precisao' => $precisao, 'precisao_por_turma' => $precisaoTurmas,
] = $relatorio;
// Questões que mais deram leitura duvidosa (pode ser bolinha mal impressa ou apagada).
$maisDuvidosas = array_values(array_filter($estatisticas, fn($q) => $q['duvidosas'] >= max(2, 0.1 * $q['alunos'])));
usort($maisDuvidosas, fn($a, $b) => $b['duvidosas'] <=> $a['duvidosas']);

// Filtros do mapa de questões
$filtroDisciplina = in_array($_GET['disciplina'] ?? '', $disciplinas, true) ? $_GET['disciplina'] : '';
$filtroDificuldade = array_key_exists($_GET['dificuldade'] ?? '', DIFICULDADES + ['sem' => 1]) ? $_GET['dificuldade'] : '';
$mapa = filtrar_questoes($estatisticas, $filtroDisciplina, $filtroDificuldade);

$grupos = [
    0 => ['Conteúdo que a turma deveria dominar', 'Marcadas como fáceis ou médias, com acerto baixo.'],
    1 => ['Sem nível de dificuldade', 'Marque a dificuldade para saber se o acerto baixo era esperado.'],
    2 => ['Difíceis', 'Acerto baixo já era esperado; retome se houver tempo.'],
];

function classe_taxa(?float $t): string
{
    if ($t === null) {
        return '';
    }
    return $t < LIMITE_REFORCO ? 'baixa' : 'boa';
}

/** Selo com a dificuldade marcada (F, M, D); vazio sem nível. */
function selo_dificuldade(?string $nivel, bool $curto = false): string
{
    if ($nivel === null) {
        return '';
    }
    $rotulo = DIFICULDADES[$nivel];
    return '<span class="nivel ' . $nivel . '" title="' . e($rotulo) . '">' . e($curto ? mb_substr($rotulo, 0, 1) : $rotulo) . '</span>';
}

/** Link da própria página trocando só alguns parâmetros (turma, filtros do mapa). */
function link_relatorio(int $id, array $params, string $ancora = ''): string
{
    return url('prova.php', ['id' => $id] + $params) . ($ancora ? "#$ancora" : '');
}

$alvo = $filtro !== '' ? 'na turma ' . $filtro : (count($turmas) > 1 ? 'em todas as turmas' : '');

cabecalho($prova['nome'], '', 'index.php');
?>

<p class="meta">
  <?= e(descrever_formato($layout, count($gabarito))) ?>.
  Gabarito: <span class="gabarito"><?= e(implode('', array_map(fn($r) => $r ?? '·', $gabarito))) ?></span>.
  Reforço indicado abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto.
  <a class="nao-imprimir" href="gabarito.php?id=<?= $id ?>">Editar gabarito e dificuldade</a>
</p>
<p class="so-impressao meta">Relatório <?= $filtro !== '' ? 'da turma ' . e($filtro) : (count($turmas) > 1 ? 'de todas as turmas' : '') ?> gerado em <?= date('d/m/Y') ?> às <?= date('H:i') ?>, com <?= count($visiveis) ?> folha(s).</p>

<?php if ($aviso): ?><p class="aviso"><?= e($aviso) ?></p><?php endif; ?>

<?php if (!$lidas): ?>
<section class="sem-folhas">
  <h2>Nenhuma folha ainda</h2>
  <p class="vazio">As folhas dos alunos são fotografadas e enviadas pelo aplicativo: escolha esta prova, informe a turma
    (por exemplo, 8A) e tire as fotos. Os resultados aparecem aqui assim que as primeiras folhas forem lidas.</p>
</section>
<?php else: ?>
<section>
  <div class="titulo-secao">
    <h2>Turmas</h2>
    <p class="acoes-relatorio nao-imprimir">
      <button type="button" class="secundario" onclick="window.print()">Imprimir relatório<?= $filtro !== '' ? ' da ' . e($filtro) : '' ?></button>
      <a href="<?= e(url('exportar.php', ['id' => $id, 'tipo' => 'questoes', 'turma' => $filtro])) ?>">CSV por questão</a>
      <a href="<?= e(url('exportar.php', ['id' => $id, 'tipo' => 'turmas'])) ?>">CSV por turma</a>
    </p>
  </div>
  <table class="tabela">
    <thead>
      <?php if ($niveis): ?>
      <tr class="grupos">
        <th colspan="3"></th>
        <th colspan="<?= count($disciplinas) ?>" class="num inicio-grupo">Por disciplina</th>
        <th colspan="<?= count($niveis) ?>" class="num inicio-grupo">Por dificuldade</th>
        <th colspan="2"></th>
      </tr>
      <?php endif; ?>
      <tr>
        <th>Turma</th><th class="num">Folhas</th><th class="num">Acerto</th>
        <?php foreach ($disciplinas as $i => $d): ?><th class="num<?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= e($d) ?></th><?php endforeach; ?>
        <?php foreach ($niveis as $i => $n): ?><th class="num<?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= e(DIFICULDADES[$n]) ?></th><?php endforeach; ?>
        <th class="num">Para reforço</th><th class="num">A revisar</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($turmas as $t): $r = $resumo[$t]; $tx = pct($r['acertos'], $r['total']); ?>
      <tr class="<?= $filtro === $t ? 'ativa' : '' ?>">
        <td><a href="<?= e(link_relatorio($id, ['turma' => $t], 'prioridades')) ?>"><?= e($t) ?></a></td>
        <td class="num"><?= $r['folhas'] ?></td>
        <td class="num <?= classe_taxa($tx) ?>"><?= fmt_pct($tx) ?></td>
        <?php foreach ($disciplinas as $i => $d): $v = $r['disciplinas'][$d] ?? [0, 0]; $ta = pct($v[0], $v[1]); ?>
          <td class="num <?= classe_taxa($ta) ?><?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= fmt_pct($ta) ?></td>
        <?php endforeach; ?>
        <?php foreach ($niveis as $i => $n): $v = $r['niveis'][$n] ?? [0, 0]; $ta = pct($v[0], $v[1]); ?>
          <td class="num <?= classe_taxa($ta) ?><?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= fmt_pct($ta) ?></td>
        <?php endforeach; ?>
        <td class="num"><?= $r['reforco'] ?></td>
        <td class="num"><?= $r['revisar'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($filtro !== ''): ?><p class="nao-imprimir"><a href="<?= e(link_relatorio($id, [])) ?>">Mostrar todas as turmas</a></p><?php endif; ?>
</section>

<section id="precisao">
  <h2>Precisão da leitura<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <p class="dica">Uma questão é lida <strong>com certeza</strong> quando todas as bolinhas estão claramente vazias ou pintadas.
    As <strong>duvidosas</strong> (marcação fraca ou dupla) são as que o app pede para conferir.</p>
  <ul class="precisao">
    <li><strong><?= fmt_pct($precisao['pct_certeza']) ?></strong> lidas com certeza
      <span class="dica">(<?= $precisao['certeza'] ?> de <?= $precisao['questoes'] ?> questões)</span></li>
    <li><strong><?= fmt_pct($precisao['pct_duvidosas']) ?></strong> duvidosas
      <span class="dica">(<?= $precisao['duvidosas'] ?>: <?= $precisao['conferidas'] ?> conferidas, <?= $precisao['pendentes'] ?> sem conferir)</span></li>
    <li>Acerto <strong><?= fmt_pct($precisao['acerto']) ?></strong>
      <?php if ($precisao['pendentes'] > 0): ?>
        <span class="dica">pode ir de <?= fmt_pct($precisao['acerto_min']) ?> a <?= fmt_pct($precisao['acerto_max']) ?>
          (margem de <?= fmt_pct($precisao['margem']) ?>), conforme as <?= $precisao['pendentes'] ?> duvidosas ainda não conferidas</span>
      <?php else: ?>
        <span class="dica">sem margem: todas as duvidosas foram conferidas</span>
      <?php endif; ?></li>
    <li>Correções do professor:
      <?php if ($precisao['conferidas'] + $precisao['certeza_conferida'] === 0): ?>
        <span class="dica">nenhuma folha revisada ainda</span>
      <?php else: ?>
        <span class="dica"><?= $precisao['corrigidas_duvidosas'] ?> de <?= $precisao['conferidas'] ?> duvidosas conferidas
          (<?= fmt_pct($precisao['pct_corrigidas_duvidosas']) ?>);
          <?= $precisao['corrigidas_sem_duvida'] ?> de <?= $precisao['certeza_conferida'] ?> leituras sem dúvida nas folhas revisadas
          (<?= fmt_pct($precisao['pct_corrigidas_sem_duvida']) ?>), o erro que o leitor não sinaliza</span>
      <?php endif; ?></li>
  </ul>
  <?php if ($filtro === '' && count($turmas) > 1): ?>
  <table class="tabela compacta">
    <thead><tr><th>Turma</th><th class="num">Com certeza</th><th class="num">Duvidosas</th><th class="num">Sem conferir</th><th class="num">Acerto</th><th class="num">Faixa do acerto</th></tr></thead>
    <tbody>
    <?php foreach ($turmas as $t): $pt = $precisaoTurmas[$t]; ?>
      <tr><td><?= e($t) ?></td><td class="num"><?= fmt_pct($pt['pct_certeza']) ?></td><td class="num"><?= $pt['duvidosas'] ?></td>
        <td class="num"><?= $pt['pendentes'] ?: '' ?></td><td class="num"><?= fmt_pct($pt['acerto']) ?></td>
        <td class="num"><?= $pt['pendentes'] ? fmt_pct($pt['acerto_min']) . ' a ' . fmt_pct($pt['acerto_max']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($maisDuvidosas): ?>
    <p class="aviso atencao">Questões com mais leituras duvidosas:
      <?= e(implode(', ', array_map(fn($q) => "{$q['numero']} ({$q['duvidosas']})", array_slice($maisDuvidosas, 0, 8)))) ?>.
      Confira se a bolinha está bem impressa ou se os alunos apagaram muito nessa questão.</p>
  <?php endif; ?>
</section>

<section id="prioridades">
  <h2>Prioridades de revisão<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <?php if ($filtro === '' && count($turmas) > 1): ?>
    <p class="dica nao-imprimir">Somando todas as turmas. Clique numa turma na tabela acima para ver as prioridades dela.</p>
  <?php endif; ?>
  <?php if (!$niveis): ?>
    <p class="aviso atencao nao-imprimir">Nenhuma questão tem dificuldade marcada. <a href="gabarito.php?id=<?= $id ?>">Marque Fácil, Médio ou Difícil</a> para separar o que a turma deveria dominar do que já era esperado errar.</p>
  <?php endif; ?>

  <?php if (!$prioridades): ?>
    <p class="vazio">Nenhuma questão ficou abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto<?= $alvo !== '' ? ' ' . e($alvo) : '' ?>.</p>
  <?php else: ?>
    <p class="dica">Questões abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto, da mais urgente para a menos urgente.</p>
    <?php $grupoAtual = null; $posicao = 0; ?>
    <?php foreach ($prioridades as $p): ?>
      <?php if ($p['grupo'] !== $grupoAtual): ?>
        <?php if ($grupoAtual !== null): ?></ol><?php endif; $grupoAtual = $p['grupo']; ?>
        <?php if ($niveis): // sem nenhum nível marcado, o aviso acima já explica ?>
        <h3 class="grupo-prioridade"><?= e($grupos[$grupoAtual][0]) ?> <span class="dica"><?= e($grupos[$grupoAtual][1]) ?></span></h3>
        <?php endif; ?>
        <ol class="prioridades" start="<?= $posicao + 1 ?>">
      <?php endif; $posicao++; ?>
        <li class="grupo-<?= $p['grupo'] ?>">
          <span class="questao">Questão <?= $p['numero'] ?></span>
          <span class="etiquetas">
            <?= selo_dificuldade($p['dificuldade']) ?>
            <span class="selo"><?= e($p['disciplina']) ?></span>
          </span>
          <span class="barra" style="--nivel: <?= (int)round($p['taxa']) ?>%" aria-hidden="true"></span>
          <strong class="taxa baixa"><?= fmt_pct($p['taxa']) ?></strong>
          <span class="motivo"><?= e($p['motivo']) ?> Certa: <?= e($p['certa']) ?>.</span>
        </li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<section id="mapa">
  <h2>Acerto por questão<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <form method="get" action="prova.php#mapa" class="filtros nao-imprimir">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($filtro !== ''): ?><input type="hidden" name="turma" value="<?= e($filtro) ?>"><?php endif; ?>
    <?php if (count($disciplinas) > 1): ?>
    <label>Disciplina
      <select name="disciplina">
        <option value="">Todas</option>
        <?php foreach ($disciplinas as $d): ?><option <?= $d === $filtroDisciplina ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Dificuldade
      <select name="dificuldade">
        <option value="">Todas</option>
        <?php foreach (DIFICULDADES + ['sem' => 'Sem nível'] as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= $chave === $filtroDificuldade ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="secundario">Filtrar</button>
    <?php if ($filtroDisciplina !== '' || $filtroDificuldade !== ''): ?>
      <a class="limpar" href="<?= e(link_relatorio($id, ['turma' => $filtro], 'mapa')) ?>">Limpar filtros</a>
    <?php endif; ?>
  </form>
  <p class="dica">Cada bolinha é uma questão. Quanto mais cheia, mais alunos acertaram. Em vermelho, abaixo do limite de reforço. A letra no canto é a dificuldade marcada.</p>
  <?php if (!$mapa): ?>
    <p class="vazio">Nenhuma questão com esses filtros.</p>
  <?php endif; ?>
  <ol class="mapa">
  <?php foreach ($mapa as $q): $nivel = (int)round($q['taxa'] ?? 0); ?>
    <li class="<?= classe_taxa($q['taxa']) ?>" style="--nivel: <?= $nivel ?>%"
        title="<?= e($q['disciplina'] . ($q['dificuldade'] ? ' · ' . DIFICULDADES[$q['dificuldade']] : '')) ?>">
      <span class="bolinha"><span><?= $q['numero'] ?></span><?= selo_dificuldade($q['dificuldade'], true) ?></span>
      <strong><?= fmt_pct($q['taxa']) ?></strong>
      <small>Certa: <?= e($q['certa']) ?><?php if ($q['distrator']): ?><br>Erro comum: <?= e($q['distrator']) ?> (<?= $q['distrator_qtd'] ?>)<?php endif; ?><?php if ($q['duvidosas']): ?><br>Duvidosas: <?= $q['duvidosas'] ?><?php endif; ?></small>
    </li>
  <?php endforeach; ?>
  </ol>
</section>

<?php if ($comparadas): ?>
<section id="dificuldade">
  <h2>Dificuldade marcada × observada<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <p class="dica">A dificuldade observada vem da proporção de acerto: acima de <?= fmt_pct(LIMITE_FACIL) ?> é fácil, abaixo de <?= fmt_pct(LIMITE_DIFICIL) ?> é difícil, e médio entre as duas.</p>
  <p><strong><?= $iguais ?> de <?= $comparadas ?></strong> questões com dificuldade marcada ficaram no nível previsto (<?= fmt_pct(pct($iguais, $comparadas)) ?>).</p>
  <div class="lado-a-lado">
    <table class="tabela compacta matriz">
      <caption>Questões por nível</caption>
      <thead>
        <tr><th>Marcada ↓ · Observada →</th><?php foreach (DIFICULDADES as $rotulo): ?><th class="num"><?= e($rotulo) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php foreach (DIFICULDADES as $marcada => $rotulo): ?>
        <tr>
          <th><?= e($rotulo) ?></th>
          <?php foreach (array_keys(DIFICULDADES) as $observada): $n = $matriz[$marcada][$observada]; ?>
            <td class="num<?= $marcada === $observada ? ' diagonal' : ($n ? ' diverge' : '') ?>"><?= $n ?: '' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($divergentes): ?>
    <table class="tabela compacta">
      <caption>Questões em que divergem</caption>
      <thead><tr><th class="num">Questão</th><th>Disciplina</th><th>Marcada</th><th>Observada</th><th class="num">Acerto</th></tr></thead>
      <tbody>
      <?php foreach ($divergentes as $q): ?>
        <tr>
          <td class="num"><?= $q['numero'] ?></td>
          <td><?= e($q['disciplina']) ?></td>
          <td><?= selo_dificuldade($q['dificuldade']) ?></td>
          <td><?= selo_dificuldade($q['observada']) ?></td>
          <td class="num <?= classe_taxa($q['taxa']) ?>"><?= fmt_pct($q['taxa']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="nao-imprimir">
  <h2>Folhas<?= $filtro !== '' ? ' da turma ' . e($filtro) : '' ?></h2>
  <table class="tabela">
    <thead><tr><th>Turma</th><th>Folha</th><th class="num">Acertos</th><th class="num">Taxa</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($visiveis as $f): ?>
      <tr>
        <td><?= e($f['turma']) ?></td>
        <td><a href="folha.php?id=<?= $f['id'] ?>"><?= e($f['arquivo']) ?></a></td>
        <td class="num"><?= $f['n_acertos'] ?>/<?= count($gabarito) ?></td>
        <td class="num <?= classe_taxa($f['taxa']) ?>"><?= fmt_pct($f['taxa']) ?></td>
        <td>
          <?php if ($f['revisar']): ?>
            <a class="selo atencao" href="folha.php?id=<?= $f['id'] ?>">Revisar questões <?= e(implode(', ', $f['revisar'])) ?></a>
          <?php elseif ($f['revisada']): ?>
            <span class="selo">Revisada</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($falhas): ?>
<section class="nao-imprimir">
  <h2>Folhas que não puderam ser lidas</h2>
  <table class="tabela">
    <thead><tr><th>Turma</th><th>Folha</th><th>Motivo</th></tr></thead>
    <tbody>
    <?php foreach ($falhas as $f): ?>
      <tr><td><?= e($f['turma']) ?></td><td><?= e($f['arquivo']) ?></td><td><?= e($f['erro']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php rodape();
