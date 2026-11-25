<?php
declare(strict_types=1);

/*
 * Núcleo do backend PHP.
 * - Guarda professores, provas, questões e folhas num banco SQLite em dados/ (src/banco.php).
 * - Guarda as imagens anotadas pelo leitor em dados/imagens, fora da pasta pública:
 *   só saem por imagem.php (painel) ou pela API, com login.
 * - Chama o leitor óptico em Python (worker/omr_cli.py), que só diz qual bolinha foi marcada.
 * - Faz a correção: quem compara com o gabarito é o PHP.
 * As operações do sistema ficam em src/servicos.php e são as mesmas para as páginas e
 * para a API. O login é próprio (src/auth.php).
 */

date_default_timezone_set('America/Sao_Paulo'); // datas no horário de Brasília
define('RAIZ', dirname(__DIR__));
carregar_env(RAIZ . '/.env');
define('DADOS', rtrim(getenv('DADOS_DIR') ?: RAIZ . '/dados', '/\\'));
// No Windows, "python3" costuma ser só o atalho da Microsoft Store; o instalador cria "python".
define('PYTHON', getenv('OMR_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'));
define('OMR_CLI', RAIZ . '/worker/omr_cli.py');
define('LIMITE_REFORCO', (float)(getenv('LIMITE_REFORCO') ?: 60)); // % de acerto
// Dificuldade observada pela proporção de acerto: acima de LIMITE_FACIL é fácil, abaixo de LIMITE_DIFICIL é difícil.
define('LIMITE_FACIL', (float)(getenv('LIMITE_FACIL') ?: 70));
define('LIMITE_DIFICIL', (float)(getenv('LIMITE_DIFICIL') ?: 30));
define('TAMANHO_MAXIMO_ARQUIVO', 20 * 1024 * 1024); // por recorte enviado pelo app
// Tipo real do arquivo (lido do conteúdo, não da extensão) => extensão usada na pasta temporária.
const TIPOS_ACEITOS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

// Alternativas da folha (AMA: A a D). O formato exato (blocos) vem da primeira folha lida.
const ALTERNATIVAS = ['A', 'B', 'C', 'D'];
const MAXIMO_QUESTOES = 99;
const TAMANHO_CADERNO = 10;
const ITENS_POR_PAGINA = [5, 10, 20, 50];
const ITENS_POR_PAGINA_PADRAO = 10;

require __DIR__ . '/src/banco.php';
require __DIR__ . '/src/servicos.php';
require __DIR__ . '/src/auth.php';

// Todo texto recebido vira UTF-8 válido antes de chegar ao banco (ver utf8_valido()).
$_GET = utf8_valido($_GET);
$_POST = utf8_valido($_POST);

/**
 * Erro que o professor (ou o app) consegue resolver: dado faltando, prova inexistente,
 * foto ilegível. Na página vira uma mensagem; na API, JSON com o código HTTP $status.
 * $detalhes vai junto no JSON da API (ex.: a lista de erros da revisão, o código do leitor).
 */
class ErroDoUsuario extends InvalidArgumentException
{
    public function __construct(string $mensagem, public readonly int $status = 422, public readonly array $detalhes = [])
    {
        parent::__construct($mensagem);
    }
}

/** Outros clientes (curl no Windows, o app) podem enviar Windows-1252: converte para UTF-8. */
function utf8_valido(mixed $valor): mixed
{
    if (is_array($valor)) {
        return array_map('utf8_valido', $valor);
    }
    if (is_string($valor) && !mb_check_encoding($valor, 'UTF-8')) {
        return mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
    }
    return $valor;
}

/** Lê o .env da raiz, se existir. Variáveis já definidas no ambiente têm prioridade. */
function carregar_env(string $arquivo): void
{
    if (!is_file($arquivo)) {
        return;
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (str_starts_with(ltrim($linha), '#') || !str_contains($linha, '=')) {
            continue;
        }
        [$nome, $valor] = array_map('trim', explode('=', $linha, 2));
        if (getenv($nome) === false) {
            putenv("$nome=" . trim($valor, "\"'"));
        }
    }
}

/* ------------------------------------------------------- Imagens (local) */

/** Pasta das imagens anotadas: dados/imagens, fora da pasta pública. */
function pasta_imagens(): string
{
    $pasta = DADOS . '/imagens';
    if (!is_dir($pasta)) {
        mkdir($pasta, 0700, true);
    }
    return $pasta;
}

/** Copia a imagem gerada pelo leitor para dados/imagens e devolve o caminho relativo guardado no banco. */
function guardar_imagem(string $arquivoLocal, string $caminho): string
{
    $destino = pasta_imagens() . '/' . $caminho;
    if (!is_dir(dirname($destino))) {
        mkdir(dirname($destino), 0700, true);
    }
    if (!copy($arquivoLocal, $destino)) {
        throw new RuntimeException('Não foi possível guardar a imagem da folha.');
    }
    return $caminho;
}

/** Caminho absoluto de uma imagem guardada, ou null se o caminho for inválido ou o arquivo não existir. */
function arquivo_da_imagem(?string $caminho): ?string
{
    if ($caminho === null || $caminho === '' || str_contains($caminho, '..')) {
        return null;
    }
    $arquivo = pasta_imagens() . '/' . $caminho;
    return is_file($arquivo) ? $arquivo : null;
}

function apagar_imagens(array $caminhos): void
{
    foreach ($caminhos as $c) {
        if ($arquivo = arquivo_da_imagem($c)) {
            @unlink($arquivo);
        }
    }
}

/* ------------------------------------------------- Arquivos temporários */

/** Pasta temporária da requisição; o Python lê dela e ela é apagada no fim. */
function pasta_temporaria(): string
{
    static $pasta = null;
    if ($pasta === null) {
        $pasta = sys_get_temp_dir() . '/leitura_' . bin2hex(random_bytes(6));
        mkdir($pasta, 0700, true);
        register_shutdown_function(fn() => apagar_pasta($pasta));
    }
    return $pasta;
}

function apagar_pasta(string $pasta): void
{
    if (!is_dir($pasta)) {
        return;
    }
    foreach (scandir($pasta) as $item) {
        if ($item !== '.' && $item !== '..') {
            $c = "$pasta/$item";
            is_dir($c) ? apagar_pasta($c) : unlink($c);
        }
    }
    rmdir($pasta);
}

/* ------------------------------------------------------------ Formatação */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Endereço de uma página do painel. Relativo: funciona em qualquer pasta do Apache. */
function url(string $pagina, array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $pagina . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Endereço absoluto do painel visto pelo celular (ex.: http://192.168.0.10/leitura), para o QR
 * da prova. Aberto como "localhost", troca pelo IP do computador na rede, que é o que o
 * celular enxerga; devolve [endereço, se o IP foi deduzido].
 */
function endereco_do_painel(array $servidor): array
{
    $https = ($servidor['HTTPS'] ?? '') !== '' && $servidor['HTTPS'] !== 'off';
    $host = $servidor['HTTP_HOST'] ?? 'localhost';
    $pasta = rtrim(str_replace('\\', '/', dirname($servidor['SCRIPT_NAME'] ?? '/')), '/');
    $deduzido = false;
    if (preg_match('#^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $host, $m)) {
        $ip = gethostbyname(gethostname());
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
            $host = $ip . ($m[2] ?? '');
            $deduzido = true;
        }
    }
    return [($https ? 'https' : 'http') . "://$host$pasta", $deduzido];
}

function pct(float $acertos, float $total): ?float
{
    return $total > 0 ? round(100 * $acertos / $total, 1) : null;
}

function fmt_pct(?float $v): string
{
    return $v === null ? '–' : number_format($v, 1, ',', '') . '%';
}

/** [1, 2, 3, 5, 7, 8] -> "1 a 3, 5, 7 e 8" */
function listar_numeros(array $numeros): string
{
    sort($numeros);
    $faixas = [];
    foreach ($numeros as $n) {
        $ultima = array_key_last($faixas);
        if ($ultima !== null && $faixas[$ultima][1] === $n - 1) {
            $faixas[$ultima][1] = $n;
        } else {
            $faixas[] = [$n, $n];
        }
    }
    $textos = array_map(fn($f) => $f[0] === $f[1] ? (string)$f[0]
        : ($f[1] === $f[0] + 1 ? "{$f[0]}, {$f[1]}" : "{$f[0]} a {$f[1]}"), $faixas);
    $ultimo = array_pop($textos);
    return $textos ? implode(', ', $textos) . " e $ultimo" : (string)$ultimo;
}

/* ------------------------------------------- Edição, ano escolar, disciplina */

/** 2 -> "2ª edição" */
function rotulo_edicao(?int $n): string
{
    return $n === null ? '–' : "{$n}ª edição";
}

/** 8 -> "8º ano" */
function rotulo_ano_escolar(int $n): string
{
    return "{$n}º ano";
}

/** Edições já usadas em alguma prova, em ordem: [1 => '1ª edição', ...]. */
function edicoes_existentes(): array
{
    $lista = [];
    foreach (db()->query('SELECT DISTINCT edicao FROM provas ORDER BY edicao')->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $lista[(int)$n] = rotulo_edicao((int)$n);
    }
    return $lista;
}

/**
 * Disciplinas das provas.
 * Uma prova de uma disciplina só já preenche todas as questões com ela. A ordem define a cor
 * de cada disciplina no gabarito: acrescente novas no fim para não trocar as cores existentes.
 */
function disciplinas(): array
{
    return [
        'Matemática',
        'Língua Portuguesa',
        'Ciências',
        'História',
        'Geografia',
        'Língua Estrangeira',
    ];
}

const TODAS_DISCIPLINAS = 'todas';
const NOME_TODAS_DISCIPLINAS = 'Geral';

const DIFICULDADES = ['facil' => 'Fácil', 'medio' => 'Médio', 'dificil' => 'Difícil'];

/** Situação da prova: nasce ativa; cancelada pode voltar a ser ativa ou ser excluída. */
const SITUACOES = ['ativa' => 'Ativa', 'cancelada' => 'Cancelada'];
/** Situações mostradas na lista quando o professor não escolhe nenhuma. */
const SITUACOES_PADRAO = ['ativa'];

/** "Matemática · 2026 · 8º ano · 1ª edição (2265M0801)" */
function nome_da_prova(?string $disciplina, int $ano, int $anoEscolar, int $edicao, string $caderno): string
{
    return implode(' · ', [$disciplina ?? NOME_TODAS_DISCIPLINAS, $ano, rotulo_ano_escolar($anoEscolar),
            rotulo_edicao($edicao)]) . " ($caderno)";
}

/* ------------------------------------------------------- Lista de provas */

/** Resultado de uma consulta de uma coluna no formato [valor => rótulo] usado pelos filtros. */
function opcoes_sql(string $sql): array
{
    $valores = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    return array_combine($valores, $valores);
}

/**
 * Filtros da lista de provas. Cada entrada vira um <select> no index e uma condição no WHERE
 * (a tabela de provas é "p"). Para criar um filtro novo basta acrescentar uma entrada.
 */
function filtros_de_provas(): array
{
    return [
        'ano' => [
            'rotulo' => 'Ano letivo',
            'condicao' => 'p.ano = ?',
            'opcoes' => opcoes_sql('SELECT DISTINCT ano FROM provas ORDER BY ano DESC'),
        ],
        'edicao' => [
            'rotulo' => 'Edição',
            'condicao' => 'p.edicao = ?',
            'opcoes' => edicoes_existentes(),
        ],
    ];
}

/** Lê os filtros escolhidos (ex.: $_GET) e devolve [where, parâmetros, escolhidos]. Valores fora das opções são ignorados. */
function aplicar_filtros(array $filtros, array $entrada): array
{
    $condicoes = ['1 = 1'];
    $params = [];
    $escolhidos = [];
    foreach ($filtros as $chave => $f) {
        $valor = trim((string)($entrada[$chave] ?? ''));
        if ($valor === '' || !array_key_exists($valor, $f['opcoes'])) {
            continue;
        }
        $condicoes[] = $f['condicao'];
        $params[] = $valor;
        $escolhidos[$chave] = $valor;
    }
    return [implode(' AND ', $condicoes), $params, $escolhidos];
}

/**
 * Números de página a mostrar: até 5, com a atual no meio quando possível.
 * Devolve ['inicio', 'fim', 'antes' (página do "…" da esquerda ou null), 'depois' (idem, direita)].
 */
function janela_de_paginas(int $atual, int $total, int $largura = 5): array
{
    $inicio = max(1, min($atual - intdiv($largura, 2), $total - $largura + 1));
    $fim = min($total, $inicio + $largura - 1);
    return [
        'inicio' => $inicio,
        'fim' => $fim,
        'antes' => $inicio > 1 ? $inicio - 1 : null,
        'depois' => $fim < $total ? $fim + 1 : null,
    ];
}

/* ---------------------------------------------------------------- Layout */

/** O formato da folha (blocos) só é conhecido depois da primeira folha lida. */
function formato_conhecido(array $layout): bool
{
    return !empty($layout['blocos']) || !empty($layout['colunas']);
}

/** O banco guarda no layout só o que o leitor precisa. */
function layout_do_leitor(array $layout): array
{
    return array_intersect_key($layout, ['caderno' => 1, 'questoes' => 1, 'alternativas' => 1, 'blocos' => 1, 'colunas' => 1]);
}

/** "Caderno 2265M0801 · 26 questões em 4 blocos (7, 7, 7, 5) · alternativas A–D" */
function descrever_formato(array $layout, int $questoes): string
{
    $partes = [];
    if (!empty($layout['caderno'])) {
        $partes[] = 'Caderno ' . $layout['caderno'];
    }
    $blocos = $layout['blocos'] ?? null;
    $partes[] = "$questoes questões" . ($blocos && count($blocos) > 1
            ? ' em ' . count($blocos) . ' blocos (' . implode(', ', $blocos) . ')' : '');
    $alts = $layout['alternativas'] ?? [];
    if ($alts) {
        $partes[] = 'alternativas ' . reset($alts) . '–' . end($alts);
    }
    return implode(' · ', $partes);
}

/**
 * Confere o gabarito enviado pelo formulário e devolve [questões limpas, erros, sem dificuldade].
 * Com $completa (liberar, ou salvar uma prova já liberada), toda questão precisa de resposta e
 * disciplina. A dificuldade é opcional: as questões sem ela voltam em "sem dificuldade" para o aviso.
 */
function validar_gabarito(array $entrada, array $prova, bool $completa): array
{
    $alts = $prova['layout']['alternativas'];
    $catalogo = disciplinas();
    $fixa = $prova['disciplina']; // prova de uma disciplina só: todas as questões têm essa
    $questoes = [];
    $semResposta = $semDisciplina = $semDificuldade = $respostaInvalida = $disciplinaInvalida = [];
    foreach ($prova['questoes'] as $i => $atual) {
        $n = (int)$atual['numero'];
        $q = (array)($entrada[$i] ?? []);
        $resposta = strtoupper(trim((string)($q['resposta'] ?? '')));
        $disciplina = $fixa ?? trim((string)($q['disciplina'] ?? ''));
        $dificuldade = (string)($q['dificuldade'] ?? '');
        if ($resposta !== '' && !in_array($resposta, $alts, true)) {
            $respostaInvalida[] = $n;
            $resposta = '';
        }
        if ($disciplina !== '' && !in_array($disciplina, $catalogo, true)) {
            $disciplinaInvalida[] = $n;
            $disciplina = '';
        }
        if ($dificuldade !== '' && !isset(DIFICULDADES[$dificuldade])) {
            $dificuldade = '';
        }
        if ($resposta === '') {
            $semResposta[] = $n;
        }
        if ($disciplina === '') {
            $semDisciplina[] = $n;
        }
        if ($dificuldade === '') {
            $semDificuldade[] = $n;
        }
        $questoes[] = [
            'numero' => $n,
            'resposta' => $resposta ?: null,
            'disciplina' => $disciplina ?: null,
            'dificuldade' => $dificuldade ?: null,
        ];
    }
    $das = fn(array $nums) => (count($nums) === 1 ? 'da questão ' : 'das questões ') . listar_numeros($nums);
    $erros = [];
    if ($respostaInvalida) {
        $erros[] = 'A resposta ' . $das($respostaInvalida) . ' precisa ser uma das alternativas (' . implode(', ', $alts) . ').';
    }
    if ($disciplinaInvalida) {
        $erros[] = 'A disciplina ' . $das($disciplinaInvalida) . ' não está na lista.';
    }
    if ($completa && $semResposta) {
        $erros[] = 'Falta a resposta ' . $das($semResposta) . '.';
    }
    if ($completa && $semDisciplina) {
        $erros[] = 'Falta a disciplina ' . $das($semDisciplina) . '.';
    }
    return [$questoes, $erros, $semDificuldade];
}

/** Grava o gabarito; com $liberar, a prova passa a aceitar folhas. */
function salvar_gabarito(int $provaId, array $questoes, bool $liberar): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($liberar) {
            $pdo->prepare("UPDATE provas SET status = 'ativa' WHERE id = ?")->execute([$provaId]);
        }
        $st = $pdo->prepare('UPDATE questoes SET resposta = ?, disciplina = ?, dificuldade = ? WHERE prova_id = ? AND numero = ?');
        foreach ($questoes as $q) {
            $st->execute([$q['resposta'], $q['disciplina'], $q['dificuldade'], $provaId, $q['numero']]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * A prova começa sem o formato da folha (blocos). A primeira folha lida define o formato;
 * as outras precisam ter o mesmo. O caderno do QR, quando lido, precisa ser o da prova.
 * Devolve o erro da folha, ou null se ela serve (e, se for a primeira, completa o $layout).
 */
function conferir_formato_da_folha(array &$layout, array $leitura, array $gabarito): ?string
{
    $detectado = $leitura['layout'];
    $n = count($gabarito);
    $caderno = $layout['caderno'] ?? null;
    $lido = isset($leitura['caderno']) && $leitura['caderno'] !== '' ? strtoupper((string)$leitura['caderno']) : null;
    if ($caderno && $lido && $lido !== strtoupper($caderno)) {
        return "Esta folha é do caderno $lido, e a prova é do caderno $caderno.";
    }
    if ((int)$detectado['questoes'] !== $n) {
        return "Esta folha tem {$detectado['questoes']} questões, e o gabarito da prova tem $n.";
    }
    $foraDaFolha = array_diff(array_filter($gabarito), $detectado['alternativas']);
    if ($foraDaFolha) {
        return 'O gabarito usa a alternativa ' . implode(', ', array_unique($foraDaFolha))
            . ', que não existe nesta folha (' . implode(', ', $detectado['alternativas']) . ').';
    }
    if (formato_conhecido($layout)) {
        if ($detectado['blocos'] !== $layout['blocos'] || $detectado['alternativas'] !== $layout['alternativas']) {
            return 'O formato desta folha (blocos ' . implode(', ', $detectado['blocos'])
                . ') é diferente do das outras folhas da prova (' . implode(', ', $layout['blocos']) . ').';
        }
        return null;
    }
    $layout = array_intersect_key($detectado, ['alternativas' => 1, 'blocos' => 1, 'questoes' => 1]) + $layout;
    return null;
}

/* ------------------------------------------------------- Leitor óptico */

/**
 * Recortes enviados pelo app (a foto inteira não sai do celular). $recortes: um por folha,
 * ['area' => caminho, 'qr' => caminho ou null, 'caderno' => texto do QR lido no app ou null].
 * Devolve uma entrada por folha: ['arquivo', 'pagina', 'ok', 'erro'?, 'respostas'?,
 * 'imagem_debug'? (área das bolinhas anotada, com o QR em cima), 'caderno'?, 'layout'?, 'confianca'?].
 */
function ler_recortes(array $recortes, ?array $layout = null): array
{
    $cadernoValido = fn($c) => is_string($c) && preg_match('/^[A-Za-z0-9]{1,40}$/', $c) ? $c : '-';
    return rodar_leitor(array_merge(['ler-recorte'], array_column($recortes, 'area'),
        ['--qr'], array_map(fn($r) => $r['qr'] ?? '-', $recortes),
        ['--caderno'], array_map(fn($r) => $cadernoValido($r['caderno'] ?? null), $recortes)), $layout);
}

function rodar_leitor(array $argumentos, ?array $layout): array
{
    if (!is_file(OMR_CLI)) {
        throw new RuntimeException('O leitor óptico (worker/omr_cli.py) não foi encontrado.');
    }
    $pastaDebug = pasta_temporaria() . '/lidas';
    $cmd = array_merge([PYTHON, OMR_CLI], $argumentos, ['--debug', $pastaDebug]);
    if ($layout && formato_conhecido($layout)) {
        $layoutJson = tempnam(pasta_temporaria(), 'layout');
        file_put_contents($layoutJson, json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE));
        array_push($cmd, '--layout', $layoutJson);
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Não foi possível iniciar o leitor óptico (Python).');
    }
    $saida = stream_get_contents($pipes[1]);
    $erros = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('O leitor óptico falhou: ' . trim($erros));
    }
    $dados = json_decode($saida, true);
    if (!is_array($dados)) {
        throw new RuntimeException('Resposta inválida do leitor óptico.');
    }
    return $dados;
}

/** Converte a leitura do Python em respostas: letra, null (branco) ou "MULTIPLA". */
function respostas_da_leitura(array $leitura): array
{
    return array_map(fn($r) => match ($r['situacao']) {
        'marcada' => $r['resposta'],
        'multipla' => 'MULTIPLA',
        default => null,
    }, $leitura['respostas']);
}

function questoes_para_revisar(array $leitura): array
{
    $lista = [];
    foreach ($leitura['respostas'] as $r) {
        if ($r['duvidosa'] || $r['situacao'] === 'multipla') {
            $lista[] = $r['questao'];
        }
    }
    return $lista;
}

/* ------------------------------------------------------------ Correção */

/** Acerto = marcação única igual ao gabarito. Branco e dupla contam como erro. */
function corrigir(array $gabarito, array $respostas): array
{
    $acertos = [];
    foreach (array_values($gabarito) as $i => $certa) {
        $acertos[] = $certa !== null && ($respostas[$i] ?? null) === $certa;
    }
    return $acertos;
}

/* ------------------------------------------------------------ Relatório */

/** Folhas da prova: [lidas, falhas]. As lidas já vêm corrigidas (acertos, n_acertos, taxa). */
function carregar_folhas(array $prova): array
{
    $st = db()->prepare('SELECT * FROM folhas WHERE prova_id = ? ORDER BY turma, id');
    $st->execute([$prova['id']]);
    $lidas = $falhas = [];
    foreach ($st->fetchAll() as $f) {
        if ($f['erro'] !== null) {
            $falhas[] = $f;
            continue;
        }
        $lidas[] = corrigir_folha(decodificar_folha($f), $prova['gabarito']);
    }
    return [$lidas, $falhas];
}

/** Colunas JSON e booleanas da folha já convertidas. */
function decodificar_folha(array $f): array
{
    $f['id'] = (int)$f['id'];
    $f['prova_id'] = (int)$f['prova_id'];
    $f['pagina'] = (int)$f['pagina'];
    $f['turma'] = (string)$f['turma'];
    $f['respostas'] = json_decode($f['respostas'] ?? '[]', true) ?? [];
    $f['revisar'] = json_decode($f['revisar'] ?? '[]', true) ?? [];
    $f['niveis'] = json_decode($f['niveis'] ?? 'null', true);
    $f['revisada'] = (bool)$f['revisada'];
    return $f;
}

/** Acrescenta à folha os acertos por questão, o total de acertos e a taxa. */
function corrigir_folha(array $folha, array $gabarito): array
{
    $folha['acertos'] = corrigir($gabarito, $folha['respostas']);
    $folha['n_acertos'] = count(array_filter($folha['acertos']));
    $folha['taxa'] = pct($folha['n_acertos'], count($gabarito));
    return $folha;
}

/** Nível pela proporção de acerto (índice de dificuldade clássico), ou null sem folhas. */
function dificuldade_observada(?float $taxa): ?string
{
    if ($taxa === null) {
        return null;
    }
    return $taxa > LIMITE_FACIL ? 'facil' : ($taxa < LIMITE_DIFICIL ? 'dificil' : 'medio');
}

/** Questão sem disciplina conta como "Geral". */
function disciplina_da_questao(array $q): string
{
    return $q['disciplina'] !== null && $q['disciplina'] !== '' ? $q['disciplina'] : NOME_TODAS_DISCIPLINAS;
}

/** Níveis de dificuldade usados na prova, na ordem Fácil, Médio, Difícil. */
function niveis_da_prova(array $questoes): array
{
    $usados = array_column($questoes, 'dificuldade');
    return array_values(array_filter(array_keys(DIFICULDADES), fn($n) => in_array($n, $usados, true)));
}

/**
 * Resumo por turma: folhas, acerto geral, alunos para reforço, folhas a revisar e acerto
 * por disciplina e por nível de dificuldade ([acertos, respostas] em cada grupo).
 */
function resumo_por_turma(array $questoes, array $folhas): array
{
    $resumo = [];
    foreach ($folhas as $f) {
        $t = $f['turma'];
        $resumo[$t] ??= ['folhas' => 0, 'acertos' => 0, 'total' => 0, 'reforco' => 0, 'revisar' => 0,
            'disciplinas' => [], 'niveis' => []];
        $r = &$resumo[$t];
        $r['folhas']++;
        $r['acertos'] += $f['n_acertos'];
        $r['total'] += count($questoes);
        $r['reforco'] += $f['taxa'] !== null && $f['taxa'] < LIMITE_REFORCO ? 1 : 0;
        $r['revisar'] += $f['revisar'] ? 1 : 0;
        foreach (array_values($questoes) as $i => $q) {
            $certo = (int)($f['acertos'][$i] ?? false);
            $d = disciplina_da_questao($q);
            $r['disciplinas'][$d] = [($r['disciplinas'][$d][0] ?? 0) + $certo, ($r['disciplinas'][$d][1] ?? 0) + 1];
            if ($q['dificuldade'] !== null) {
                $n = $q['dificuldade'];
                $r['niveis'][$n] = [($r['niveis'][$n][0] ?? 0) + $certo, ($r['niveis'][$n][1] ?? 0) + 1];
            }
        }
        unset($r);
    }
    ksort($resumo, SORT_NATURAL);
    return $resumo;
}

/* ------------------------------------------------- Precisão da leitura */

// Mesmos limiares do leitor (worker/omr.py): marcada a partir de 0,5; dúvida entre 0,25 e 0,65.
const LIMIAR_MARCADA = 0.5;
const ZONA_DUVIDA = [0.25, 0.65];

/**
 * Leitura original de uma questão, refeita a partir do nível de cada bolinha (0 = vazia,
 * 1 = toda pintada), que fica guardado mesmo depois da correção do professor.
 * Devolve [resposta (letra, "MULTIPLA" ou null), duvidosa (marcação fraca ou dupla)].
 */
function leitura_pelos_niveis(array $niveis, array $alternativas): array
{
    $marcadas = [];
    $fraca = false;
    foreach (array_values($niveis) as $i => $v) {
        if ($v >= LIMIAR_MARCADA) {
            $marcadas[] = $alternativas[$i] ?? null;
        }
        if ($v > ZONA_DUVIDA[0] && $v < ZONA_DUVIDA[1]) {
            $fraca = true;
        }
    }
    $resposta = match (count($marcadas)) {
        0 => null,
        1 => $marcadas[0],
        default => 'MULTIPLA',
    };
    return [$resposta, $fraca || count($marcadas) > 1];
}

/**
 * Quanto da leitura de um conjunto de folhas é certo, e quanto isso pesa no acerto.
 * - certeza: todas as bolinhas claramente vazias ou pintadas; duvidosas: fraca ou dupla.
 * - conferidas: duvidosas de folhas que o professor revisou; pendentes: as outras.
 * - acerto_min / acerto_max: o acerto se cada pendente estiver errada / certa (a margem).
 * - corrigidas_duvidosas: das conferidas, quantas o professor mudou;
 *   corrigidas_sem_duvida: leituras sem dúvida que o professor mudou (o erro que o leitor
 *   não sinaliza), sobre as questões sem dúvida das folhas revisadas.
 */
function precisao_da_leitura(array $folhas, array $gabarito, array $alternativas): array
{
    $c = array_fill_keys(['questoes', 'certeza', 'duvidosas', 'conferidas', 'pendentes', 'sem_niveis',
        'corrigidas_duvidosas', 'certeza_conferida', 'corrigidas_sem_duvida', 'acertos', 'podem_cair',
        'podem_subir'], 0);
    foreach ($folhas as $f) {
        $niveis = is_array($f['niveis'] ?? null) ? $f['niveis'] : null;
        $revisada = !empty($f['revisada']);
        foreach (array_values($gabarito) as $i => $certa) {
            $c['questoes']++;
            $atual = $f['respostas'][$i] ?? null;
            $acertou = $certa !== null && $atual === $certa;
            $c['acertos'] += (int)$acertou;
            if ($niveis === null || !is_array($niveis[$i] ?? null)) {
                $c['sem_niveis']++;
                continue;
            }
            [$lida, $duvidosa] = leitura_pelos_niveis($niveis[$i], $alternativas);
            $mudou = $lida !== $atual;
            if (!$duvidosa) {
                $c['certeza']++;
                if ($revisada) {
                    $c['certeza_conferida']++;
                    $c['corrigidas_sem_duvida'] += (int)$mudou;
                }
                continue;
            }
            $c['duvidosas']++;
            if ($revisada) {
                $c['conferidas']++;
                $c['corrigidas_duvidosas'] += (int)$mudou;
            } else {
                $c['pendentes']++;
                $c[$acertou ? 'podem_cair' : 'podem_subir']++;
            }
        }
    }
    $t = $c['questoes'];
    return $c + [
        'pct_certeza' => pct($c['certeza'], $t),
        'pct_duvidosas' => pct($c['duvidosas'], $t),
        'margem' => pct($c['pendentes'], $t),
        'acerto' => pct($c['acertos'], $t),
        'acerto_min' => pct($c['acertos'] - $c['podem_cair'], $t),
        'acerto_max' => pct($c['acertos'] + $c['podem_subir'], $t),
        'pct_corrigidas_duvidosas' => pct($c['corrigidas_duvidosas'], $c['conferidas']),
        'pct_corrigidas_sem_duvida' => pct($c['corrigidas_sem_duvida'], $c['certeza_conferida']),
    ];
}

/** Quantas leituras de cada questão ficaram duvidosas (fraca ou dupla), na ordem das questões. */
function duvidosas_por_questao(array $folhas, array $alternativas, int $questoes): array
{
    $n = array_fill(0, $questoes, 0);
    foreach ($folhas as $f) {
        foreach ((is_array($f['niveis'] ?? null) ? $f['niveis'] : []) as $i => $niv) {
            if ($i < $questoes && is_array($niv) && leitura_pelos_niveis($niv, $alternativas)[1]) {
                $n[$i]++;
            }
        }
    }
    return $n;
}

/**
 * Uma linha por questão, calculada sobre as folhas recebidas (uma turma ou todas):
 * gabarito, disciplina, dificuldade marcada e observada, acerto e o erro comum
 * (a alternativa errada mais marcada; branco e dupla não entram).
 */
function estatisticas_das_questoes(array $questoes, array $folhas): array
{
    $linhas = [];
    foreach (array_values($questoes) as $i => $q) {
        $acertos = $brancos = 0;
        $erradas = [];
        foreach ($folhas as $f) {
            $r = $f['respostas'][$i] ?? null;
            if ($r !== null && $r === $q['resposta']) {
                $acertos++;
            } elseif ($r === null || $r === 'MULTIPLA') {
                $brancos++;
            } else {
                $erradas[$r] = ($erradas[$r] ?? 0) + 1;
            }
        }
        ksort($erradas);
        arsort($erradas); // ordenação estável: no empate fica a primeira letra
        $taxa = pct($acertos, count($folhas));
        $observada = dificuldade_observada($taxa);
        $linhas[] = [
            'numero' => (int)$q['numero'],
            'certa' => $q['resposta'],
            'disciplina' => disciplina_da_questao($q),
            'dificuldade' => $q['dificuldade'],
            'alunos' => count($folhas),
            'acertos' => $acertos,
            'brancos' => $brancos,
            'taxa' => $taxa,
            'distrator' => $erradas ? (string)array_key_first($erradas) : null,
            'distrator_qtd' => $erradas ? reset($erradas) : 0,
            'observada' => $observada,
            'diverge' => $q['dificuldade'] !== null && $observada !== null && $q['dificuldade'] !== $observada,
        ];
    }
    return $linhas;
}

/**
 * Prioridades de revisão: questões com acerto abaixo do LIMITE_REFORCO, da mais urgente
 * para a menos urgente. Primeiro as fáceis e médias (conteúdo que a turma deveria dominar),
 * depois as sem nível marcado e por último as difíceis (acerto baixo já era esperado).
 * Dentro de cada grupo, do menor acerto para o maior; no empate, a fácil vem antes.
 */
function prioridades_de_revisao(array $estatisticas): array
{
    $grupo = ['facil' => 0, 'medio' => 0, '' => 1, 'dificil' => 2];
    $lista = array_values(array_filter($estatisticas, fn($e) => $e['taxa'] !== null && $e['taxa'] < LIMITE_REFORCO));
    foreach ($lista as &$e) {
        $e['grupo'] = $grupo[$e['dificuldade'] ?? ''];
        $e['motivo'] = motivo_da_prioridade($e);
    }
    unset($e);
    usort($lista, fn($a, $b) => [$a['grupo'], $a['taxa'], $a['dificuldade'] === 'medio', $a['numero']]
        <=> [$b['grupo'], $b['taxa'], $b['dificuldade'] === 'medio', $b['numero']]);
    return $lista;
}

/** "Marcada como fácil, mas só 35,0% acertaram. Erro comum: B (12 alunos)." */
function motivo_da_prioridade(array $e): string
{
    $acerto = fmt_pct($e['taxa']);
    $texto = match ($e['dificuldade']) {
        'facil', 'medio' => 'Marcada como ' . mb_strtolower(DIFICULDADES[$e['dificuldade']]) . ", mas só $acerto acertaram.",
        'dificil' => "Questão difícil: $acerto de acerto.",
        default => "$acerto de acerto (sem nível marcado).",
    };
    if ($e['distrator'] !== null) {
        $texto .= " Erro comum: {$e['distrator']} ({$e['distrator_qtd']} " . ($e['distrator_qtd'] === 1 ? 'aluno' : 'alunos') . ').';
    }
    if ($e['alunos'] > 0 && $e['brancos'] * 2 >= $e['alunos']) {
        $texto .= " {$e['brancos']} em branco ou com marcação dupla.";
    }
    return $texto;
}

/**
 * Dificuldade marcada pelo professor × observada nas folhas: [marcada][observada] => número de
 * questões, só das que têm as duas.
 */
function matriz_de_dificuldade(array $estatisticas): array
{
    $niveis = array_keys(DIFICULDADES);
    $matriz = array_fill_keys($niveis, array_fill_keys($niveis, 0));
    foreach ($estatisticas as $e) {
        if ($e['dificuldade'] !== null && $e['observada'] !== null) {
            $matriz[$e['dificuldade']][$e['observada']]++;
        }
    }
    return $matriz;
}

/** Filtros do mapa de questões por disciplina e dificuldade ('sem' = sem nível marcado); vazio = todas. */
function filtrar_questoes(array $estatisticas, string $disciplina, string $dificuldade): array
{
    return array_values(array_filter($estatisticas, fn($e) =>
        ($disciplina === '' || $e['disciplina'] === $disciplina)
        && ($dificuldade === '' || ($e['dificuldade'] ?? 'sem') === $dificuldade)));
}

/* ------------------------------------------------------------- Uploads */

/**
 * Arquivos de um campo de $_FILES (um ou vários). Confere o tamanho e o tipo real pelo
 * conteúdo (finfo): um arquivo renomeado para .jpg não passa.
 */
function upload_normalizado(array $campo): array
{
    $arquivos = [];
    if (!isset($campo['name'])) {
        return $arquivos;
    }
    $limite = intdiv(TAMANHO_MAXIMO_ARQUIVO, 1024 * 1024) . ' MB';
    foreach ((array)$campo['name'] as $i => $nome) {
        $erro = ((array)$campo['error'])[$i];
        if ($erro === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            throw new ErroDoUsuario("\"$nome\" passa do tamanho máximo de envio.", 413);
        }
        if ($erro !== UPLOAD_ERR_OK) {
            throw new ErroDoUsuario("Falha no envio de \"$nome\" (código $erro). Tente de novo.", 400);
        }
        $tmp = ((array)$campo['tmp_name'])[$i];
        if (filesize($tmp) > TAMANHO_MAXIMO_ARQUIVO) {
            throw new ErroDoUsuario("\"$nome\" tem mais de $limite.", 413);
        }
        $ext = TIPOS_ACEITOS[(new finfo(FILEINFO_MIME_TYPE))->file($tmp)] ?? null;
        if ($ext === null) {
            throw new ErroDoUsuario("\"$nome\" não é JPG nem PNG.", 415);
        }
        $arquivos[] = ['nome' => $nome, 'tmp' => $tmp, 'ext' => $ext];
    }
    return $arquivos;
}

/** Nome guardado no banco: nunca o nome original, que pode trazer o nome do aluno. */
function nome_generico(string $prefixo, int $ordem, string $ext): string
{
    return preg_replace('/[^A-Za-z0-9_-]+/', '', $prefixo) . '_folha' . str_pad((string)$ordem, 2, '0', STR_PAD_LEFT) . ".$ext";
}

/** Move o upload para a pasta temporária da requisição, com o nome $nome. */
function guardar_upload(array $arq, string $nome): string
{
    $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($nome, PATHINFO_FILENAME));
    $destino = pasta_temporaria() . '/' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $arq['ext'];
    if (!move_uploaded_file($arq['tmp'], $destino)) {
        throw new RuntimeException("Não foi possível salvar \"{$arq['nome']}\".");
    }
    return $destino;
}

/* ------------------------------------------------------------- Consultas */

function buscar_prova(int $id): array
{
    $st = db()->prepare('SELECT * FROM provas WHERE id = ?');
    $st->execute([$id]);
    $prova = $st->fetch();
    if (!$prova) {
        throw new ErroDoUsuario('Prova não encontrada.', 404);
    }
    foreach (['id', 'ano', 'edicao', 'ano_escolar'] as $c) {
        $prova[$c] = (int)$prova[$c];
    }
    $st = db()->prepare('SELECT numero, resposta, disciplina, dificuldade FROM questoes WHERE prova_id = ? ORDER BY numero');
    $st->execute([$id]);
    $questoes = $st->fetchAll();
    foreach ($questoes as &$q) {
        $q['numero'] = (int)$q['numero'];
    }
    unset($q);
    $prova['layout'] = (json_decode($prova['layout'], true) ?: []) + ['questoes' => count($questoes), 'alternativas' => ALTERNATIVAS];
    $prova['questoes'] = $questoes;
    $prova['gabarito'] = array_column($questoes, 'resposta');
    return $prova;
}

/* ------------------------------------------------------------- Páginas */

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Início do HTML de uma página. $menu marca o item ativo da barra superior
 * ('provas' ou 'nova'); $voltar mostra um link "Voltar".
 */
function cabecalho(string $titulo, string $menu = '', string $voltar = ''): void
{
    $professor = professor_logado();
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($titulo) . ' | Panorama da Prova</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap">'
        . '<link rel="stylesheet" href="estilo.css"><script src="dropdown.js" defer></script></head><body>';
    // Barra superior branca, na largura toda; o conteúdo dela fica alinhado com o <main>.
    echo '<header class="topo"><div class="topo-interno"><a class="marca" href="index.php">' . simbolo_marca() . 'Panorama da Prova</a>'
        . '<nav class="menu" aria-label="Menu principal">';
    if ($professor) {
        echo '<a class="item-menu" href="index.php"' . ($menu === 'provas' ? ' aria-current="page"' : '') . '>Provas</a>'
            . '<a class="item-menu" href="nova-prova.php"' . ($menu === 'nova' ? ' aria-current="page"' : '') . '>Nova prova</a>';
    }
    echo '</nav>';
    if ($voltar) {
        echo '<a class="voltar" href="' . e($voltar) . '">Voltar</a>';
    }
    if ($professor) {
        echo '<form method="post" action="sair.php" class="sessao">' . campo_csrf()
            . '<span>' . e($professor['email']) . '</span><button type="submit" class="secundario">Sair</button></form>';
    }
    echo '</div></header><main><h1>' . e($titulo) . '</h1>';
}

/**
 * Status da prova num selo só, com os mesmos nomes do aplicativo: cancelada vale mais que o
 * gabarito pendente (rascunho); senão, a prova está liberada. Devolve [rótulo, classe do .selo].
 */
function status_da_prova(array $prova): array
{
    return match (true) {
        $prova['situacao'] === 'cancelada' => ['Cancelada', 'cancelada'],
        $prova['status'] === 'rascunho' => ['Gabarito pendente', 'atencao'],
        default => ['Liberada', 'certo'],
    };
}

/** Símbolo do sistema: quatro bolinhas de folha de respostas, duas marcadas (o mesmo do app). */
function simbolo_marca(): string
{
    return '<svg class="simbolo" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true">'
        . '<rect width="32" height="32" rx="8" fill="currentColor"/>'
        . '<circle cx="11" cy="11" r="4" fill="#fff"/><circle cx="21" cy="11" r="3.2" fill="none" stroke="#fff" stroke-width="1.6"/>'
        . '<circle cx="11" cy="21" r="3.2" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="21" cy="21" r="4" fill="#fff"/></svg>';
}

/**
 * Situação da prova no topo da página dela. Ativa: botão para cancelar. Cancelada: aviso, com
 * os botões para ativar de novo e para excluir de vez. Os formulários vão para situacao.php.
 */
function quadro_situacao(array $prova): string
{
    $form = fn(string $acao, string $rotulo, string $classe, string $confirmar = '') =>
        '<form method="post" action="situacao.php"' . ($confirmar ? ' onsubmit="return confirm(' . e(json_encode($confirmar, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
        . campo_csrf() . '<input type="hidden" name="id" value="' . (int)$prova['id'] . '">'
        . '<input type="hidden" name="acao" value="' . $acao . '">'
        . '<button type="submit" class="' . $classe . '">' . e($rotulo) . '</button></form>';

    if ($prova['situacao'] === 'cancelada') {
        return '<div class="situacao-prova cancelada nao-imprimir" role="status">'
            . '<p><strong>Prova cancelada.</strong> Não aparece na lista padrão nem no aplicativo, e não recebe folhas. '
            . 'Ative de novo para voltar a usar, ou exclua de vez.</p><div class="acoes-situacao">'
            . $form('ativar', 'Ativar prova', '')
            . $form('excluir', 'Excluir prova', 'secundario perigo',
                'Excluir esta prova de vez? O gabarito, as folhas lidas e as imagens serão apagados, e isso não pode ser desfeito.')
            . '</div></div>';
    }
    return '<div class="situacao-prova nao-imprimir">'
        . $form('cancelar', 'Cancelar prova', 'link perigo',
            'Cancelar esta prova? Ela sai da lista padrão e do aplicativo e para de receber folhas. Nada é apagado: você pode ativá-la de novo.')
        . '</div>';
}

function rodape(): void
{
    echo '</main></body></html>';
}
