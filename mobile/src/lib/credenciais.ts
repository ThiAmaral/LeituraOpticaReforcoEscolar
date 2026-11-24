import { armazenamento } from './armazenamento';

/*
 * Endereço da API e sessão do professor guardada no aparelho (token devolvido por
 * POST /api/entrar). Só o token fica guardado, nunca a senha.
 */

/** Endereço do servidor PHP, sem /api no fim (ex.: http://192.168.0.10/leitura). */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? '').replace(/\/+$/, '');

/** Vazio ou ainda com o texto de exemplo do .env.exemplo ("<...>"). */
export const configuracaoFaltando = !API_URL || API_URL.includes('<') ? ['EXPO_PUBLIC_API_URL'] : [];

export type Professor = { id: number; email: string; nome: string | null };
export type SessaoGuardada = { token: string; professor: Professor };

const CHAVE = 'sessao';
const ouvintes = new Set<(s: SessaoGuardada | null) => void>();

export function lerSessao(): SessaoGuardada | null {
  try {
    const texto = armazenamento?.getItem(CHAVE);
    return texto ? (JSON.parse(texto) as SessaoGuardada) : null;
  } catch {
    return null;
  }
}

export function guardarSessao(sessao: SessaoGuardada | null) {
  try {
    if (sessao) armazenamento?.setItem(CHAVE, JSON.stringify(sessao));
    else armazenamento?.removeItem(CHAVE);
  } catch {
    // sem armazenamento (ex.: navegador em modo privado): a sessão vale só enquanto o app está aberto
  }
  ouvintes.forEach((f) => f(sessao));
}

/** Avisa quando a sessão muda (login, logout ou token recusado pelo servidor). */
export function aoMudarSessao(f: (s: SessaoGuardada | null) => void) {
  ouvintes.add(f);
  return () => {
    ouvintes.delete(f);
  };
}
