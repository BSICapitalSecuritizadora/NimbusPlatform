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
