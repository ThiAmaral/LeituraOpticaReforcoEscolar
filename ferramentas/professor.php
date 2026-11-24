<?php
declare(strict_types=1);

/*
 * Cadastro de professores (só a coordenação, pela linha de comando).
 *
 *   php ferramentas/professor.php adicionar professor@escola.br "Nome do professor"
 *       cadastra ou troca a senha (a senha é pedida sem aparecer na tela)
 *   php ferramentas/professor.php desativar professor@escola.br
 *       o professor deixa de entrar no painel e no app
 *   php ferramentas/professor.php listar
 *
 * No XAMPP: C:\xampp\php\php.exe ferramentas\professor.php ...
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/web/lib.php';

/** Lê a senha sem mostrar o que é digitado (no Windows, pelo PowerShell). */
function pedir_senha(string $pergunta): string
{
    if (($env = getenv('SENHA_PROFESSOR')) !== false && $env !== '') {
        return $env; // para scripts; não use em computador compartilhado
    }
    fwrite(STDOUT, $pergunta);
    if (PHP_OS_FAMILY === 'Windows') {
        $senha = (string)shell_exec('powershell -NoProfile -Command "$s = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"');
    } else {
        shell_exec('stty -echo');
        $senha = (string)fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim($senha, "\r\n");
}

$acao = $argv[1] ?? '';
try {
    switch ($acao) {
        case 'adicionar':
            $email = $argv[2] ?? '';
            $nome = $argv[3] ?? null;
            $senha = pedir_senha('Senha (mínimo 8 caracteres): ');
            if ($senha !== pedir_senha('Repita a senha: ')) {
                throw new ErroDoUsuario('As senhas não conferem.');
            }
            salvar_professor($email, $senha, $nome);
            echo "Professor $email cadastrado." . PHP_EOL;
            break;
        case 'desativar':
            $st = db()->prepare('UPDATE professores SET ativo = 0 WHERE email = ?');
            $st->execute([$argv[2] ?? '']);
            db()->prepare('DELETE FROM tokens WHERE professor_id IN (SELECT id FROM professores WHERE email = ?)')
                ->execute([$argv[2] ?? '']);
            echo $st->rowCount() ? "Professor desativado." . PHP_EOL : "E-mail não encontrado." . PHP_EOL;
            break;
        case 'listar':
            foreach (db()->query('SELECT email, nome, ativo, criado_em FROM professores ORDER BY email') as $p) {
                printf("%-40s %-30s %s  desde %s%s", $p['email'], $p['nome'] ?? '', $p['ativo'] ? 'ativo  ' : 'inativo',
                    substr($p['criado_em'], 0, 10), PHP_EOL);
            }
            break;
        default:
            fwrite(STDERR, "Uso: php ferramentas/professor.php adicionar <email> [nome] | desativar <email> | listar" . PHP_EOL);
            exit(1);
    }
} catch (ErroDoUsuario $ex) {
    fwrite(STDERR, $ex->getMessage() . PHP_EOL);
    exit(1);
}
