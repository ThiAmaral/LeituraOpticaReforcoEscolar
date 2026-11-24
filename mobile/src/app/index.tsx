import { router, Stack, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Platform, Pressable, RefreshControl, Text, View } from 'react-native';

import { Aviso, Carregando, Chip, cor, estilos, FalhaAoCarregar } from '@/componentes/ui';
import { api, mensagemDeErro } from '@/lib/api';
import { useSessao } from '@/lib/sessao';
import type { ProvaResumo } from '@/lib/tipos';

const POR_PAGINA = 20;

/**
 * Lista de provas: as mesmas do painel web, na mesma ordem (a mais recente primeiro) e com
 * os mesmos filtros (ano letivo e edição). As páginas seguintes carregam ao rolar.
 */
export default function Provas() {
  const { config, erroConfig, recarregarConfig, sair, sessao } = useSessao();
  const [ano, setAno] = useState<number | undefined>(undefined);
  const [edicao, setEdicao] = useState<number | undefined>(undefined);
  const [provas, setProvas] = useState<ProvaResumo[] | null>(null);
  const [total, setTotal] = useState(0);
  const [erro, setErro] = useState<string | null>(null);
  const [atualizando, setAtualizando] = useState(false);
  const [carregandoMais, setCarregandoMais] = useState(false);
  const pagina = useRef({ atual: 0, ultima: 1 });

  const carregar = useCallback(async () => {
    setErro(null);
    try {
      const r = await api.listarProvas({ ano, edicao, pagina: 1, por_pagina: POR_PAGINA });
      pagina.current = { atual: r.pagina, ultima: r.paginas };
      setProvas(r.provas);
      setTotal(r.total);
    } catch (e) {
      setErro(mensagemDeErro(e));
    }
  }, [ano, edicao]);

  // Recarrega ao voltar para a tela (depois de escanear) e ao trocar os filtros.
  useFocusEffect(
    useCallback(() => {
      carregar();
    }, [carregar]),
  );

  async function atualizar() {
    setAtualizando(true);
    await carregar();
    setAtualizando(false);
  }

  async function carregarMais() {
    const { atual, ultima } = pagina.current;
    if (carregandoMais || provas === null || atual >= ultima) return;
    setCarregandoMais(true);
    try {
      const r = await api.listarProvas({ ano, edicao, pagina: atual + 1, por_pagina: POR_PAGINA });
      pagina.current = { atual: r.pagina, ultima: r.paginas };
      setProvas((antes) => [...(antes ?? []), ...r.provas.filter((p) => !antes?.some((a) => a.id === p.id))]);
      setTotal(r.total);
    } catch (e) {
      setErro(mensagemDeErro(e));
    } finally {
      setCarregandoMais(false);
    }
  }

  if (erroConfig) return <FalhaAoCarregar mensagem={erroConfig} tentarDeNovo={recarregarConfig} />;
  if (!config) return <Carregando texto="Conectando ao servidor..." />;

  return (
    <View style={estilos.tela}>
      <Stack.Screen
        options={{
          headerRight: () => (
            <Pressable onPress={sair} hitSlop={10} accessibilityRole="button">
              <Text style={{ color: cor.primaria, fontSize: 16, fontWeight: '600' }}>Sair</Text>
            </Pressable>
          ),
        }}
      />
      <FlatList
        data={provas ?? []}
        keyExtractor={(p) => String(p.id)}
        contentContainerStyle={[estilos.conteudo, { paddingBottom: 40 }]}
        refreshControl={<RefreshControl refreshing={atualizando} onRefresh={atualizar} />}
        onEndReached={carregarMais}
        onEndReachedThreshold={0.4}
        ListHeaderComponent={
          <View style={{ gap: 10 }}>
            <Text style={estilos.dica}>{sessao?.professor.email}</Text>
            {config.anos.length > 0 && (
              <View style={estilos.linha}>
                <Chip rotulo="Todos os anos" marcado={ano === undefined} onPress={() => setAno(undefined)} />
                {config.anos.map((a) => (
                  <Chip key={a} rotulo={String(a)} marcado={ano === a} onPress={() => setAno(a)} />
                ))}
              </View>
            )}
            {config.edicoes.length > 0 && (
              <View style={estilos.linha}>
                <Chip rotulo="Todas as edições" marcado={edicao === undefined} onPress={() => setEdicao(undefined)} />
                {config.edicoes.map((e) => (
                  <Chip key={e.numero} rotulo={e.nome} marcado={edicao === e.numero} onPress={() => setEdicao(e.numero)} />
                ))}
              </View>
            )}
            {provas !== null && total > 0 && (
              <Text style={estilos.dica}>
                {total} {total === 1 ? 'prova' : 'provas'}
              </Text>
            )}
            {erro && <Aviso texto={erro} />}
            {provas === null && !erro && <Carregando />}
          </View>
        }
        ListEmptyComponent={
          provas !== null ? (
            <Text style={[estilos.dica, { textAlign: 'center', marginTop: 24 }]}>
              Nenhuma prova neste filtro. As provas são cadastradas no painel web.
            </Text>
          ) : undefined
        }
        ListFooterComponent={carregandoMais ? <ActivityIndicator color={cor.primaria} style={{ marginTop: 12 }} /> : undefined}
        renderItem={({ item }) => <CartaoProva prova={item} />}
        ItemSeparatorComponent={() => <View style={{ height: 10 }} />}
      />
    </View>
  );
}

function avisarRascunho() {
  const texto = 'Termine o gabarito no web para liberar esta prova.';
  if (Platform.OS === 'web') {
    window.alert(texto);
  } else {
    Alert.alert('Gabarito pendente', texto);
  }
}

function CartaoProva({ prova }: { prova: ProvaResumo }) {
  const rascunho = prova.status === 'rascunho';
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={rascunho ? 'O gabarito ainda não foi liberado no painel web' : 'Abre a prova para escanear as turmas'}
      onPress={() => (rascunho ? avisarRascunho() : router.push(`/prova/${prova.id}`))}
      style={({ pressed }) => [estilos.cartao, rascunho && { opacity: 0.75 }, pressed && { opacity: 0.6 }]}
    >
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
        <Text style={[estilos.rotulo, { flex: 1, fontSize: 16 }]}>{prova.nome}</Text>
        {rascunho && (
          <Text
            style={{
              fontSize: 13,
              fontWeight: '700',
              color: cor.alerta,
              backgroundColor: cor.alertaClaro,
              paddingHorizontal: 8,
              paddingVertical: 2,
              borderRadius: 8,
              alignSelf: 'flex-start',
            }}
          >
            Gabarito pendente
          </Text>
        )}
      </View>
      <Text style={estilos.dica}>
        {prova.ano} · {prova.edicao_nome} · caderno {prova.caderno}
      </Text>
      {!rascunho && (
        <Text style={estilos.texto}>
          {prova.turmas} {prova.turmas === 1 ? 'turma' : 'turmas'} · {prova.folhas}{' '}
          {prova.folhas === 1 ? 'folha corrigida' : 'folhas corrigidas'}
        </Text>
      )}
    </Pressable>
  );
}
