# Calculadora Interna de PU Diario

## Escopo atual

- Operacoes CDI + spread fixo ao ano
- Base 252 dias uteis
- Curva diaria com juros, amortizacao ordinaria, pagamentos e quantidade vigente
- Validacao contra planilhas de referencia em `docs/samples/pu-validation/`

## Onde usar no painel

- `Gestao > Emissoes > Editar emissao`
- Acoes disponiveis:
  - `Configurar Calculo de PU`
  - `Gerar Curva PU`
  - `Visualizar Curva PU Diario`
  - `Validar contra Planilha`
  - `Ver Relatorio de Divergencias`
  - `Exportar Curva PU`

## Pre-requisitos para gerar a curva

- Parametros de PU preenchidos
- Data inicial e final da curva
- PU inicial maior que zero
- Spread configurado
- Indexador CDI
- Quantidade vigente via `integralization_histories`
- Calendario de dias uteis carregado em `business_calendar_dates`
- Taxas CDI disponiveis em `index_rates`

## Auditoria

- Geracao e validacao registram logs no `activity_log`
- Cada linha da curva salva `calculation_memory` com fatores e valores intermediarios

## Exportacao

- A exportacao gera CSV com:
  - data
  - versao
  - PU atualizado
  - PU residual
  - juros real
  - amortizacao
  - quantidade
  - valor total
  - pagamento total
  - pagamento de juros
  - CDI usado
  - data do CDI
  - fator DI
  - fator spread
  - fator combinado
  - DUP
  - DUT

## Observacoes operacionais

- `payments` e `pu_histories` continuam como projecoes legadas
- A validacao pode rodar em `display-scale` ou `raw-scale`
- Divergencias pequenas em total e pagamento ainda podem refletir precisao interna da planilha de referencia
