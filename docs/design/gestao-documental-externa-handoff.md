# Gestão Documental Externa — Análise e Direção de Redesign

**Cliente:** BSI Capital Securitizadora
**Módulo:** Gestão Documental Externa
**Objetivo:** Elevar a percepção visual e operacional do módulo a um padrão bancário/institucional, com sensação de seriedade, rastreabilidade, conformidade e governança documental.
**Stack:** Laravel 12 · Livewire 4 · Flux UI Free · Tailwind v4
**Data:** 05/05/2026

---

## 1. Diagnóstico da interface atual

A tela atual cumpre a função, mas comunica visualmente um portal genérico de envio de arquivos — não um ambiente de **controle documental institucional**. Os principais sinais que enfraquecem a percepção corporativa:

**Linguagem visual com tom de portal de cliente, não de back-office financeiro.**
- Banner de boas-vindas com emoji "👋", cumprimento informal ("Olá, Anderson!") e gradiente azul/dourado consumindo a primeira dobra. Em sistemas bancários, esse espaço é reservado a contexto operacional (filtros, KPIs, ações).
- Botão "+ Nova Solicitação" em destaque dourado dentro do banner, duplicando o CTA "+ Novo Envio" do header. Redundância e ruído.

**KPIs com aparência de dashboard de SaaS, não de painel de controle documental.**
- Apenas 3 cards genéricos ("Envios Realizados", "Aguardando Análise", "Solicitações Aprovadas") com ícones decorativos coloridos em fundo pastel. Faltam indicadores que transmitam **risco operacional**: pendências, vencimentos, rejeições, SLA.
- A nomenclatura mistura "Envio", "Solicitação" e "Análise" sem clareza taxonômica.

**Tabela rasa e pouco informativa.**
- Apenas 3 colunas (Referência, Data de Envio, Situação). Não há tipo de documento, operação/emissão associada, responsável, prazo, origem ou versão.
- Identificador "#2" é casual demais — ambientes financeiros usam protocolos formatados (`DOC-2026-000002`).
- Status "PENDENTE" em badge dourado com ícone de relógio; em ambiente bancário, dourado é cor de marca (premium), não de estado operacional.

**Ausência de filtros, busca e atalhos.**
- A tabela "Suas Solicitações Recentes" expõe um link "Ver Todas" no canto, mas não há filtro algum visível na primeira dobra. Em fluxo real de trabalho (operação diária), o usuário precisa de filtros e tabs persistentes.

**Tom de texto informal e impreciso.**
- "Olá, Anderson!", "Bem-vindo ao seu portal exclusivo de solicitações e documentos", "Suas Solicitações Recentes". Linguagem de portal de relacionamento, não de sistema de gestão documental regulado.

**Ausência completa de sinais de governança.**
- Nenhum elemento sugere trilha de auditoria, versionamento, autoria de alterações ou responsável pela análise. Em uma securitizadora, isso é insuficiente para passar a sensação de conformidade.

**Detalhes de UI que reduzem peso institucional.**
- Cards excessivamente arredondados (border-radius alto), espaçamento interno generoso e ícones grandes em círculos coloridos. Tudo isso comunica "consumer app", não "enterprise financial system".

---

## 2. Direção visual recomendada

A referência mental deve ser **Bloomberg Terminal × Murex × Calypso × portais corporativos do BNDES/Itaú Empresas**, e não Notion, Linear ou qualquer SaaS B2B contemporâneo.

**Sensação a transmitir:** sólido, denso, auditável, executivo, neutro, regulado. O usuário deve sentir que está operando em um sistema **regulamentado**, não em um app.

**Princípios visuais:**

1. **Densidade controlada.** Mais informação por dobra, sem sufocar. Tabelas com linhas mais baixas (40–44px), tipografia menor (13–14px) e padding interno reduzido.
2. **Paleta sóbria com dourado contido.** Marinho profundo (`#0B1E3A`) e cinza-azulado (`#1F2A44`) como base; dourado (`#B8860B` / `#A88532`) **apenas** em logo, marcas de marca e CTAs primários — nunca em badges de status.
3. **Status com cores neutras-funcionais, não vibrantes.** Tons dessaturados: âmbar antigo, verde-floresta, vinho institucional, azul-aço.
4. **Tipografia executiva.** Sans-serif geométrica neutra (Inter, IBM Plex Sans, ou similar) com pesos 500/600 para hierarquia, evitando weight 700+ que dá ar promocional.
5. **Bordas finas (1px), sombras quase invisíveis.** Em vez de elevação por sombra, use bordas (`#E5E7EB`) e fundo levemente alternado para separar áreas.
6. **Identificadores formais.** Códigos com prefixo + ano + sequência (`DOC-2026-000002`), datas no padrão ISO ou DD/MM/AAAA HH:mm, hash/versão visíveis.
7. **Zero emojis. Zero ilustrações. Zero gradientes em áreas operacionais.** Reserve gradiente apenas para a barra do header de marca, se for indispensável.

---

## 3. Melhorias práticas por área

### 3.1 Cabeçalho e estrutura geral

**O que existe hoje**
- Topbar dourada com logo + navegação + CTA + avatar.
- Banner de boas-vindas com emoji.

**O que precisa mudar**
- **Remover o banner de boas-vindas.** Ele consome a dobra mais valiosa com conteúdo decorativo.
- **Substituir por um cabeçalho de página formal**, com:
  - Breadcrumb: `Painel › Gestão Documental Externa`
  - Título: **"Gestão Documental Externa"**
  - Subtítulo operacional: *"Acompanhamento, validação e arquivamento de documentação recebida de contrapartes, cedentes e prestadores."*
  - Badge institucional no canto: `Ambiente Operacional · Conformidade LGPD`
  - Ações primárias alinhadas à direita: `[ Novo Documento ]` (primário, dourado) e `[ Exportar ]` `[ Imprimir Relatório ]` (secundários, ghost).
- **Padronizar a topbar.** Reduzir o dourado a um fio (1–2px) sob o logo; menus e avatar em tom neutro claro. CTA "Novo Documento" pode ser branco com borda dourada para sobriedade.

### 3.2 Indicadores (KPIs)

**Estrutura proposta — 6 indicadores em uma linha** (em telas <1440px, 3+3 em duas linhas):

| Indicador | Descrição operacional |
|---|---|
| **Recebidos no período** | Total de documentos recebidos no filtro vigente (ex.: mês atual) |
| **Em análise** | Sob revisão da equipe interna |
| **Com pendência** | Documentos devolvidos ao cedente/contraparte aguardando complemento |
| **Validados** | Aprovados e arquivados |
| **Rejeitados** | Recusados, com motivo registrado |
| **Vencendo em 30 dias** | Documentos com vigência expirando — alerta proativo |

**Estilo dos KPIs:**
- Card retangular, borda fina cinza, **sem ícones grandes coloridos**. Pequeno indicador de cor à esquerda (4px de barra vertical) na cor do status.
- Número grande (28–32px, weight 600, marinho), label menor abaixo (12px, uppercase, letter-spacing 0.04em, cinza médio).
- Variação vs. período anterior em cinza pequeno (`▲ 12 vs. abr/26`).
- Card clicável que aplica o filtro correspondente à tabela.

### 3.3 Tabela principal

**Colunas recomendadas (em ordem):**

| # | Coluna | Por que |
|---|---|---|
| 1 | **Protocolo** (`DOC-2026-000123`) | Identificador formal, ordenação cronológica |
| 2 | **Operação / Emissão** | Vincula o documento à operação do mercado (ex.: `CRA-018ª Série`) |
| 3 | **Tipo** | Tipologia (Estatuto, Certidão, Balanço, Termo, Contrato, etc.) |
| 4 | **Origem / Contraparte** | Quem enviou (cedente, custodiante, devedor, terceiro) |
| 5 | **Recebido em** | Data + hora |
| 6 | **Vigência / Validade** | Data de expiração documental |
| 7 | **Status** | Badge institucional |
| 8 | **Responsável (Analista)** | Avatar + nome — atribui rastreabilidade |
| 9 | **Ações** | Menu kebab: Visualizar, Validar, Solicitar correção, Histórico, Baixar |

**Tratamento visual:**
- Cabeçalho da tabela em cinza muito claro (`#F8FAFC`), texto cinza médio uppercase (11–12px, letter-spacing 0.05em, weight 500).
- Linhas com 44px de altura, separador 1px cinza-claro, hover sutil (`#F9FAFB`).
- Sem zebra striping (deixa cara de planilha legada). Use a borda fina como separador.
- Tipografia tabular (`font-variant-numeric: tabular-nums`) para alinhar datas e protocolos.
- Coluna "Status" sem ícones excessivos — apenas o badge.
- Ações via botão `⋯` discreto, alinhado à direita.

**Recursos esperados na tabela:**
- Ordenação por coluna.
- Seleção múltipla com ações em massa (validar, exportar, etiquetar).
- Densidade alternável (Compacta / Padrão / Confortável).
- Paginação inferior com total ("**1.248 documentos** · página 1 de 42 · 30 por página").
- Coluna fixa: "Protocolo" sticky à esquerda em scroll horizontal.

### 3.4 Status e badges

**Taxonomia institucional proposta** (substitui os atuais "Pendente" / "Aprovado"):

| Status | Quando | Cor (token) | Aparência |
|---|---|---|---|
| **Recebido** | Acabou de entrar, não triado | `slate-600` em fundo `slate-50` | Borda 1px slate-200 |
| **Em triagem** | Sendo classificado | `blue-700` em fundo `blue-50` | Borda 1px blue-200 |
| **Em análise** | Analista atribuído | `indigo-700` em fundo `indigo-50` | Borda 1px indigo-200 |
| **Com pendência** | Devolvido ao remetente | `amber-800` em fundo `amber-50` | Borda 1px amber-200 |
| **Validado** | Aprovado e arquivado | `emerald-800` em fundo `emerald-50` | Borda 1px emerald-200 |
| **Rejeitado** | Recusa formal | `rose-800` em fundo `rose-50` | Borda 1px rose-200 |
| **Expirado** | Vigência vencida | `zinc-700` em fundo `zinc-100` | Borda 1px zinc-300 |

**Regras visuais para badges:**
- **Pill** com border-radius pequeno (`rounded` = 4–6px), **não** totalmente arredondado.
- 11–12px, uppercase, letter-spacing 0.04em, weight 500.
- Borda 1px sempre presente (dá peso institucional e diferencia do fundo).
- **Sem ícones decorativos** dentro do badge — no máximo um pequeno ponto (●) à esquerda na mesma cor do texto.
- Cores **dessaturadas** — fuja de saturações altas (>70%). Olhe para a paleta de prospectos de CVM, não de SaaS.

### 3.5 Tela de detalhe do documento

**Estrutura em blocos verticais, separados por seção institucional:**

```
┌─ Cabeçalho ────────────────────────────────────────────────┐
│ Breadcrumb: Painel › Gestão Documental Externa › DOC-2026-000123
│ Protocolo: DOC-2026-000123      [Status: Em análise]
│ Tipo: Certidão Negativa de Débitos Federais
│ Ações: [ Validar ]  [ Devolver com pendência ]  [ Rejeitar ]  [ ⋯ ]
└────────────────────────────────────────────────────────────┘

┌─ 1. Identificação ────────────────────────────────┐
│ Protocolo · Tipo · Subtipo · Operação vinculada
│ Emissão associada · Vigência (início → fim)
│ Hash de integridade (SHA-256, primeiros 12 chars)
└────────────────────────────────────────────────────┘

┌─ 2. Controle e governança ───────────────────────┐
│ Status atual · Responsável (analista) · Próxima ação · SLA
│ Recebido em · Última atualização · Validado em
│ Versão atual (v.3) · Confidencialidade (Restrito/Interno/Público)
└────────────────────────────────────────────────────┘

┌─ 3. Origem e responsáveis ───────────────────────┐
│ Contraparte (Razão Social, CNPJ)
│ Remetente (e-mail, IP de envio)
│ Procurador / Representante (nome, CPF, instrumento)
│ Equipe interna responsável
└────────────────────────────────────────────────────┘

┌─ 4. Arquivo ──────────────────────────────────────┐
│ Pré-visualização do PDF (lateral direita ou inline)
│ Nome do arquivo · Tamanho · Páginas
│ [ Baixar ] [ Verificar assinatura digital ] [ Ver versões anteriores ]
└────────────────────────────────────────────────────┘

┌─ 5. Pendências e observações ────────────────────┐
│ Lista de pendências abertas (com data, autor, descrição)
│ Campo para nova observação interna (visível só ao time)
│ Anexos complementares
└────────────────────────────────────────────────────┘

┌─ 6. Trilha de auditoria ─────────────────────────┐
│ Linha do tempo invertida com:
│ – Data/hora · Autor · Ação · Detalhe (de → para)
│ Filtros: Tudo · Status · Anexos · Comentários · Sistema
│ Exportar trilha (CSV/PDF assinado)
└────────────────────────────────────────────────────┘
```

**Tratamento visual:**
- Cada bloco com cabeçalho cinza-claro, número da seção em pequeno (`01 · IDENTIFICAÇÃO`).
- Campos em par label/valor, label em cinza pequeno (12px), valor em escuro (14px, weight 500).
- Layout em 2 colunas dentro do bloco quando os campos forem curtos.
- O bloco "Trilha de auditoria" deve ser **visualmente protagonista** — é o que comunica governança.

### 3.6 Fluxo de upload / envio

**Reorganizar em 3 etapas claras**, em wizard horizontal:

**Etapa 1 — Identificação do documento**
- Operação/Emissão vinculada (autocomplete)
- Tipo e subtipo (dropdown encadeado)
- Vigência (de → até)
- Confidencialidade

**Etapa 2 — Arquivo e validação**
- Drop zone com instruções explícitas: *"Arquivos aceitos: PDF, P7S, XML. Máximo 25 MB. Recomenda-se PDF/A para arquivamento."*
- Verificação automática: tipo de arquivo, tamanho, OCR de cabeçalho, detecção de assinatura digital ICP-Brasil.
- Resumo do arquivo carregado (nome, tamanho, páginas, presença de assinatura).

**Etapa 3 — Confirmação e envio**
- Resumo de tudo (estilo recibo).
- Checkbox de declaração: *"Confirmo que o documento enviado é íntegro, legítimo e autorizado para envio."*
- Botão **"Protocolar Envio"** (não "Enviar" — "protocolar" carrega peso institucional).
- Após envio: tela de confirmação com **número do protocolo gerado**, hash do arquivo, data/hora, e opção de imprimir comprovante.

**Tom das instruções:**
- *"Os documentos enviados ficam sujeitos a triagem antes da análise. O envio gera protocolo e trilha de auditoria automáticos. Documentos com inconsistências serão devolvidos com pendência formal."*

### 3.7 Filtros e usabilidade operacional

**Barra de filtros persistente acima da tabela**, em uma única linha:

- **Operação** (multi-select)
- **Tipo de documento** (multi-select)
- **Status** (multi-select)
- **Responsável** (multi-select)
- **Origem / Contraparte** (autocomplete)
- **Período de envio** (range de datas)
- **Vigência até** (range de datas)
- **Busca livre** (protocolo, nome, hash)

**Atalhos inteligentes (saved views) acima dos filtros, em formato de tabs:**

```
[ Todos ]  [ Com pendência ]  [ Sem analista atribuído ]  
[ Vencendo em 30 dias ]  [ Rejeitados (últimos 7 dias) ]  
[ Aguardando triagem ]  [ + Salvar visualização atual ]
```

- Tab ativa em marinho com underline 2px dourado.
- Contador entre parênteses ao lado do nome (`Com pendência (47)`).
- Permitir ao usuário salvar combinações de filtros como "minhas visualizações".

### 3.8 Linguagem e tom

**Substituições de copy recomendadas:**

| Hoje | Proposto |
|---|---|
| 👋 Olá, Anderson! | (remover banner) |
| Bem-vindo ao seu portal exclusivo… | Gestão Documental Externa |
| Nova Solicitação / Novo Envio | Novo Documento / Protocolar Envio |
| Suas Solicitações Recentes | Movimentação recente |
| Envios Realizados | Documentos protocolados |
| Aguardando Análise | Em análise |
| Solicitações Aprovadas | Validados |
| PENDENTE | Em análise / Com pendência (depende do estado real) |
| Detalhes | Abrir documento |
| Ver Todas | Abrir lista completa |

**Princípios de copy:**
- Substantivos formais ("documento", "protocolo", "vigência", "análise") em lugar de verbos casuais.
- Evite emoji, gírias, exclamações.
- Datas no padrão `DD/MM/AAAA HH:mm` (BRT) sempre que possível.
- Mensagens de sucesso/erro com numeração de protocolo (`Documento DOC-2026-000123 protocolado em 04/05/2026 20:05`).

### 3.9 Visual design — tokens

**Paleta proposta** (Tailwind v4 — mapear via `@theme` no CSS):

```
--color-brand-navy:        #0B1E3A   (header, títulos)
--color-brand-navy-2:      #1F2A44   (subtítulos)
--color-brand-gold:        #B8860B   (CTA primário, bordas de marca)
--color-brand-gold-soft:   #E5C36A   (hover dourado)

--color-surface-base:      #F4F6FA   (background da página)
--color-surface-card:      #FFFFFF   (cards e tabela)
--color-surface-muted:     #F8FAFC   (cabeçalho de tabela, blocos secundários)

--color-border-subtle:     #E5E7EB
--color-border-default:    #D1D5DB
--color-border-strong:     #9CA3AF

--color-text-primary:      #0F172A
--color-text-secondary:    #475569
--color-text-muted:        #94A3B8

Status:
--color-status-recebido:   #475569 / bg #F1F5F9
--color-status-triagem:    #1D4ED8 / bg #EFF6FF
--color-status-analise:    #4338CA / bg #EEF2FF
--color-status-pendencia:  #92400E / bg #FFFBEB
--color-status-validado:   #065F46 / bg #ECFDF5
--color-status-rejeitado:  #9F1239 / bg #FFF1F2
--color-status-expirado:   #3F3F46 / bg #F4F4F5
```

**Tipografia:**
- `font-sans`: Inter ou IBM Plex Sans.
- Página H1: 22–24px, weight 600, marinho.
- Subtítulo H2: 16px, weight 500, cinza médio.
- Corpo: 14px, weight 400, primary.
- Tabela: 13px, weight 400. Cabeçalho 11–12px, weight 500, uppercase.
- Badges: 11px, weight 500, uppercase.
- Números (KPI): 28–32px, weight 600, tabular-nums.

**Espaçamento:**
- Container: max 1440px, padding lateral 32px.
- Entre blocos: 24px (não 32–48px como hoje).
- Padding interno de cards: 20–24px (não 32px).
- Linha de tabela: 44px.

**Bordas e sombras:**
- Border-radius: cards 6–8px (não 12–16px). Inputs 4–6px. Badges 4px.
- Sombras: praticamente ausentes. Use `box-shadow: 0 1px 2px rgba(15,23,42,0.04)` como máximo.
- Bordas 1px cinza-claro substituem sombras.

**Ícones:**
- Lucide (combina com Flux UI), tamanho 16px na tabela / 18px em headers.
- Cor herdada do texto (`currentColor`), nunca em círculo colorido grande.

### 3.10 Rastreabilidade e auditoria

**Elementos visuais que devem aparecer em toda a UI:**

1. **Protocolo sempre visível** — em cada linha, em cada tela de detalhe, em cada e-mail.
2. **Hash de integridade do arquivo** — exibido na tela de detalhe (primeiros 12 caracteres SHA-256, com tooltip mostrando o hash completo + algoritmo).
3. **Versão do documento** — indicador `v.1`, `v.2`… junto ao nome do arquivo, com link para ver versões anteriores.
4. **Autoria de cada ação** — toda mudança de status, comentário, devolução, validação registra: data/hora, usuário, IP, ação, valor anterior → valor novo.
5. **Trilha de auditoria como aba/bloco protagonista** — não como um log escondido. Em formato de timeline executiva.
6. **Exportação assinada** — opção de exportar a trilha em PDF assinado pelo sistema (com selo de hash) — é o que permite usar como evidência em auditoria externa.
7. **Carimbo de data/hora confiável** — exibir sempre que possível "Recebido em 04/05/2026 20:05:32 BRT" com fonte do timestamp (servidor), não cliente.

---

## 4. Prioridade de implementação

### Essencial (entrega 1 — base institucional)
1. Remover banner de boas-vindas e redesenhar o cabeçalho da página (título + subtítulo operacional + ações).
2. Substituir a barra superior dourada por versão sóbria (dourado contido).
3. Reformular taxonomia de status (7 estados) e implementar badges institucionais.
4. Reformular tabela: novas colunas, nova tipografia, nova densidade, paginação executiva.
5. Implementar filtros persistentes + busca + atalhos inteligentes em tabs.
6. Substituir copy informal por linguagem institucional em todo o módulo.
7. Adicionar protocolo formatado (`DOC-AAAA-NNNNNN`) em vez de `#2`.
8. Trilha de auditoria mínima na tela de detalhe (timeline com data/autor/ação).

### Importante (entrega 2 — controle e governança)
9. Tela de detalhe reorganizada em blocos (identificação, controle, origem, arquivo, pendências, auditoria).
10. KPIs reformulados (6 indicadores) com filtragem por clique.
11. Wizard de envio em 3 etapas + protocolo no final.
12. Hash SHA-256 do arquivo exibido na tela de detalhe.
13. Versionamento de documento (substituições mantêm versão anterior).
14. Exportação CSV/Excel da listagem com filtros aplicados.
15. Visualizações salvas (saved views) por usuário.

### Refinamento premium (entrega 3 — diferenciação)
16. Pré-visualização inline do PDF na tela de detalhe (sem precisar baixar).
17. Verificação automática de assinatura digital ICP-Brasil.
18. Exportação da trilha de auditoria como PDF assinado pelo sistema.
19. Alertas proativos de vencimento (e-mail + badge de alerta no dashboard).
20. Densidade alternável da tabela (compacta/padrão/confortável) com persistência por usuário.
21. Tema escuro (dark mode) institucional para uso prolongado em telas operacionais.
22. Atribuição automática por regras (analista por tipo de documento ou operação).

---

## 5. Redesign conceitual — descrição da tela ideal

### Estrutura da página

```
[ Topbar — marinho, fio dourado de 2px sob a logo ]
[ BSI Capital | Painel · Operações · Documental · Relatórios |  Anderson ▾ ]

[ Header da página ]
Painel › Gestão Documental Externa
══════════════════════════════════════════════════
Gestão Documental Externa
Acompanhamento, validação e arquivamento de documentação
recebida de contrapartes, cedentes e prestadores.
[Ambiente Operacional · LGPD]            [ Exportar ] [ Novo Documento ]

[ Linha de KPIs — 6 cards retangulares, borda fina, barra de cor à esquerda ]
| 248       | 47        | 19        | 1.182     | 12        | 23        |
| RECEBIDOS | EM ANÁLISE| C/PEND.   | VALIDADOS | REJEITADOS| VENCENDO  |
| ▲ 12 mai  | ▲ 4 mai   | ▼ 2 mai   | ▲ 38 mai  | ▬ mai     | 30 dias   |

[ Atalhos (tabs) ]
[ Todos (1.531) ] [ Com pendência (19) ] [ Sem analista (8) ] 
[ Vencendo em 30d (23) ] [ Rejeitados últ. 7d (3) ] [ + Salvar visão ]

[ Barra de filtros — uma linha ]
Operação ▾ | Tipo ▾ | Status ▾ | Responsável ▾ | Recebido entre __ e __ | 🔍 Buscar...

[ Tabela executiva ]
PROTOCOLO       | OPERAÇÃO        | TIPO           | ORIGEM           | RECEBIDO         | VIGÊNCIA   | STATUS          | RESP.   | ⋯
DOC-2026-000123 | CRA-018ª Série  | Cert. CND Fed. | Cedente Alpha S/A| 04/05/2026 20:05 | 30/06/2026 | ● Em análise    | M.Silva | ⋯
DOC-2026-000122 | CRI-007ª Série  | Estatuto Soc.  | Devedor Beta Ltda| 04/05/2026 18:31 | —          | ● Com pendência | A.Costa | ⋯
DOC-2026-000121 | FIDC Gamma      | Balanço 12/25  | Auditoria Delta  | 03/05/2026 09:14 | 31/12/2026 | ● Validado      | M.Silva | ⋯
...

[ Rodapé da tabela ]
1.531 documentos · página 1 de 52 · 30 por página · [ ‹ 1 2 3 4 5 … 52 › ]

[ Footer ]
© 2026 BSI Capital Securitizadora S/A · Versão 4.2.1 · Servidor BR-SP-01
```

### Comportamento esperado

**Estilo dos status:** badges retangulares com borda 1px e fundo dessaturado, ponto de cor à esquerda, texto uppercase 11px. Cores funcionais e contidas.

**Tabela:** linhas finas, tipografia tabular, hover sutil cinza-claro. Clicar em qualquer linha abre o detalhe. Selecionar múltiplas linhas habilita barra de ações em massa no topo.

**Filtros:** persistem na URL (querystring) e por usuário. Tabs de atalho mostram contadores em tempo real.

**Tom geral:** marinho profundo + branco + cinzas, dourado contido apenas na marca e CTA primário, status em cores neutras dessaturadas. Densidade média-alta. Tipografia compacta. Bordas finas substituindo sombras.

### Sensação resultante

O usuário, ao abrir o módulo, deve ler a tela em segundos como **um painel de controle documental regulado**: protocolos formais, contadores operacionais que sinalizam risco, atalhos para a fila do dia, filtros completos. Cada documento carrega protocolo, hash, versão, vigência, responsável e trilha de auditoria visível. A linguagem é institucional, sem cumprimentos pessoais nem promessas de "portal exclusivo". A paleta é sóbria, o dourado é contido, e o conjunto comunica **conformidade, governança e maturidade operacional** — o oposto de um upload-portal genérico.

---

## 6. Próximos passos sugeridos

1. **Validar tokens de design** com o time (paleta, tipografia, espaçamento).
2. **Aprovar taxonomia de status** com a área de operações/jurídico.
3. **Aprovar nomenclatura de protocolo** (`DOC-AAAA-NNNNNN` ou padrão da casa).
4. **Implementar entrega 1 (essencial)** — pode ser feita em 2–3 sprints na stack atual (Livewire + Flux + Tailwind), reaproveitando componentes Flux existentes (`flux:table`, `flux:badge`, `flux:button`, `flux:tabs`).
5. **Auditar a trilha de auditoria existente** no backend — confirmar se já há registro suficiente em log para popular a timeline da tela de detalhe; senão, modelar a tabela `audit_trail` antes do front.

---

*Documento elaborado com foco em direção visual e UX. Para handoff técnico componente a componente (props Flux, tokens Tailwind, estados de loading/erro), gerar spec sheet detalhado por componente após aprovação desta direção.*
