<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Pré-cadastro de uma prova: caderno, ano letivo, edição, disciplina, ano escolar e número
 * de questões. A prova nasce como rascunho, e o professor segue para o gabarito.
 */

$professor = iniciar_pagina();
$edicoes = edicoes_existentes();
$erro = null;
$erros = [];
$dados = [
    'caderno' => '',
    'ano' => date('Y'),
    'edicao' => $edicoes ? (string)max(array_keys($edicoes)) : '',
    'edicao_nova' => '',
    'disciplina' => '',
    'ano_escolar' => '',
    'questoes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = array_merge($dados, array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, $dados)));
    // "Nova edição": vale o número digitado ao lado do select.
    $edicao = $dados['edicao'] === 'nova' || !$edicoes ? $dados['edicao_nova'] : $dados['edicao'];
    try {
        $id = cadastrar_prova(['edicao' => $edicao] + $dados, $professor['id']);
        redirecionar("gabarito.php?id=$id&nova=1");
    } catch (ErroDoUsuario $ex) {
        $erro = $ex->getMessage();
        $erros = $ex->detalhes['erros'] ?? [];
        if (isset($erros['edicao']) && ($dados['edicao'] === 'nova' || !$edicoes)) {
            $erros['edicao_nova'] = $erros['edicao'];
            unset($erros['edicao']);
        }
    }
}

/** Mensagem de erro de um campo, ligada a ele por aria-describedby. */
function erro_do_campo(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? '<span class="erro-campo" id="erro-' . $campo . '">' . e($erros[$campo]) . '</span>' : '';
}

function atributos_de_erro(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . $campo . '"' : '';
}

$novaEdicao = !$edicoes || $dados['edicao'] === 'nova';

cabecalho('Nova prova', 'nova');
?>
<p class="meta">Informe as características da prova. Depois do cadastro, você preenche a resposta, a disciplina e a
  dificuldade de cada questão.</p>
<?php if ($erro): ?><p class="erro" role="alert"><?= e($erro) ?></p><?php endif; ?>

<form method="post" class="formulario cadastro-prova" id="cadastro" novalidate>
  <?= campo_csrf() ?>

  <div class="linha">
    <label>Número do caderno
      <input name="caderno" required maxlength="<?= TAMANHO_CADERNO ?>" pattern="[A-Za-z0-9]{1,<?= TAMANHO_CADERNO ?>}"
             value="<?= e($dados['caderno']) ?>" placeholder="2265M0801" autocomplete="off" spellcheck="false"
             class="maiusculas"<?= atributos_de_erro($erros, 'caderno') ?>>
      <span class="dica">Até <?= TAMANHO_CADERNO ?> letras ou números, como no QR da folha.</span>
      <?= erro_do_campo($erros, 'caderno') ?>
    </label>
    <label>Ano letivo
      <input name="ano" required inputmode="numeric" maxlength="4" pattern="\d{4}" value="<?= e($dados['ano']) ?>"<?= atributos_de_erro($erros, 'ano') ?>>
      <?= erro_do_campo($erros, 'ano') ?>
    </label>
  </div>

  <div class="linha">
    <div class="campo-edicao">
      <?php if ($edicoes): ?>
        <label>Edição
          <select name="edicao" id="edicao"<?= atributos_de_erro($erros, 'edicao') ?>>
            <?php foreach ($edicoes as $n => $rotulo): ?>
              <option value="<?= $n ?>" <?= $dados['edicao'] === (string)$n ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
            <option value="nova" <?= $dados['edicao'] === 'nova' ? 'selected' : '' ?>>Nova edição…</option>
          </select>
          <?= erro_do_campo($erros, 'edicao') ?>
        </label>
      <?php endif; ?>
      <label class="edicao-nova" id="campo-edicao-nova" <?= $novaEdicao ? '' : 'data-oculto' ?>>
        <?= $edicoes ? 'Número da nova edição' : 'Edição' ?>
        <input name="edicao_nova" id="edicao-nova" inputmode="numeric" maxlength="1" pattern="[1-9]"
               value="<?= e($dados['edicao_nova'] !== '' ? $dados['edicao_nova'] : ($edicoes ? '' : '1')) ?>" placeholder="2"<?= atributos_de_erro($erros, 'edicao_nova') ?>>
        <span class="dica">Um número de 1 a 9: 2 cria a "2ª edição".</span>
        <?= erro_do_campo($erros, 'edicao_nova') ?>
      </label>
    </div>
    <label>Ano escolar
      <select name="ano_escolar" required<?= atributos_de_erro($erros, 'ano_escolar') ?>>
        <option value="">Escolha…</option>
        <?php for ($n = 1; $n <= 9; $n++): ?>
          <option value="<?= $n ?>" <?= $dados['ano_escolar'] === (string)$n ? 'selected' : '' ?>><?= e(rotulo_ano_escolar($n)) ?></option>
        <?php endfor; ?>
      </select>
      <?= erro_do_campo($erros, 'ano_escolar') ?>
    </label>
  </div>

  <div class="linha">
    <label>Disciplina
      <select name="disciplina" required<?= atributos_de_erro($erros, 'disciplina') ?>>
        <option value="">Escolha…</option>
        <?php foreach (disciplinas() as $d): ?>
          <option <?= $dados['disciplina'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
        <?php endforeach; ?>
        <option value="<?= TODAS_DISCIPLINAS ?>" <?= $dados['disciplina'] === TODAS_DISCIPLINAS ? 'selected' : '' ?>>Todas as disciplinas</option>
      </select>
      <span class="dica">Com uma disciplina só, todas as questões já vêm com ela. Com todas, você escolhe a de cada questão.</span>
      <?= erro_do_campo($erros, 'disciplina') ?>
    </label>
    <label>Número de questões
      <input name="questoes" required inputmode="numeric" maxlength="2" pattern="\d{1,2}" value="<?= e($dados['questoes']) ?>"
             placeholder="26"<?= atributos_de_erro($erros, 'questoes') ?>>
      <span class="dica">De 1 a <?= MAXIMO_QUESTOES ?>.</span>
      <?= erro_do_campo($erros, 'questoes') ?>
    </label>
  </div>

  <p class="previa-nome" aria-live="polite" hidden>Nome da prova: <strong id="previa-nome"></strong></p>

  <button type="submit">Cadastrar e preencher o gabarito</button>
</form>

<script>
(function () {
  'use strict';
  var form = document.getElementById('cadastro');
  var edicao = document.getElementById('edicao');
  var campoNova = document.getElementById('campo-edicao-nova');
  var novaInput = document.getElementById('edicao-nova');
  var previa = form.querySelector('.previa-nome');
  var nome = document.getElementById('previa-nome');

  function mostrarNova() {
    var nova = !edicao || edicao.value === 'nova';
    campoNova.hidden = !nova;
    if (nova && edicao && document.activeElement === edicao) novaInput.focus();
  }
  if (edicao) edicao.addEventListener('change', mostrarNova);
  campoNova.removeAttribute('data-oculto');
  mostrarNova();

  // Só dígitos nos campos numéricos; caderno em maiúsculas, só letras e números.
  form.querySelectorAll('input[inputmode="numeric"]').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/\D/g, ''); });
  });
  var caderno = form.elements.caderno;
  caderno.addEventListener('input', function () { caderno.value = caderno.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });

  function atualizarNome() {
    var f = form.elements;
    var ed = !edicao || edicao.value === 'nova' ? novaInput.value : edicao.value;
    var disc = f.disciplina.value === '<?= TODAS_DISCIPLINAS ?>' ? '<?= NOME_TODAS_DISCIPLINAS ?>' : f.disciplina.value;
    var partes = [disc, f.ano.value, f.ano_escolar.value && f.ano_escolar.value + 'º ano', ed && ed + 'ª edição'];
    var completo = partes.every(Boolean) && f.caderno.value;
    previa.hidden = !completo;
    if (completo) nome.textContent = partes.join(' · ') + ' (' + f.caderno.value + ')';
  }
  form.addEventListener('input', atualizarNome);
  form.addEventListener('change', atualizarNome);
  atualizarNome();
})();
</script>
<?php rodape();
