# Aplicativo — Corretor de provas

Aplicativo iOS/Android em Expo (React Native, TypeScript) para fotografar as folhas de
resposta de cada turma e ver a correção na hora. O app não tem banco próprio: tudo passa
pela API PHP (`web/api/`), com o mesmo login do painel web.

## Telas

| Tela | Arquivo | O que faz |
|---|---|---|
| Login | `src/app/login.tsx` | E-mail e senha do painel; a API devolve um token, guardado no aparelho |
| Provas | `src/app/index.tsx` | As mesmas provas do web, mais recente primeiro, com filtros de ano e edição. Rascunho aparece como "Gabarito pendente" e não abre |
| Prova | `src/app/prova/[id]/index.tsx` | Continuar uma turma ou adicionar uma nova; turmas já corrigidas; apagar as fotos guardadas no celular |
| Escanear turma | `src/app/prova/[id]/escanear.tsx` | Câmera aberta; a cada foto, a nota e as questões duvidosas para confirmar ou corrigir; contador de folhas |
| Resultado da turma | `src/app/prova/[id]/resultado.tsx` | Acerto geral, por dificuldade e por disciplina, precisão da leitura e prioridades de revisão |
| Folha | `src/app/folha/[id].tsx` | Imagem anotada pelo leitor e correção manual |

Cadastro de prova e gabarito ficam só no painel web.

## O que sai do celular

A foto da folha **não sai do aparelho**. O app acha os 4 quadrados pretos, endireita a
folha e envia só dois recortes: a área de respostas e a faixa do QR (que termina antes
do campo do nome). A foto original fica numa pasta privada do app, fora da galeria, e o
professor pode apagá-la na tela da prova.

## Configurar

1. Instale o Node.js 20+ e rode, nesta pasta: `npm install`.
2. Copie `.env.exemplo` para `.env` e preencha `EXPO_PUBLIC_API_URL` com o endereço do
   servidor visto pelo celular, por exemplo `http://192.168.0.10/leitura` (o IP do
   computador aparece no `ipconfig`). O celular e o computador precisam estar na mesma rede,
   e o Apache precisa aceitar conexões da rede (veja o README da raiz).

## Rodar no celular

```bash
npx expo start
```

Instale o Expo Go no celular e leia o QR que aparece no terminal.

Para o scanner de documento do sistema (botão **Scanner** na câmera), é preciso um
development build: `npx expo run:android` (com o Android Studio) ou o EAS Build.

## Testar no navegador (desenvolvimento)

`npx expo start --web` abre o app no navegador, em http://localhost:8081. Libere essa
origem na API com `CORS_ORIGENS=http://localhost:8081` no `.env` da raiz. Sem câmera no
navegador, use o botão **Galeria**.

## Verificações

```bash
npx tsc --noEmit
npx expo lint
```

## Abrir uma prova pelo QR code (teste)

A página da prova no web mostra um QR code com `http://<servidor>/abrir.php?prova=<id>`.
Essa página abre `leituraoptica://prova/<id>`, que cai direto na tela da prova. Só funciona
com o app instalado (development build); o Expo Go não abre o endereço `leituraoptica://`.
