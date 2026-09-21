# Plano de Refinamento Visual — Tela de Medição

## Contexto & Objetivos
Refinar o layout da tela "Medição" (`ViewMeasurement`), tornando-a mais executiva, legível e equilibrada, preservando integralmente todas as 25 regras de negócio, dados e funcionalidades existentes.
- Paleta institucional: Azul-petróleo (`#091B23`), Dourado sutil (`#A06E28`), Off-white (`#E6E4E4`), Verde para concluído, Vermelho apenas para alertas críticos.
- Alinhamento de decisões (Opções A / A / A):
  1. Pagamentos e Comprovações em listagem operacional executiva com histórico de versões expansível (Accordion Alpine.js).
  2. Condição Financeira com barra de progresso dourada refinada (Registrado vs. Esperado) e avanço físico em tag.
  3. Aprovação por Responsabilidade em mini-cards compactos lado a lado com status por etapa.

---

## Fases de Execução

### Fase 1: Cabeçalho Executivo & Container Cockpit
- **Arquivo**: `app/Filament/Resources/Measurements/Pages/ViewMeasurement.php`
- Adicionar `$extraBodyAttributes = ['class' => 'bsi-cockpit-page bsi-measurement-view-page']`.
- Implementar `getSubheading()` com metadados executivos (Operação, Competência, Situação, Etapa atual).
- Refinar botões de cabeçalho (outline/ghost refinado com borda dourada sutil).

### Fase 2: Grid da Primeira Dobra & Resumo da Medição
- **Arquivo**: `app/Filament/Resources/Measurements/Schemas/MeasurementInfolist.php`
- Reestruturar layout em grid de 12 colunas:
  - Esquerda (5 colunas ~ 42%): Resumo da Medição, Aprovação por Responsabilidade, Condição Financeira.
  - Direita (7 colunas ~ 58%): Pagamentos e Comprovações, Arquivos por Empreendimento.
- Refinar "Resumo da Medição" com grid horizontal limpa, labels discretos em uppercase e valores em destaque.

### Fase 3: Bloco "Aprovação por Responsabilidade"
- **Arquivo**: `resources/views/filament/infolists/measurement-responsibilities.blade.php`
- Criar visão compacta dos responsáveis (Engenharia, Gestão, Compliance, Pagamento, Finalizador) mapeados com o status de aprovação de cada etapa.

### Fase 4: Condição Financeira & Barra de Progresso
- **Arquivo**: `resources/views/filament/infolists/measurement-financial-reconciliation.blade.php`
- Valores alinhados à direita com fonte tabular (`tabular-nums font-mono`).
- Barra de progresso financeira refinada com trilha discreta e preenchimento dourado (#A06E28).
- Destaque hierárquico nos totais e conciliação.

### Fase 5: Pagamentos e Comprovações
- **Arquivo**: `resources/views/filament/infolists/measurement-payments-list.blade.php`
- Listagem operacional de pagamentos: Data, Empreendimento, Método, Valor alinhado à direita, Status do comprovante e Download direto.
- Accordion suave (Alpine.js) para expansão de histórico de versões (v1, v2...), detalhes de auditoria (SHA-256, conferente) e justificativas longas.

### Fase 6: Linha do Tempo & Atividade por Etapa
- **Arquivos**:
  - `resources/views/filament/infolists/measurement-timeline.blade.php`: Linha vertical com gradiente sutil, evento atual em destaque dourado, eventos concluídos em verde discreto.
  - `resources/views/filament/infolists/measurement-reviews-table.blade.php`: Tabela operacional com Etapa, Responsável, Decisão, Data/Hora e observações em segunda camada de menor contraste.

### Fase 7: Estilização BSI & Responsividade
- **Arquivo**: `resources/css/filament/admin/theme.css`
- Estilos dedicados para `.bsi-measurement-view-page`: espaçamentos, tipografia, bordas sutis, botões institucionais e comportamento responsivo (desktop 2 colunas, tablet/mobile empilhado sem overflow).

### Fase 8: Verificação & Testes
- Executar suíte de testes do módulo de medição (`php artisan test --compact --filter=Measurement`).
- Formatar código modificado com Laravel Pint (`vendor/bin/pint --dirty --format agent`).
- Validar responsividade e integridade visual.
