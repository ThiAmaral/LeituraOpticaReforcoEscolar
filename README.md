# LeituraOptica-ReforcoEscolar

Corrige as folhas de resposta das avaliações e mostra a cada turma o que precisa de
reforço. O professor cadastra a prova e o gabarito no painel web; as folhas dos alunos
são fotografadas pelo aplicativo.

```
web/          Painel em PHP (XAMPP): provas, gabarito, relatórios
  api/        API JSON para o aplicativo (controlador frontal)
  src/        Banco (banco.php), login (auth.php), operações (servicos.php) e rotas da API (api.php)
worker/       Leitor óptico em Python/OpenCV (só diz qual bolinha foi marcada)
mobile/       Aplicativo em Expo (React Native): fotografa as folhas de cada turma
ferramentas/  Cadastro de professores, gerador de folhas de teste e validador da leitura
exemplos/     Folhas fictícias para testar o leitor
dados/        Criada sozinha: banco SQLite e imagens das folhas (fora do git)
```

O leitor precisa do Python 3.10+ com as bibliotecas do `worker/requirements.txt`:

```bash
pip install -r worker/requirements.txt
```

Os detalhes do aplicativo (telas, privacidade, scanner) estão no [README do app](mobile/README.md).

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

Para o celular alcançar o servidor, troque `localhost` por `0.0.0.0` (aceita conexões da rede).

## Rodar o aplicativo no celular

Precisa do **Node.js 20+** no computador e do **Expo Go** no celular (Play Store ou App
Store). O app usa o **Expo SDK 58**: o Expo Go precisa ser da mesma versão (aparece na
tela inicial dele). Se a loja tiver um Expo Go mais novo, o projeto precisa ser atualizado
com `npx expo install expo@^<versão> --fix`, dentro de `mobile/`.

1. **Deixe o servidor aberto para a rede.** No Apache, use `Require all granted` (veja
   acima); sem o Apache:

   ```bash
   C:\xampp\php\php.exe -S 0.0.0.0:8090 -t web web/src/roteador.php
   ```

2. **Descubra o IP do computador** na rede Wi-Fi (linha "Endereço IPv4"):

   ```bash
   ipconfig
   ```

3. **Configure o app.** Em `mobile/`, copie `.env.exemplo` para `.env` e coloque o endereço
   do servidor visto pelo celular, sem `/api` no fim:

   ```
   EXPO_PUBLIC_API_URL=http://192.168.0.10:8090
   ```

   Com o Apache, use `http://192.168.0.10/leitura`. Sempre que o IP mudar, atualize o `.env`
   e reinicie o Expo.

4. **Instale e inicie o app** (a primeira vez, `npm install`):

   ```bash
   cd mobile
   npm install
   npx expo start --clear
   ```

5. **Abra no celular.** Com o celular **no mesmo Wi-Fi** do computador, leia o QR que aparece
   no terminal: no Android, pelo próprio Expo Go; no iPhone, pela câmera. Ou, no Expo Go,
   toque em "Enter URL manually" e digite o endereço `exp://...` mostrado no terminal.
6. Entre com o mesmo e-mail e senha do painel web.

### Se não abrir no celular

| O que acontece | O que fazer |
|---|---|
| "Something went wrong" ao abrir | Versão do Expo Go diferente da do projeto (SDK 58): atualize um dos dois |
| O app abre, mas diz "Sem conexão com o servidor" | Confira o IP no `mobile/.env` e se o PHP está em `0.0.0.0`. No navegador do celular, abra `http://<IP>:8090/login.php`: se não abrir, é a rede |
| Nem o navegador do celular abre o servidor | Celular em outra rede (ou nos dados móveis), roteador isolando os aparelhos, ou firewall do Windows: libere o `php.exe` e o `node.exe` na rede em uso |
| O QR abre o navegador em vez do app | Leia o QR de dentro do Expo Go ou digite o endereço `exp://...` |

Para testar no navegador do computador, sem celular: `npx expo start --web` e, no `.env`
da raiz, `CORS_ORIGENS=http://localhost:8081`.

## Configuração opcional

Num arquivo `.env` na raiz (fora do git):

| Variável | Padrão | Uso |
|---|---|---|
| `DADOS_DIR` | `dados/` | Onde ficam o banco e as imagens |
| `OMR_PYTHON` | `python` | Python usado pelo leitor óptico |
| `LIMITE_REFORCO` | 60 | % de acerto abaixo da qual a questão entra nas prioridades |
| `LIMITE_FACIL` / `LIMITE_DIFICIL` | 70 / 30 | Faixas da dificuldade observada |
| `CORS_ORIGENS` | (nenhuma) | Origens liberadas na API para o app no navegador, ex.: `http://localhost:8081` |
