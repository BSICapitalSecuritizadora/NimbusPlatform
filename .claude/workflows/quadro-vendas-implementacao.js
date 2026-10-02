export const meta = {
  name: 'quadro-vendas-implementacao',
  description: 'Implementa as 8 frentes do Quadro de Vendas em copias isoladas: cadeia principal + 3 trilhas paralelas com merge',
  whenToUse: 'Implementar o pacote Quadro de Vendas completo conforme especificacoes e parecer do juiz',
  phases: [
    { title: 'Trilhas paralelas', detail: 'importacoes, garantias-relatorio e automacao-operacao em copias proprias' },
    { title: 'Cadeia principal', detail: 'autorizacao, fluxo, merges, regras, baixa, extemporaneos na copia Y' },
  ],
}

const A = args || {}
const P = A.paths

const IMPL_SCHEMA = {
  type: 'object',
  properties: {
    front: { type: 'string' },
    status: { type: 'string', enum: ['completo', 'parcial'] },
    summary: { type: 'string' },
    commits: { type: 'array', items: { type: 'string' } },
    files_changed_count: { type: 'number' },
    migrations: { type: 'array', items: { type: 'string' } },
    new_tests: { type: 'array', items: { type: 'string' } },
    tests_run: { type: 'array', items: { type: 'string' } },
    suite_result: { type: 'string' },
    deviations_from_spec: { type: 'array', items: { type: 'string' } },
    integration_notes: { type: 'array', items: { type: 'string' } },
    remaining_items: { type: 'array', items: { type: 'string' } },
    known_issues: { type: 'array', items: { type: 'string' } },
  },
  required: ['front', 'status', 'summary', 'commits', 'files_changed_count', 'migrations', 'new_tests', 'tests_run', 'suite_result', 'deviations_from_spec', 'integration_notes', 'remaining_items', 'known_issues'],
}

function context(copy, front, done, later) {
  return `Data: 2026-10-01. Escreva em portugues (pt-BR) nos campos descritivos; nomes de codigo como estao.

## Missao
Implementar, numa copia isolada do repositorio NimbusPlatform (Laravel 13.34, Filament 5.9, Livewire 4.4.7, activitylog 5.1.1, permission 8.3, Pest 5), a frente "${front}" do pacote "Quadro de Vendas completo". O dono pediu: "Faca todos os ajustes para o modulo ficar completo" e delegou as pendencias: "os demais pontos pode corrigir eles da melhor maneira". O disco temporario de upload em producao cai no azure (FILESYSTEM_DISK=azure, PRIVATE_FILESYSTEM_DISK=local, sem LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK).

## Onde trabalhar
- Sua copia: ${copy}. E um repositorio git de RASCUNHO proprio (nao e o do projeto), com vendor, .env e APP_KEY prontos. Trabalhe SO nela; nenhum outro agente edita esta copia enquanto voce trabalha.
- NUNCA toque o workspace compartilhado ${P.workspace} (outras sessoes trabalham la): nem leitura, nem escrita, nem git, nem testes.
- Antes de comecar: git -C ${copy} status e git -C ${copy} log --oneline. Se houver mudancas nao commitadas de uma tentativa anterior desta mesma frente (interrompida), revise-as e continue de onde parou, sem refazer nem perder trabalho valido.

## Especificacao
- Specs das 8 frentes: ${P.specs} (JSON, lista). A sua e a de "topic" que comeca com "${front}". Leia inteira: decisions, changes, schema_changes, new_identifiers, tests, interplay, risks.
- Parecer do juiz de consistencia, OBRIGATORIO e prevalece sobre a spec quando conflitam: ${P.judgeMd} (JSON completo em ${P.judgeJson}). Siga os SHARED CONTRACTS, as resolucoes de CONFLICTS que tocam sua frente, a FILE OWNERSHIP, a MIGRATION REVIEW e as convencoes de teste (conflito 20).
- Achados originais da auditoria (evidencias): ${P.findings} e ${P.low}.
- Memorias do projeto (armadilhas conhecidas): ${P.memoryDir}/*.md.
- Frentes ja integradas nesta copia antes de voce: ${done.length ? done.join(', ') : 'nenhuma (copia = origin/main ebbdf72)'}. Construa por cima delas: elas ja mudaram assinaturas, fixtures e regras conforme o parecer.
- Frentes que entram DEPOIS (nao implemente as partes delas): ${later.length ? later.join(', ') : 'nenhuma'}. Se sua frente precisa de uma peca delas que ainda nao existe, implemente o minimo compativel com o contrato compartilhado, isolado num metodo, e registre em integration_notes.

## Regras de implementacao
- Siga CLAUDE.md (ja injetado) e o estilo dos arquivos irmaos (docblocks em portugues explicando o porque). Use o Skill tool para carregar laravel-best-practices, testing-best-practices e livewire-development, se disponiveis.
- Migrations: crie com php artisan make:migration --no-interaction (timestamps reais; NAO use os nomes fixos da spec), aditivas, seguras para MySQL 8.4 com dado real, com as guardas da MIGRATION REVIEW (hasColumn/hasTable/FK conferida por colunas), nomes de indice <= 64 caracteres, validas tambem em SQLite.
- Nenhuma permissao nova. Activitylog v5: Spatie\\Activitylog\\Models\\Concerns\\LogsActivity, Spatie\\Activitylog\\Support\\LogOptions, dontLogEmptyChanges(), alteracoes em attribute_changes; trilha regulada com useLogName() protegido em config/audit.php.
- Textos de UI em portugues, na voz das telas existentes. Nada de TODO/FIXME.
- Pint: vendor/bin/pint <arquivos PHP que voce criou ou alterou> (passe caminhos; nunca --dirty nem diretorio inteiro).

## Testes
- Escreva os testes da spec (Pest em tests/Feature; fixtures em tests/Support). Rode-os e os existentes afetados: cd ${copy} && php -d memory_limit=1536M artisan test --compact <arquivos>.
- No fim, rode a suite do Quadro (lista base em ${P.sbFiles}; filtre os arquivos que existem e acrescente os testes novos) e as suites de Garantias, Importacao, Relatorio, Contratos, Parcelas, Unidades, Emissao e Rollout que sua frente tocar. Corrija tudo o que quebrar, inclusive testes de frentes anteriores afetados pela sua mudanca (ajustando-os conforme o parecer, sem enfraquecer o que provam). Meta: zero falhas; os pulados devem continuar sendo so os de MySQL e de benchmark opt-in.
- Ha outras trilhas rodando testes na mesma maquina: nao rode a suite inteira mais de 2 vezes; testes do grupo mysql nao rodam aqui (sem MySQL), escreva-os mesmo assim quando a spec pedir.

## Fechamento
- git -C ${copy} add -A && git -C ${copy} -c user.email=scratch@local -c user.name=scratch commit -q -m "frente: ${front}" (repositorio de rascunho).
- Se nao conseguir terminar tudo, commite o que estiver verde com "frente: ${front} (parcial)" e devolva status 'parcial' com remaining_items precisos, para outro agente continuar dali.
- Devolva o schema: status, resumo, commits, migrations, testes novos, comandos de teste com resultado, suite_result (ex.: 'Quadro: N passed, M skipped, 0 failed'), desvios da spec, notas de integracao para os proximos merges, pendencias e problemas conhecidos.`
}

const ADJUST = {
  'autorizacao-trilha': `Inclua a lacuna do parecer: SalesBoardRolloutRecipientDirectory::remove() roda numa transacao que trava a Emissao (lockForUpdate) antes do delete, com teste mysql remove x activate (grupo mysql, com limpeza no afterEach). Implemente D7 (importar exige create E update) so por policies/abilities, canImport() e visible(), sem reescrever analisadores nem importadores (a frente importacoes, em paralelo, reescreve esses arquivos).`,
  'importacoes': `Voce trabalha em PARALELO, a partir da base (sem autorizacao e fluxo). Nao implemente a regra de permissao de importacao (canImport/visible): e da autorizacao; mantenha os visible() existentes nos lugares atuais para facilitar o merge. Inclua o ContractInstallmentImportScaleTest opt-in (NIMBUS_BENCH=1) da spec; o composer test:bench e o workflow de benchmark sao da automacao. .env.example: linha do disco temporario COMENTADA; .env.example.production: explicita; teste do fallback avalia config/livewire.php sem a variavel (parecer, conflito 23). Nao implemente desconto (regras faz depois, sobre o seu codigo) nem o aviso por linha de competencia publicada (extemporaneos faz depois); deixe as estruturas preparadas para receber esses campos (StoredInstallment, digest, linha).`,
  'garantias-relatorio': `Voce trabalha em PARALELO, a partir da base. Voce e a DONA do relatorio mensal, do PDF e do Recebivel, inclusive do classificador de competencia cancelada com os textos unificados do parecer (conflito 7). A regra 3 do write guard entra sobre o guard da base (fluxo acrescentara isPublished/manualWriteRefusal antes do merge; o merge resolve a ordem do parecer, conflito 9). Use CompetenceCalendar::lastClosedMonth() (e currentMonth() se precisar) em vez de criar BusinessTime::previousMonthStart (conflito 21). Nao implemente a pre-checagem de republish (e da extemporaneos). Siga a MIGRATION REVIEW para a migration de dados do activity_log (lotes por PK, idempotente).`,
  'automacao-operacao': `Voce trabalha em PARALELO, a partir da base. Nao edite relatorio, PDF nem Recebivel e nao crie CancelledCompetenceLookup (garantias e a dona, conflito 7). A reabertura de competencia cancelada precisa recusar quando houver competencia posterior PUBLICADA do mesmo empreendimento (conflito 3): como PublishedCompetenceBoundary ainda nao existe (sera criada pela frente baixa-unidade), implemente a checagem por consulta direta a sales_board_publications de ciclos do mesmo construction_id com reference_month posterior, num metodo privado isolado, e registre em integration_notes para o merge trocar pela classe. O evento SalesBoardPriorPositionChanged e da extemporaneos e ainda nao existe: nao crie; registre em integration_notes que a reabertura deve dispara-lo depois do commit. Inclua tests/Feature/ContractInstallmentImportScaleTest.php no composer test:bench e no workflow de benchmark mesmo que o arquivo ainda nao exista nesta copia (vem da importacoes). Migration de reabertura com guarda por etapa (colunas por hasColumn, FK por colunas em getForeignKeys).`,
  'fluxo-ux-governanca': `Construa sobre autorizacao-trilha (ja integrada: SalesBoardAccess, GuardsRelationManagerAccess, assinaturas com ator, helpers protected). Nao edite SalesBoardIssuePresenter (as dicas vao para regras). closedRoundIds() com Returned (a extemporaneos acrescenta Approved depois, conflito 4). Anexo da Validacao com rotulo em ActivityResource::friendlySubjectType ('Anexo da Validacao da Construtora', lacuna do parecer). Novas superficies usam SalesBoardAccess (download do anexo: canView; supersedeIfOutdated: authorizeOperationOrApproval) e helpers novos do Blade nascem protected (conflito 18). Antivirus: reutilize ScansUploadedFile/ClamAvFileScanner com a politica de config/uploads.php (clamav.enabled); nao crie politica propria. SalesBoard::publications()/currentPublication() conforme conflito 8 (nao HasOne simples).`,
  'regras-apuracao': `Construa sobre autorizacao-trilha, fluxo-ux-governanca e importacoes (ja integradas). Voce e dona de SalesBoardIssueCode e SalesBoardIssuePresenter (isVisibleToBuilder, groupFrozen, dicas unificadas, inclusive a de EXCHANGE_SOURCE_MISSING do parecer), de SalesBoardPlausibility como fonte unica (ajuste o SpreadsheetPlausibility da importacoes para usar suas constantes e a regra 'a importacao nunca e mais permissiva que um bloqueador', conflito 6), de ExchangeContractRecognizer (conflito 8), do desconto sobre o importador reescrito (conflito 17: StoredInstallment, digest, SpreadsheetAmount, insert em lote, modelo da planilha, relatorio de ausentes com grupo 'parcialmente pagas'), do Blocked na volta do distrato de permuta no ContractReconciler (conflito 19) e de end()/substitute() sobre o predicado initialPositionIsFrozen() da fluxo (conflito 5). Writer com $frozenWarnings (conflito 13).`,
  'baixa-unidade': `Construa sobre as frentes ja integradas. PublishedCompetenceBoundary com semantica de PUBLICACAO (whereExists em sales_board_publications) e os 3 metodos do parecer (conflito 1); ordem de lock dos ciclos do mes mais recente para o mais antigo (conflito 2); classifyUnitAt unico na derivacao (conflito 10); checagens de baixa no ConstructionUnitExchangeService (registerExtraordinary, substitute, declareBaseline) e no ContractBatchProjection; aviso 'unidade_baixada' na importacao de valores reescrita (conflito 22); isVisibleToBuilder dos 3 casos e mensagem de UNIT_RETIRED sem motivo (conflito 12); RM de baixas com GuardsRelationManagerAccess (conflito 18); migrations com guardas hasTable/FK. O teste mysql 'baixa em M-1 x aprovacao de M' fica para a extemporaneos (que cria a trava da aprovacao); voce garante a ordem de lock.`,
  'extemporaneos-retificacao': `Construa sobre TODAS as outras frentes (ja integradas). Siga os conflitos 1, 3, 4, 8, 9, 10, 11, 12, 13, 14 e 16 do parecer: cadeia de publicacoes (UNIQUE antes da FK de supersedes), ancora, extemporaneos (exclusao D3 em toda a janela; checagens de movimento viram LATE_* em extemporaneo/revisao_venda), ponte (usa baseline->warnings para baixa/reativacao), retificacao (republish com a pre-checagem de garantias; maker/checker de quem abriu), closedRoundIds com Approved e SalesBoardOpenReviewsSuperseder, recusa de cancelamento com publicacao, evento SalesBoardPriorPositionChanged (inclusive o disparo na reabertura da automacao), regra de ordem da aprovacao, relatorio de negociacoes sobre movimentos congelados usando ExchangeContractRecognizer, aviso por linha 'registered_competence_notice' na importacao (conflito 16), orcamentos finais de consultas (derivacao 8/9 com ancora, observacao 7), testes mysql de corrida do cancelamento e de baixa em M-1 x aprovacao de M, mensagem propria para rodadas 'retificacao_desistida' (lacuna) e DECLARATION_VERSION da aprovacao.`,
}

async function implement(front, copy, done, later, phaseName) {
  let last = null
  for (let attempt = 1; attempt <= 3; attempt++) {
    const extra = last && last.status === 'parcial'
      ? `\n\n## Continuacao (tentativa ${attempt})\nUma tentativa anterior desta frente terminou parcial. Pendencias declaradas:\n- ${last.remaining_items.join('\n- ')}\nProblemas conhecidos: ${JSON.stringify(last.known_issues)}\nContinue a partir do estado commitado e do que estiver nao commitado na copia.`
      : ''
    const r = await agent(`${context(copy, front, done, later)}

## Ajustes do orquestrador para esta frente
${ADJUST[front]}${extra}`, { label: `impl:${front}${attempt > 1 ? ':' + attempt : ''}`, phase: phaseName, schema: IMPL_SCHEMA })
    if (!r) {
      log(`impl:${front} tentativa ${attempt} sem resultado`)
      continue
    }
    last = r
    log(`impl:${front} tentativa ${attempt}: ${r.status} | ${r.suite_result}`)
    if (r.status === 'completo') break
  }
  return last
}

async function merge(front, srcCopy, dstCopy, done, later) {
  const r = await agent(`${context(dstCopy, 'merge de ' + front, done, later)}

## Sua tarefa: integrar a frente "${front}" na copia principal
A frente "${front}" foi implementada em paralelo, a partir da base, na copia ${srcCopy} (repositorio de rascunho proprio). A copia principal ${dstCopy} ja tem: ${done.join(', ')}.
1. Gere o patch da frente: BASE=$(git -C ${srcCopy} rev-list --max-parents=0 HEAD); git -C ${srcCopy} diff --binary $BASE HEAD > /tmp/claude-1000/-home-anderson-projects-NimbusPlatform/cdf9b81d-0ff6-4141-b732-101e8c20ace4/scratchpad/merge-${front}.patch. Confira tambem git -C ${srcCopy} status (nao deve haver nada fora do commit; se houver, inclua no patch com git diff HEAD).
2. Aplique na principal: cd ${dstCopy} && git apply --3way --whitespace=nowarn <patch>. Resolva TODOS os conflitos entendendo as duas frentes (specs e parecer), seguindo a FILE OWNERSHIP e as resolucoes de conflito; nunca descarte comportamento de nenhum dos lados sem motivo do parecer.
3. Leia as integration_notes da frente (abaixo) e aplique as trocas pedidas (ex.: trocar consultas provisorias pelas classes do contrato compartilhado que ja existem na principal).
4. Ajuste testes das duas frentes que quebrarem pela combinacao, conforme as convencoes do parecer (conflito 20), sem enfraquecer o que provam.
5. Rode a suite do Quadro e as suites tocadas pela frente integrada; meta zero falhas. Pint nos arquivos PHP alterados no merge.
6. Commit: git -C ${dstCopy} add -A && git -C ${dstCopy} -c user.email=scratch@local -c user.name=scratch commit -q -m "merge: ${front}".
Resultado da frente integrada (resumo, notas de integracao e pendencias):
${JSON.stringify(arguments[5] || {}, null, 1)}`, { label: `merge:${front}`, phase: 'Cadeia principal', schema: IMPL_SCHEMA })
  log(`merge:${front}: ${r ? r.status + ' | ' + r.suite_result : 'sem resultado'}`)
  return r
}

const Y = P.copyY
const Z = P.copyZ
const W = P.copyW
const V = P.copyV

const ORDER = ['autorizacao-trilha', 'fluxo-ux-governanca', 'importacoes', 'regras-apuracao', 'baixa-unidade', 'garantias-relatorio', 'automacao-operacao', 'extemporaneos-retificacao']
const after = k => ORDER.slice(ORDER.indexOf(k) + 1)

phase('Trilhas paralelas')
const zP = implement('importacoes', Z, [], ORDER.filter(k => k !== 'importacoes'), 'Trilhas paralelas')
const wP = implement('garantias-relatorio', W, [], ORDER.filter(k => k !== 'garantias-relatorio'), 'Trilhas paralelas')
const vP = implement('automacao-operacao', V, [], ORDER.filter(k => k !== 'automacao-operacao'), 'Trilhas paralelas')

phase('Cadeia principal')
const results = {}
results['autorizacao-trilha'] = await implement('autorizacao-trilha', Y, [], after('autorizacao-trilha'), 'Cadeia principal')
results['fluxo-ux-governanca'] = await implement('fluxo-ux-governanca', Y, ['autorizacao-trilha'], after('fluxo-ux-governanca'), 'Cadeia principal')

const z = await zP
results['importacoes'] = z
results['merge:importacoes'] = z
  ? await merge('importacoes', Z, Y, ['autorizacao-trilha', 'fluxo-ux-governanca'], after('importacoes'), z)
  : null
if (!z) log('importacoes sem resultado: merge pulado')

results['regras-apuracao'] = await implement('regras-apuracao', Y, ['autorizacao-trilha', 'fluxo-ux-governanca', 'importacoes'], after('regras-apuracao'), 'Cadeia principal')
results['baixa-unidade'] = await implement('baixa-unidade', Y, ['autorizacao-trilha', 'fluxo-ux-governanca', 'importacoes', 'regras-apuracao'], after('baixa-unidade'), 'Cadeia principal')

const w = await wP
results['garantias-relatorio'] = w
results['merge:garantias-relatorio'] = w
  ? await merge('garantias-relatorio', W, Y, ['autorizacao-trilha', 'fluxo-ux-governanca', 'importacoes', 'regras-apuracao', 'baixa-unidade'], after('garantias-relatorio'), w)
  : null
if (!w) log('garantias-relatorio sem resultado: merge pulado')

const v = await vP
results['automacao-operacao'] = v
results['merge:automacao-operacao'] = v
  ? await merge('automacao-operacao', V, Y, ['autorizacao-trilha', 'fluxo-ux-governanca', 'importacoes', 'regras-apuracao', 'baixa-unidade', 'garantias-relatorio'], after('automacao-operacao'), v)
  : null
if (!v) log('automacao-operacao sem resultado: merge pulado')

results['extemporaneos-retificacao'] = await implement('extemporaneos-retificacao', Y, ORDER.filter(k => k !== 'extemporaneos-retificacao'), [], 'Cadeia principal')

return results
