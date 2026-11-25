<?php
declare(strict_types=1);

/*
 * Banco SQLite: um arquivo em dados/ (fora da pasta pública web/).
 * As tabelas são criadas na primeira conexão. Mudanças futuras de esquema entram
 * em migracoes(), na ordem; cada uma roda uma vez só (PRAGMA user_version).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DADOS)) {
        mkdir(DADOS, 0700, true);
    }
    // Se a pasta do projeto inteira cair dentro do htdocs, o Apache não entrega o banco nem as imagens.
    if (!is_file(DADOS . '/.htaccess')) {
        file_put_contents(DADOS . '/.htaccess', "Require all denied\n");
    }
    $pdo = new PDO('sqlite:' . DADOS . '/banco.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');   // leituras não esperam a gravação de outra requisição
    $pdo->exec('PRAGMA busy_timeout = 5000');  // duas gravações ao mesmo tempo: espera até 5 s
    migrar($pdo);
    return $pdo;
}

/** Aplica as migrações que ainda não rodaram neste arquivo de banco. */
function migrar(PDO $pdo): void
{
    $versao = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $lista = migracoes();
    for ($v = $versao; $v < count($lista); $v++) {
        $pdo->beginTransaction();
        try {
            $pdo->exec($lista[$v]);
            $pdo->exec('PRAGMA user_version = ' . ($v + 1));
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
}

/** Uma entrada por versão do esquema. Nunca altere uma que já rodou: acrescente outra. */
function migracoes(): array
{
    return [
        // 1: esquema inicial
        <<<SQL
        CREATE TABLE professores (
            id         INTEGER PRIMARY KEY,
            nome       TEXT,
            email      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            senha_hash TEXT NOT NULL,
            ativo      INTEGER NOT NULL DEFAULT 1,
            criado_em  TEXT NOT NULL
        );

        -- Tokens do app (API). Só o hash do token fica guardado.
        CREATE TABLE tokens (
            hash         TEXT PRIMARY KEY,
            professor_id INTEGER NOT NULL REFERENCES professores(id) ON DELETE CASCADE,
            expira_em    INTEGER NOT NULL,
            criado_em    TEXT NOT NULL
        );

        CREATE TABLE provas (
            id           INTEGER PRIMARY KEY,
            nome         TEXT NOT NULL,
            caderno      TEXT NOT NULL,             -- até 10 caracteres, maiúsculas (ex.: 2265M0801)
            ano          INTEGER NOT NULL,          -- ano letivo
            edicao       INTEGER NOT NULL,          -- 1 a 9
            disciplina   TEXT,                      -- NULL = todas as disciplinas ("Geral")
            ano_escolar  INTEGER NOT NULL,          -- 1 a 9
            status       TEXT NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho', 'ativa')),
            layout       TEXT NOT NULL,             -- JSON: o que o leitor precisa (alternativas, blocos)
            criado_por   INTEGER REFERENCES professores(id) ON DELETE SET NULL,
            criado_em    TEXT NOT NULL
        );
        CREATE INDEX provas_criado_em ON provas (criado_em DESC, id DESC);

        CREATE TABLE questoes (
            prova_id    INTEGER NOT NULL REFERENCES provas(id) ON DELETE CASCADE,
            numero      INTEGER NOT NULL,
            resposta    TEXT,                       -- vazia no rascunho; obrigatória para liberar
            disciplina  TEXT,
            dificuldade TEXT CHECK (dificuldade IN ('facil', 'medio', 'dificil')),
            PRIMARY KEY (prova_id, numero)
        );

        CREATE TABLE folhas (
            id           INTEGER PRIMARY KEY,
            prova_id     INTEGER NOT NULL REFERENCES provas(id) ON DELETE CASCADE,
            turma        TEXT NOT NULL,
            arquivo      TEXT NOT NULL,             -- nome genérico (turma_folhaNN), nunca o original
            pagina       INTEGER NOT NULL DEFAULT 1,
            imagem_debug TEXT,                      -- caminho relativo em dados/imagens
            respostas    TEXT,                      -- JSON
            niveis       TEXT,                      -- JSON: escurecimento de cada bolinha
            revisar      TEXT NOT NULL DEFAULT '[]',
            revisada     INTEGER NOT NULL DEFAULT 0,
            erro         TEXT,
            criado_em    TEXT NOT NULL
        );
        CREATE INDEX folhas_prova_turma ON folhas (prova_id, turma);
        SQL,

        // 2: situação da prova, à parte do status do gabarito (rascunho/ativa). Toda prova nasce
        // ativa; cancelada sai da lista padrão e do app, e só ela pode ser excluída.
        <<<SQL
        ALTER TABLE provas ADD COLUMN situacao TEXT NOT NULL DEFAULT 'ativa'
            CHECK (situacao IN ('ativa', 'cancelada'));
        SQL,
    ];
}

/** Data e hora atuais no formato guardado no banco (horário de Brasília). */
function agora(): string
{
    return date('Y-m-d H:i:s');
}
