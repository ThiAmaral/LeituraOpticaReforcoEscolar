<?php
declare(strict_types=1);

/*
 * Login dos professores, sem serviço externo.
 * - Os professores ficam na tabela "professores", com a senha em hash (password_hash).
 *   Só a coordenação cadastra: ferramentas/professor.php (linha de comando).
 * - Painel web: sessão PHP, com CSRF nos formulários.
 * - App (API): o login devolve um token aleatório; só o hash dele fica no banco.
 *   O app manda "Authorization: Bearer <token>" em toda chamada.
 */

const VALIDADE_TOKEN_SEGUNDOS = 30 * 24 * 3600; // token do app vale 30 dias

/* ----------------------------------------------------------- Professores */

/** Professor (['id', 'email', 'nome']) se o e-mail e a senha conferem; senão null. */
function conferir_senha(string $email, string $senha): ?array
{
    $st = db()->prepare('SELECT id, email, nome, senha_hash FROM professores WHERE email = ? AND ativo = 1');
    $st->execute([trim($email)]);
    $p = $st->fetch();
    // Mesmo sem professor, roda o password_verify: o tempo de resposta não revela quem existe.
    $hash = $p['senha_hash'] ?? password_hash('-', PASSWORD_DEFAULT);
    if (!password_verify($senha, $hash) || !$p) {
        return null;
    }
    if (password_needs_rehash($p['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE professores SET senha_hash = ? WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $p['id']]);
    }
    return ['id' => (int)$p['id'], 'email' => $p['email'], 'nome' => $p['nome']];
}

/** Cadastra ou troca a senha de um professor (usado pela ferramenta de linha de comando). */
function salvar_professor(string $email, string $senha, ?string $nome = null): int
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ErroDoUsuario('E-mail inválido.');
    }
    if (mb_strlen($senha) < 8) {
        throw new ErroDoUsuario('A senha precisa ter pelo menos 8 caracteres.');
    }
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st = db()->prepare('SELECT id FROM professores WHERE email = ?');
    $st->execute([$email]);
    $id = $st->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE professores SET senha_hash = ?, nome = COALESCE(?, nome), ativo = 1 WHERE id = ?')
            ->execute([$hash, $nome, $id]);
        return (int)$id;
    }
    db()->prepare('INSERT INTO professores (email, nome, senha_hash, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$email, $nome, $hash, agora()]);
    return (int)db()->lastInsertId();
}

/* ------------------------------------------------------ Token (API, app) */

/** Token do cabeçalho "Authorization: Bearer ...", ou null. */
function token_do_cabecalho(array $servidor): ?string
{
    $cabecalho = $servidor['HTTP_AUTHORIZATION'] ?? $servidor['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($cabecalho), $m)) {
        return null;
    }
    return $m[1];
}

/** Cria um token para o app e devolve ['token', 'expira_em']. */
function criar_token(int $professorId): array
{
    $token = bin2hex(random_bytes(32));
    $expira = time() + VALIDADE_TOKEN_SEGUNDOS;
    db()->prepare('INSERT INTO tokens (hash, professor_id, expira_em, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $professorId, $expira, agora()]);
    if (random_int(1, 20) === 1) {
        db()->prepare('DELETE FROM tokens WHERE expira_em < ?')->execute([time()]);
    }
    return ['token' => $token, 'expira_em' => $expira];
}

/** Professor dono do token, ou null se o token não existe ou venceu. */
function professor_do_token(string $token): ?array
{
    $st = db()->prepare('SELECT p.id, p.email, p.nome FROM tokens t JOIN professores p ON p.id = t.professor_id
                         WHERE t.hash = ? AND t.expira_em > ? AND p.ativo = 1');
    $st->execute([hash('sha256', $token), time()]);
    $p = $st->fetch();
    return $p ? ['id' => (int)$p['id'], 'email' => $p['email'], 'nome' => $p['nome']] : null;
}

function apagar_token(string $token): void
{
    db()->prepare('DELETE FROM tokens WHERE hash = ?')->execute([hash('sha256', $token)]);
}

/* ---------------------------------------------------- Sessão (painel web) */

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('leitura_optica');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** Professor da sessão web (['id', 'email', 'nome']), ou null. */
function professor_logado(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    return $_SESSION['professor'] ?? null;
}

/** Login do painel. Senha errada: ErroDoUsuario 401. */
function entrar(string $email, string $senha): void
{
    if (trim($email) === '' || $senha === '') {
        throw new ErroDoUsuario('Informe o e-mail e a senha.', 400);
    }
    $professor = conferir_senha($email, $senha);
    if (!$professor) {
        usleep(400_000); // freia tentativas em sequência
        throw new ErroDoUsuario('E-mail ou senha incorretos.', 401);
    }
    session_regenerate_id(true); // o id e o CSRF de antes do login não servem mais
    unset($_SESSION['csrf']);
    $_SESSION['professor'] = $professor;
}

function sair(): void
{
    $_SESSION = [];
    session_destroy();
}

/**
 * Início de toda página do painel: exige login (sem login, vai para login.php e volta
 * depois), confere o CSRF dos POST e mostra os erros de forma amigável.
 */
function iniciar_pagina(): array
{
    set_exception_handler('pagina_de_erro');
    iniciar_sessao();
    $professor = $_SESSION['professor'] ?? null;
    if ($professor) {
        // Professor desativado pela coordenação perde o acesso na próxima página.
        $st = db()->prepare('SELECT 1 FROM professores WHERE id = ? AND ativo = 1');
        $st->execute([$professor['id']]);
        if (!$st->fetchColumn()) {
            $professor = null;
        }
    }
    if (!$professor) {
        $_SESSION = [];
        $volta = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        redirecionar(url('login.php') . ($volta !== '' ? '?volta=' . urlencode($volta) : ''));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        conferir_csrf($_POST['csrf'] ?? null);
    }
    return $professor;
}

/** Erro não tratado numa página: ErroDoUsuario mostra a mensagem; o resto, uma mensagem genérica. */
function pagina_de_erro(Throwable $ex): void
{
    $status = $ex instanceof ErroDoUsuario ? $ex->status : 500;
    if ($status === 500) {
        error_log((string)$ex);
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    cabecalho($status === 404 ? 'Não encontrado' : 'Algo deu errado');
    echo '<p class="erro">' . e($status === 500 ? 'Erro no servidor: ' . $ex->getMessage() : $ex->getMessage()) . '</p>';
    rodape();
}

/** Endereço de volta depois do login: só caminhos deste site (nada de "//outro.site"). */
function destino_seguro(string $volta): string
{
    return preg_match('#^/(?![/\\\\])[^\s]*$#', $volta) ? $volta : url('index.php');
}

/* ---------------------------------------------------------------- CSRF */

function token_csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

/** Formulário sem o token da sessão (ex.: enviado de outro site) é recusado. */
function conferir_csrf(?string $enviado): void
{
    if (!is_string($enviado) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
        throw new ErroDoUsuario('O formulário expirou. Recarregue a página e tente de novo.', 403);
    }
}
