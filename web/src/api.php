<?php
declare(strict_types=1);

/*
 * API REST para o aplicativo. O controlador frontal é api/index.php.
 * - POST /api/entrar devolve um token; as outras rotas exigem "Authorization: Bearer <token>".
 * - O app só lê provas e gabaritos: cadastro e gabarito são feitos no painel web.
 * - As respostas são JSON; os erros saem como {"erro": "..."} com o código HTTP certo:
 *   400 requisição malformada, 401 sem login, 404 não encontrado, 405 método errado,
 *   409 estado da prova não permite, 413 arquivo grande demais, 415 tipo de arquivo,
 *   422 dado inválido, 500 erro no servidor.
 * As funções de cada rota só traduzem HTTP <-> funções de src/servicos.php.
 */

const VALIDADE_URL_IMAGEM = 300; // segundos que a URL de uma imagem vale

/** [método, caminho, função, exige login]. {id} é um número inteiro. */
function rotas_da_api(): array
{
    return [
        ['POST', 'entrar', 'api_entrar', false],
        ['POST', 'sair', 'api_sair', true],
        ['GET', 'config', 'api_config', true],
        ['GET', 'provas', 'api_listar_provas', true],
        ['GET', 'provas/{id}', 'api_ver_prova', true],
        ['GET', 'provas/{id}/folhas', 'api_listar_folhas', true],
        ['POST', 'provas/{id}/folhas', 'api_enviar_folhas', true],
        ['GET', 'provas/{id}/relatorio', 'api_relatorio', true],
        ['GET', 'folhas/{id}', 'api_ver_folha', true],
        ['PUT', 'folhas/{id}', 'api_revisar_folha', true],
        // A imagem é aberta pelo componente de imagem do app, sem cabeçalho: vale a assinatura da URL.
        ['GET', 'imagens/{id}', 'api_imagem', false],
    ];
}

/** Caminho da rota a partir da URL: "/leitura/api/provas/4" ou ".../api/index.php/provas/4" -> "provas/4". */
function caminho_da_api(string $uri): string
{
    $caminho = parse_url($uri, PHP_URL_PATH) ?: '/';
    return preg_match('#/api(?:/index\.php)?(/.*)?$#', $caminho, $m) ? trim($m[1] ?? '', '/') : '';
}

/**
 * Escolhe a rota. Devolve [função, ['id' => ...], exige login].
 * Caminho desconhecido: 404. Caminho certo com outro método: 405.
 */
function encontrar_rota(string $metodo, string $caminho, array $rotas): array
{
    $permitidos = [];
    foreach ($rotas as [$m, $modelo, $funcao, $login]) {
        $regex = '#^' . str_replace('\{id\}', '(?P<id>[1-9]\d{0,17})', preg_quote($modelo, '#')) . '$#';
        if (!preg_match($regex, $caminho, $partes)) {
            continue;
        }
        if ($m === $metodo) {
            return [$funcao, isset($partes['id']) ? ['id' => (int)$partes['id']] : [], $login];
        }
        $permitidos[] = $m;
    }
    if ($permitidos) {
        throw new ErroDoUsuario("Use $caminho com " . implode(' ou ', $permitidos) . '.', 405, ['permitidos' => $permitidos]);
    }
    throw new ErroDoUsuario('Rota não encontrada: ' . ($caminho === '' ? '/' : $caminho), 404);
}

/** Atende uma requisição à API: autentica, chama a função da rota e escreve o JSON. */
function atender_api(array $servidor): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $metodo = strtoupper($servidor['REQUEST_METHOD'] ?? 'GET');
    foreach (cabecalhos_cors($servidor['HTTP_ORIGIN'] ?? '', (string)getenv('CORS_ORIGENS')) as $cabecalho) {
        header($cabecalho);
    }
    if ($metodo === 'OPTIONS') {
        http_response_code(204); // pré-verificação do navegador; o app no celular não faz
        return;
    }
    try {
        [$funcao, $params, $exigeLogin] = encontrar_rota($metodo, caminho_da_api($servidor['REQUEST_URI'] ?? '/'), rotas_da_api());
        $professor = $exigeLogin ? autenticar_api($servidor) : null;
        conferir_tamanho_do_envio($servidor);
        [$status, $corpo] = $funcao($params, $professor, $servidor);
    } catch (ErroDoUsuario $ex) {
        $status = $ex->status;
        $corpo = ['erro' => $ex->getMessage()] + $ex->detalhes;
        if ($status === 401) {
            header('WWW-Authenticate: Bearer');
        } elseif ($status === 405) {
            header('Allow: ' . implode(', ', $ex->detalhes['permitidos']));
        }
    } catch (Throwable $ex) {
        error_log((string)$ex);
        $status = 500;
        $corpo = ['erro' => 'Erro no servidor. Tente de novo; se continuar, avise a coordenação.'];
    }
    http_response_code($status);
    if ($status !== 204 && $corpo !== null) {
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}

/**
 * Cabeçalhos CORS para a versão web do app em desenvolvimento (npx expo start --web).
 * O app no celular não precisa deles. Só vale para as origens listadas em CORS_ORIGENS
 * (separadas por vírgula, ex.: http://localhost:8081); sem a variável, nenhuma.
 */
function cabecalhos_cors(string $origem, string $permitidas): array
{
    $lista = array_filter(array_map('trim', explode(',', $permitidas)));
    if ($origem === '' || !in_array($origem, $lista, true)) {
        return [];
    }
    return [
        "Access-Control-Allow-Origin: $origem",
        'Vary: Origin',
        'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers: Authorization, Content-Type',
        'Access-Control-Max-Age: 600',
    ];
}

/** O Apache com mod_php nem sempre põe o Authorization em $_SERVER: procura nos cabeçalhos. */
function com_authorization(array $servidor): array
{
    if (!isset($servidor['HTTP_AUTHORIZATION']) && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                $servidor['HTTP_AUTHORIZATION'] = $valor;
            }
        }
    }
    return $servidor;
}

/** Professor do token enviado pelo app; sem token válido, 401. */
function autenticar_api(array $servidor): array
{
    $token = token_do_cabecalho(com_authorization($servidor));
    if ($token === null) {
        throw new ErroDoUsuario('Faça login: envie o token no cabeçalho Authorization: Bearer <token>.', 401);
    }
    return professor_do_token($token) ?? throw new ErroDoUsuario('Sessão expirada ou inválida. Entre de novo.', 401);
}

/** Envio maior que o post_max_size chega sem nenhum campo; avisa o motivo em vez de "falta a foto". */
function conferir_tamanho_do_envio(array $servidor): void
{
    $enviado = (int)($servidor['CONTENT_LENGTH'] ?? 0);
    $limite = tamanho_em_bytes((string)ini_get('post_max_size'));
    if ($limite > 0 && $enviado > $limite) {
        throw new ErroDoUsuario('O envio passa do tamanho máximo aceito pelo servidor.', 413);
    }
}

/** "200M" -> 209715200 */
function tamanho_em_bytes(string $valor): int
{
    $valor = trim($valor);
    $numero = (int)$valor;
    return match (strtoupper(substr($valor, -1))) {
        'G' => $numero * 1024 ** 3,
        'M' => $numero * 1024 ** 2,
        'K' => $numero * 1024,
        default => $numero,
    };
}

/** Corpo JSON da requisição (POST/PUT com Content-Type: application/json). Vazio = []. */
function corpo_json(): array
{
    $texto = (string)file_get_contents('php://input');
    if (trim($texto) === '') {
        return [];
    }
    $dados = json_decode($texto, true);
    if (!is_array($dados)) {
        throw new ErroDoUsuario('O corpo da requisição precisa ser um objeto JSON.', 400);
    }
    return utf8_valido($dados);
}

/* ------------------------------------------------- URL assinada de imagem */

/** Segredo do servidor para assinar as URLs das imagens; criado no primeiro uso, em dados/. */
function segredo_do_servidor(): string
{
    $arquivo = DADOS . '/segredo.key';
    if (!is_file($arquivo)) {
        db(); // garante a pasta dados/ (e o .htaccess que a protege)
        file_put_contents($arquivo, bin2hex(random_bytes(32)), LOCK_EX);
    }
    return trim((string)file_get_contents($arquivo));
}

function assinatura_da_imagem(int $folhaId, int $ate): string
{
    return hash_hmac('sha256', "imagem:$folhaId:$ate", segredo_do_servidor());
}

/** Endereço da API visto pelo app, a partir da própria requisição (ex.: http://192.168.0.10/leitura/api). */
function base_da_api(array $servidor): string
{
    $https = ($servidor['HTTPS'] ?? '') !== '' && $servidor['HTTPS'] !== 'off';
    $host = $servidor['HTTP_HOST'] ?? 'localhost';
    $caminho = parse_url($servidor['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefixo = preg_match('#^(.*?/api)(?:/|$)#', $caminho, $m) ? $m[1] : '/api';
    return ($https ? 'https' : 'http') . "://$host$prefixo";
}

/** URL da imagem anotada de uma folha, que vale por VALIDADE_URL_IMAGEM segundos. */
function url_da_imagem(int $folhaId, array $servidor): string
{
    $ate = time() + VALIDADE_URL_IMAGEM;
    return base_da_api($servidor) . "/imagens/$folhaId?ate=$ate&assinatura=" . assinatura_da_imagem($folhaId, $ate);
}

/* --------------------------------------------------------- Formato JSON */

/** "2026-10-12 14:03:00" -> "2026-10-12T14:03:00-03:00" */
function data_iso(?string $data): ?string
{
    return $data === null ? null : date(DATE_ATOM, strtotime($data));
}

/** Resumo de uma prova, como na lista. */
function prova_resumo_json(array $p): array
{
    return [
        'id' => (int)$p['id'],
        'nome' => $p['nome'],
        'ano' => (int)$p['ano'],
        'edicao' => (int)$p['edicao'],
        'edicao_nome' => rotulo_edicao((int)$p['edicao']),
        'status' => $p['status'],
        'caderno' => $p['caderno'],
        'turmas' => (int)($p['turmas'] ?? 0),
        'folhas' => (int)($p['folhas'] ?? 0),
        'criado_em' => data_iso($p['criado_em']),
    ];
}

/** Prova para o app: dados do cadastro e o gabarito (só leitura). */
function prova_json(array $prova): array
{
    $layout = $prova['layout'];
    return [
        'id' => $prova['id'],
        'nome' => $prova['nome'],
        'ano' => $prova['ano'],
        'edicao' => $prova['edicao'],
        'edicao_nome' => rotulo_edicao($prova['edicao']),
        'ano_escolar' => $prova['ano_escolar'],
        'disciplina' => $prova['disciplina'] ?? NOME_TODAS_DISCIPLINAS,
        'status' => $prova['status'],
        'caderno' => $prova['caderno'],
        'criado_em' => data_iso($prova['criado_em']),
        'formato_conhecido' => formato_conhecido($layout),
        'formato' => descrever_formato($layout, count($prova['questoes'])),
        'alternativas' => $layout['alternativas'],
        'blocos' => $layout['blocos'] ?? null,
        'questoes' => array_map(fn($q) => [
            'numero' => $q['numero'],
            'resposta' => $q['resposta'],
            'disciplina' => $q['disciplina'],
            'dificuldade' => $q['dificuldade'],
        ], $prova['questoes']),
    ];
}

/** Folha lida e corrigida: respostas, acertos por questão, nota e questões a revisar. */
function folha_json(array $folha, array $gabarito): array
{
    $folha = corrigir_folha($folha, $gabarito);
    return [
        'id' => (int)$folha['id'],
        'prova_id' => (int)$folha['prova_id'],
        'turma' => (string)$folha['turma'],
        'arquivo' => $folha['arquivo'],
        'pagina' => (int)$folha['pagina'],
        'respostas' => $folha['respostas'],
        'acertos' => $folha['acertos'],
        'n_acertos' => $folha['n_acertos'],
        'questoes' => count($gabarito),
        'taxa' => $folha['taxa'],
        'revisar' => $folha['revisar'],
        'revisada' => (bool)$folha['revisada'],
        'criado_em' => data_iso($folha['criado_em'] ?? null),
    ];
}

/** Uma porcentagem por grupo: [acertos, total] -> 63.1 */
function porcentagens(array $grupos): array
{
    return array_map(fn($v) => pct($v[0], $v[1]), $grupos);
}

/**
 * Respostas de uma folha enviadas pelo app: a lista completa (uma por questão) ou só as
 * que mudaram, por número ({"12": "B", "13": null}). Devolve a lista completa.
 */
function respostas_da_revisao(array $atuais, mixed $enviadas): array
{
    if (!is_array($enviadas)) {
        throw new ErroDoUsuario('Envie "respostas": a lista completa ou {"numero": "letra"}.', 400);
    }
    if (array_is_list($enviadas) && count($enviadas) === count($atuais)) {
        return $enviadas;
    }
    $novas = $atuais;
    foreach ($enviadas as $numero => $valor) {
        $i = (int)$numero - 1;
        if (!array_key_exists($i, $novas)) {
            throw new ErroDoUsuario("A prova não tem a questão $numero.");
        }
        $novas[$i] = $valor;
    }
    return $novas;
}

/** Precisão da leitura para a API (sem os contadores internos da margem). */
function precisao_json(array $p): array
{
    return [
        'questoes' => $p['questoes'],
        'com_certeza' => $p['certeza'],
        'duvidosas' => $p['duvidosas'],
        'pct_com_certeza' => $p['pct_certeza'],
        'pct_duvidosas' => $p['pct_duvidosas'],
        'conferidas' => $p['conferidas'],
        'pendentes' => $p['pendentes'],
        'margem' => $p['margem'],
        'acerto' => $p['acerto'],
        'acerto_min' => $p['acerto_min'],
        'acerto_max' => $p['acerto_max'],
        'corrigidas_duvidosas' => $p['corrigidas_duvidosas'],
        'pct_corrigidas_duvidosas' => $p['pct_corrigidas_duvidosas'],
        'corrigidas_sem_duvida' => $p['corrigidas_sem_duvida'],
        'conferidas_sem_duvida' => $p['certeza_conferida'],
        'pct_corrigidas_sem_duvida' => $p['pct_corrigidas_sem_duvida'],
        'sem_niveis' => $p['sem_niveis'],
    ];
}

/* ---------------------------------------------------- Funções das rotas */

/** {"email": "...", "senha": "..."} -> {"token", "expira_em", "professor"} */
function api_entrar(array $params, ?array $professor, array $servidor): array
{
    $corpo = corpo_json() ?: $_POST;
    $email = trim((string)($corpo['email'] ?? ''));
    $senha = (string)($corpo['senha'] ?? '');
    if ($email === '' || $senha === '') {
        throw new ErroDoUsuario('Informe o e-mail e a senha.', 400);
    }
    $professor = conferir_senha($email, $senha);
    if (!$professor) {
        usleep(400_000); // freia tentativas em sequência
        throw new ErroDoUsuario('E-mail ou senha incorretos.', 401);
    }
    $token = criar_token($professor['id']);
    return [200, ['token' => $token['token'], 'expira_em' => date(DATE_ATOM, $token['expira_em']), 'professor' => $professor]];
}

function api_sair(array $params, array $professor, array $servidor): array
{
    $token = token_do_cabecalho(com_authorization($servidor));
    if ($token !== null) {
        apagar_token($token);
    }
    return [204, null];
}

function api_config(array $params, array $professor, array $servidor): array
{
    return [200, [
        'professor' => $professor,
        'edicoes' => array_map(fn($n, $nome) => ['numero' => $n, 'nome' => $nome], array_keys(edicoes_existentes()), edicoes_existentes()),
        'anos' => array_map('intval', array_keys(opcoes_sql('SELECT DISTINCT ano FROM provas ORDER BY ano DESC'))),
        'disciplinas' => disciplinas(),
        'dificuldades' => DIFICULDADES,
        'limites' => ['reforco' => LIMITE_REFORCO, 'facil' => LIMITE_FACIL, 'dificil' => LIMITE_DIFICIL],
        'itens_por_pagina' => ITENS_POR_PAGINA,
        'tamanho_maximo_arquivo' => TAMANHO_MAXIMO_ARQUIVO,
    ]];
}

/** ?ano=&edicao=&pagina=&por_pagina=: a mesma lista e a mesma ordem do painel web. */
function api_listar_provas(array $params, array $professor, array $servidor): array
{
    $lista = listar_provas($_GET);
    return [200, [
        'filtros' => $lista['escolhidos'],
        'pagina' => $lista['pagina'],
        'paginas' => $lista['paginas'],
        'por_pagina' => $lista['por_pagina'],
        'total' => $lista['total'],
        'provas' => array_map('prova_resumo_json', $lista['provas']),
    ]];
}

function api_ver_prova(array $params, array $professor, array $servidor): array
{
    return [200, ['prova' => prova_json(buscar_prova($params['id']))]];
}

/** Folhas da prova (opcional ?turma=), para a lista e o contador do app. */
function api_listar_folhas(array $params, array $professor, array $servidor): array
{
    $prova = buscar_prova($params['id']);
    [$lidas] = carregar_folhas($prova);
    $turma = trim((string)($_GET['turma'] ?? ''));
    $daTurma = fn($f) => $turma === '' || $f['turma'] === $turma;
    return [200, [
        'turma' => $turma,
        'folhas' => array_map(fn($f) => folha_json($f, $prova['gabarito']), array_values(array_filter($lidas, $daTurma))),
    ]];
}

/**
 * multipart/form-data: turma e o recorte feito no app: área de respostas em "folha", QR em
 * "qr" (opcional) e o caderno lido no app em "caderno" (opcional). A foto inteira, com o
 * nome do aluno, não sai do celular. Volta com a nota e as questões a revisar, ou com o
 * motivo da recusa ("erro" e "codigo"). Folha recusada não é gravada: o app pede outra foto.
 */
function api_enviar_folhas(array $params, array $professor, array $servidor): array
{
    $prova = buscar_prova($params['id']);
    $turma = turma_valida((string)($_POST['turma'] ?? ''));
    $resultado = ler_folhas_aluno($prova, $turma, recorte_do_app('folha', 'qr'));
    $folhas = [];
    foreach ($resultado as $r) {
        if (!$r['ok']) {
            $folhas[] = ['ok' => false, 'arquivo' => $r['arquivo'], 'pagina' => $r['pagina'],
                'erro' => $r['erro'], 'codigo' => $r['codigo']];
            continue;
        }
        $folha = buscar_folha($r['id']);
        $folhas[] = ['ok' => true] + folha_json($folha, $prova['gabarito']) + ['leitura' => $r['leitura']];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM folhas WHERE prova_id = ? AND turma = ? AND erro IS NULL');
    $st->execute([$prova['id'], $turma]);
    return [200, ['turma' => $turma, 'folhas_da_turma' => (int)$st->fetchColumn(), 'folhas' => $folhas]];
}

function api_relatorio(array $params, array $professor, array $servidor): array
{
    $prova = buscar_prova($params['id']);
    $r = relatorio_prova($prova, trim((string)($_GET['turma'] ?? '')));
    return [200, [
        'prova' => ['id' => $prova['id'], 'nome' => $prova['nome'], 'questoes' => count($prova['questoes'])],
        'turma' => $r['turma'],
        'turmas' => $r['turmas'],
        'limites' => ['reforco' => LIMITE_REFORCO, 'facil' => LIMITE_FACIL, 'dificil' => LIMITE_DIFICIL],
        'folhas' => count($r['visiveis']),
        'resumo' => array_map(fn($t) => [
            'turma' => $t,
            'folhas' => $r['resumo'][$t]['folhas'],
            'acerto' => pct($r['resumo'][$t]['acertos'], $r['resumo'][$t]['total']),
            'por_disciplina' => porcentagens($r['resumo'][$t]['disciplinas']),
            'por_dificuldade' => porcentagens($r['resumo'][$t]['niveis']),
            'para_reforco' => $r['resumo'][$t]['reforco'],
            'a_revisar' => $r['resumo'][$t]['revisar'],
            'precisao' => precisao_json($r['precisao_por_turma'][$t]),
        ], $r['turmas']),
        'precisao' => precisao_json($r['precisao']),
        'prioridades' => array_map(fn($p) => array_intersect_key($p, array_flip(['numero', 'certa', 'disciplina',
            'dificuldade', 'taxa', 'distrator', 'distrator_qtd', 'brancos', 'grupo', 'motivo'])), $r['prioridades']),
        'questoes' => $r['estatisticas'],
        'dificuldade' => [
            'matriz' => $r['matriz'],
            'comparadas' => $r['comparadas'],
            'no_nivel_previsto' => $r['iguais'],
            'concordancia' => pct($r['iguais'], $r['comparadas']),
            'divergentes' => array_column($r['divergentes'], 'numero'),
        ],
    ]];
}

function api_ver_folha(array $params, array $professor, array $servidor): array
{
    $folha = buscar_folha($params['id']);
    $prova = buscar_prova($folha['prova_id']);
    return [200, ['folha' => folha_json($folha, $prova['gabarito']) + [
        'alternativas' => $prova['layout']['alternativas'],
        'gabarito' => $prova['gabarito'],
        'niveis' => $folha['niveis'],
        'imagem_url' => arquivo_da_imagem($folha['imagem_debug']) ? url_da_imagem($folha['id'], $servidor) : null,
    ]]];
}

/** {"respostas": [...]} completa, ou {"respostas": {"12": "B"}} só com as que mudaram. */
function api_revisar_folha(array $params, array $professor, array $servidor): array
{
    $folha = buscar_folha($params['id']);
    $prova = buscar_prova($folha['prova_id']);
    revisar_folha($folha, $prova, respostas_da_revisao($folha['respostas'], corpo_json()['respostas'] ?? null));
    return [200, ['folha' => folha_json(buscar_folha($params['id']), $prova['gabarito'])]];
}

/** Imagem anotada de uma folha, pela URL assinada de api_ver_folha(). Responde a imagem, não JSON. */
function api_imagem(array $params, ?array $professor, array $servidor): array
{
    $ate = (int)($_GET['ate'] ?? 0);
    $assinatura = (string)($_GET['assinatura'] ?? '');
    if ($ate < time() || !hash_equals(assinatura_da_imagem($params['id'], $ate), $assinatura)) {
        throw new ErroDoUsuario('Link da imagem vencido. Abra a folha de novo.', 403);
    }
    $st = db()->prepare('SELECT imagem_debug FROM folhas WHERE id = ?');
    $st->execute([$params['id']]);
    $arquivo = arquivo_da_imagem($st->fetchColumn() ?: null);
    if (!$arquivo) {
        throw new ErroDoUsuario('Imagem não encontrada.', 404);
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($arquivo));
    header('Cache-Control: private, max-age=' . VALIDADE_URL_IMAGEM);
    readfile($arquivo);
    exit; // a resposta já é a imagem: nada de JSON depois
}
