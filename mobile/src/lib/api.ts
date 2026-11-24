import { File } from 'expo-file-system';
import { Platform } from 'react-native';

import { API_URL, guardarSessao, lerSessao, type SessaoGuardada } from './credenciais';
import { ErroRecorte, type Recorte } from './recorte';
import type { Config, Folha, FolhaDetalhe, ListaDeProvas, Prova, Relatorio, ResultadoEnvio } from './tipos';

/*
 * Cliente da API PHP (web/src/api.php). O login (POST entrar) devolve um token, que vai
 * em "Authorization: Bearer" em toda chamada. Os erros da API chegam como {"erro": "..."}
 * e viram ErroApi, com a mensagem já escrita para o professor.
 */

export class ErroApi extends Error {
  constructor(
    mensagem: string,
    /** Código HTTP; 0 quando o servidor não respondeu. */
    public readonly status: number,
    public readonly dados: Record<string, unknown> = {},
  ) {
    super(mensagem);
  }

  get codigo(): string | null {
    return typeof this.dados.codigo === 'string' ? this.dados.codigo : null;
  }
}

/** Mensagem de qualquer erro para mostrar na tela. */
export function mensagemDeErro(e: unknown): string {
  return e instanceof ErroApi || e instanceof ErroRecorte ? e.message : String(e);
}

/** Foto local pronta para envio. */
export type Foto = { uri: string; width: number; height: number };

type Opcoes = {
  json?: unknown;
  form?: FormData;
  query?: Record<string, string | number | undefined>;
  /** Rota pública (o login): não manda token. */
  semLogin?: boolean;
};

async function chamar<T>(metodo: string, rota: string, opcoes: Opcoes = {}): Promise<T> {
  const cabecalhos: Record<string, string> = { Accept: 'application/json' };
  if (!opcoes.semLogin) {
    const token = lerSessao()?.token;
    if (!token) {
      throw new ErroApi('Entre de novo para continuar.', 401);
    }
    cabecalhos.Authorization = `Bearer ${token}`;
  }
  const query = Object.entries(opcoes.query ?? {})
    .filter(([, v]) => v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  let corpo: BodyInit | undefined;
  if (opcoes.form) {
    corpo = opcoes.form; // o fetch põe o Content-Type multipart com o boundary
  } else if (opcoes.json !== undefined) {
    cabecalhos['Content-Type'] = 'application/json';
    corpo = JSON.stringify(opcoes.json);
  }

  let resposta: Response;
  try {
    resposta = await fetch(`${API_URL}/api/${rota}${query ? `?${query}` : ''}`, {
      method: metodo,
      headers: cabecalhos,
      body: corpo,
    });
  } catch (e) {
    console.warn(`Falha no envio para ${rota}:`, e); // aparece no terminal do Expo
    throw new ErroApi('Sem conexão com o servidor. Confira a internet e o endereço do servidor.', 0);
  }

  if (resposta.status === 204) {
    return undefined as T;
  }
  let dados: Record<string, unknown> = {};
  try {
    dados = await resposta.json();
  } catch {
    // corpo vazio ou que não é JSON: trata abaixo pelo código HTTP
  }
  if (!resposta.ok) {
    if (resposta.status === 401 && !opcoes.semLogin) {
      guardarSessao(null); // token vencido ou professor desativado: volta para o login
    }
    const mensagem = typeof dados.erro === 'string' ? dados.erro : `Erro ${resposta.status} no servidor.`;
    throw new ErroApi(mensagem, resposta.status, dados);
  }
  return dados as T;
}

/**
 * Acrescenta a foto ao formulário. No celular, o fetch global do Expo (expo/fetch) só aceita
 * arquivos como File do expo-file-system. No navegador, o arquivo vira Blob.
 */
async function anexarFoto(form: FormData, campo: string, foto: Foto, nome: string) {
  if (Platform.OS === 'web') {
    const blob = await (await fetch(foto.uri)).blob();
    form.append(campo, blob, nome);
  } else {
    form.append(campo, new File(foto.uri), nome);
  }
}

/** Área de respostas, faixa do QR (se achada) e o caderno lido no celular. */
async function anexarRecorte(form: FormData, r: Recorte, nome: string) {
  await anexarFoto(form, 'folha', r.area, `${nome}_area.jpg`);
  if (r.qr) await anexarFoto(form, 'qr', r.qr, `${nome}_qr.jpg`);
  if (r.caderno) form.append('caderno', r.caderno);
}

/* ------------------------------------------------------------- Rotas */

export const api = {
  entrar: (email: string, senha: string) =>
    chamar<SessaoGuardada>('POST', 'entrar', { json: { email: email.trim(), senha }, semLogin: true }),

  sair: () => chamar<void>('POST', 'sair'),

  config: () => chamar<Config>('GET', 'config'),

  /** A mesma lista do painel web: mais recente primeiro, com filtros e páginas. */
  listarProvas: (filtros: { ano?: number; edicao?: number; pagina?: number; por_pagina?: number }) =>
    chamar<ListaDeProvas>('GET', 'provas', { query: filtros }),

  verProva: (id: number) => chamar<{ prova: Prova }>('GET', `provas/${id}`).then((r) => r.prova),

  listarFolhas: (id: number, turma?: string) =>
    chamar<{ folhas: Folha[] }>('GET', `provas/${id}/folhas`, { query: { turma } }),

  /** Envia o recorte de uma folha (nunca a foto inteira); o nome é genérico. */
  async enviarFolha(id: number, turma: string, recorte: Recorte, ordem: number) {
    const form = new FormData();
    form.append('turma', turma);
    await anexarRecorte(form, recorte, `${turma}_folha${String(ordem).padStart(2, '0')}`);
    return chamar<{ turma: string; folhas_da_turma: number; folhas: ResultadoEnvio[] }>(
      'POST',
      `provas/${id}/folhas`,
      { form },
    );
  },

  relatorio: (id: number, turma?: string) =>
    chamar<Relatorio>('GET', `provas/${id}/relatorio`, { query: { turma } }),

  verFolha: (id: number) => chamar<{ folha: FolhaDetalhe }>('GET', `folhas/${id}`).then((r) => r.folha),

  /** Só as respostas que mudaram ({"12": "B", "13": null}) ou a lista completa. */
  revisarFolha: (id: number, respostas: Record<number, string | null> | (string | null)[]) =>
    chamar<{ folha: Folha }>('PUT', `folhas/${id}`, { json: { respostas } }).then((r) => r.folha),
};
