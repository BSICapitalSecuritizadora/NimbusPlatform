# Product

<!-- impeccable:product-schema 1 -->

> Capturado em 2026-08-25 via `$impeccable init` sem entrevista (modo automático, sem canal de pergunta disponível). Fatos derivados de evidência forte no repositório estão marcados como **[inferido]** onde ainda precisam de confirmação humana.

## Platform

web

## Users

Três públicos, três guards de sessão (`config/auth.php`):

- **Equipe interna (backoffice)** — guard `web`, modelo `User`; login via Microsoft Azure SSO, aprovação obrigatória e 2FA. Administra via Filament 5 em `/admin` (~38 resources). Roles: `super-admin`, `admin`, `editor`, `commercial-representative` (spatie/laravel-permission).
- **Investidores** — guard `investor`, modelo `Investor`; portal Livewire em `/investidor` com dashboard, emissões e documentos com download auditado. **[inferido]** pessoas físicas/jurídicas que aplicaram em emissões estruturadas pela BSI e acompanham posição e documentação.
- **Clientes externos (Gestão Documental Externa)** — guard `nimbus`, modelo `PortalUser`; acesso por código/PIN em `/gestao-documental-externa`; enviam documentos (submissions versionadas) e consultam documentos. **[inferido]** empresas/clientes das operações (incorporadoras, empresas agro) que alimentam o fluxo documental da securitização.

## Product Purpose

Plataforma web institucional e operacional da **BSI Capital Securitizadora S.A.**, securitizadora registrada na CVM que estrutura e gerencia operações de crédito **CRI, CRA e CR** (recebíveis imobiliários, do agronegócio e do futuro). O footer institucional resume: "Estruturamos e gerimos operações de crédito com rigor técnico, controle documental e reporte contínuo aos investidores."

Sucesso significa: operações de securitização gerenciadas com rastreabilidade completa (maker/checker, auditoria, conformidade LGPD), investidores informados continuamente, e documentação operacional fluindo entre clientes externos e o backoffice sem atrito.

## Positioning

O produto não é um SaaS genérico: é o sistema operacional interno de uma securitizadora regulada (CVM, selo ANBIMA), cobrindo o ciclo completo — captação de propostas, estruturação, cálculo e homologação de PU (CDI/IPCA/Prefixado), gestão de obrigações e garantias com extração assistida por IA, medições de obra com fluxo de aprovação de engenharia, e reporte ao investidor. O diferencial é a profundidade regulatória e de auditoria em cada fluxo, não a interface.

## Operating Context

- Produção em servidor dedicado (`/var/www/bsicapital`): cron `schedule:run`, fila Redis via Supervisor (`infra/production/`).
- Scheduler denso (`routes/console.php`): sync diário Conta Azul, índices CDI/IPCA (BCB/SGS), geração de curva de PU realizada, obrigações diárias, snapshots mensais de fundos, purgas LGPD mensais.
- Workflows críticos com homologação maker/checker: curvas de PU, medições de obra, dados aprovados com lock.
- Site institucional público em pt_BR com verticais `/imobiliario/cri-real-estate`, `/agronegocio/cra`, `/infra-empresas/cr-futuro`, páginas de emissões por código IF, compliance, canal de ética, governança, RI.

## Capabilities and Constraints

- **Backoffice Filament 5**: emissões, fundos, investidores, clientes, contratos/parcelas, obras/unidades, vendas, recebíveis, despesas/fornecedores, documentos, propostas, recrutamento, usuários/roles, índices e feriados.
- **Calculadora de PU** (`app/Domain/PuCalculator/`): curvas CDI+spread, IPCA (projeção + série aprovada), Prefixado; calendários B3/ANBIMA; maker/checker; exportação CSV/PDF. Documentação: `docs/pu-calculator-operacional.md`.
- **Obrigações e garantias**: extração de cláusulas via Google Gemini, revisão humana, evidências, notificações de vencimento, reconciliação mensal.
- **Medições de obra** com aprovação/trava de engenharia; uploads com hash e antivírus (`ScanFileForMalware`).
- **Integrações**: Conta Azul (ERP contábil, OAuth), Google Gemini, BCB/SGS, ANBIMA, Azure Blob Storage, lookup de CNPJ, Azure SSO.
- **Superfícies**: site institucional (Blade + Bootstrap 5), backoffice (Filament 5), portal do investidor e gestão documental externa (Livewire 4 + Flux UI + Tailwind 4), POC read-only `/operacional/propostas` (Inertia + Vue 3).
- **Stack**: PHP 8.4, Laravel 12, Pest 3; DB local SQLite, produção MySQL (paridade via `composer test:parity`).
- **Idioma do produto**: pt_BR (obrigatório em toda superfície voltada a usuário).
- **Terminologia de domínio**: CRI, CRA, CR, PU, código IF, ISIN, emissão/série, maker/checker — preservar exatamente.
- **Decisão em aberto**: `composer.json` ainda carrega o stub `laravel/livewire-starter-kit` (nome/descrição não renomeados).

## Brand Commitments

- Marca exposta ao usuário: **"BSI Capital" / "BSI Capital Securitizadora"** (© BSI Capital Securitizadora S.A.). "Nimbus" é codinome legado interno do módulo documental — **nunca** expor ao usuário.
- Tom de voz: institucional/regulado — padrão bancário, rastreabilidade, conformidade e governança documental (`docs/design/gestao-documental-externa-handoff.md`). Referências declaradas: Bloomberg/BNDES, não SaaS consumer.
- Assets de marca: `public/images/bsi-logo.png`, `bsi-capital-logo.png`, `logo-mob.png`, `logo-bsi-email.png`, `selo-anbima.jpg`, vídeo `public/videos/logo-animacao-bsi.mp4`; YouTube `@BSICapitalSecuritizadora`.

## Evidence on Hand

- ~55 imagens institucionais reais em `public/images/`, organizadas por vertical (CRI, CRA, CR) e serviço (originação, estrutura jurídica, captação, portal do investidor, auditoria, etc.).
- Copy pública completa já existente: home, sobre, serviços, compliance, canal de ética, governança, RI, `/emissoes` com detalhe por código IF, documentos públicos, estudos de caso, política de privacidade e termos LGPD.
- Auditoria documental real de uma emissão: `docs/fase-2b-5-1-alto-bellevue.md` (CRI Alto Bellevue, 1ª série da 24ª emissão, código IF 26E0017614, ISIN BRALBLCRI008).
- `routes/legacy-redirects.php` evidencia migração de um site anterior.
- **Não fabricar**: depoimentos/testemunhais, números de mercado ou benchmarks não presentes no repositório, claims de rentabilidade.

## Product Principles

1. **Rastreabilidade acima de conveniência** — todo fluxo crítico tem auditoria, homologação ou lock; nunca sacrificar isso por ergonomia.
2. **Institucional, não consumer** — o padrão visual e verbal é bancário/regulado; a confiança de investidores e reguladores é o ativo.
3. **Três públicos, três portas** — backoffice, investidor e cliente externo têm superfícies e permissões separadas; nenhuma mistura de contexto.
4. **pt_BR é o idioma do produto** — toda superfície voltada a usuário fala português brasileiro e usa a terminologia regulatória exata.
5. **Dados reais, nunca inventados** — copy e provas vêm das operações e documentos reais; ausência de evidência vira seção omitida, não texto genérico.

## Accessibility & Inclusion

Sem requisito formal registrado no repositório. **[inferido]** como plataforma financeira regulada, contraste e legibilidade de dados tabulares/relatórios devem seguir WCAG AA como piso prático — confirmar se há exigência contratual ou regulatória específica.
