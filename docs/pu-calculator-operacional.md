# Calculadora de PU — Guia Operacional

Este documento descreve o uso **operacional** da calculadora de PU diário (CDI + spread) em produção:
permissões, estados da curva, geração/validação em fila, reprocessamento seguro, auditoria e verificação
de dados faltantes. A engine de cálculo permanece isolada em `app/Domain/PuCalculator` e não deve ser
alterada por este fluxo.

## Permissões

As ações críticas são controladas por permissões granulares (grupo **"Curva de PU"** em Perfis):

| Permissão | O que libera |
|-----------|--------------|
| `pu.curve.view` | Ver painel, curva, relatório de divergências |
| `pu.parameters.configure` | Configurar parâmetros e editar eventos de PU |
| `pu.curve.generate` | Gerar curva |
| `pu.curve.validate` | Validar contra planilha |
| `pu.curve.export` | Exportar curva (CSV) |
| `pu.curve.reprocess` | Reprocessar curva quando já existe versão **homologada** |
| `pu.curve.homologate` | Homologar curva |
| `pu.curve.invalidate` | Invalidar curva |

**Distribuição padrão por perfil** (seeder `RolesAndPermissionsSeeder`):
- **super-admin / admin**: todas.
- **editor**: opera o ciclo (ver, configurar, gerar, validar, exportar, reprocessar). **Não** homologa nem invalida.
- Homologação e invalidação ficam reservadas a admin/super-admin (separação de funções — *maker/checker*).

## Estados da curva (`EmissionPuCurveVersion.status`)

Cada geração cria um registro de **versão** (`emission_pu_curve_versions`) com `calculation_version`
(`v1`, `v2`, …), `batch_id`, usuário responsável, snapshot de parâmetros, contagem de linhas, erro e
resumo de validação. Estados:

- **pending / processing** — geração enfileirada / em execução.
- **generated** — curva gerada, ainda não validada.
- **validated** — validada contra planilha sem divergências relevantes.
- **divergent** — validada porém com divergências.
- **homologated** — homologada e **protegida** contra sobrescrita.
- **error** — falha na geração (mensagem em `error_message`).
- **obsolete** — substituída por versão mais recente ou invalidada (histórico preservado).

A "versão corrente" é a mais recente que **não** está obsoleta.

## Fluxo operacional (tela Editar Emissão)

1. **Configurar Cálculo de PU** — define período, PU inicial, spread, indexador (CDI), calendário e modo
   de consulta do CDI. Alterações são auditadas.
2. **Cadastrar eventos de PU** (aba *Eventos de PU*) — juros/amortizações. Alterações são auditadas.
3. **Gerar / Reprocessar Curva PU** — dispara `GeneratePuDailyCurveJob` (fila). A página acompanha o
   progresso por polling e notifica ao concluir. Cada geração cria uma nova versão; versões anteriores
   **não homologadas** passam a `obsolete`.
4. **Painel da Curva PU** — mostra status (badge), versão atual, data da última geração, responsável,
   resumo da última validação e o **relatório de índices** (CDI faltante / projetado / calendário).
5. **Validar contra Planilha** — dispara `ValidatePuCurveJob` (fila). Atualiza o status da versão para
   `validated` ou `divergent` e grava o resumo. Relatório detalhado em *Ver Relatório de Divergências*.
6. **Homologar Curva** — marca a versão corrente como `homologated` (protegida).
7. **Invalidar Curva** — marca a versão corrente como `obsolete` (histórico preservado).
8. **Exportar Curva PU** — CSV da versão escolhida (auditado).

## Reprocessamento seguro

- A geração **nunca sobrescreve** dados: sempre cria `v(N+1)`.
- Se existir versão **homologada**, o botão vira **"Reprocessar Curva PU"** e:
  - exige a permissão `pu.curve.reprocess`;
  - exige uma **confirmação explícita** (checkbox) no modal;
  - o job só prossegue com `confirmedReprocess = true` (guarda também no backend).
- A versão homologada permanece homologada; a nova versão entra como `generated`.

## Índices CDI e dados faltantes

Antes de gerar, os pré-requisitos (`PuCurvePrerequisiteService`) **bloqueiam** a geração se faltar
calendário ou CDI obrigatório. O relatório consolidado (`PuIndexCoverageService`) identifica:

- todas as datas sem CDI no período;
- datas usando **CDI projetado** (`source_reference = forward_projection`);
- o último CDI disponível;
- datas de calendário faltantes.

### Comando de verificação

```bash
# Uma emissão específica
php artisan pu:check-missing-data {emission_id}

# Todas as emissões com parâmetros de PU configurados
php artisan pu:check-missing-data
```

Sai com **código ≠ 0** quando há índice/calendário obrigatório faltando — adequado para uso em rotinas
de monitoramento/CI antes de gerar curvas em lote.

## Auditoria

Trilha registrada via Spatie Activitylog (`log_name = pu-calculation`) para:
configuração de parâmetros (`pu_parameters_updated`, com diff), alteração de eventos (`pu_event_changed`),
geração (`pu_curve_generated`) e reprocessamento (`pu_curve_reprocessed`), falha de geração
(`pu_curve_generation_failed`), validação (`pu_curve_validated`), exportação (`pu_curve_exported`),
homologação (`pu_curve_homologated`), invalidação (`pu_curve_invalidated`) e download do PDF de homologação
(`pu_homologation_report_downloaded`). Cada entrada registra o usuário responsável (`causer`).

## Histórico / Auditoria por emissão (timeline)

Acesse **Gestão > Emissões > [emissão] > Editar > "Histórico / Auditoria"**, ou diretamente a URL
`/admin/emissions/{id}/pu-history` (permissão `pu.curve.view`). A tela mostra:

- **Timeline de versões** (mais recente primeiro): versão, status (badge), responsáveis e datas de
  geração/validação/homologação, total de linhas, parâmetros principais, resumo da validação com as maiores
  divergências, motivo de obsolescência e erros de job. Cada versão tem botões para o relatório de
  divergências e para baixar o **PDF de homologação**.
- **Auditoria das ações**: lista cronológica de todas as ações da calculadora (geração, validação,
  homologação, exportação, reprocessamento, invalidação, alteração de parâmetros/eventos), com responsável.
- Aviso automático quando há versão em "processando" há mais de 30 min (possível worker parado).

## PDF de homologação

Na timeline ou no Painel da Curva PU, clique em **"Baixar PDF de homologação"** (permissão `pu.curve.export`;
rota `admin.emissions.pu-homologation.pdf`). O documento traz identificação da emissão, dados da versão,
parâmetros, resumo da validação (com indicação de display-scale/raw-scale) e um bloco simples de aprovação
interna. Os valores são apenas leitura dos dados persistidos — não há recálculo.

## Worker de fila em produção (obrigatório)

Geração (`GeneratePuDailyCurveJob`) e validação (`ValidatePuCurveJob`) rodam na **fila** (driver `database`).
Sem um worker ativo, os jobs ficam **pendentes** e a curva nunca sai de "processando". Em produção mantenha
um worker supervisionado:

```bash
# Opção simples (supervisionada por Supervisor/systemd):
php artisan queue:work --queue=default --tries=1 --timeout=900

# Ou Laravel Horizon (se/quando adotado), gerenciado por Supervisor.
```

Exemplo de programa no Supervisor:

```
[program:nimbus-queue]
command=php /var/www/html/artisan queue:work --tries=1 --timeout=900
autostart=true
autorestart=true
numprocs=1
stopwaitsecs=920
```

### Health-check da fila

```bash
php artisan pu:queue-health           # exit != 0 se houver jobs falhos ou versões travadas
php artisan pu:queue-health --stale-minutes=15
```

Reporta jobs pendentes, jobs falhos (`failed_jobs`) e versões `processing` há muito tempo. Útil para
monitoramento/alertas. Observação: os contadores assumem o driver `database`; com Horizon/Redis as métricas
de pendência vêm do próprio Horizon.

## Monitoramento operacional (scheduler + alertas + painel)

### Migrations

Antes de tudo, aplique as migrations pendentes no ambiente real:

```bash
php artisan migrate
```

Inclui `obsolete_reason` (timeline/PDF) e a permissão `pu.dashboard.view` (painel operacional).

### Scheduler

O `pu:queue-health --alert` roda automaticamente a cada 10 minutos (registrado em `routes/console.php`,
nome `pu-queue-health`). Em produção, garanta o cron do scheduler:

```cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

Verifique com `php artisan schedule:list`. O comando só lê o banco e só envia e-mail se houver
destinatários — é seguro em ambientes locais.

### Worker de fila

Igual à seção anterior: mantenha `php artisan queue:work --tries=1 --timeout=900` supervisionado
(Supervisor/systemd) ou Laravel Horizon. **Sem worker, as curvas ficam "processando" indefinidamente.**

### Alertas por e-mail

Configure os destinatários no `.env`:

```dotenv
PU_CALCULATOR_ALERT_RECIPIENTS="ops@empresa.com,risco@empresa.com"
PU_CALCULATOR_STALE_PROCESSING_MINUTES=30
PU_CALCULATOR_ALERT_COOLDOWN_MINUTES=180
```

O alerta (`PuCurveHealthAlertMail`) é disparado pelo scheduler quando há problema crítico:
curva travada em "processando", job de PU falho (`GeneratePuDailyCurveJob`/`ValidatePuCurveJob`),
curva em estado de erro ou CDI obrigatório faltante. Há **cooldown** (default 180 min) por conjunto de
problemas — um problema novo re-dispara imediatamente. Sem destinatários configurados, nenhum e-mail é
enviado. Slack/Teams não estão integrados nesta fase (apenas e-mail).

### Painel Operacional de PU (dashboard multi-emissão)

Acesse **Gestão > Painel Operacional de PU** (`/admin/pu-curve-dashboard`, permissão `pu.dashboard.view`).
Mostra cards consolidados (emissões com PU, homologadas, validadas, divergentes, processando, erro,
obsoletas, sem curva, CDI faltante, jobs pendentes/falhos) e uma tabela por emissão com versão atual,
status, datas de geração/validação/homologação, responsável, maior divergência e CDI faltante, com ações
rápidas: timeline, PDF de homologação e abrir a emissão para reprocessar.

### Como interpretar alertas / agir

- **Curva "processando" há muito tempo** → confirme o worker (`queue:work`/Horizon) e veja
  `php artisan queue:failed`; reprocesse pela tela da emissão se o job morreu.
- **Job de PU falho** → `php artisan queue:failed` lista; corrija a causa e `queue:retry`.
- **CDI faltante** → `php artisan pu:check-missing-data {emissão}` e complete os índices/calendário.
- **Curva em erro** → veja `error_message` no painel/timeline e os logs.

### Drivers de fila

Os contadores de fila (`pu:queue-health`, painel, alertas) assumem o driver **`database`** (tabelas `jobs`/
`failed_jobs`). Com **Horizon/Redis**, as métricas de pendência/falha devem vir do Horizon — a integração
Horizon completa não faz parte desta fase.

## Troubleshooting

- **"Geração bloqueada por dados incompletos"** — rode `pu:check-missing-data` e complete calendário/CDI/parâmetros.
- **"Reprocessamento não autorizado"** — usuário sem `pu.curve.reprocess` tentando reprocessar curva homologada.
- **"Confirmação necessária"** — marque o checkbox de confirmação ao reprocessar curva homologada.
- **Status `error`** — veja `error_message` na versão (painel) e os logs (`GeneratePuDailyCurveJob failed`).
- **Geração travada** — há um lock por emissão (`pu_curve_generation_{id}_lock`, TTL 30 min); aguarde ou
  verifique a fila.

## Indexadores e status de homologação

A calculadora é multi-indexador. O status operacional de cada indexador é a fonte única
`PuIndexer::isHomologated()` (consumida pelo PDF de homologação e pela UI):

| Indexador | Método | Status | Observação |
| --- | --- | --- | --- |
| **CDI + spread** | `cdi_spread` | **Homologado** | Calibrado contra os gabaritos AMANI (CDI defasado, dia útil) e TROUPE (CDI do dia de calendário anterior, exato). |
| **Prefixado** | `fixed_rate` (`phase3-fixed-v1`) | **Homologado** (determinístico) | Gera curva sem depender de série de índices. Ainda **não validado contra gabarito de mercado**; é determinístico e coberto por testes de não regressão. |
| **IPCA** | `ipca_corrected` (`phase3-ipca-experimental`) | **Em preparação (bloqueado)** | Engine **implementada e validada** contra o gabarito real (CRI RIO BRANCO 15ª série) na **janela pré-amortização** (~3 anos, a 12 casas). Ainda **bloqueada para geração** (a interação das amortizações intermediárias não está homologada). Ver abaixo. |

### IPCA — por que ainda está bloqueado

> **Atualização (2026-06-23):** a `IpcaCurveCalculator` deixou de ser esqueleto e agora **implementa o
> núcleo IPCA** (correção monetária + cupom real), com precisão decimal (`bcmath`/`Decimal`, sem `float`).
> Ela é **validada por teste** contra o gabarito real na janela **2021-09-30 → 2024-09-16** (véspera da
> primeira amortização intermediária): `factor_spread` (cupom) bate à precisão publicada do gabarito
> (~10 casas) e o PU corrigido/atualizado fica dentro de tolerância < R$ 0,001 por cota.
>
> Mesmo assim a geração **continua bloqueada**: o gabarito tem **9 amortizações intermediárias** cuja
> interação com aniversário/deflação **ainda não é reproduzida**. Portanto `PuIndexer::Ipca->isHomologated()`
> permanece `false` e o `PuCurvePrerequisiteService` segue bloqueando a geração. A fórmula final **não é
> inventada**; a política de projeção (meses sem IPCA publicado) é decisão de negócio (ver abaixo).

### Mecânica homologada da engine IPCA (janela pré-amortização)

Decodificada e validada a 12 casas contra o gabarito (engine em `IpcaCurveCalculator`):

- **Aniversário mensal** no dia `base_index_date->day` (no gabarito, **dia 23**). Períodos vão de um
  aniversário ao próximo; `dup/dut` em **dias corridos** (30/31).
- **Correção monetária (pró-rata, com piso de deflação)**:
  `corrigido = base_corrigido × max(NI_ref / NI_prev, 1) ^ (dup/dut)`, onde `NI_ref` é o número-índice do
  mês de referência (1º do mês do aniversário de abertura, defasado de `index_lag_months`; no gabarito
  `lag = 0`) e `NI_prev` é o do mês anterior. O **piso `max(…, 1)`** reproduz a regra observada: nos
  **5 meses deflacionários** do gabarito (2022-07/08/09, 2023-06, 2024-08) a correção **não decresce**.
- **Cupom real** (`annual_rate`, no gabarito **10,5%**) em base **30/360 mensal**:
  `fator = (1 + taxa/100) ^ (dup/(dut×12))`; `juros = corrigido × (fator − 1)`;
  `PU atualizado = corrigido + juros`. Exposto na coluna `factor_spread`. Juros **pagos mensalmente** no
  aniversário (evento de juros); o fator reinicia no período seguinte.
- **Número-índice IPCA** carregado em `index_rates` (`indexer = IPCA`, `rate_date` = 1º do mês). O número
  de Ago/2021 (denominador da 1ª variação) não é publicado no gabarito e é **recuperado** do próprio
  corrigido em 2021-10-23.

**Pendente (não homologado):** interação das **9 amortizações intermediárias** com aniversário/deflação
(salto observado no reinício pós-amortização). A engine produz curva após a 1ª amortização, mas esse
trecho **não é validado** e a geração operacional permanece bloqueada até essa decodificação + aprovação.

O bloqueio operacional é aplicado em duas camadas, cobertas por testes:

1. `PuIndexer::Ipca->isHomologated()` retorna `false` (consumido por UI/PDF).
2. `PuCurvePrerequisiteService` adiciona issue bloqueante com a mensagem
   "A curva IPCA está em preparação e não pode ser gerada nesta versão da calculadora.".

Além disso, `IpcaCurveCalculator::calculate()` lança `IndexerNotSupportedException` quando faltar
número-índice para o período (a política de **projeção de mercado** ainda não foi implementada).

Na UI, a opção IPCA aparece com o rótulo `IPCA (em preparação)` e a geração é negada.

### Parâmetros IPCA já disponíveis (preparatórios)

A migration `add_multi_indexer_fields_to_emission_pu_parameters_table` já criou os campos que a
engine IPCA usará quando houver gabarito (todos `nullable`, sem efeito operacional enquanto bloqueado):

- `index_lag_months` — defasagem do índice IPCA em meses.
- `base_index_date` — data-base do número-índice de partida.
- `correction_frequency` — frequência de correção monetária (ex.: mensal).
- `index_projection_policy` — política de projeção do índice quando não há valor publicado.

### Análise do gabarito IPCA (CRI RIO BRANCO 15ª série)

Gabarito: `docs/samples/pu-validation/15ª SÉRIE - CRI RIO BRANCO_*.xlsx`, aba `PuDiario`, 2520 linhas
diárias de **2021-09-30** a **2028-08-23** (mesmo layout de colunas dos gabaritos CDI). Mecânica
decodificada a partir dos valores (a planilha é export de valores, sem fórmulas embutidas):

- **Número-índice IPCA** na coluna do índice utilizado (`valordoindiceutilizado`), datado no **1º do mês**
  de referência (`datadoindiceutilizado`). Ex.: Set/21 = `5944,21`; Out/21 = `6018,51` ⇒ variação
  `+1,2499%`, que confere com o IPCA real de outubro/2021.
- **Aniversário mensal no dia 23**: `dup/dut` da correção zeram a cada dia 23; `dut` = dias **corridos**
  do período aniversário-a-aniversário (30/31).
- **Correção monetária (pró-rata em dias corridos)**:
  `corrigido = corrigido_aniversário × (NI_mês / NI_mês-1) ^ (dupCorrecao / dutCorrecao)`.
- **Defasagem (`index_lag_months`)**: **confirmada = 0** — cada período aplica a variação do número-índice
  do 1º do mês do aniversário de abertura (`NI_ref/NI_prev`).
- **Juros (cupom real)**: **confirmado 10,5% a.a.**, base **30/360 mensal** — `fator = (1+0,105)^(dup/(dut×12))`,
  acrescido sobre o **corrigido**; `PU atualizado = corrigido + juros`; juros **pagos mensalmente** no aniversário.
- **Deflação**: **confirmado piso `max(ratio, 1)`** — meses de IPCA negativo não corrigem para baixo
  (5 ocorrências no gabarito).
- **Amortização**: **9 amortizações** — 8 intermediárias a partir de **2024-09-17** + bullet final no
  vencimento (2028-08-23). A interação das amortizações intermediárias com aniversário/deflação **ainda
  não foi homologada** (fora da janela validada).
- **Cauda sem índice publicado**: no gabarito histórico a curva fica **flat e com juros zerados** após o
  último número-índice (Jun/2025). Isso reflete o recorte do gabarito, **não** a política de projeção de
  produção (ver decisão abaixo).

### Política de projeção (decisão de negócio)

Para meses **sem IPCA publicado** (cauda/futuro), a política adotada é **projeção de mercado
(ANBIMA/curva)**: a engine usará uma série de número-índice **projetada** cadastrada em `index_rates`
para os meses futuros (`index_projection_policy` correspondente). Consequências:

- O gabarito histórico valida **apenas o núcleo determinístico** (correção + juros + amortização) nos
  meses com número-índice conhecido.
- A projeção de mercado **não é validável** por este gabarito histórico e exige um cenário separado com
  série projetada. A **integração automática ANBIMA/B3 está fora de escopo** — a série projetada é
  alimentada manualmente em `index_rates`.

### Plano de homologação IPCA (antes de alterar a engine)

1. **Travar o estado atual** (feito): testes garantem IPCA bloqueado/experimental e CDI/Prefixado sem
   regressão; persistência/cast dos parâmetros IPCA cobertos.
2. **Semear a série de número-índice IPCA** (`index_rates`) cobrindo o período do gabarito, derivando os
   NI consecutivos da própria planilha (cada período expõe `NI_mês`); incluir o mês anterior ao início
   para a primeira variação.
3. **Evoluir `IpcaCurveCalculator`** com `Decimal`/precisão segura (sem `float`):
   correção pró-rata dia-23 + juros 30/360 + amortização bullet, **reaproveitando** os serviços de
   eventos/pagamentos/quantidade/`total_value` já usados por CDI/Prefixado (sem tocar nas fórmulas
   específicas de CDI/Prefixado).
4. **Validação contra o gabarito** (à semelhança de AMANI/TROUPE), comparando **fatores em até 12 casas**
   (cast `decimal:16` formata via float) e os PUs/juros/amortização nas escalas de exibição.
5. **Só então**: virar `PuIndexer::Ipca->isHomologated()` para `true`, remover o bloqueio do
   `PuCurvePrerequisiteService`, habilitar a geração na UI e ajustar o rótulo. **Mediante aprovação.**

### Como destravar o IPCA (resumo)

Somente após o plano acima ser implementado, a validação passar e a homologação ser **aprovada**:
virar `isHomologated()` para `true`, remover o bloqueio do prerequisite e habilitar a UI. Enquanto isso,
a opção IPCA permanece visível como `IPCA (em preparação)` e a geração é negada.

## Migrations no ambiente real (MySQL)

As migrations desta fase e das anteriores precisam ser aplicadas no **MySQL real**:

```bash
php artisan migrate
```

Migrations relevantes desta fase e da anterior:

- `add_obsolete_reason_to_emission_pu_curve_versions_table` — `obsolete_reason` (timeline/PDF).
- `seed_pu_dashboard_permission` — permissão `pu.dashboard.view` (painel operacional).
- `add_multi_indexer_fields_to_emission_pu_parameters_table` — `annual_rate`, `calculation_method`,
  `method_version`, `rounding_policy` e os campos preparatórios de IPCA; torna `spread_rate` nullable
  (Prefixado/IPCA não usam spread).
- `widen_index_rates_rate_value_for_ipca` — amplia `index_rates.rate_value` de `decimal(12,8)` para
  `decimal(20,8)`. O número-índice IPCA é de magnitude muito maior que as taxas CDI/prefixada (milhares,
  crescente no tempo) e não cabe em `decimal(12,8)` no longo prazo. Valores CDI existentes não mudam.

Todas são reversíveis (`down()`). O `down()` da multi-indexador repõe `spread_rate = 0` onde for nulo
antes de voltar a coluna para `NOT NULL`.

> **Nota:** em ambientes de desenvolvimento sem acesso ao host `mysql`, a suíte de testes valida o
> schema em SQLite em memória. Isso confirma a reversibilidade e a integridade das migrations, mas o
> ambiente de produção/staging **deve** rodar `php artisan migrate` no MySQL real.

## Isolamento das mudanças de PU antes do commit

Há trabalho concorrente no repositório em **site** e **Obrigações**. As mudanças da Calculadora de PU
**não devem ser misturadas** com esses arquivos no mesmo commit. Antes de commitar:

1. `git status` e separe os arquivos por escopo. Escopo **PU** (commitável junto): `app/Domain/PuCalculator/**`,
   `app/Models/EmissionPuParameter.php` e demais models `EmissionPu*`, migrations `*_pu_*`/`*multi_indexer*`/
   `*obsolete_reason*`/`*dashboard*`, `tests/**/PuCalculator/**` e `tests/Unit/PuCalculator/**`,
   `docs/pu-calculator-operacional.md`, `docs/samples/pu-validation/**`.
2. Fora do escopo PU (**não** incluir): qualquer coisa em `site`/landing, `Obligation*`/Obrigações,
   migrations `*obligation*`/`*evidence*`.
3. Faça **stage seletivo** (`git add <arquivos-de-PU>`), nunca `git add -A`/`git add .`.
4. **Nenhum commit sem aprovação.**

> **Nota:** quando esta fase foi preparada, o working tree estava limpo, exceto pelo gabarito IPCA
> `docs/samples/pu-validation/15ª SÉRIE - CRI RIO BRANCO_*.xlsx` (untracked) — esse arquivo é escopo PU.
