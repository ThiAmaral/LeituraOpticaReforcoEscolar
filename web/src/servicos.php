<?php
declare(strict_types=1);

/*
 * Operações do sistema, usadas do mesmo jeito pelas páginas e pela API. Recebem dados
 * já lidos da requisição, gravam no banco e devolvem arrays; não escrevem HTML nem JSON.
 * Erro que o professor pode resolver sai como ErroDoUsuario, com o código HTTP.
 */

/**
 * Lista de provas com os filtros (ano, edição) e a paginação escolhidos, da mais recente
 * para a mais antiga. $entrada: ex.: $_GET, com 'pagina' e 'por_pagina' (5, 10, 20 ou 50).
 * Devolve ['provas', 'filtros', 'escolhidos', 'pagina', 'paginas', 'por_pagina', 'total'].
 */
function listar_provas(array $entrada): array
{
    $filtros = filtros_de_provas();
    [$where, $params, $escolhidos] = aplicar_filtros($filtros, $entrada);
    $porPagina = (int)($entrada['por_pagina'] ?? 0);
    $porPagina = in_array($porPagina, ITENS_POR_PAGINA, true) ? $porPagina : ITENS_POR_PAGINA_PADRAO;

    $st = db()->prepare("SELECT COUNT(*) FROM provas p WHERE $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min(max(1, (int)($entrada['pagina'] ?? 1)), $paginas);

    $st = db()->prepare("
        SELECT p.id, p.nome, p.ano, p.edicao, p.status, p.caderno, p.criado_em,
               (SELECT COUNT(*) FROM folhas f WHERE f.prova_id = p.id AND f.erro IS NULL) AS folhas,
               (SELECT COUNT(DISTINCT f.turma) FROM folhas f WHERE f.prova_id = p.id AND f.erro IS NULL) AS turmas
        FROM provas p
        WHERE $where
        ORDER BY p.criado_em DESC, p.id DESC
        LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($params);
    $provas = $st->fetchAll();
    foreach ($provas as &$p) {
        foreach (['id', 'ano', 'edicao', 'folhas', 'turmas'] as $c) {
            $p[$c] = (int)$p[$c];
        }
    }
    unset($p);
    return ['provas' => $provas, 'filtros' => $filtros, 'escolhidos' => $escolhidos, 'pagina' => $pagina,
        'paginas' => $paginas, 'por_pagina' => $porPagina, 'total' => $total];
}

/**
 * Pré-cadastro de uma prova, como rascunho, com uma questão em branco por número.
 * $dados: caderno, ano, edicao, disciplina (uma de disciplinas() ou 'todas'), ano_escolar, questoes.
 * Devolve o id. Com erros de preenchimento, ErroDoUsuario com 'erros' => [campo => mensagem].
 */
function cadastrar_prova(array $dados, ?int $professorId): int
{
    $erros = [];
    $caderno = strtoupper(trim((string)($dados['caderno'] ?? '')));
    if ($caderno === '') {
        $erros['caderno'] = 'Informe o número do caderno.';
    } elseif (!preg_match('/^[A-Z0-9]{1,' . TAMANHO_CADERNO . '}$/', $caderno)) {
        $erros['caderno'] = 'O caderno tem até ' . TAMANHO_CADERNO . ' letras ou números, sem espaços nem símbolos.';
    }
    $ano = trim((string)($dados['ano'] ?? ''));
    if (!preg_match('/^\d{4}$/', $ano) || (int)$ano < 2000 || (int)$ano > 2100) {
        $erros['ano'] = 'Informe o ano letivo com quatro dígitos, por exemplo ' . date('Y') . '.';
    }
    $edicao = trim((string)($dados['edicao'] ?? ''));
    if (!preg_match('/^[1-9]$/', $edicao)) {
        $erros['edicao'] = 'Escolha a edição ou digite uma nova, de 1 a 9.';
    }
    $disciplina = (string)($dados['disciplina'] ?? '');
    if ($disciplina !== TODAS_DISCIPLINAS && !in_array($disciplina, disciplinas(), true)) {
        $erros['disciplina'] = 'Escolha a disciplina da prova.';
    }
    $anoEscolar = trim((string)($dados['ano_escolar'] ?? ''));
    if (!preg_match('/^[1-9]$/', $anoEscolar)) {
        $erros['ano_escolar'] = 'Escolha o ano escolar, do 1º ao 9º.';
    }
    $questoes = trim((string)($dados['questoes'] ?? ''));
    if (!preg_match('/^\d{1,2}$/', $questoes) || (int)$questoes < 1) {
        $erros['questoes'] = 'Informe o número de questões, de 1 a ' . MAXIMO_QUESTOES . '.';
    }
    if ($erros) {
        throw new ErroDoUsuario('Confira os campos destacados.', 422, ['erros' => $erros]);
    }

    $disciplinaDaProva = $disciplina === TODAS_DISCIPLINAS ? null : $disciplina;
    $nome = nome_da_prova($disciplinaDaProva, (int)$ano, (int)$anoEscolar, (int)$edicao, $caderno);
    $layout = ['caderno' => $caderno, 'questoes' => (int)$questoes, 'alternativas' => ALTERNATIVAS];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO provas (nome, caderno, ano, edicao, disciplina, ano_escolar, status, layout, criado_por, criado_em)
                       VALUES (?, ?, ?, ?, ?, ?, 'rascunho', ?, ?, ?)")
            ->execute([$nome, $caderno, (int)$ano, (int)$edicao, $disciplinaDaProva, (int)$anoEscolar,
                json_encode($layout, JSON_UNESCAPED_UNICODE), $professorId, agora()]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO questoes (prova_id, numero, resposta, disciplina) VALUES (?, ?, NULL, ?)');
        for ($n = 1; $n <= (int)$questoes; $n++) {
            $ins->execute([$id, $n, $disciplinaDaProva]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Grava o gabarito: por questão, resposta, disciplina e dificuldade ($entrada na ordem das
 * questões, como no formulário).
 * - Rascunho + salvar: grava como estiver.
 * - Liberar: exige resposta e disciplina em todas. Se faltar dificuldade em alguma e
 *   $confirmouSemDificuldade for falso, não grava e devolve as questões em 'sem_dificuldade'
 *   para o professor confirmar.
 * - Prova já liberada: o gabarito precisa continuar completo (as folhas são corrigidas com ele).
 * Devolve ['questoes', 'erros', 'sem_dificuldade', 'gravou', 'liberou'].
 */
function atualizar_gabarito(array $prova, array $entrada, bool $liberar, bool $confirmouSemDificuldade = false): array
{
    $rascunho = $prova['status'] === 'rascunho';
    $liberar = $liberar && $rascunho;
    [$questoes, $erros, $semDificuldade] = validar_gabarito($entrada, $prova, $liberar || !$rascunho);
    $pedirConfirmacao = $liberar && !$erros && $semDificuldade && !$confirmouSemDificuldade;
    $gravou = !$erros && !$pedirConfirmacao;
    if ($gravou) {
        salvar_gabarito((int)$prova['id'], $questoes, $liberar);
    }
    return [
        'questoes' => $questoes,
        'erros' => $erros,
        'sem_dificuldade' => $pedirConfirmacao ? $semDificuldade : [],
        'gravou' => $gravou,
        'liberou' => $gravou && $liberar,
    ];
}

/** Apaga um rascunho. Prova liberada não pode ser descartada. */
function descartar_rascunho(array $prova): void
{
    if ($prova['status'] !== 'rascunho') {
        throw new ErroDoUsuario('Só um rascunho pode ser descartado.', 409);
    }
    db()->prepare('DELETE FROM provas WHERE id = ?')->execute([$prova['id']]);
}

/** "8A": sem espaços sobrando, até 20 caracteres. */
function turma_valida(string $turma): string
{
    $turma = trim(preg_replace('/\s+/', ' ', $turma));
    if ($turma === '') {
        throw new ErroDoUsuario('Informe a turma das folhas enviadas.');
    }
    if (mb_strlen($turma) > 20) {
        throw new ErroDoUsuario('O nome da turma pode ter até 20 caracteres.');
    }
    return $turma;
}

/**
 * Recorte enviado pelo app: a área de respostas em $campoArea e, à parte, o QR em $campoQr,
 * com o caderno que o app leu em $_POST['caderno']. Devolve [] ou um upload com 'qr' e 'caderno'.
 */
function recorte_do_app(string $campoArea, string $campoQr): array
{
    $area = upload_normalizado($_FILES[$campoArea] ?? []);
    if (!$area) {
        return [];
    }
    $qr = upload_normalizado($_FILES[$campoQr] ?? []);
    $caderno = trim((string)($_POST['caderno'] ?? ''));
    return [$area[0] + ['qr' => $qr[0] ?? null, 'caderno' => $caderno !== '' ? $caderno : null]];
}

/**
 * Lê, corrige e grava as folhas de uma turma (recortes enviados pelo app). Devolve uma
 * entrada por folha:
 * lida: ['id', 'arquivo', 'pagina', 'ok' => true, 'leitura' => saída do leitor]
 * recusada: ['id' => null, 'arquivo', 'pagina', 'ok' => false, 'erro', 'codigo'].
 * Folha recusada não é gravada: o professor tira outra foto na hora. Fica guardada só a
 * área das bolinhas anotada (com o QR em cima), sem o nome do aluno.
 */
function ler_folhas_aluno(array $prova, string $turma, array $recortes): array
{
    if ($prova['status'] === 'rascunho') {
        throw new ErroDoUsuario('Libere o gabarito no painel web antes de enviar as folhas dos alunos.', 409);
    }
    $turma = turma_valida($turma);
    if (!$recortes) {
        throw new ErroDoUsuario('Envie o recorte da folha.');
    }
    $id = (int)$prova['id'];
    $layout = $prova['layout'];
    $gabarito = $prova['gabarito'];
    $st = db()->prepare('SELECT COUNT(*) FROM folhas WHERE prova_id = ? AND turma = ?');
    $st->execute([$id, $turma]);
    $primeira = (int)$st->fetchColumn() + 1;

    $entradas = $nomes = [];
    foreach (array_values($recortes) as $i => $r) {
        $nome = nome_generico($turma, $primeira + $i, $r['ext']);
        $area = guardar_upload($r, $nome);
        $nomes[$area] = $nome;
        $entradas[] = ['area' => $area, 'qr' => $r['qr'] ? guardar_upload($r['qr'], "qr_$nome") : null,
            'caderno' => $r['caderno']];
    }
    $leituras = ler_recortes($entradas, $layout);
    foreach ($leituras as &$l) {
        $l['arquivo'] = $nomes[$l['arquivo']] ?? basename((string)$l['arquivo']);
        // Toda folha é conferida contra a prova: caderno, número de questões e formato.
        if ($l['ok'] && ($motivo = conferir_formato_da_folha($layout, $l, $gabarito)) !== null) {
            $l = ['ok' => false, 'erro' => $motivo, 'codigo' => 'formato'] + $l;
        }
    }
    unset($l);

    $ins = db()->prepare('INSERT INTO folhas (prova_id, turma, arquivo, pagina, imagem_debug, respostas, niveis, revisar, criado_em)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $guardadas = [];
    $resultado = [];
    $semFormato = !formato_conhecido($prova['layout']);
    try {
        db()->beginTransaction();
        foreach ($leituras as $l) {
            $item = ['id' => null, 'arquivo' => $l['arquivo'], 'pagina' => (int)($l['pagina'] ?? 1), 'ok' => $l['ok']];
            if ($l['ok']) {
                $imagem = null;
                if (isset($l['imagem_debug']) && is_file($l['imagem_debug'])) {
                    $imagem = $guardadas[] = guardar_imagem($l['imagem_debug'], "$id/" . bin2hex(random_bytes(8)) . '_anotada.jpg');
                }
                $ins->execute([$id, $turma, $l['arquivo'], $item['pagina'], $imagem,
                    json_encode(respostas_da_leitura($l)),
                    json_encode(array_column($l['respostas'], 'niveis')),
                    json_encode(questoes_para_revisar($l)),
                    agora()]);
                $item['id'] = (int)db()->lastInsertId();
                $item['leitura'] = array_diff_key($l, ['arquivo' => 1, 'pagina' => 1, 'ok' => 1, 'imagem_debug' => 1]);
            } else {
                $item += ['erro' => $l['erro'], 'codigo' => $l['codigo'] ?? null];
            }
            $resultado[] = $item;
        }
        if ($semFormato && formato_conhecido($layout)) {
            db()->prepare('UPDATE provas SET layout = ? WHERE id = ?')
                ->execute([json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE), $id]);
        }
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        apagar_imagens($guardadas);
        throw $ex;
    }
    return $resultado;
}

/** Folha lida (sem erro), com as respostas, os níveis e as questões a revisar já decodificados. */
function buscar_folha(int $id): array
{
    $st = db()->prepare('SELECT * FROM folhas WHERE id = ? AND erro IS NULL');
    $st->execute([$id]);
    $folha = $st->fetch();
    if (!$folha) {
        throw new ErroDoUsuario('Folha não encontrada.', 404);
    }
    $folha = decodificar_folha($folha);
    $folha['niveis'] ??= [];
    return $folha;
}

/**
 * Revisão manual de uma folha. $respostas tem uma entrada por questão, na ordem:
 * letra, "MULTIPLA" (marcação dupla) ou null/"" (em branco).
 */
function revisar_folha(array $folha, array $prova, array $respostas): void
{
    $alts = $prova['layout']['alternativas'];
    $n = count($prova['gabarito']);
    if (count($respostas) !== $n) {
        throw new ErroDoUsuario("Envie as respostas das $n questões.");
    }
    $novas = [];
    $invalidas = [];
    foreach (array_values($respostas) as $i => $v) {
        $v = $v === null ? '' : strtoupper(trim((string)$v));
        if ($v !== '' && $v !== 'MULTIPLA' && !in_array($v, $alts, true)) {
            $invalidas[] = $i + 1;
        }
        $novas[] = $v === '' ? null : $v;
    }
    if ($invalidas) {
        throw new ErroDoUsuario('Resposta inválida ' . (count($invalidas) === 1 ? 'na questão ' : 'nas questões ')
            . listar_numeros($invalidas) . '. Use ' . implode(', ', $alts) . ', MULTIPLA ou vazio.');
    }
    db()->prepare("UPDATE folhas SET respostas = ?, revisar = '[]', revisada = 1 WHERE id = ?")
        ->execute([json_encode($novas), $folha['id']]);
}

/**
 * Tudo o que o relatório da prova mostra, para uma turma ($turma) ou todas (''):
 * resumo por turma, estatísticas e prioridades por questão e a comparação entre
 * dificuldade marcada e observada.
 */
function relatorio_prova(array $prova, string $turma = ''): array
{
    if ($prova['status'] === 'rascunho') {
        throw new ErroDoUsuario('A prova ainda não foi liberada.', 409);
    }
    $questoes = $prova['questoes'];
    [$lidas, $falhas] = carregar_folhas($prova);
    $resumo = resumo_por_turma($questoes, $lidas);
    $turmas = array_map('strval', array_keys($resumo)); // turma "7" vira chave inteira no array
    $turma = in_array($turma, $turmas, true) ? $turma : '';
    $visiveis = $turma !== '' ? array_values(array_filter($lidas, fn($f) => $f['turma'] === $turma)) : $lidas;
    $estatisticas = estatisticas_das_questoes($questoes, $visiveis);
    $alternativas = $prova['layout']['alternativas'] ?? ALTERNATIVAS;
    foreach (duvidosas_por_questao($visiveis, $alternativas, count($questoes)) as $i => $n) {
        $estatisticas[$i]['duvidosas'] = $n;
    }
    $precisaoPorTurma = [];
    foreach ($turmas as $t) {
        $precisaoPorTurma[$t] = precisao_da_leitura(array_filter($lidas, fn($f) => $f['turma'] === $t),
            $prova['gabarito'], $alternativas);
    }
    $matriz = matriz_de_dificuldade($estatisticas);
    return [
        'turma' => $turma,
        'turmas' => $turmas,
        'resumo' => $resumo,
        'lidas' => $lidas,
        'falhas' => $falhas,
        'visiveis' => $visiveis,
        'disciplinas' => array_values(array_unique(array_map('disciplina_da_questao', $questoes))),
        'niveis' => niveis_da_prova($questoes),
        'estatisticas' => $estatisticas,
        'precisao' => precisao_da_leitura($visiveis, $prova['gabarito'], $alternativas),
        'precisao_por_turma' => $precisaoPorTurma,
        'prioridades' => prioridades_de_revisao($estatisticas),
        'matriz' => $matriz,
        'comparadas' => array_sum(array_map('array_sum', $matriz)),
        'iguais' => array_sum(array_map(fn($n) => $matriz[$n][$n], array_keys(DIFICULDADES))),
        'divergentes' => array_values(array_filter($estatisticas, fn($e) => $e['diverge'])),
    ];
}
