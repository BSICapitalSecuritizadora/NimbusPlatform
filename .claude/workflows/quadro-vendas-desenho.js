export const meta = {
  name: 'quadro-vendas-desenho',
  description: 'Desenho das correcoes para completar o Quadro de Vendas (8 frentes + juiz de consistencia), somente leitura',
  whenToUse: 'Produzir especificacoes concretas antes de implementar as correcoes do Quadro de Vendas',
  phases: [
    { title: 'Desenhar', detail: 'um projetista por frente le o codigo e especifica' },
    { title: 'Consolidar', detail: 'juiz confere conflitos e aderencia as decisoes' },
  ],
}

const A = args || {}
const BASE = A.base
const WS = A.workspace
const FINDINGS = A.findings
const LOW = A.low
const MEM = A.memoryDir

const CONTEXT = `Data: 2026-09-30. Escreva em portugues (pt-BR) nos campos descritivos; nomes de codigo como estao.

## Missao
O dono (Anderson) pediu: "Faca todos os ajustes para o modulo [Quadro de Vendas] ficar completo". Sobre os pontos que dependiam de decisao dele, disse: "os demais pontos pode corrigir eles da melhor maneira". Ou seja, as pendencias agora sao decididas por nos, escolhendo a opcao mais robusta, conservadora e coerente com a arquitetura e com as decisoes anteriores dele. Sobre o disco temporario de upload, ele informou que no App Service existem FILESYSTEM_DISK=azure e outra variavel de disco = local (provavelmente PRIVATE_FILESYSTEM_DISK=local); LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK nao existe la, entao o bug do disco temporario esta confirmado.
Esta etapa e SO DE DESENHO: voce le o codigo e produz uma especificacao concreta e implementavel. Nao implemente.

## Onde ler
- Copia limpa do origin/main atual (6500958: Laravel 13.34, Filament 5.9.0, Livewire 4.4.7, activitylog 5.1.1, permission 8.3.0, Pest 5) com vendor proprio: ${BASE}. Leia o codigo DAQUI. Pode rodar testes daqui (cd ${BASE} && php artisan test --compact <arquivo>) e criar probes NOVOS so em ${BASE}/tests/Feature/Probe/<seu-rotulo>/ (sob tests/Feature para herdar o TestCase). NAO altere arquivos existentes em ${BASE} (e compartilhada).
- Workspace compartilhado ${WS}: NAO toque (outras sessoes trabalham la). Nem leitura e necessaria: use a copia limpa.
- Achados da auditoria de 30/09 (JSON completo com evidencias e vereditos): ${FINDINGS}. Achados baixos/info resumidos: ${LOW}. Procure pelos ids citados na sua frente.
- Memorias do projeto (armadilhas ja conhecidas; leia as que tocam sua frente): ${MEM}/*.md, em especial activitylog-v5-migracao.md, activitylog-log-name-default-retencao.md, mysql-prod-sqlite-testes.md, filament-relation-manager-view-page.md, relation-manager-lazy-load-pula-canviewforrecord.md, filament-acao-desabilitada-com-tooltip.md, filament-shared-schema-record-typehint.md, filament-deleteaction-status-padrao-sucesso.md, filament-assert-notified-consome-sessao.md, flag-de-seguranca-nunca-env-cru.md, seeder-syncpermissions-revoga-migration.md, testes-grupo-mysql-vazam-dados.md, fuso-tecnico-utc-fuso-negocio-brt.md, sales-board-chave-natural-vs-reader.md, sales-board-decisoes-governanca.md, sales-board-auditoria-prontidao.md, livewire-disco-temporario-azure.md, politica-desconto-periodo-substituicao.md, fk-delegacoes-restrict.md, laravel-event-discovery-duplica-listener.md, filament-datepicker-estado-com-hora.md, pest-dataset-closure-tipado-nao-resolve.md, storage-fake-raiz-por-processo.md.

## Decisoes do dono que NAO podem ser revertidas (25/09)
(1) permissao sales-boards.approve para decidir excecoes, aprovar/publicar e aprovar/ativar rollout; admin recebe, editor nao; maker/checker: quem aprova nao pode ser quem enviou a validacao nem quem abriu a homologacao; super-admin isento. (2) garantias: usuario escolhe a competencia (padrao mes anterior no fuso de negocio); fechar com obra sem quadro do mes ou com posicao transportada exige confirmacao explicita gravada no snapshot; publicar um quadro depois marca o snapshot como desatualizado; "Reabrir" com permissao propria. (3) permuta extraordinaria e encerrar permuta com a Emissao em operacao: motivo obrigatorio, autor, auditoria e permissao de aprovacao do Quadro. (4) cancelar competencia so para ciclo nao aprovado, com motivo, autor e trilha; substitui revisoes abertas e encerra o alvo da automacao; gerar ciclo exige competencia coberta pela automacao. Voce pode ESTENDER (ex.: caminho de reabertura, retificacao) desde que nao contradiga.

## Restricoes tecnicas
- Producao: App Service unico, MySQL 8.4 com DADO REAL; o startup roda migrate --force a cada deploy. Toda migration precisa ser segura com dado existente: aditiva, colunas nullable ou com default, sem backfill pesado no startup, sem lock longo em tabela grande, idempotente quando fizer DDL condicional; nada que falhe se ja houver dado "estranho". Nunca proponha escrever em producao.
- Testes rodam em SQLite em memoria; o schema precisa valer nos dois bancos (sem indice unico parcial; use coluna gerada storedAs + unique quando precisar; nomes de indice <= 64 chars).
- Fuso: persistencia UTC; dia/mes de negocio via App\\Support\\BusinessTime (America/Sao_Paulo). Dinheiro em centavos inteiros (App\\Support\\Money\\IntegerMoney).
- Activitylog v5: trait Spatie\\Activitylog\\Models\\Concerns\\LogsActivity, LogOptions em Spatie\\Activitylog\\Support\\LogOptions, dontLogEmptyChanges(); alteracoes em attribute_changes; trilha regulada precisa de useLogName() com nome listado em config/audit.php (protected_logs), senao expira em 365 dias.
- Permissoes: enum AccessPermission e o RolesAndPermissionsSeeder (syncPermissions) sao a fonte de verdade; permission nova para role nao-admin precisa estar no seeder e numa migration; RolesAndPermissionsConvergenceTest amarra.
- Flags/limiares de config nunca com env() cru: interpretar no config e falhar fechado (SalesBoardAutomationConfig).
- Filament 5.9: acao customizada so e recusada no servidor se tiver visible()/hidden()/disabled()/authorize() avaliados no mount; o Blade esconder botao nao protege. RelationManager sem Policy libera tudo. Lazy-load de RM pula canViewForRecord.
- Convencoes do projeto: Pest; codigo e comentarios seguem o estilo dos arquivos irmaos (docblocks em portugues explicando o porque); use o Skill tool para carregar laravel-best-practices e testing-best-practices se estiver disponivel.

## Saida
Especificacao concreta: cada decisao com o achado que resolve, a regra escolhida e o porque (e alternativas rejeitadas); lista de arquivos a mudar/criar com o que muda; migrations (DDL e analise de seguranca MySQL com dado real); novos identificadores (casos de enum, codigos de issue, permissoes, chaves de config, log names, colunas, classes); testes a escrever (arquivo e casos); interacoes com as outras frentes (arquivos e enums compartilhados); riscos. Pergunte ao dono (owner_questions) SO o que for impossivel decidir com seguranca; o padrao e decidir.
As outras frentes em paralelo: autorizacao-trilha, importacoes, regras-apuracao, extemporaneos-retificacao, baixa-unidade, automacao-operacao, garantias-relatorio, fluxo-ux-governanca.`

const SPEC_SCHEMA = {
  type: 'object',
  properties: {
    topic: { type: 'string' },
    summary: { type: 'string' },
    decisions: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          finding_ids: { type: 'array', items: { type: 'string' } },
          decision: { type: 'string' },
          rationale: { type: 'string' },
          rejected_alternatives: { type: 'string' },
        },
        required: ['finding_ids', 'decision', 'rationale', 'rejected_alternatives'],
      },
    },
    changes: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          kind: { type: 'string', enum: ['edit', 'new', 'migration', 'test', 'config', 'view', 'css', 'seeder'] },
          description: { type: 'string' },
        },
        required: ['file', 'kind', 'description'],
      },
    },
    schema_changes: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          migration_name: { type: 'string' },
          table: { type: 'string' },
          ddl_summary: { type: 'string' },
          mysql_safety: { type: 'string' },
        },
        required: ['migration_name', 'table', 'ddl_summary', 'mysql_safety'],
      },
    },
    new_identifiers: {
      type: 'array',
      items: {
        type: 'object',
        properties: { kind: { type: 'string' }, name: { type: 'string' }, where: { type: 'string' } },
        required: ['kind', 'name', 'where'],
      },
    },
    tests: {
      type: 'array',
      items: {
        type: 'object',
        properties: { file: { type: 'string' }, cases: { type: 'array', items: { type: 'string' } } },
        required: ['file', 'cases'],
      },
    },
    interplay: { type: 'array', items: { type: 'string' } },
    risks: { type: 'array', items: { type: 'string' } },
    owner_questions: { type: 'array', items: { type: 'string' } },
    estimated_size: { type: 'string', description: 'P, M, G ou GG, com uma frase' },
  },
  required: ['topic', 'summary', 'decisions', 'changes', 'schema_changes', 'new_identifiers', 'tests', 'interplay', 'risks', 'owner_questions', 'estimated_size'],
}

const TOPICS = [
  {
    key: 'autorizacao-trilha',
    prompt: `Achados (ids nos arquivos de achados): acoes-validacao-sem-autorizacao/equivalentes (as 5 acoes do BuilderReviewWorkspace: confirmSection, reopenSection, declareDivergence, removeDivergence, submitReview; servicos SalesBoardBuilderReviewEditor e SalesBoardBuilderReviewSubmissionService sem checagem); 'Analisar diferenca' (acceptDifference) do ManageSalesBoardRollout e SalesBoardRolloutHomologationService::acceptDifference sem checagem; secoes e divergencias sem trilha (SalesBoardBuilderDivergence e SalesBoardBuilderReviewSection sem LogsActivity; remocao e delete fisico); relation-managers-sem-portao-proprio; metodos-publicos-devolvem-models (emission(), recipients(), currentHomologation() do rollout); permissao-criar-escreve-alem-de-criar (importacoes atualizam com *.create; assistente da Emissao grava Quadro inicial com emissions.create); verificar-alteracoes-grava-para-consulta; perfil-so-gestao-sem-abrir-analise-e-cancelar (OpenManagementReviewAction e CancelSalesBoardCycleAction exigem update; SalesBoardManagementReviewOpeningService sem checagem); testes de chamada forjada ausentes para acoes de preparo.
Direcao preferida (valide no codigo): autorizar no servidor com a mesma regra de canEdit() (sales-boards.update) nas 5 acoes e nos servicos; acceptDifference exige sales-boards.update no servidor e no servico; LogsActivity com log name protegido em secao e divergencia (e trilha da remocao); RMs do modulo com canViewForRecord explicito e checagem no caminho de renderizacao (trait reutilizavel); metodos publicos que devolvem models viram protected ou propriedades computadas nao chamaveis; importar exige create E update; Quadro inicial do assistente exige sales-boards.create (UI e servidor); 'Verificar alteracoes' so para quem tem update ou approve, com tooltip corrigido; abrir a Analise da Gestao permitido para update ou approve (checado no servico) e 'Cancelar competencia' visivel para quem tem approve; testes de chamada forjada (Livewire e, se viavel, pela rota real de update) para cada acao de preparo. Diga exatamente quais testes existentes precisam mudar.`,
  },
  {
    key: 'importacoes',
    prompt: `Achados: lacuna-importacoes-disco-temporario-azure (todos), lacuna-ingestao-escala-limites-web (todos), ordenacao quebrada das conferencias de contratos/unidades/valores, lacuna-ingestao-ausencias-nao-reconciliadas (parcelas e contratos ausentes do arquivo), texto-tres-casas-abaixo-de-mil-lido-como-milhar, reimportacao que nao desfaz casos do parser antigo (vigencia errada com o mesmo valor da 'sem alteracao'), csv-anunciado-recusado-no-upload, proveniencia-so-por-horario, falta de teste de custo por requisicao e de teste com disco nao local.
Direcao preferida (valide no codigo e meca com probe): (a) config/livewire.php com disco temporario padrao 'local' (env continua sobrescrevendo) + um helper unico que devolve um caminho LOCAL legivel para qualquer TemporaryUploadedFile (se o disco nao for local, copia por stream para arquivo temporario local e limpa depois), usado por todos os assistentes (parcelas na listagem e no contrato, contratos, unidades, valores, clientes) e pelo import de Recebiveis; comando artisan para expurgar livewire-tmp antigos num disco dado (dry-run por padrao) para a operacao limpar o container publico; (b) escala: analise de parcelas com memoria limitada (contadores + listas de previa limitadas + mapa compacto do que existe), memoizada por hash sha256 do conteudo (nao pelo caminho) e reaproveitada entre as requisicoes do assistente (upload, Proximo, Confirmar) via cache/arquivo local com TTL, eliminando a dupla analise do Confirmar; meta: 172.800 linhas dentro de 256M e bem abaixo de 120 s por requisicao; importacao grava em chunks relendo o arquivo por stream; (c) comparadores de 2 argumentos nas previas de contratos, unidades e valores; (d) ausencias: a conferencia de parcelas lista as parcelas cadastradas ausentes do arquivo para os contratos presentes nele (pagas e em aberto), e oferece opcao explicita, desmarcada por padrao, de registrar cancelamento das ausentes EM ABERTO com data (padrao: dia de negocio) e motivo, gravando na trilha e no ImportRun; contratos ausentes do arquivo (mesmo escopo de Emissao/obra do arquivo) aparecem como aviso, sem distrato automatico; (e) texto: virgula e separador decimal pt-BR; ponto seguido de exatamente 3 digitos e ambiguo -> aviso na conferencia mostrando o valor interpretado; plausibilidade na conferencia (pago muito acima do previsto, previsto maior que a venda, venda fora de faixa do valor base da unidade, ano < 1990) como aviso, e o claramente impossivel como erro de linha; (f) vigencia: comparar tambem a data de referencia; (g) CSV: decidir entre aceitar csv/txt na regra temporaria do Livewire ou tirar text/csv dos campos, conforme o que os leitores suportam; (h) proveniencia: avaliar import_run_id nullable em contracts e contract_installments (ADD COLUMN INSTANT no MySQL 8.4) e ImportRun para unidades/valores. Meca memoria e tempo antes/depois com probe em volume (use NIMBUS_BENCH ou probe proprio).`,
  },
  {
    key: 'regras-apuracao',
    prompt: `Achados: parcelas em aberto canceladas num mes anterior ao distrato geram 'Quitacao do mes' falsa; parcela paga abaixo do previsto nunca conta como paga (contrato 100% pago com desconto fica financiado para sempre); 'Encerrar permuta' numa unidade com contrato de permuta bloqueia todas as competencias seguintes da obra; distrato-de-contrato-de-permuta-vira-movimento; permuta-valor-zero-aceita/permuta-valor-zero-sem-achado; distratado-sem-data-ocupa-unidade; avisos nao bloqueantes da derivacao so aparecem na CLI; e a lacuna-dados-producao-antes-da-correcao-do-parser (a derivacao nao acusa venda x1000, venda no ano 0026, parcela x1000, vigencia em 1970).
Direcao preferida (valide no codigo): (1) contrato com data de distrato nunca vira 'quitado' por causa de parcelas canceladas: para contrato distratado, parcela cancelada conta como em aberto nas competencias anteriores ao distrato; para contrato nao distratado (renegociacao), a parcela cancelada continua fora; (2) parcela 'baixada' = data de pagamento + valor pago > 0 conta como paga; pago abaixo do previsto gera aviso informativo (codigo novo) com quantidade e diferenca, visivel na tela; (3) encerrar permuta com contrato de permuta vigente: o servico distrata o contrato de permuta na mesma data e na mesma transacao, com motivo e trilha (ou recusa com explicacao clara se houver algo que impeca), e a derivacao nao conta distrato de contrato de permuta em 'Distratos do mes' (simetria com vendas); (4) permuta com valor zero = ausencia de valor, com issue bloqueante como no valor da unidade, e formularios/servico recusando 0; (5) plausibilidade na derivacao: codigos novos para venda com valor fora de faixa em relacao ao valor de referencia da unidade (bloqueante quando a razao indica erro de escala, ex. >100x ou <0,01x; aviso em faixa mais estreita), datas anteriores a 1990 em venda, pagamento, distrato e vigencia (bloqueante), previsto de parcela maior que o valor da venda (aviso), distratado sem data (bloqueante); (6) avisos visiveis na UI: onde a derivacao guarda os avisos por versao e como mostrar em ciclo, Validacao, Analise e homologacao do rollout, com o SalesBoardIssuePresenter. Defina os limiares exatos, justifique, e liste os testes existentes que mudam.`,
  },
  {
    key: 'extemporaneos-retificacao',
    prompt: `Achados: lacuna-fatos-retroativos-apos-publicacao (todos: fato com data em competencia publicada muda o balde do mes seguinte sem movimento; venda atrasada abaixo do piso escapa da conformidade e da Gestao; nenhum sinal automatico e 'Verificar alteracoes' manda recalcular o que nao pode; contrato e parcela aceitam data em competencia publicada; relatorio, Recebivel e garantias nao fecham mes a mes); 'Quadro publicado nao tem caminho de correcao'; relatorio-conta-permuta-como-venda; o PDF de competencia passada le as negociacoes ao vivo ao lado das unidades publicadas.
Direcao preferida (valide profundamente no codigo, este e o desenho mais delicado): (A) movimentos extemporaneos: ao derivar a competencia M, comparar a classificacao ao vivo de cada unidade no fim da ultima competencia aprovada P do empreendimento com a classificacao congelada aprovada de P (linhas do ciclo aprovado); cada diferenca vira movimento de M marcado como extemporaneo, com a competencia de origem (mes da data do fato) e o tipo pela transicao (venda, distrato, quitacao); venda extemporanea passa pela conformidade de preco (politica vigente na data da venda) e vira pendencia da Gestao quando nao conforme/indeterminada; a Validacao e a Analise mostram esses movimentos destacados; o reconciliador de baldes passa a explica-los; so vale quando P e competencia automatizada com linhas congeladas (legado manual nao tem linhas por unidade: defina o comportamento). (B) retificacao: 'Retificar competencia' so para a ULTIMA competencia aprovada de cada empreendimento, por quem tem sales-boards.approve, com motivo; abre nova versao (recalculo em modo retificacao), passa por Validacao e Analise com maker/checker, e a nova publicacao substitui a anterior com historico (SalesBoardHistory/SalesBoardPublication), marca garantias desatualizadas (invalidador existente) e deixa as competencias seguintes nao aprovadas desatualizadas (fingerprint inclui a versao publicada de P); nada apaga a publicacao anterior. (C) formularios e importacoes de contrato e parcela avisam (nao bloqueiam) quando a data cai em competencia publicada: 'entrara como extemporaneo em MM/AAAA; para corrigir a competencia publicada use Retificar'. (D) 'Verificar alteracoes' numa competencia aprovada explica isso em vez de mandar recalcular. (E) relatorio mensal: competencia com ciclo aprovado usa os movimentos congelados (inclusive extemporaneos, rotulados) e nao as negociacoes ao vivo; contrato de permuta nao conta como 'Venda (mes)'. Verifique maquina de estados do ciclo, versoes/baselines, SalesBoardPublicationService/Projection, SalesBoardWriteGuard, guardas de imutabilidade, SalesBoardHistory, Reader, consumidores (EmissionMonthlyReportService, ContractNegotiationEvents, Recebivel, garantias). Diga como a retificacao convive com Cancelar competencia e com a automacao.`,
  },
  {
    key: 'baixa-unidade',
    prompt: `Achados: 'Unidade errada vira permanente: nao ha baixa/inativacao e Correcao necessaria nao tem saida' (alto), unidade-fora-do-arquivo-sem-baixa, falta de tipo de divergencia 'unidade inexistente'.
Direcao preferida (valide no codigo): baixa (inativacao) de unidade com data de efeito (dia de negocio), motivo obrigatorio, autor, trilha em log protegido, e reativacao registrada; regras: nao pode baixar unidade com contrato vigente ou permuta vigente na data; a data de efeito nao pode cair em competencia ja aprovada/publicada (mesma guarda da permuta extraordinaria); permissao constructions.update e, quando a Emissao estiver sob o Quadro automatizado ou a unidade tiver ancoras (ciclo, historico de valor), tambem sales-boards.approve; a derivacao exclui das competencias posteriores a data de efeito a unidade baixada (sem apagar historico) e registra um aviso informativo na competencia da baixa; o fingerprint da fonte inclui os campos da baixa para a deteccao de desatualizacao; a exclusao continua recusada para unidade ancorada; novo tipo de divergencia 'Unidade inexistente'; o fluxo 'Correcao necessaria' passa a ter saida (baixar a unidade e recalcular). Defina colunas (construction_units) ou tabela propria append-only (avalie) e o impacto em ConstructionUnitPolicy, SalesBoardSourceGuard, ConstructionUnit (troca de obra), telas de unidade, importacao de unidades, garantias (valor de estoque), relatorio.`,
  },
  {
    key: 'automacao-operacao',
    prompt: `Achados: emissao-liquidada-sem-parada; lembretes nulos por padrao (ninguem e avisado); alvo-satisfeito-por-ciclo-cancelado; competencia cancelada nao pode ser gerada de novo (cancelamento-irreversivel-sem-quadro, competencia-cancelada-sem-volta, competencia-automatizada-cancelada-sem-publicacao); reference-month-cli-formatos-ambiguos; duration-ms-em-segundos; corrida do $now x first_attempt_at (teste-quadro-intermitente-virada-de-segundo, teste-lembrete-intermitente) em codigo e testes; sem-sinal-de-vida-do-agendador; seeder-demo-sem-trava-de-producao; interruptor-global-nao-para-a-emissao (texto enganoso); homologacao-aceita-emissao-em-elaboracao; previa-diagnostico-so-cli; benchmarks-escala-fora-do-ci.
Direcao preferida (valide no codigo): (1) Emissao liquidada (status de encerramento; descubra o enum e se ha data) para de gerar: a descoberta nao cria alvo depois da liquidacao e os pendentes sao encerrados com motivo proprio, com aviso; (2) limiares padrao de lembrete definidos no config (o dono delegou): proponha valores (ex.: pronta para a construtora 0 dia, bloqueada 1 dia, falha 3 tentativas, validacao 5/10 dias, analise 3/7 dias), mantendo parse que falha fechado e env sobrescrevendo; atualize .env.example e testes; (3) processador trata ciclo cancelado como encerramento (CompetenceCancelled), nao satisfacao; (4) caminho de volta apos cancelar: avalie 'Reabrir competencia cancelada' (volta o mesmo ciclo a estado recalculavel, por sales-boards.approve, com motivo e trilha) contra 'Regerar' (novo ciclo; exigiria mudar a unique construction_id+reference_month com coluna gerada). Prefira o que nao mexe na unique se for seguro; (5) CLI aceita so mm/aaaa e aaaa-mm; (6) duracao medida com relogio de alta resolucao e gravada (coluna nullable) ou calculada no processo; (7) usar o mesmo $now em first_attempt_at e nos filtros de lembrete, e congelar relogio nos testes intermitentes; (8) batimento do agendador visivel na tela 'Automacao do Quadro' (tarefa agendada a cada minuto gravando timestamp em cache), sem mexer no /healthcheck; (9) DatabaseSeeder recusa semear dados de demonstracao em producao; (10) textos do interruptor corrigidos (o freio de uma Emissao e 'Retornar ao modo legado'); (11) abrir/aprovar homologacao e ativar recusam Emissao em elaboracao; (12) previa de prontidao e diagnostico de posicao acessiveis pela UI somente leitura para quem tem sales-boards.view (avalie custo); (13) benchmarks: decidir se entram no CI (sem tornar o gate instavel) ou num comando/checklist.`,
  },
  {
    key: 'garantias-relatorio',
    prompt: `Achados: trilha de fechar/reabrir competencia de Garantias em log nao protegido (expira em 365 dias); obra-sem-quadro-ate-competencia-sem-confirmacao; informar-valor-mes-corrente; snapshots anteriores a de156e7 com sales_board_coverage nulo ignorados pelo invalidador; troca-fonte-pu-nao-marca-competencia (homologar/invalidar curva de PU muda o saldo devedor sem marcar a competencia); pdf-mensal-sem-secao-de-garantias; selo-pendente-some-sem-fechar; competencia-automatizada-cancelada-sem-publicacao (rotulo nos consumidores); relatorios-competencia-padrao-utc; e 'Model e unique aceitam um segundo quadro do mesmo empreendimento e mes sob outra Emissao' (dupla contagem).
Direcao preferida (valide no codigo): log names protegidos para toda a trilha de competencia de garantias; obra sem quadro ate a competencia tambem exige a confirmacao explicita; 'Informar valor do mes' com padrao no mes de negocio anterior; snapshot com cobertura nula tratado como desconhecido (marcado desatualizado quando um quadro da Emissao/competencia for publicado); homologar/invalidar curva de PU marca as competencias de garantias afetadas como desatualizadas com motivo proprio (por evento, minimizando mudanca no dominio do PU); PDF: decidir entre incluir a secao de garantias (com as marcas de parcial e desatualizada) ou retirar o calculo do relatorio (leia o template, o commit 08b6f85 e quem recebe o relatorio; escolha e justifique); selo 'Pendente' so some com a competencia fechada; competencia cancelada rotulada como cancelada nos consumidores (PDF, Recebivel), mostrando a posicao transportada; tela e controller de Relatorios com competencia padrao no mes de negocio anterior (BusinessTime); guarda no model/servico que recusa segundo quadro do mesmo empreendimento e mes sob outra Emissao (sem constraint de banco que possa falhar com dado existente), mais o que fazer se ja existir duplicidade (diagnostico existente).`,
  },
  {
    key: 'fluxo-ux-governanca',
    prompt: `Achados: atestacao de impacto sobrevive a reavaliacao que muda o retrato; atestacao-com-instancia-velha-regrava-homologacao-aprovada; motivo-de-devolucao-antigo-em-rodada-de-versao-nova; homologacao-superseded-nunca-gravada; 'A validacao da construtora e feita por usuario interno, sem canal externo e sem evidencia anexada'; 'Voltar a Emissao para Em Elaboracao libera ao editor a permuta sem sales-boards.approve'; 'Politica de desconto retroativa (emissions.update) apaga a excecao antes de a Gestao decidir' (e a permissao da politica, item nao decidido); textos-governanca-enviar-pontuacao; ordem-lexicografica-unidades; titulo-invisivel-tema-claro; select-nativo-texto-branco-tema-claro; 'Validacao com 800 unidades: pagina de 33 mil px ... ~870 KB por clique'; aprovacao-recalcula-posicao-varias-vezes; nova-atualizacao-quadro-publicado e acao-oferecida-e-recusada-no-fim; isencao-super-admin-diverge-pu (comentario desatualizado).
Direcao preferida (valide no codigo): reavaliacao que muda o retrato zera a atestacao (como o aceite de diferenca); markReviewed com transacao, lock e releitura; motivo de devolucao so na rodada criada pela devolucao sobre a mesma versao; status Superseded gravado quando a fonte muda depois da aprovacao da homologacao (na recusa da ativacao ou na reavaliacao), com a tela coerente; evidencia da Validacao: ao enviar, exigir identificacao de quem respondeu pela construtora (nome, e-mail), canal, data do recebimento e anexo da resposta (disco privado), exibidos na Analise; o canal externo proprio da construtora (portal) fica fora deste pacote e deve ser dito; permuta 'inicial' (nao extraordinaria) recusada para unidade com ancora em ciclo/publicacao, independentemente do status da Emissao (fecha o atalho de voltar para Em Elaboracao); politica de desconto retroativa (alcanca competencia ja gerada/congelada) exige sales-boards.approve no servidor, prospectiva continua com emissions.update, e o registrar confere permissao no servidor; rotulos 'Aprovar e publicar' e 'Adicionar responsavel' nos botoes finais e pontuacao das mensagens do portao; ordem natural das unidades (bloco, numero) na aba Unidades e na Validacao; CSS do tema claro (titulo do cabecalho escuro e select nativo; cuidado com :not(.dark), veja a memoria css-not-dark-vaza-no-modo-escuro.md e filament-dropdown-*); Validacao com secoes recolhiveis e acoes no topo, renderizando so a secao aberta; portao da aprovacao calculado uma vez por requisicao; 'Nova Atualizacao' e 'Adicionar' escondidos ou desabilitados com explicacao quando o guard ja sabe que vai recusar; comentario 'como no PU' corrigido.`,
  },
]

function designPrompt(t) {
  return `${CONTEXT}

## Sua frente: ${t.key}

${t.prompt}

Leia o codigo de verdade (arquivos, testes existentes, enums, migrations) antes de decidir; confirme cada premissa da direcao preferida e corrija-a quando o codigo mostrar algo melhor. Nomeie tudo de forma concreta (classes, metodos, colunas, casos de enum, codigos de issue, textos de UI em portugues).`
}

phase('Desenhar')
const specs = await parallel(TOPICS.map(t => () =>
  agent(designPrompt(t), { label: `desenhar:${t.key}`, phase: 'Desenhar', schema: SPEC_SCHEMA })
    .then(s => (s ? { ...s, key: t.key } : null))))

const ok = specs.filter(Boolean)
const missing = TOPICS.filter((t, i) => !specs[i]).map(t => t.key)
if (missing.length) log(`Frentes sem especificacao: ${missing.join(', ')}`)
log(`Especificacoes: ${ok.length}/${TOPICS.length}`)

phase('Consolidar')
const JUDGE_SCHEMA = {
  type: 'object',
  properties: {
    conflicts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          topics: { type: 'array', items: { type: 'string' } },
          issue: { type: 'string' },
          resolution: { type: 'string' },
        },
        required: ['topics', 'issue', 'resolution'],
      },
    },
    shared_contracts: { type: 'array', items: { type: 'string' } },
    decision_problems: { type: 'array', items: { type: 'string' } },
    migration_review: { type: 'array', items: { type: 'string' } },
    implementation_order: { type: 'array', items: { type: 'string' } },
    file_ownership: { type: 'array', items: { type: 'string' } },
    gaps: { type: 'array', items: { type: 'string' } },
    verdict: { type: 'string' },
  },
  required: ['conflicts', 'shared_contracts', 'decision_problems', 'migration_review', 'implementation_order', 'file_ownership', 'gaps', 'verdict'],
}

const judge = await agent(`${CONTEXT}

## Sua tarefa: juiz de consistencia do desenho
Abaixo estao as especificacoes das 8 frentes. Elas serao implementadas em PARALELO, cada uma numa copia isolada, e os patches serao aplicados em sequencia com merge de 3 vias. Encontre:
1. conflitos entre frentes (mesmo arquivo/metodo/enum/coluna/migration mexidos de formas incompativeis; regras de negocio que se contradizem, ex.: extemporaneos x baixa de unidade x regras de quitacao x reabertura de competencia cancelada x retificacao) e diga a resolucao;
2. contratos compartilhados que precisam de nome unico combinado (casos de enum, codigos de issue, log names, permissoes, colunas, chaves de config);
3. decisoes que contrariam as decisoes do dono de 25/09 ou que inventam regra alem do necessario;
4. migrations inseguras para MySQL 8.4 com dado real ou que nao funcionam em SQLite;
5. ordem de implementacao/merge recomendada e dono de cada arquivo quente (quem edita o que);
6. lacunas: achados da auditoria (arquivos de achados) que nenhuma frente cobriu.
Seja concreto e confira no codigo quando precisar.

Especificacoes:
${JSON.stringify(ok, null, 1)}`, { label: 'consolidar:juiz', phase: 'Consolidar', schema: JUDGE_SCHEMA })

return { specs: ok, judge }
