# Fase 2B.5.1 — Fechamento documental e baseline verificável do CRI Alto Bellevue

Data de corte da auditoria: 25/08/2026. Emissão analisada: ID 7, CRI Alto Bellevue, 1ª série da 24ª emissão, código IF `26E0017614`, ISIN `BRALBLCRI008`.

## Veredicto

**C — Baseline inapto.**

O contrato permite provar o indexador, o percentual da Taxa DI, o spread, a base, a regra de DUP, o modo de consulta da Taxa DI, o lag e os eventos programados. Porém, não é possível configurar a engine atual sem omitir ou presumir elementos materiais:

1. não existe calendário confirmado que represente exclusivamente a definição contratual de Dia Útil;
2. a data da primeira integralização é apenas indicativa no Anúncio de Início e operacional no banco, sem boletim/extrato de liquidação vinculado;
3. a quantidade integralizada de 4.000 CRI não aparece em documento de encerramento ou extrato B3 vinculado;
4. o prêmio excepcional dos dois Dias Úteis anteriores à integralização está comprovado, mas não possui representação explícita na configuração/engine atual;
5. não há gabarito de PU do Alto Bellevue;
6. `index_rates` não contém nenhuma Taxa DI, portanto nem uma curva de conferência pode ser executada com o estado atual;
7. não há eventos de PU nem pagamentos registrados para a emissão, embora o cronograma já tenha datas vencidas na data de corte.

Nenhum `EmissionPuParameter` foi criado ou proposto. Não houve alteração de engine, calendário, curva, `PuHistory`, pagamento ou obrigação.

## 1. Auditoria obrigatória de `B3_LISTED_TRADING`

### 1.1 Estado encontrado

| Atributo | Resultado observado |
|---|---|
| Linhas | 3.287, IDs contínuos 2.527–5.813 |
| Período | 01/01/2021 a 31/12/2029, todos os dias corridos |
| Criação/atualização | `2026-08-24 18:25:38` UTC, equivalente a `2026-08-24 15:25:38` em America/Sao_Paulo, em todas as linhas |
| Decisões | 2.347 úteis e 940 não úteis |
| Regra observada | 100% idêntica a segunda–sexta útil e sábado/domingo não útil; zero divergências |
| Origem/fonte | `data_origin=inferred`; `source=calendar_inference`; `source_is_official=false` |
| Documento/revisão externa | `source_document=null`; `source_revision=null` |
| Revisão interna | `revision=2` |
| Importação | `import_run_id=null`; zero `business_calendar_import_runs` |
| Staging | zero lotes e zero datas staged; não existe `batch_uuid` relacionado |
| Feriados relacionados | zero fatos em `business_holidays` |
| Anos | nove registros 2021–2029, todos `provisional`, sem checksum, confirmação, confirmador ou documento |
| Auditoria de usuário/processo | nenhuma entrada em `activity_log` na janela de criação |

As linhas não representam sessões reais da B3. Por exemplo, 01/01/2021, 01/01/2026, 21/04/2026 e 25/12/2026 estão marcados como úteis porque caem em dias de semana. Em 2026, 24/12 e 31/12 também estão marcados como úteis, enquanto o [calendário oficial da B3 para 2026](https://www.b3.com.br/pt_br/noticias/calendario-de-negociacao-da-b3-confira-o-funcionamento-da-bolsa-em-2026.htm) informa que não há sessão de negociação nessas datas.

### 1.2 Origem técnica

O fingerprint das linhas coincide integralmente com `BusinessCalendarCoverageService::backfill()`:

- iteração por todos os dias corridos do intervalo;
- `is_business_day = ! fim_de_semana` quando não existe `BusinessHoliday`;
- `data_origin=inferred`, `source=calendar_inference`, fonte não oficial;
- criação de anos provisórios com revisão incrementada para 2;
- inserção em lotes com um único timestamp.

O serviço foi introduzido no commit `16c55bd` de 30/06/2026 e recebeu os campos de governança no commit `8aaeff9` de 24/08/2026 às 11:28:26 -03. As linhas foram criadas às 15:25:38 -03 desse dia, depois desse commit e antes do commit `130361f`, de 17:51:11 -03.

O entrypoint público compatível é:

```text
php artisan pu:business-calendar:seed --calendar=B3_LISTED_TRADING --from=2021-01-01 --to=2029-12-31
```

O comando chama `backfill()` diretamente e não restringe o calendário aos códigos autocompletáveis. A equivalência de intervalo e fingerprint torna esse comando a hipótese mais forte. Contudo, como não existe log do comando, `batch_uuid`, import run, staging ou activity log, não é possível distinguir documentalmente entre esse comando, uma chamada direta ao serviço ou uma execução via Tinker. Também não é possível atribuir a operação a uma pessoa. O autor dos commits identifica quem escreveu o código, não quem executou a mutação.

Nenhuma migration, seed, teste ou importador insere esse conjunto no banco de aplicação. As migrations apenas criam as estruturas/catálogo; testes usam banco isolado; o importador ANBIMA rejeita `B3_LISTED_TRADING`.

Há ainda uma inconsistência de catálogo: `business_calendars` declara a fonte como “B3 (fonte oficial ainda pendente de staging e aprovação)”, status `awaiting_official_source` e indisponibilidade para novas configurações, mas mantém `is_official=true` e `financial_use_allowed=true`. Esses flags de catálogo não conferem oficialidade às linhas, que são explicitamente não oficiais.

### 1.3 Classificação

- **Derivado:** sim, exclusivamente da regra de dia da semana.
- **Sem proveniência:** sim, quanto ao ato de criação e à fonte de sessões.
- **Incompleto:** não em cobertura de datas; sim em fatos de mercado, pois não contém nenhuma exceção de sessão.
- **Confiável:** não.
- **Não utilizável:** sim, para homologação ou cálculo financeiro.

Classificação final: **derivado, sem proveniência e não utilizável**. Nenhuma linha foi apagada ou corrigida nesta fase.

## 2. Corpus documental do Alto Bellevue

Foram revisados os 13 registros vinculados, correspondentes a 12 arquivos únicos. Os documentos financeiros primários são:

| Documento | ID | Páginas | Papel na análise |
|---|---:|---:|---|
| Anúncio de Início | 3 | 4 | quantidade ofertada e cronograma indicativo de liquidação |
| Termo de Securitização | 4 | 132 | fonte contratual principal dos CRI |
| Primeiro Aditamento + versão consolidada | 5 | 132 | versão vigente; ratifica as condições financeiras não alteradas |
| CCB | 7 e 13 | 47 | lastro; os dois registros têm o mesmo checksum |
| CCI | 10 | — | representação do crédito imobiliário subjacente |
| Contrato de Distribuição | 11 | 26 | características da oferta e possibilidade de colocação parcial |
| Contrato de Cessão | 14 | — | transferência do lastro |

A AGT de 11/08/2026 e os instrumentos de garantia/registro foram examinados para eventos posteriores e não contêm memória de PU do CRI. A CCB e os instrumentos de garantia repetem regras do lastro, mas sua sobretaxa de **7,50% a.a. pertence à CCB**, não ao CRI. Para a emissão ID 7 prevalece o spread de 6,00% a.a. do Termo.

O Primeiro Aditamento, de 13/05/2026, apenas inclui “Agente de Liquidação” e exclui “Índice Substitutivo” (p. 2, cláusulas 2.1–2.2); a cláusula 3.1, p. 3, ratifica todas as demais condições. A versão original do Termo pode, portanto, ser citada para as cláusulas financeiras abaixo.

## 3. Ficha técnica contratual

### 3.1 Evidências centrais

| Parâmetro | Documento, página/cláusula | Excerto relevante | Status |
|---|---|---|---|
| Indexador e percentual | Termo, p. 24, 4.1.8 | “variação acumulada de 100% [...] da Taxa DI” | Comprovado |
| Spread da 1ª série | Termo, p. 24, 4.1.8 | “6,00% [...] ao ano referente aos CRI 1ª Série” | Comprovado |
| Base anual | Termo, p. 24, 4.1.8 | “com base em um ano de 252 [...] Dias Úteis” | Comprovado |
| Regra de DUP | Termo, p. 25, 4.1.8 | Dias Úteis entre integralização/último pagamento, inclusive, e cálculo, exclusive | Comprovado |
| Dia Útil | Termo, p. 8, definição | “Qualquer dia que não seja sábado, domingo ou feriado nacional na República Federativa do Brasil” | Comprovado |
| Início da remuneração | Termo, p. 14 e pp. 24–25 | primeiro período começa na Data de Integralização, inclusive | Comprovado quanto à regra; data efetiva pendente |
| Data de emissão | Termo, p. 23, 4.1.5 | 08/05/2026 para a 1ª série | Comprovado |
| Vencimento | Termo, p. 23, 4.1.6 | 08/05/2031; prazo de 1.826 dias corridos | Comprovado |
| VNU inicial | Termo, p. 23, 4.1.3 | R$ 1.000,00 na Data de Emissão | Comprovado |
| Quantidade emitida | Termo, p. 23, 4.1.2; Anúncio, pp. 1–2 | 5.000 CRI da 1ª série | Comprovado |
| Ausência de correção monetária | Termo, p. 24, 4.1.7 | VNU/saldo “não será atualizado monetariamente” | Comprovado |
| Juros | Termo, pp. 26 e 111–112 | mensais, de 08/06/2026 a 08/05/2031 | Comprovado |
| Amortização | Termo, pp. 26–27 e 111–112 | 0,0000% até 08/04/2031; 100,0000% em 08/05/2031 | Comprovado |
| Convenção de pagamento | Termo, p. 29, 4.1.22 | prorrogação automática ao primeiro Dia Útil subsequente | Comprovado |
| Integralização | Termo, p. 31, 5.2.1–5.2.2 | à vista no ato da subscrição; primeira pelo VNU; via B3 | Comprovado quanto à regra |
| Amortização extraordinária | Termo, p. 35, 6.1–6.1.3 | repasse proporcional de amortização da CCB; aviso de 30 dias; prêmio inicial de 2%; limitada a 98% do VNU atualizado | Comprovado |
| Arredondamento | Termo, pp. 24–26, 4.1.8, observações (i)–(v) | fator diário 16 casas sem arredondar; acumulado truncado em 16; Fator DI 8; DI × spread 9 | Comprovado |
| Prêmio inicial | Termo, p. 26, observação (vii) | no primeiro pagamento, produto de 2 Dias Úteis anteriores à integralização, com DI e spread | Comprovado; sem mapeamento explícito na engine atual |

O cronograma é bullet quanto ao principal: há carência integral de amortização programada até o vencimento. Os juros não têm carência além do primeiro período e são mensais no dia 8, sujeitos à prorrogação. As datas do Anexo II são datas originais; as datas efetivas dependem do calendário contratual.

### 3.2 Data e quantidade efetivamente integralizadas

O Anúncio de Início, p. 2, apresenta 15/05/2026 como “Data de Primeira Liquidação dos CRI”, mas qualifica todas as datas futuras como “meramente indicativas”. O Primeiro Aditamento, p. 2, confirma apenas que em 13/05/2026 os CRI ainda não haviam sido integralizados.

O banco registra uma integralização em 15/05/2026 de 4.000 unidades a R$ 1.000,00, total de R$ 4.000.000,00, criada em 20/08/2026 por usuário ID 2. Isso corrobora a data indicativa, mas não substitui documento de aceitação, boletim de subscrição, extrato/relatório de liquidação B3 ou Anúncio de Encerramento.

- Data efetiva de primeira integralização: **Inferível com alta confiança**, não comprovada.
- Quantidade efetivamente integralizada: **Não localizada documentalmente**.
- Existência de integralizações posteriores ou encerramento definitivo da colocação: **Não localizada**.

### 3.3 Regra da Taxa DI e modo da engine

O Termo, p. 26, observação (vi), determina:

> “Taxa DI divulgada com 5 (cinco) Dias Úteis de defasagem em relação à data efetiva de cálculo”; no exemplo, cálculo no dia 14 usa a taxa publicada no dia 7, sendo 10, 11, 12, 13 e 14 Dias Úteis.

A CCB, p. 9, repete literalmente a regra e o exemplo. Não existe exemplo numérico de PU ou de fatores; existe apenas esse exemplo de datas.

Conclusão documental:

| Modo suportado | Compatibilidade contratual |
|---|---|
| `PreviousAvailableBusinessDay` | Refutado: busca a última taxa disponível, não a taxa exata de cinco Dias Úteis antes |
| `PreviousCalendarDayExact` | Refutado: usa D-1 calendário, incompatível com o exemplo dia 14 → dia 7 |
| `BusinessDayLagExact` | **Comprovado** |

- quantidade do lag: **5 Dias Úteis**;
- sinal/direção na engine: **`-5`**, para trás;
- data da taxa em cada dia útil de cálculo `t`: taxa exata de `t - 5 Dias Úteis`;
- intervalo contratual de contagem: a data de cálculo é incluída e a data da Taxa DI é excluída, isto é, `(data DI, data de cálculo]`;
- `shiftBusinessDays(t, -5)` percorre internamente cinco Dias Úteis anteriores, excluindo `t` e incluindo o destino; para um `t` útil resolve o mesmo endpoint demonstrado pelo contrato;
- calendário do lag: a própria definição contratual de Dia Útil, não ANBIMA nem sessões B3.

O modo e o lag estão comprovados; o **calendário que deve alimentá-los permanece pendente**.

## 4. Definição contratual de Dia Útil

A definição completa do Termo, p. 8, é:

> “Qualquer dia que não seja sábado, domingo ou feriado nacional na República Federativa do Brasil, ou, ainda, exclusivamente no caso de obrigações não pecuniárias, que também não seja feriado comercial no município de São Paulo, estado de São Paulo.”

PU, remuneração, amortização e pagamento são obrigações pecuniárias. Logo, para a curva, a regra é estritamente: fins de semana + feriados nacionais instituídos por lei federal.

### 4.1 Distinções jurídicas e operacionais

| Categoria | Tratamento na definição pecuniária |
|---|---|
| Feriados nacionais legais | Não úteis |
| Feriados bancários ANBIMA/CMN adicionais | Não entram automaticamente |
| Pontos facultativos | Não entram |
| Carnaval | Não é feriado nacional federal; não útil para mercado financeiro por regra do CMN |
| Corpus Christi | Não é feriado nacional federal; não útil para mercado financeiro por regra do CMN |
| Paixão de Cristo | Feriado religioso dependente de lei municipal, nos termos da Lei 9.093/1995; não é nacional federal |
| 24/12 e 31/12 | Não são feriados nacionais; podem ter ponto facultativo/ausência de sessão B3 |
| Feriados estaduais/municipais | Não entram em obrigação pecuniária; a ressalva de São Paulo vale apenas para obrigação não pecuniária |
| Sessões B3 Listed | Conceito distinto; inclui decisões operacionais de negociação não previstas na definição contratual |

A [Resolução CMN 4.880/2020](https://www.bcb.gov.br/estabilidadefinanceira/exibenormativo?numero=4880&tipo=RESOLU%C3%87%C3%83O+CMN), art. 6º, exclui, além de fins de semana e feriados nacionais, segunda e terça de Carnaval e Corpus Christi dos dias úteis do mercado financeiro. A [ANBIMA para 2026](https://www.anbima.com.br/feriados/fer_nacionais/2026.asp) também inclui Carnaval, Paixão de Cristo e Corpus Christi. Portanto, `BR_BANKING_ANBIMA` é mais restritivo que o contrato.

### 4.2 Fonte oficial recomendada

Não foi localizada uma fonte oficial única e machine-readable que entregue, com semântica jurídica e histórico de vigência, apenas os feriados nacionais brasileiros. A fonte primária adequada é a legislação federal oficial, versionada por vigência:

- [Lei 662/1949, art. 1º](https://www.planalto.gov.br/ccivil_03/leis/l0662.htm): 1º/1, 21/4, 1º/5, 7/9, 2/11, 15/11 e 25/12, na redação da Lei 10.607/2002;
- [Lei 6.802/1980](https://www.planalto.gov.br/ccivil_03/leis/l6802.htm): 12/10;
- [Lei 14.759/2023](https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/lei/l14759.htm): 20/11, vigente desde sua publicação em dezembro de 2023;
- [Lei 9.093/1995](https://www.planalto.gov.br/ccivil_03/leis/l9093.htm): separa feriados civis federais/estaduais e religiosos municipais.

Para uso histórico, o calendário deverá manter por ano o conjunto de leis vigente naquele período, URL/DOU, checksum do manifesto, revisão e aprovação humana. Portarias anuais de expediente da Administração Pública e a planilha ANBIMA podem servir de conferência negativa, mas não de fonte constitutiva do calendário contratual.

Nenhum calendário definitivo foi criado nesta fase.

### 4.3 Diff conceitual de 2026

As únicas datas de 2026 que alteram a decisão entre B3 legado/ANBIMA e feriados nacionais legais são:

| Data | Evento | B3 legado | BR_BANKING_ANBIMA | Feriados nacionais legais | B3_LISTED_TRADING |
|---|---|---:|---:|---:|---|
| 16/02/2026 | Carnaval | Não útil | Não útil | **Útil** | Excluído: sem proveniência |
| 17/02/2026 | Carnaval | Não útil | Não útil | **Útil** | Excluído: sem proveniência |
| 03/04/2026 | Paixão de Cristo | Não útil | Não útil | **Útil** | Excluído: sem proveniência |
| 04/06/2026 | Corpus Christi | Não útil | Não útil | **Útil** | Excluído: sem proveniência |

As demais datas nacionais de 2026 resultam na mesma decisão nos três primeiros calendários, ou caem em fim de semana, e por isso não aparecem no diff.

Como sanity check separado, o calendário oficial de negociação da B3 informa ausência de sessão em 24/12 e 31/12/2026, enquanto as linhas atuais de `B3_LISTED_TRADING` marcam ambas como úteis. Essa constatação reforça a rejeição das linhas, mas não as promove a coluna válida do diff.

## 5. Busca de gabarito e estado operacional

Não existe gabarito do Alto Bellevue nos seguintes locais verificados:

- 13 documentos vinculados e seus anexos;
- planilhas privadas do projeto;
- uploads temporários e importações operacionais;
- amostras `docs/samples/pu-validation`;
- histórico Git do módulo de PU;
- tabelas `pu_histories`, `emission_pu_daily_curves` e versões da curva.

As planilhas privadas que contêm “Alto Bellevue” são exclusivamente de contratos, unidades ou parcelas imobiliárias. O `pu.xlsx` histórico do Git é do **CRI Conviva**. Os demais gabaritos são de Rio Branco, Conviva, Amani ou Troupe e não podem substituir o Alto Bellevue.

Estado da emissão ID 7:

- zero `EmissionPuParameter`;
- zero `emission_pu_events`;
- zero `emission_pu_daily_curves`;
- zero `pu_histories`;
- zero `payments`;
- zero `index_rates`, inclusive CDI.

Não foi produzida planilha artificial.

## 6. Tabela final de parâmetros

| Parâmetro | Valor encontrado | Evidência | Status | Necessário para baseline |
|---|---|---|---|---|
| Indexador | Taxa DI divulgada pela B3 | Termo p. 24–25, 4.1.8 | Comprovado | Sim |
| Percentual da DI | 100% | Termo p. 24, 4.1.8 | Comprovado | Sim |
| Spread CRI 1ª série | 6,00% a.a. | Termo p. 24, 4.1.8 | Comprovado | Sim |
| Base | 252 Dias Úteis | Termo p. 24–25 | Comprovado | Sim |
| Método | exponencial, cumulativo, pro rata por Dias Úteis | Termo p. 24–26 | Comprovado | Sim |
| DUP | início/último pagamento inclusive; cálculo exclusive | Termo p. 25 | Comprovado | Sim |
| Dia Útil | não sábado, domingo ou feriado nacional no Brasil | Termo p. 8 | Comprovado | Sim |
| Calendário da engine | calendário nacional-only ainda inexistente/não confirmado | Leis federais; nenhum código apto no banco | Não localizado | **Sim — bloqueante** |
| Modo CDI | `BusinessDayLagExact` | Termo p. 26 e CCB p. 9 | Comprovado | Sim |
| Lag CDI | `-5` Dias Úteis | mesmo exemplo dia 14 → dia 7 | Comprovado | Sim |
| Intervalo do lag | `(data DI, cálculo]` | Termo p. 26, observação (vi) | Comprovado | Sim |
| Data DI diária | data exata cinco Dias Úteis antes de cada dia útil de cálculo | Termo p. 26 | Comprovado | Sim |
| Data de emissão | 08/05/2026 | Termo p. 23, 4.1.5 | Comprovado | Sim |
| Início contratual da remuneração | integralização inclusive | Termo p. 14 e pp. 24–25 | Comprovado | Sim |
| Primeira integralização efetiva | 15/05/2026 | Anúncio p. 2 apenas indicativo + banco | Inferível com alta confiança | **Sim — bloqueante** |
| Quantidade emitida | 5.000 | Termo p. 23; Anúncio pp. 1–2 | Comprovado | Sim |
| Quantidade integralizada | 4.000 no banco | sem Anúncio de Encerramento/boletim/extrato vinculado | Não localizado | **Sim — bloqueante para quantidade/total** |
| VNU inicial | R$ 1.000,00 | Termo p. 23 e p. 31 | Comprovado | Sim |
| Vencimento | 08/05/2031 | Termo p. 23 | Comprovado | Sim |
| `curve_start_date` | deve corresponder à integralização efetiva, ainda não comprovada | Termo p. 14; Anúncio p. 2 indicativo | Ambíguo | **Sim — bloqueante** |
| `curve_end_date` | 08/05/2031, salvo liquidação antecipada | Termo p. 23 e p. 35 | Comprovado | Sim |
| Atualização monetária | não há | Termo p. 24, 4.1.7 | Comprovado | Sim |
| Juros | mensais, dia 8, 08/06/2026–08/05/2031 | Anexo II, pp. 111–112 | Comprovado | Sim |
| Periodicidade | juros mensais; principal bullet no vencimento | Termo p. 26 e Anexo II | Comprovado | Sim |
| Carência | principal sem amortização até 08/05/2031; primeiro juro em 08/06/2026 | Anexo II, pp. 111–112 | Comprovado | Sim |
| Amortização | bullet; 100% em 08/05/2031 | Anexo II, pp. 111–112 | Comprovado | Sim |
| Convenção de pagamento | primeiro Dia Útil subsequente, sem alterar a data original | Termo p. 29, 4.1.22 | Comprovado | Sim |
| Datas efetivas dos eventos | primeiro Dia Útil seguinte quando necessário | Termo p. 29; dependem do calendário pendente | Inferível com alta confiança após o calendário | **Sim — bloqueante** |
| Prêmio do primeiro pagamento | dois Dias Úteis anteriores à integralização | Termo p. 26, observação (vii) | Comprovado | **Sim — bloqueante por falta de mapeamento** |
| Extraordinárias | repasse proporcional; aviso 30 dias; prêmio; limite de 98% no parcial | Termo p. 35, 6.1–6.1.3 | Comprovado | Condicional a evento |
| Precisão/arredondamento | regras de 16/8/9 casas do Termo | Termo pp. 24–26 | Comprovado | Sim |
| Série diária CDI | nenhuma linha no banco | `index_rates` vazio | Não localizado | **Sim — bloqueante para execução** |
| Gabarito Alto Bellevue | inexistente nos artefatos pesquisados | documentos, storage, DB e Git | Não localizado | **Sim — bloqueante para baseline verificável** |

## 7. Matriz final de conceitos

| Conceito | B3 legado | ANBIMA | Feriados nacionais | B3 Listed |
|---|---|---|---|---|
| Semântica | alias legado com semântica ANBIMA atual | dias úteis do mercado financeiro/bancário | feriados nacionais criados por lei federal | sessões de negociação do mercado listado |
| Fonte atual | 1.263 feriados; linhas antigas sem metadados de origem | importação ANBIMA, fonte marcada oficial, anos ainda provisórios | legislação Planalto/DOU; calendário ainda não materializado | linhas atuais `calendar_inference`, não oficiais |
| Carnaval | não útil | não útil | útil | dado atual diz útil, mas é inválido |
| Paixão de Cristo | não útil | não útil | útil, salvo regra local não aplicável à obrigação pecuniária | dado atual diz útil, mas é inválido |
| Corpus Christi | não útil | não útil | útil | dado atual diz útil, mas é inválido |
| 24/12 e 31/12/2026 | úteis | úteis | úteis | dado atual diz útil; B3 oficial informa ausência de sessão |
| Estadual/municipal | não incluído na base atual | não incluído na lista nacional ANBIMA | excluído do conceito nacional | depende do calendário operacional oficial da B3 |
| Adequado ao contrato Alto Bellevue | não | não | semanticamente sim, após fonte/versionamento/aprovação | não |
| Utilizável hoje na homologação | não como candidato contratual | não como candidato contratual | não existe ainda | **não utilizável** |

## 8. Informações faltantes e próximos passos mínimos

1. Obter da securitizadora/coordenador o **Boletim de Subscrição ou documento de aceitação** e o **relatório/extrato de liquidação B3** da primeira integralização.
2. Obter o **Anúncio de Encerramento** ou mapa final de colocação, incluindo todas as integralizações, quantidades, datas, preços e eventual ágio/deságio.
3. Obter do agente fiduciário, securitizadora ou escriturador a **memória de cálculo/PU oficial** do primeiro cupom e de pelo menos uma janela que atravesse Carnaval, Paixão ou Corpus; idealmente arquivo diário com data DI, taxa, DUP, fatores e PU.
4. Confirmar por escrito como o **prêmio dos dois Dias Úteis anteriores** é incorporado ao PU de abertura/primeiro cupom e definir, em fase própria, sua representação na engine atual. Não simular essa regra deslocando arbitrariamente a data inicial.
5. Construir, em fase posterior, um calendário separado de **feriados nacionais legais**, com manifesto anual versionado, leis/DOU, checksum e aprovação; cobrir pelo menos os dois dias úteis anteriores à integralização até o vencimento.
6. Carregar Taxa DI de fonte oficial B3, ou documentar formalmente a equivalência da série alternativa usada, preservando data de publicação e revisão.
7. Gerar os eventos programados apenas depois da confirmação do calendário, preservando data original e data efetiva.
8. Para `B3_LISTED_TRADING`, identificar o executor em logs externos que não estão no repositório/banco e substituir, por staging e aprovação em fase própria, os dados derivados por calendário oficial B3. Até lá, manter o código fora de homologação financeira.
