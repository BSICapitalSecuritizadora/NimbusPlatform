# Fase 3A — Conta Azul: estratégia futura de cutover (3C)

> Status: planejamento. Nenhum cutover é executado na Fase 3A. O job legado
> (`SyncContaAzulExpensesJob`, diário às 06:00) segue como único pipeline
> operacional até uma autorização explícita posterior.

## Invariante

O pipeline legado e o pipeline por conta (`ContaAzulBillIngestionService`)
nunca importam a mesma conta financeira concorrentemente. A violação desse
invariante produziria o mesmo título nas duas escritas (histórico
operacional + staging) sem vínculo entre elas.

## Por que o cutover é por conta financeira, não por emissão

- A identidade canônica do título é global (`conta_azul_bill_id` único em
  `expense_histories` e em `conta_azul_imported_bills`).
- Uma mesma `BankAccount` pode servir a várias emissões; migrar "por emissão"
  partiria uma conta ao meio, com metade dos títulos no legado e metade no
  staging.
- Portanto a unidade de migração é a conta financeira do Conta Azul
  (`BankAccount.conta_azul_account_id`).

## Pré-requisitos (3B antes do 3C)

1. Revisão e atribuição governada operantes: todo título `pending` da conta
   resolvido para `attributed` (ou descartado com motivo auditado).
2. Promoção staging → `Expense`/`ExpenseHistory` implementada e testada,
   reutilizando `conta_azul_bill_id` como chave de idempotência contra o
   histórico legado (nunca duplicar um `bill_id` já presente em
   `expense_histories`).
3. Reconciliação conta a conta: títulos do período de sobreposição
   comparados entre legado e staging antes de qualquer desligamento.

## Sequência por conta financeira

Para cada `conta_azul_account_id`, nesta ordem, sem exceção:

1. **Congelar o legado para a conta.** Pausar a importação legada somente
   dessa conta (escopo por `funds.conta_azul_account_id`), mantendo o
   schedule das demais. Registrar data/hora do congelamento.
2. **Importar o período de sobreposição no staging.** Rodar a ingestão por
   conta cobrindo desde antes do congelamento (para capturar edições
   tardias de títulos) até o presente.
3. **Reconciliar.** Conferir título a título: todo `bill_id` legado do
   período existe no staging com mesmos valor/estado, e vice-versa.
   Divergências bloqueiam o passo seguinte.
4. **Promover.** Criar os `Expense`/`ExpenseHistory` a partir do staging
   somente para `bill_id`s ainda ausentes no legado (idempotência pela
   chave global).
5. **Verificar leitores.** Conferir despesas, garantias e relatórios da(s)
   emissão(ões) servida(s) pela conta antes e depois — nenhuma diferença
   fora dos títulos novos do período.
6. **Aposentar o mapeamento legado da conta.** Somente agora remover o
   `conta_azul_account_id` dos `Fund`s dessa conta (nunca antes, nunca em
   lote multi-conta) e habilitar o pipeline novo para a `BankAccount`.
7. **Monitorar um ciclo.** Uma janela completa de sincronização com
   alertamento em qualquer título sem atribuição antes de migrar a próxima
   conta.

## Proibições permanentes

- Nunca ativar os dois pipelines para a mesma conta ao mesmo tempo.
- Nunca copiar `BankAccount.conta_azul_account_id` para vários `Fund`s como
  atalho de migração (recria a atribuição implícita que a Fase 3 existe
  para eliminar).
- Nunca reescrever `ExpenseHistory` existente durante a migração; a
  promoção só insere `bill_id`s novos.
- Nunca reaproveitar percentuais de alocação para distribuir despesas do
  Conta Azul.

## Rollback por conta

Se qualquer verificação falhar após o passo 6: desabilitar o pipeline novo
para a conta, restaurar o `conta_azul_account_id` dos `Fund`s a partir do
backup pré-migração, e reprocessar o período pelo legado. O staging
preservado (`source_payload` + `payload_fingerprint`) serve de evidência
para o diagnóstico — nunca é apagado no rollback.

---

# Fase 3B — addendum: conciliação governada entregue

> Status: implementado. A atribuição vive só no staging
> (`attributed_emission_id` + `conta_azul_bill_attributions`); nenhum
> `Expense`/`ExpenseHistory` é criado ou reescrito pela conciliação. O job
> legado segue como único pipeline operacional. Nenhum cutover é executado
> aqui — aguardar autorização explícita para qualquer mudança 3C.

## O que a 3B resolveu (refina os pré-requisitos acima)

- **Revisão e atribuição governada operantes** (pré-requisito 1, feito):
  `Conciliação Conta Azul` e `Importações Conta Azul` sob Financeiro;
  estados `pending` → `insufficient_information`/`ready_for_review` →
  `attributed`; um título, uma emissão; correções só por ação governada
  com histórico append-only (`ContaAzulBillAttributionService`).
- **Mudança externa após atribuição:** reimportações com alteração
  relevante (valor, fornecedor, vencimento, categoria, centro de custo,
  estado de pagamento) levantam `needs_re_review` com motivo e valores
  anteriores na trilha, sem mover a atribuição; metadados inócuos
  atualizam em silêncio (`ContaAzulExternalChangePolicy`).
- **Consciência do legado:** cada título exibe se já existe em
  `expense_histories` ("Já registrado no fluxo legado") e divergências de
  emissão entre staging e legado ficam explícitas para reconciliação
  futura — insumo direto do passo 3 da sequência por conta.
- **Recuperação de lote interrompido:** lotes `running` sem heartbeat por
  mais de `conta-azul.stale_batch_timeout_minutes` (padrão 60) são
  recuperáveis para `abandoned` (terminal, retomável) por ação
  administrativa ou `conta-azul:recover-stale-batch`; lotes ativos são
  recusados; títulos preservados; retry referencia o lote abandonado.
- **Elegibilidade por verificação:** atribuição final exige conteúdo
  verificado por importação `completed` (lote de conteúdo ou última
  observação completa), salvo exceção justificada registrada na decisão.

## O que permanece para a 3C (não implementado)

1. Promoção staging → `Expense`/`ExpenseHistory` (pré-requisito 2):
   inserir somente `bill_id`s ausentes no legado, com a atribuição
   vigente como emissão; `bill_id`s já presentes nunca são duplicados
   nem reescritos — a detecção 3B vira a guarda da promoção.
2. Reconciliação conta a conta do período de sobreposição (passo 3),
   consumindo o indicador de divergência legado × staging.
3. Estratégia de coordenação aprovada para pausar o legado por conta
   (passo 1) e habilitar o pipeline novo (passo 6), mantido o invariante
   de um pipeline ativo por conta financeira.
4. Decisão de retenção: a trilha `conta_azul_imports` segue descartável
   (365 dias) com as decisões duráveis na tabela de atribuições; se a
   governança exigir trilha de auditoria de longo prazo para as decisões,
   promover o log antes do cutover (requer sign-off de negócio/jurídico
   conforme `config/audit.php`).
