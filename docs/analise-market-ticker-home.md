# Análise de Viabilidade — Market Ticker na Home (BSI Capital)

**Data:** 20/08/2026
**Escopo:** diagnóstico + recomendação de arquitetura. Nenhum código implementado nesta etapa.
**Status do acesso ao código:** o workspace `NimbusPlatform` está vazio e não existe repositório `NimbusPlatform` no GitHub de `AndersonCav`. A análise abaixo assume a stack declarada no perfil (**Laravel + Azure**). Os itens marcados com ⚠️ dependem de verificação no código-fonte real.

---

## 1. Complexidade técnica

**Classificação geral: BAIXA a MÉDIA.**

| Parte | Complexidade | Comentário |
|---|---|---|
| Faixa visual (marquee/ticker) no frontend | Baixa | Animação CSS pura, sem biblioteca. Desktop + mobile. |
| SELIC / CDI / IPCA via API do Banco Central | Baixa | API pública, sem autenticação, JSON. |
| Cache + scheduler + persistência local | Baixa | Laravel já tem Scheduler, Cache e banco. Não exige infraestrutura nova. |
| Ibovespa | Média | Não existe API oficial gratuita da B3 para cotação. Exige serviço intermediário (brapi.dev ou similar), token, e gestão de delay/licença. |
| Emissões em distribuição (dados internos) | ⚠️ Baixa a Média | Depende de como as emissões estão modeladas hoje no banco. Ver item 4. |
| Conformidade regulatória da comunicação | Média | Não é código; é revisão de compliance. Ver item 8. |

O que será mais simples: o pipeline de SELIC/CDI/IPCA (fonte única, gratuita, estável) e a renderização do componente.
O que exigirá mais cuidado: a decisão de fonte do Ibovespa (custo x delay x licença de uso em site institucional) e a camada de robustez (fallback, staleness, mercado fechado).

---

## 2. Fontes dos indicadores

### SELIC, CDI, IPCA — Banco Central do Brasil (SGS) ✅ recomendado

Fonte oficial, gratuita, sem autenticação, JSON/CSV, CORS habilitado.

- Endpoint: `https://api.bcb.gov.br/dados/serie/bcdata.sgs.{código}/dados/ultimos/{N}?formato=json`
- Séries relevantes: **SELIC meta = 432** · SELIC efetiva diária = 11 · **CDI diário = 12** · CDI anualizado = 4389 · **IPCA mensal = 433**
- Exemplo: `curl "https://api.bcb.gov.br/dados/serie/bcdata.sgs.11/dados/ultimos/1?formato=json"`

Peculiaridades conhecidas (justificam a camada de cache/fallback, não impedem o uso):

- Datas em `dd/MM/yyyy` e valores com vírgula decimal — exige parse customizado.
- Limite informal de ~10 anos de intervalo por requisição (desde mar/2025, formato JSON/CSV); para o ticker, usar `/dados/ultimos/{N}` contorna isso.
- Janelas de manutenção noturna e feriados bancários podem responder 503/HTML estático — cron à 0h falha silenciosamente; agendar em horário comercial.
- Rate limit não documentado oficialmente; recomenda-se no máximo ~5 req/s.

[Fonte: portal de dados abertos do BCB / brazilvisible.org/docs/apis/banco-central/sgs-indices, consultado em 20/08/2026]

### Ibovespa — sem API oficial gratuita ⚠️

A B3 não disponibiliza API pública gratuita de cotações. Opções reais:

| Opção | Custo | Delay | Observação |
|---|---|---|---|
| **brapi.dev** (`^BVSP`) | Gratuito até 15.000 req/mês (1 ticker/chamada, token) | até ~15 min no plano gratuito | Opção mais pragmática. Planos pagos: Startup R$ 99,99/mês (150 mil req/mês, atualização 15 min), Pro com 5 min. |
| AwesomeAPI | Gratuito | — | Foco em câmbio/cripto; cobertura de índices limitada — validar antes de assumir. |
| Vendedores profissionais (ex.: bolsai, Pro ~R$ 29/mês) | Baixo | Melhor SLA | Útil se o ticker virar peça crítica. |
| Scraper do site da B3 | Grátis | — | **Não recomendado**: frágil, contra ToS, risco institucional. |

[Fonte: brapi.dev/faq e brapi.dev, consultado em 20/08/2026]

Decisão recomendada: começar com brapi.dev gratuito (15 mil req/mês sobram para 1 req a cada 15 min no pregão: ~1.400 req/mês). Se a experiência do delay incomodar, upgrade para Startup.

**Importante:** exibir SELIC/CDI/IPCA e IBOV **não exige B3 nem serviço pago** para 3 dos 4 indicadores. Só o IBOV tem dependência externa não-oficial.

---

## 3. Estratégia de atualização

Princípio: **a Home nunca chama API externa.** Um worker/scheduler atualiza uma tabela local; a renderização lê só dado local (banco ou cache).

Frequência recomendada por indicador:

| Indicador | Cadência real do dado | Agendamento sugerido |
|---|---|---|
| SELIC (meta) | Muda a cada reunião do COPOM (~45 dias) | 1×/dia, 08h (horário comercial, fora da janela de manutenção do SGS) |
| CDI | Diário (dias úteis) | 1×/dia, 08h |
| IPCA | Mensal (IBGE) | 1×/dia, 08h — o valor só muda ~1×/mês, mas a checagem diária é barata |
| Ibovespa | Contínuo no pregão | A cada 15 min, apenas seg–sex, 10h05–18h (horário de Brasília). Fora do pregão: nenhuma chamada |
| Emissões em distribuição | Dado interno | Reativo: leitura direta do banco na renderização (com cache de alguns minutos) |

Mecânica:

- **Scheduler**: Laravel Scheduler (`->dailyAt('08:00')`, `->everyFifteenMinutes()->weekdays()->between('10:05','18:00')`). No Azure, um cron no App Service/Container Job chamando `php artisan schedule:run`.
- **Persistência**: tabela local com último valor válido + `reference_date` + `fetched_at` + `source`.
- **Fallback**: se a fonte falhar, mantém o último valor válido. Se o dado estiver velho demais (ex.: IBOV > 24h, IPCA > 45 dias), o ticker exibe sem destaque ou omite o item — nunca exibe dado errado como se fosse fresco.
- **Cache de renderização**: o endpoint/componente da Home lê o agregado do banco com cache de 1–5 min.

---

## 4. Emissões em distribuição ⚠️

**Este é o único item que não dá para fechar sem ver o código/banco.** Perguntas a responder no diagnóstico:

1. Existe tabela/modelo de emissões (CRIs/CRAs) com nome, série, indexador, spread/taxa?
2. Existe um campo de **situação** (ex.: "em distribuição", "encerrada", "liquidada") ou datas de início/fim da oferta?
3. Existe URL da página da oferta para linkar?

Cenários:

- **Melhor caso** (provável, se o site já lista ofertas): query simples `where status = 'em_distribuicao'`, zero duplicação de dado.
- **Caso intermediário**: falta só o campo de situação → adicionar 1 coluna/enum, alimentada pelo admin existente.
- **Pior caso**: emissões não estão estruturadas → criar cadastro mínimo (nome, série, indexador, remuneração, situação, link). Mesmo assim, ~1 dia de trabalho.

**Reaproveitamento é a regra**: o ticker consome a mesma fonte que o site já usa para as páginas de oferta. Alternativa externa (dados abertos da CVM sobre ofertas de CRI por CNPJ da securitizadora) existe como complemento de auditoria, mas **não deve ser a fonte primária** — o dado interno é o oficial e mais atualizado.

---

## 5. UX/UI

Referência tratada como conceitual (faixa de terminal financeiro), adaptada à identidade institucional da BSI:

- **Forma**: faixa horizontal discreta (altura ~32–40px), no topo ou logo abaixo do hero, fundo escuro/sólido da paleta institucional, tipografia monoespaçada ou tabular para os números.
- **Movimento**: marquee suave via animação CSS (`translateX` em loop contínuo), **pausa no hover** (`animation-play-state: paused`) e no focus.
- **Hierarquia**: índices macro (SELIC, CDI, IPCA, IBOV) primeiro, separados das emissões por um divisor mais forte (ex.: `◆` ou barra dupla). Emissões com tratamento visual distinto (ex.: ícone + nome em caixa alta).
- **Variação**: setas/cores sutis para IBOV e IPCA (verde/vermelho discretos, nunca chamativos); SELIC/CDI são taxas estáveis — sem seta.
- **Separadores**: pipe fino ou ponto vertical, espaçamento generoso.
- **Desktop**: marquee contínuo. **Mobile**: mesmo marquee (funciona bem), com velocidade ajustada; alternativa aceitável: faixa estática com scroll horizontal manual.
- **Acessibilidade**: `prefers-reduced-motion` desliga a animação (vira faixa estática); `aria-live="off"` e conteúdo duplicável por leitores de tela; contraste AA; possibilidade de pausar.
- **Tom**: evitar qualquer elemento de "portal de trading" — sem flashes, sem atualização por segundo, sem gritaria de cores. A sensação desejada é *terminal institucional*, não *Bloomberg*.

---

## 6. Performance

Impacto projetado: **desprezível**, desde que a arquitetura correta seja seguida:

- **Zero chamadas externas no request** — o dado vem do banco/cache local. APIs externas nunca são dependência de renderização (eliminando risco de timeout/impairment de TTFB).
- Animação CSS com `transform` (GPU-composited) — sem layout thrash, sem JS pesado.
- Payload do ticker: < 2 KB de HTML/JSON.
- Sem impacto esperado em LCP/CLS: faixa com altura fixa reservada no layout (sem CLS), conteúdo server-side rendered (sem espera de fetch no cliente).

---

## 7. Confiabilidade dos dados

- **Timestamp visível**: exibir discretamente `Atualizado em dd/mm HH:mm` (hover/tooltip ou texto à direita da faixa). Para IBOV isso é obrigatório (delay de ~15 min); para taxas diárias, a data de referência basta.
- **Fonte**: rodapé da faixa ou tooltip com `Fonte: Banco Central / B3 via brapi`.
- **Mercado fechado / fds / feriado**: IBOV exibe último fechamento com rótulo "Fechamento dd/mm" — comportamento honesto e esperado em tickers institucionais.
- **Dado ainda não divulgado** (ex.: IPCA do mês corrente): exibe o último disponível com a data de referência explícita (`IPCA jun/26: 0,24%`).
- **Staleness**: regra de expiração por indicador; dado vencido some ou perde destaque — nunca parece fresco.

---

## 8. Aspectos institucionais/regulatórios

A BSI é securitizadora e as emissões (CRIs) em distribuição são ofertas reguladas pela CVM (ofertas públicas de CRI seguem regime próprio — ex.: Resolução CVM 60/2021). Cuidados:

- **Revisão de compliance/jurídico antes do go-live** — este é o gate real do projeto, não a tecnologia.
- O ticker deve ser **informativo, não promocional**: nome, série e condição de remuneração são fatos da oferta já públicos; evitar gatilhos comerciais ("última chance", "alta rentabilidade", comparações com poupança etc.).
- **Disclaimer padrão**: "Informações meramente institucionais. Não constituem recomendação de investimento. Consulte os documentos da oferta." — uma linha no rodapé da faixa ou na página.
- Remuneração deve espelhar **exatamente** o documento da oferta (ex.: `CDI + 3,00% a.a.`) — fonte única de verdade no banco, sem re-digitação.
- Link para a oferta: adequado se apontar para a página institucional da emissão (com os documentos), não para uma landing de captação.
- Nota de contexto: várias securitizadoras e distribuidoras brasileiras já exibem ofertas em distribuição no site com esse formato sóbrio — a prática é consolidada, desde que validada pelo compliance da casa.

---

## 9. Arquitetura recomendada (assumindo Laravel + Azure ⚠️)

```
┌────────────────────────────────────────────────────────┐
│ Azure (App Service / Container)                        │
│                                                        │
│  cron → artisan schedule:run                           │
│    ├─ 08h diário:  UpdateEconomicIndicators            │
│    │     └─ Http → api.bcb.gov.br (SGS 432/12/433)     │
│    └─ 15min pregão: UpdateIbovespaQuote                │
│          └─ Http → brapi.dev/api/quote/^BVSP           │
│                                                        │
│  Ambos gravam em: market_snapshots                     │
│  (indicator, value, variation, reference_date,         │
│   fetched_at, source, status)                          │
└────────────────────────────────────────────────────────┘
                     │
                     ▼
┌────────────────────────────────────────────────────────┐
│ HomeController / View Composer                         │
│   TickerDataService::current()                         │
│     ├─ lê market_snapshots (cache 1–5 min)             │
│     └─ lê emissions.where(status,'em_distribuicao')    │
│                                                        │
│ <x-market-ticker :items="..." />  (Blade component)    │
│   HTML SSR + CSS marquee + JS mínimo (pausa/a11y)      │
└────────────────────────────────────────────────────────┘
```

Componentes:

| Peça | Tipo | Esforço |
|---|---|---|
| `market_snapshots` (migration + model) | Tabela nova | ½ dia |
| `BcbSgsClient` + `BrapiClient` | Services (Laravel HTTP client, retry, timeout) | 1 dia |
| `UpdateEconomicIndicators`, `UpdateIbovespaQuote` | Artisan commands agendados | ½–1 dia |
| `TickerDataService` (agrega indicadores + emissões + staleness) | Service | ½ dia |
| `<x-market-ticker>` Blade component + CSS marquee | Frontend | 1–2 dias (incl. responsivo/a11y) |
| Configuração: `.env` (token brapi, flags de feature) | Config | trivial |
| Fallback/staleness + testes | Cross-cutting | 1 dia |

Sem infraestrutura nova: Scheduler + cache + banco já existem no stack. Redis ajuda, mas cache em arquivo/database resolve.

---

## 10. Estimativa de esforço por etapas

| Etapa | Escopo | Esforço estimado |
|---|---|---|
| 1. Ticker só com emissões internas | Tabela/query + componente visual base | 2–4 dias (depende do item 4 ⚠️) |
| 2. Indicadores econômicos (SELIC/CDI/IPCA) | Client BCB + command diário + exibição | 1–2 dias |
| 3. Ibovespa | brapi.dev + command de pregão + rótulo de fechamento | 1–1,5 dia (+ custo R$ 0–100/mês) |
| 4. Cache, scheduler, staleness, fallback | Robustez transversal + testes | 1–2 dias |
| 5. Refino visual, responsivo, acessibilidade, compliance copy | Polimento + revisão jurídica | 2–3 dias (+ agenda do compliance) |

**Total: ~7–12 dias de trabalho** (1,5 a 2,5 semanas), com etapas 1+2 entregando um MVP visível em menos de 1 semana.

---

## Veredito

**Vale a pena.** ✅

- Complexidade técnica baixa, custo marginal ~zero (só o IBOV pode gerar custo de até R$ 100/mês), risco de performance nulo com a arquitetura proposta.
- O ganho de percepção institucional é real e barato: a faixa comunica exatamente "a BSI está conectada ao mercado e há operações acontecendo agora" — e faz isso com dados que a casa já tem (emissões) + dados oficiais gratuitos (BCB).
- Os dois riscos reais são **gerenciáveis e não técnicos**: (1) aprovação de compliance para exibir remuneração de emissões na Home — mitigar com formato sóbrio + disclaimer + validação prévia; (2) confiabilidade do IBOV via terceiros — mitigar com delay declarado e fallback de fechamento.
- Recomendação de caminho: **começar pela Etapa 1 + 2** (emissões + BCB), que é 100% grátis, de baixo risco e já entrega a sensação desejada; IBOV entra depois como incremento.

**Próximo passo necessário para o diagnóstico completo:** disponibilizar o código do projeto (clonar o repositório no workspace) para validar os itens ⚠️ — principalmente a modelagem das emissões (item 4) e os pontos de extensão da Home (item 9).
