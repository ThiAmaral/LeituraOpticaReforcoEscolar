import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';

import { api, ErroApi } from './api';
import { aoMudarSessao, configuracaoFaltando, guardarSessao, lerSessao, type SessaoGuardada } from './credenciais';
import type { Config } from './tipos';

type Sessao = {
  sessao: SessaoGuardada | null;
  /** Ainda lendo a sessão guardada no aparelho. */
  carregando: boolean;
  /** Edições, anos, disciplinas, dificuldades e limites da escola (GET /api/config). */
  config: Config | null;
  erroConfig: string | null;
  recarregarConfig: () => void;
  entrar: (email: string, senha: string) => Promise<string | null>;
  sair: () => Promise<void>;
};

const Contexto = createContext<Sessao | null>(null);

export function ProvedorDeSessao({ children }: { children: ReactNode }) {
  // A sessão guardada é lida na hora (o armazenamento do aparelho é síncrono).
  const [sessao, setSessao] = useState<SessaoGuardada | null>(lerSessao);
  const carregando = false;
  const [carregada, setCarregada] = useState<{ token: string; config: Config } | null>(null);
  const [erroConfig, setErroConfig] = useState<string | null>(null);
  const [tentativa, setTentativa] = useState(0);

  useEffect(() => aoMudarSessao(setSessao), []);

  const token = sessao?.token ?? null;
  const config = token && carregada?.token === token ? carregada.config : null;

  useEffect(() => {
    if (!token) return;
    let valida = true;
    api
      .config()
      .then((c) => valida && (setErroConfig(null), setCarregada({ token, config: c })))
      .catch((e) => valida && setErroConfig(e instanceof ErroApi ? e.message : String(e)));
    return () => {
      valida = false;
    };
  }, [token, tentativa]);

  const recarregarConfig = useCallback(() => {
    setErroConfig(null);
    setTentativa((t) => t + 1);
  }, []);

  /** Devolve a mensagem de erro para a tela, ou null se entrou. */
  const entrar = useCallback(async (email: string, senha: string) => {
    if (configuracaoFaltando.length) {
      // Sem isso o login tenta um endereço inexistente e só diria "sem conexão".
      return `O app não está configurado: preencha ${configuracaoFaltando.join(', ')} no mobile/.env e reinicie com "npx expo start --clear".`;
    }
    try {
      guardarSessao(await api.entrar(email, senha));
      return null;
    } catch (e) {
      return e instanceof ErroApi ? e.message : `Não foi possível entrar: ${String(e)}`;
    }
  }, []);

  const sair = useCallback(async () => {
    setErroConfig(null);
    try {
      await api.sair(); // o token deixa de valer no servidor
    } catch {
      // sem conexão: a sessão local é encerrada mesmo assim
    }
    guardarSessao(null);
  }, []);

  return (
    <Contexto.Provider value={{ sessao, carregando, config, erroConfig, recarregarConfig, entrar, sair }}>
      {children}
    </Contexto.Provider>
  );
}

export function useSessao(): Sessao {
  const valor = useContext(Contexto);
  if (!valor) throw new Error('useSessao fora do ProvedorDeSessao');
  return valor;
}
