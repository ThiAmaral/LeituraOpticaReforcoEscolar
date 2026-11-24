<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Gabarito da prova: resposta, disciplina e dificuldade de cada questão.
 * Rascunho: "Salvar rascunho" grava como estiver; "Liberar prova" exige todas as respostas
 * (e disciplinas) e, se faltar dificuldade, pergunta se quer liberar mesmo assim.
 * A mesma tela edita o gabarito de uma prova já liberada.
 */

iniciar_pagina();
$prova = buscar_prova((int)($_GET['id'] ?? 0));
$id = $prova['id'];
$rascunho = $prova['status'] === 'rascunho';
$erros = [];
$semDificuldade = [];
$aviso = match (true) {
    isset($_GET['nova']) => 'Prova cadastrada. Preencha a resposta de cada questão e libere a prova para receber as folhas.',
    isset($_GET['salvo']) => 'Rascunho salvo. Você pode voltar e liberar quando terminar.',
    default => null,
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';
    if ($acao === 'descartar' && $rascunho) {
        descartar_rascunho($prova);
        redirecionar('index.php');
    }
    $liberar = $acao === 'liberar' || $acao === 'liberar_confirmado';
    $r = atualizar_gabarito($prova, (array)($_POST['q'] ?? []), $liberar, $acao === 'liberar_confirmado');
    if ($r['gravou']) {
        redirecionar($rascunho && !$r['liberou'] ? "gabarito.php?id=$id&salvo=1" : "prova.php?id=$id" . ($r['liberou'] ? '&liberada=1' : ''));
    }
    // Com erro ou aviso, a tela volta com o que o professor escolheu, sem perder nada.
    $erros = $r['erros'];
    $semDificuldade = $r['sem_dificuldade'];
    $prova['questoes'] = $r['questoes'];
    $aviso = null;
}

$alts = $prova['layout']['alternativas'];
$questoes = $prova['questoes'];
$catalogo = disciplinas();
$fixa = $prova['disciplina'];
$preenchidas = count(array_filter(array_column($questoes, 'resposta')));

// Cor de cada disciplina na faixa e nos cartões (mesma ordem do catálogo).
$paleta = ['#2f6fae', '#b4572f', '#2e7650', '#7a4fa3', '#a8326e', '#5d6b1f'];
$cores = [];
foreach ($catalogo as $i => $d) {
    $cores[$d] = $paleta[$i % count($paleta)];
}

cabecalho($prova['nome'], '', $rascunho ? 'index.php' : "prova.php?id=$id");
?>

<p class="meta">
  Caderno <strong><?= e($prova['caderno']) ?></strong> · <?= count($questoes) ?> questões · alternativas <?= e(reset($alts) . '–' . end($alts)) ?>
  · <?= $fixa ? 'todas as questões de ' . e($fixa) : 'todas as disciplinas' ?>.
</p>

<?php if ($rascunho): ?>
  <p class="dica">Rascunho: <span id="contador-respostas"><?= $preenchidas ?> de <?= count($questoes) ?></span> respostas preenchidas.
    A prova só recebe as folhas dos alunos depois de liberada.</p>
<?php else: ?>
  <p class="dica">Esta prova já está liberada. Mudar uma resposta recalcula na hora as notas das folhas já lidas.</p>
<?php endif; ?>
<?php if ($aviso): ?><p class="aviso"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($erros): ?>
  <div class="erro" role="alert"><?php foreach ($erros as $m): ?><p><?= e($m) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<form method="post" class="revisao-gabarito sem-imagem" id="revisao">
  <?= campo_csrf() ?>

  <?php if ($semDificuldade): ?>
    <div class="confirmar-liberacao" role="alertdialog" aria-labelledby="titulo-confirmar">
      <p id="titulo-confirmar"><strong><?= count($semDificuldade) === 1 ? 'A questão ' . $semDificuldade[0] . ' está' : 'As questões ' . e(listar_numeros($semDificuldade)) . ' estão' ?> sem dificuldade.</strong>
        Sem ela, o relatório não separa o que a turma deveria dominar do que já era esperado errar.
        Você pode marcar a dificuldade depois.</p>
      <div class="acoes">
        <button type="submit" name="acao" value="liberar_confirmado">Liberar mesmo assim</button>
        <a class="botao secundario" href="#cartoes" id="marcar-dificuldade">Voltar e marcar a dificuldade</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="painel">
    <?php if (!$fixa): ?>
    <div class="faixa-disciplinas">
      <p class="rotulo">Disciplinas</p>
      <ol class="faixa" aria-label="Disciplina de cada questão">
        <?php foreach ($questoes as $q): ?>
          <li data-numero="<?= $q['numero'] ?>" title="Questão <?= $q['numero'] ?>: <?= e($q['disciplina'] ?? 'sem disciplina') ?>"
              style="<?= $q['disciplina'] && isset($cores[$q['disciplina']]) ? '--cor: ' . e($cores[$q['disciplina']]) : '' ?>"></li>
        <?php endforeach; ?>
      </ol>
      <ul class="legenda"></ul>
    </div>
    <?php endif; ?>

    <div class="selecao" hidden>
      <p class="dica">Para aplicar a várias questões de uma vez, clique no número da primeira e depois no da última.</p>
      <p class="selecionadas" aria-live="polite">Nenhuma questão selecionada.</p>
      <div class="acoes">
        <button type="button" class="secundario" data-acao="todas">Selecionar todas</button>
        <?php if (!$fixa): ?>
        <label>Disciplina
          <select data-campo="disciplina">
            <?php foreach ($catalogo as $d): ?><option><?= e($d) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button type="button" data-acao="disciplina">Aplicar disciplina</button>
        <?php endif; ?>
        <span class="grupo" role="group" aria-label="Aplicar dificuldade">
          <?php foreach (DIFICULDADES as $chave => $rotulo): ?>
            <button type="button" class="secundario" data-dificuldade="<?= $chave ?>"><?= e($rotulo) ?></button>
          <?php endforeach; ?>
        </span>
        <button type="button" class="link" data-acao="limpar">Limpar seleção</button>
      </div>
    </div>

    <div class="cartoes" id="cartoes">
      <?php foreach ($questoes as $i => $q): $n = $q['numero']; $semResp = $q['resposta'] === null; ?>
        <fieldset class="cartao<?= $semDificuldade && in_array($n, $semDificuldade, true) ? ' sem-dificuldade' : '' ?>"
                  data-indice="<?= $i ?>" data-numero="<?= $n ?>"
                  style="<?= $q['disciplina'] && isset($cores[$q['disciplina']]) ? '--cor: ' . e($cores[$q['disciplina']]) : '' ?>">
          <legend>
            <button type="button" class="numero" aria-pressed="false" title="Selecionar a partir desta questão">
              <span class="sr">Questão</span> <?= sprintf('%02d', $n) ?>
            </button>
            <?php if ($erros && $semResp): ?><span class="selo atencao">Sem resposta</span><?php endif; ?>
          </legend>

          <div class="opcoes resposta" role="radiogroup" aria-label="Resposta da questão <?= $n ?>">
            <?php foreach ($alts as $a): ?>
              <label><input type="radio" name="q[<?= $i ?>][resposta]" value="<?= e($a) ?>" <?= $q['resposta'] === $a ? 'checked' : '' ?>><span><?= e($a) ?></span></label>
            <?php endforeach; ?>
          </div>

          <?php if ($fixa): ?>
            <p class="disciplina-fixa"><?= e($fixa) ?></p>
          <?php else: ?>
          <label class="campo">Disciplina
            <select name="q[<?= $i ?>][disciplina]">
              <option value="">Escolha…</option>
              <?php foreach ($catalogo as $d): ?>
                <option <?= $q['disciplina'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>

          <div class="opcoes dificuldade" role="radiogroup" aria-label="Dificuldade da questão <?= $n ?>">
            <?php foreach (DIFICULDADES as $chave => $rotulo): ?>
              <label><input type="radio" name="q[<?= $i ?>][dificuldade]" value="<?= $chave ?>" <?= $q['dificuldade'] === $chave ? 'checked' : '' ?>><span><?= e($rotulo) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </div>

    <div class="rodape-revisao">
      <?php if ($rascunho): ?>
        <button type="submit" name="acao" value="liberar">Liberar prova</button>
        <button type="submit" name="acao" value="salvar" class="secundario">Salvar rascunho</button>
      <?php else: ?>
        <button type="submit" name="acao" value="salvar">Salvar alterações</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($rascunho): ?>
<form method="post" class="descartar" data-confirmar="Descartar este rascunho? A prova e o gabarito preenchido serão apagados.">
  <?= campo_csrf() ?>
  <input type="hidden" name="acao" value="descartar">
  <button type="submit" class="link perigo">Descartar rascunho</button>
</form>
<?php endif; ?>

<script>window.CORES_DISCIPLINAS = <?= json_encode($cores, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="gabarito.js"></script>
<?php rodape();
