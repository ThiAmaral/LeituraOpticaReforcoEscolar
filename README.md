# LeituraOptica-ReforcoEscolar

Corrige as folhas de resposta das avaliações e mostra a cada turma o que precisa de
reforço. O professor cadastra a prova e o gabarito no painel web; as folhas dos alunos
são fotografadas pelo aplicativo.

```
web/          Painel em PHP (XAMPP): provas, gabarito, relatórios
  src/        Banco (banco.php), login (auth.php) e operações (servicos.php); fechada ao navegador
mobile/       Aplicativo (em construção)
ferramentas/  Cadastro de professores pela linha de comando
docs/         Requisitos (requisitos.md)
dados/        Criada sozinha: banco SQLite e imagens das folhas (fora do git)
```

O que o sistema faz, tela por tela, está em [docs/requisitos.md](docs/requisitos.md).

## Rodar no XAMPP

Precisa do XAMPP com PHP 8.1 ou mais novo. O banco é SQLite: não precisa ligar o MySQL.

1. **Apontar o Apache para a pasta `web/`.** Abra `C:\xampp\apache\conf\extra\httpd-xampp.conf`
   e acrescente no fim (troque o caminho se o projeto estiver em outro lugar):

   ```apache
   Alias /leitura "C:/Users/<usuario>/Documentos/projetoTcc/web"
   <Directory "C:/Users/<usuario>/Documentos/projetoTcc/web">
       AllowOverride All
       Require local
   </Directory>
   ```

   `Require local` deixa abrir só do próprio computador. Para o celular acessar pela rede,
   troque por `Require all granted`.
2. No XAMPP Control Panel, reinicie o **Apache** e abra http://localhost/leitura.
3. Cadastre os professores (só quem estiver cadastrado entra):

   ```bash
   C:\xampp\php\php.exe ferramentas\professor.php adicionar professor@escola.br "Nome do professor"
   ```

   A senha é pedida sem aparecer na tela. `desativar <email>` tira o acesso e
   `listar` mostra os cadastrados.

A pasta `dados/` (banco e imagens) é criada no primeiro acesso. Para começar do zero,
apague essa pasta. Para fazer backup, copie-a.

### Sem o Apache (desenvolvimento)

```bash
C:\xampp\php\php.exe -S localhost:8090 -t web web/src/roteador.php
```

## Configuração opcional

Num arquivo `.env` na raiz (fora do git):

| Variável | Padrão | Uso |
|---|---|---|
| `DADOS_DIR` | `dados/` | Onde ficam o banco e as imagens |
| `OMR_PYTHON` | `python` | Python usado pelo leitor óptico |
| `LIMITE_REFORCO` | 60 | % de acerto abaixo da qual a questão entra nas prioridades |
| `LIMITE_FACIL` / `LIMITE_DIFICIL` | 70 / 30 | Faixas da dificuldade observada |
