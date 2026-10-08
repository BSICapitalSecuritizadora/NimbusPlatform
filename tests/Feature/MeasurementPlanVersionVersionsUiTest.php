<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanVersionsRelationManager;
use App\Models\Construction;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

/**
 * A aba "Versões dos Planos" da operação e o cronograma em acompanhamento.
 *
 * A tela é a única porta de replanejamento que a pessoa usa: ela precisa
 * mostrar cada versão com a vigência que de fato teve, oferecer cada ação só
 * onde ela vale e devolver toda recusa do serviço de versões como algo que se
 * lê -- com o modal aberto quando há o que corrigir nele, e sem gravar nada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // As medições do acompanhamento guardam o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
    // Vigência e datas da tela são do calendário de negócio: o fuso fixo deixa
    // as fronteiras de mês independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * Leva o relógio ao meio-dia de Brasília (15h UTC) do dia: a vigência de uma
 * versão ativada é o mês desse dia no calendário de negócio.
 */
function versionsTabTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 15:00:00", 'UTC'));
}

/**
 * Operação em andamento com um administrador em todos os papéis: quem
 * replaneja também envia e aprova a medição, e a autorização só é assunto
 * onde o teste troca o usuário.
 *
 * @return array{actor: User, operation: Operation}
 */
function versionsTabOperation(): array
{
    $actor = makeAdminUser();
    $operation = Operation::factory()->create([
        'status' => 'active',
        ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
    ]);

    return ['actor' => $actor, 'operation' => $operation];
}

/**
 * Plano de uma obra da operação criado pela porta de escrita: a V1 nasce em
 * rascunho com o Fundo de Obra e uma medição prevista por competência. O
 * acumulado vai digitado como soma corrida desde zero, como a planilha da obra
 * costuma chegar; a ativação o recalcula a partir do avanço físico atual.
 *
 * @param  array<string, string>  $monthlyByMonth  previsto mensal por competência ('Y-m')
 */
function versionsTabPlan(Operation $operation, User $actor, string $development, string $fund, array $monthlyByMonth, string $initialPercent = '0.00', ?string $referenceDate = null): MeasurementPlanSet
{
    $construction = Construction::factory()->create([
        'emission_id' => $operation->emission_id,
        'development_name' => $development,
    ]);
    $lines = [];
    $running = 0;

    foreach (array_keys($monthlyByMonth) as $index => $month) {
        $running += (int) MeasurementPhysicalProgress::basisPoints($monthlyByMonth[$month]);
        $lines[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => $monthlyByMonth[$month],
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => "Plano {$development}",
        'construction_id' => $construction->id,
        'is_default' => ! $operation->planSets()->exists(),
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => $initialPercent,
        'initial_physical_progress_reference_date' => $referenceDate,
    ], ['construction_fund_amount' => $fund], $lines);
}

function versionsTabVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa pelo serviço como quem acabou de abrir a tela: com o contador atual.
 */
function versionsTabActivate(MeasurementPlanVersion $version, User $actor): MeasurementPlanVersion
{
    $seen = $version->fresh();

    return app(MeasurementPlanVersionService::class)->activate($seen, $actor, (int) $seen->revision);
}

/**
 * Abre a revisão do plano pelo serviço -- rascunho copiado da vigente -- e,
 * quando informado, grava nele outro Fundo de Obra.
 */
function versionsTabRevise(MeasurementPlanSet $planSet, User $actor, MeasurementPlanRevisionCategory $category, ?string $reason, ?string $fund = null): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($planSet->fresh(), $actor, [
        'revision_category' => $category->value,
        'revision_reason' => $reason,
    ]);

    return $fund === null
        ? $draft
        : $service->updateDraft($draft, $actor, ['construction_fund_amount' => $fund], null, (int) $draft->revision);
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function versionsTabLines(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))
        ->all();
}

function versionsTabManager(Operation $operation): Testable
{
    return Livewire::test(PlanVersionsRelationManager::class, [
        'ownerRecord' => $operation->fresh(),
        'pageClass' => ViewOperation::class,
    ]);
}

/**
 * Torre Aurora com 35% executados até 30/04/2026 e a V1 vigente desde
 * 05/2026, ativada em 15/05/2026: Fundo de Obra de R$ 20.000.000,00 e 10%
 * previstos por mês de 05/2026 a 07/2026 -- acumulado de 45%, 55% e 65% sobre
 * o avanço inicial. O relógio fica em 15/05/2026.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function versionsTabActivePlan(): array
{
    versionsTabTravelTo('2026-05-15');
    ['actor' => $actor, 'operation' => $operation] = versionsTabOperation();
    $planSet = versionsTabPlan($operation, $actor, 'Torre Aurora', '20000000.00', [
        '2026-05' => '10.00',
        '2026-06' => '10.00',
        '2026-07' => '10.00',
    ], '35.00', '2026-04-30');
    $v1 = versionsTabActivate(versionsTabVersion($planSet, 1), $actor);

    return ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet->fresh(), 'v1' => $v1];
}

/**
 * Uma operação com versões em todas as situações, e o relógio em 10/07/2026:
 *
 * - Torre Aurora: V1 valeu de 05/2026 a 06/2026, V2 (custo, R$ 23 milhões)
 *   vigente desde 07/2026 e V3 em rascunho;
 * - Torre Boreal: V1 e V2 ativadas no mesmo mês -- a V1 não regeu competência
 *   nenhuma -- e V3 cancelada;
 * - Torre Cerrado: só a V1, em rascunho, com uma medição prevista.
 *
 * Outra operação tem o próprio plano, que a aba desta não pode mostrar.
 *
 * @return array{actor: User, operation: Operation, aurora: array<int, MeasurementPlanVersion>, boreal: array<int, MeasurementPlanVersion>, cerrado: MeasurementPlanVersion, outsider: MeasurementPlanVersion}
 */
function versionsTabHistory(): array
{
    versionsTabTravelTo('2026-05-15');
    ['actor' => $actor, 'operation' => $operation] = versionsTabOperation();

    $aurora = versionsTabPlan($operation, $actor, 'Torre Aurora', '20000000.00', ['2026-05' => '10.00', '2026-06' => '10.00', '2026-07' => '10.00']);
    versionsTabActivate(versionsTabVersion($aurora, 1), $actor);
    versionsTabRevise($aurora, $actor, MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');

    $boreal = versionsTabPlan($operation, $actor, 'Torre Boreal', '8000000.00', ['2026-07' => '20.00', '2026-08' => '20.00', '2026-09' => '20.00']);
    $cerrado = versionsTabPlan($operation, $actor, 'Torre Cerrado', '5000000.00', ['2026-09' => '5.00']);

    $elsewhere = Operation::factory()->create([
        'status' => 'active',
        ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
    ]);
    $outsider = versionsTabPlan($elsewhere, $actor, 'Torre Distante', '1000000.00', ['2026-09' => '10.00']);

    versionsTabTravelTo('2026-07-10');
    versionsTabActivate(versionsTabVersion($aurora, 2), $actor);
    versionsTabRevise($aurora, $actor, MeasurementPlanRevisionCategory::Schedule, 'Fundação atrasou: cronograma refeito com a construtora.');

    versionsTabActivate(versionsTabVersion($boreal, 1), $actor);
    versionsTabActivate(versionsTabRevise($boreal, $actor, MeasurementPlanRevisionCategory::PhysicalPlanning, 'Curva física redistribuída antes da primeira medição.'), $actor);
    $cancelled = versionsTabRevise($boreal, $actor, MeasurementPlanRevisionCategory::Scope, 'Inclusão do bloco B.');
    app(MeasurementPlanVersionService::class)->cancel($cancelled, $actor, 'O comitê não aprovou a ampliação.', (int) $cancelled->revision);

    return [
        'actor' => $actor,
        'operation' => $operation,
        'aurora' => [1 => versionsTabVersion($aurora, 1), 2 => versionsTabVersion($aurora, 2), 3 => versionsTabVersion($aurora, 3)],
        'boreal' => [1 => versionsTabVersion($boreal, 1), 2 => versionsTabVersion($boreal, 2), 3 => versionsTabVersion($boreal, 3)],
        'cerrado' => versionsTabVersion($cerrado, 1),
        'outsider' => versionsTabVersion($outsider, 1),
    ];
}

/**
 * O que a linha da versão mostra em cada coluna, formatado como a tabela o
 * desenha; `null` onde a célula fica com o marcador de vazio.
 *
 * @return array<string, string|null>
 */
function versionsTabRow(Testable $component, MeasurementPlanVersion $version): array
{
    $table = $component->instance()->getTable();
    $record = $component->instance()->getTableRecord((string) $version->getKey());
    $cells = [];

    foreach (['plan', 'version_number', 'status', 'validity', 'construction_fund_amount', 'lines_count', 'revision_category', 'activated_at'] as $name) {
        $column = $table->getColumn($name);
        $column->record($record);
        $column->clearCachedState();
        $state = $column->getState();
        $cells[$name] = blank($state) ? null : (string) $column->formatState($state);
    }

    return $cells;
}

/**
 * Confere que a linha da versão oferece exatamente estas ações, entre todas
 * as ações de linha da aba.
 *
 * @param  list<string>  $visible
 */
function versionsTabAssertActions(Testable $component, MeasurementPlanVersion $version, array $visible): void
{
    foreach (['viewVersion', 'compareVersion', 'activateVersion', 'editDraft', 'createRevision', 'generateDraftLines', 'cancelDraft'] as $name) {
        in_array($name, $visible, true)
            ? $component->assertTableActionVisible($name, $version)
            : $component->assertTableActionHidden($name, $version);
    }
}

function versionsTabForgetNotifications(): void
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
}

/**
 * Notificações do Filament enviadas desde a última limpeza, lidas sem
 * consumir: o `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array{status: string|null, title: string, body: string|null}>
 */
function versionsTabNotifications(): array
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];

    return array_values(array_map(fn (array $notification): array => [
        'status' => $notification['status'] ?? null,
        'title' => (string) ($notification['title'] ?? ''),
        'body' => isset($notification['body']) ? (string) $notification['body'] : null,
    ], $notifications));
}

/**
 * O que uma recusa não pode mudar: as versões do plano, as linhas delas e a
 * trilha de auditoria, como estão gravadas.
 *
 * @return array{versions: list<array<string, mixed>>, lines: list<array<string, mixed>>, activities: int}
 */
function versionsTabFootprint(MeasurementPlanSet $planSet): array
{
    return [
        'versions' => MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->orderBy('id')->get()
            ->map(fn (MeasurementPlanVersion $version): array => $version->getAttributes())->all(),
        'lines' => MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->orderBy('id')->get()
            ->map(fn (MeasurementPlanLine $line): array => $line->getAttributes())->all(),
        'activities' => Activity::query()->count(),
    ];
}

/**
 * HTML do modal montado como a pessoa o recebe. O componente inteiro vai na
 * resposta como JSON escapado; o parcial do modal, não.
 */
function versionsTabModalHtml(Testable $component): string
{
    return $component->getMountedActionModalHtml();
}

function versionsTabDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

/**
 * Texto de cada nó que casa com a expressão, sem espaços nas pontas.
 *
 * @return list<string>
 */
function versionsTabTexts(string $html, string $expression): array
{
    $texts = [];

    foreach (versionsTabDom($html)->query($expression) as $node) {
        $texts[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    return $texts;
}

/**
 * Grade de contexto do modal (`<dl>`): o valor de cada termo.
 *
 * @return array<string, string>
 */
function versionsTabDefinitions(string $html): array
{
    $xpath = versionsTabDom($html);
    $definitions = [];

    foreach ($xpath->query('//dl/div') as $pair) {
        $definitions[trim($xpath->query('dt', $pair)->item(0)->textContent)] = trim($xpath->query('dd', $pair)->item(0)->textContent);
    }

    return $definitions;
}

/**
 * Células de cada linha do corpo das tabelas do modal, na ordem da tela.
 *
 * @return list<list<string>>
 */
function versionsTabTableRows(string $html): array
{
    $xpath = versionsTabDom($html);
    $rows = [];

    foreach ($xpath->query('//table/tbody/tr') as $row) {
        $cells = [];

        foreach ($xpath->query('th|td', $row) as $cell) {
            $cells[] = trim($cell->textContent);
        }

        $rows[] = $cells;
    }

    return $rows;
}

/**
 * A tabela do modal de ativação que fica sob o rótulo "Cronograma que a
 * ativação grava": o cabeçalho e as células de cada medição prevista. O modal
 * da revisão tem também a tabela da comparação; aqui só conta a do rótulo.
 *
 * @return array{header: list<string>, rows: list<list<string>>}
 */
function versionsTabActivationSchedule(string $html): array
{
    $xpath = versionsTabDom($html);
    $table = '//*[@role="term"][normalize-space(.)="Cronograma que a ativação grava"]'
        .'/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " fi-in-entry ")][1]//table';
    $header = [];
    $rows = [];

    foreach ($xpath->query($table.'/thead/tr/th') as $cell) {
        $header[] = trim($cell->textContent);
    }

    foreach ($xpath->query($table.'/tbody/tr') as $row) {
        $cells = [];

        foreach ($xpath->query('th|td', $row) as $cell) {
            $cells[] = trim($cell->textContent);
        }

        $rows[] = $cells;
    }

    return ['header' => $header, 'rows' => $rows];
}

// ── Tabela ───────────────────────────────────────────────────────────────────

it('lists one row per version of every plan of the operation, newest version first within each plan', function () {
    $history = versionsTabHistory();
    $this->actingAs($history['actor']);
    ['aurora' => $aurora, 'boreal' => $boreal] = $history;

    $manager = versionsTabManager($history['operation'])
        ->assertOk()
        ->assertSee('Versões do plano de medição')
        ->assertCountTableRecords(7)
        ->assertCanNotSeeTableRecords([$history['outsider']]);

    // Ordem do plano (o primeiro cadastrado antes) e, dentro dele, a versão
    // mais nova no alto: o rascunho em preparação, a vigente, as substituídas.
    expect(collect($manager->instance()->getTableRecords()->items())->map->getKey()->all())->toBe([
        $aurora[3]->id,
        $aurora[2]->id,
        $aurora[1]->id,
        $boreal[3]->id,
        $boreal[2]->id,
        $boreal[1]->id,
        $history['cerrado']->id,
    ]);
});

it('shows each version with its real validity, fund, schedule size, motive and activation', function () {
    $history = versionsTabHistory();
    $this->actingAs($history['actor']);
    ['aurora' => $aurora, 'boreal' => $boreal, 'cerrado' => $cerrado] = $history;
    $manager = versionsTabManager($history['operation']);

    // A vigência é a que a versão de fato teve: a Boreal V1 foi substituída no
    // mesmo mês em que foi ativada e não regeu competência nenhuma; o rascunho
    // mostra a vigência que teria se ativado agora (07/2026). Ativação em
    // 15/05 e 10/07 às 15h UTC aparece no horário de Brasília.
    expect([
        'Aurora V3' => versionsTabRow($manager, $aurora[3]),
        'Aurora V2' => versionsTabRow($manager, $aurora[2]),
        'Aurora V1' => versionsTabRow($manager, $aurora[1]),
        'Boreal V3' => versionsTabRow($manager, $boreal[3]),
        'Boreal V2' => versionsTabRow($manager, $boreal[2]),
        'Boreal V1' => versionsTabRow($manager, $boreal[1]),
        'Cerrado V1' => versionsTabRow($manager, $cerrado),
    ])->toBe([
        'Aurora V3' => ['plan' => 'Torre Aurora', 'version_number' => 'V3', 'status' => 'Rascunho', 'validity' => 'Se ativada agora: desde 01/07/2026', 'construction_fund_amount' => 'R$ 23.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Cronograma', 'activated_at' => null],
        'Aurora V2' => ['plan' => 'Torre Aurora', 'version_number' => 'V2', 'status' => 'Vigente', 'validity' => 'Desde 01/07/2026', 'construction_fund_amount' => 'R$ 23.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Custo', 'activated_at' => '10/07/2026 12:00'],
        'Aurora V1' => ['plan' => 'Torre Aurora', 'version_number' => 'V1', 'status' => 'Substituída', 'validity' => '01/05/2026 a 30/06/2026', 'construction_fund_amount' => 'R$ 20.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Plano inicial', 'activated_at' => '15/05/2026 12:00'],
        'Boreal V3' => ['plan' => 'Torre Boreal', 'version_number' => 'V3', 'status' => 'Cancelada', 'validity' => null, 'construction_fund_amount' => 'R$ 8.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Escopo', 'activated_at' => null],
        'Boreal V2' => ['plan' => 'Torre Boreal', 'version_number' => 'V2', 'status' => 'Vigente', 'validity' => 'Desde 01/07/2026', 'construction_fund_amount' => 'R$ 8.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Planejamento físico', 'activated_at' => '10/07/2026 12:00'],
        'Boreal V1' => ['plan' => 'Torre Boreal', 'version_number' => 'V1', 'status' => 'Substituída', 'validity' => 'Substituída no mês da própria ativação: não regeu competência', 'construction_fund_amount' => 'R$ 8.000.000,00', 'lines_count' => '3 linhas', 'revision_category' => 'Plano inicial', 'activated_at' => '10/07/2026 12:00'],
        'Cerrado V1' => ['plan' => 'Torre Cerrado', 'version_number' => 'V1', 'status' => 'Rascunho', 'validity' => 'Se ativada agora: desde 01/07/2026', 'construction_fund_amount' => 'R$ 5.000.000,00', 'lines_count' => '1 linha', 'revision_category' => 'Plano inicial', 'activated_at' => null],
    ]);

    // Cada revisão leva a própria justificativa sob o motivo, e quem ativou
    // aparece sob a data.
    $manager->assertTableColumnHasDescription('revision_category', 'Reajuste do orçamento aprovado pelo comitê.', $aurora[2])
        ->assertTableColumnHasDescription('revision_category', 'Fundação atrasou: cronograma refeito com a construtora.', $aurora[3])
        ->assertTableColumnHasDescription('activated_at', $history['actor']->name, $aurora[2]);
});

it('tells apart a version superseded in its own activation month that still has measurements sent under it', function () {
    // Torre Aurora com a V1 vigente desde 05/2026; relógio em 15/05/2026.
    $plan = versionsTabActivePlan();
    ['actor' => $actor, 'operation' => $operation, 'v1' => $auroraV1] = $plan;
    $boreal = versionsTabPlan($operation, $actor, 'Torre Boreal', '8000000.00', ['2026-05' => '20.00', '2026-06' => '20.00']);

    // A medição de maio da Aurora é enviada sob a V1 e recusada pela
    // Engenharia: deixa de ocupar a competência -- a revisão pode valer em
    // maio --, mas o arquivo continua ligado à V1.
    $measurement = Scenario::measurement([...$plan, 'lines' => versionsTabLines($auroraV1)], '2026-05');
    app(MeasurementWorkflow::class)->reject($measurement, $actor, 'Arquivo ilegível: reenviar a medição.');
    versionsTabActivate(versionsTabVersion($boreal, 1), $actor);

    // Ainda em maio, as duas obras revisam e ativam a V2: nenhuma das V1 regeu
    // competência própria.
    versionsTabActivate(versionsTabRevise($plan['planSet'], $actor, MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00'), $actor);
    versionsTabActivate(versionsTabRevise($boreal, $actor, MeasurementPlanRevisionCategory::PhysicalPlanning, 'Curva física redistribuída antes da primeira medição.'), $actor);
    $this->actingAs($actor);

    $manager = versionsTabManager($operation);

    // Só a V1 da Aurora tem medição enviada sob ela: a vigência diz isso, em
    // vez de "não regeu competência", que esconderia o arquivo dela.
    expect($measurement->fresh()->status)->toBe('rejected')
        ->and($measurement->assets()->sole()->plan_version_id)->toBe($auroraV1->id)
        ->and(versionsTabRow($manager, $auroraV1)['validity'])->toBe('Substituída no mês da própria ativação: sem competência própria, mas com medições enviadas sob ela')
        ->and(versionsTabRow($manager, versionsTabVersion($boreal, 1))['validity'])->toBe('Substituída no mês da própria ativação: não regeu competência')
        ->and(versionsTabRow($manager, versionsTabVersion($plan['planSet'], 2))['validity'])->toBe('Desde 01/05/2026');

    // O resumo da versão mostra a mesma vigência.
    $summary = versionsTabDefinitions(versionsTabModalHtml(versionsTabManager($operation)->mountTableAction('viewVersion', $auroraV1)));

    expect($summary['Vigência'])->toBe('Substituída no mês da própria ativação: sem competência própria, mas com medições enviadas sob ela');
});

it('shows a superseded version with its own summary and schedule', function () {
    $history = versionsTabHistory();
    $this->actingAs($history['actor']);

    $manager = versionsTabManager($history['operation'])->mountTableAction('viewVersion', $history['aurora'][1]);
    $html = versionsTabModalHtml($manager);

    // O histórico se lê como valeu: o fundo e o cronograma da V1, não os da
    // vigente.
    expect($manager->instance()->getMountedAction()->getModalHeading())->toBe('Torre Aurora · V1 · Substituída')
        ->and(versionsTabDefinitions($html))->toBe([
            'Situação' => 'Substituída',
            'Vigência' => '01/05/2026 a 30/06/2026',
            'Fundo de Obra' => 'R$ 20.000.000,00',
            'Motivo' => 'Plano inicial',
        ])
        ->and(versionsTabTableRows($html))->toBe([
            ['01', '05/2026', '10,00%', '10,00%'],
            ['02', '06/2026', '10,00%', '20,00%'],
            ['03', '07/2026', '10,00%', '30,00%'],
        ]);
});

// ── Ações por situação ───────────────────────────────────────────────────────

it('offers each version action only in the situation where it applies', function () {
    $history = versionsTabHistory();
    $this->actingAs($history['actor']);
    ['aurora' => $aurora, 'boreal' => $boreal] = $history;
    $manager = versionsTabManager($history['operation']);

    // Rascunho de revisão: edita, gera linhas, ativa e cancela. A revisão nova
    // não aparece na vigente da Aurora porque o plano já tem esse rascunho.
    versionsTabAssertActions($manager, $aurora[3], ['viewVersion', 'compareVersion', 'activateVersion', 'editDraft', 'generateDraftLines', 'cancelDraft']);
    versionsTabAssertActions($manager, $aurora[2], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $aurora[1], ['viewVersion']);
    // Cancelada é histórico; ainda se compara com a versão de onde partiu.
    versionsTabAssertActions($manager, $boreal[3], ['viewVersion', 'compareVersion']);
    // Vigente de um plano sem rascunho: é dela que nasce a revisão.
    versionsTabAssertActions($manager, $boreal[2], ['viewVersion', 'compareVersion', 'createRevision']);
    versionsTabAssertActions($manager, $boreal[1], ['viewVersion']);
    // Rascunho da V1: não se cancela (o plano ficaria sem versão) e não há
    // versão anterior para comparar.
    versionsTabAssertActions($manager, $history['cerrado'], ['viewVersion', 'activateVersion', 'editDraft', 'generateDraftLines']);
});

it('keeps only reading and draft cancellation once the operation is completed', function () {
    $history = versionsTabHistory();
    ['aurora' => $aurora, 'boreal' => $boreal] = $history;
    app(OperationLifecycleService::class)->complete($history['operation'], $history['actor']);
    $this->actingAs($history['actor']);
    $manager = versionsTabManager($history['operation']);

    // Operação concluída não replaneja; cancelar o rascunho que sobrou é
    // arrumação e continua possível.
    versionsTabAssertActions($manager, $aurora[3], ['viewVersion', 'compareVersion', 'cancelDraft']);
    versionsTabAssertActions($manager, $aurora[2], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $boreal[2], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $history['cerrado'], ['viewVersion']);

    $manager->callTableAction('cancelDraft', $aurora[3], data: ['cancellation_reason' => 'Operação concluída sem a revisão.'])
        ->assertHasNoTableActionErrors();

    expect($aurora[3]->fresh()->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($aurora[2]->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

it('hides every write action from a participant who can only read the operation', function () {
    $history = versionsTabHistory();
    ['aurora' => $aurora, 'boreal' => $boreal] = $history;
    $reader = User::factory()->withTwoFactor()->create();
    $reader->givePermissionTo('operations.view');
    $history['operation']->update(['assigned_user_id' => $reader->id]);
    $this->actingAs($reader);

    $manager = versionsTabManager($history['operation'])->assertOk();

    // Quem só lê a operação vê o histórico e as comparações, nada além.
    versionsTabAssertActions($manager, $aurora[3], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $aurora[2], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $boreal[3], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $boreal[2], ['viewVersion', 'compareVersion']);
    versionsTabAssertActions($manager, $history['cerrado'], ['viewVersion']);
});

// ── Ativação ─────────────────────────────────────────────────────────────────

it('activates the draft through the modal and supersedes the active version', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    versionsTabTravelTo('2026-07-10');
    $this->actingAs($plan['actor']);

    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);
    $action = $manager->instance()->getMountedAction();

    // O modal diz a partir de quando a revisão vale e o que muda com ela.
    expect($manager->get('mountedActions.0.data.expected_revision'))->toBe(1)
        ->and($action->getModalHeading())->toBe('Ativar a V2 do plano')
        ->and($action->getModalDescription())->toBe('A V2 passa a valer a partir de 01/07/2026, o mês da ativação, e a versão vigente passa a substituída. Medições já enviadas continuam na versão em que foram enviadas. O avanço físico não muda: a V2 planeja só o que resta.')
        ->and(versionsTabTableRows(versionsTabModalHtml($manager))[0])
        ->toBe(['Custo previsto (Fundo de Obra)', 'R$ 20.000.000,00', 'R$ 23.000.000,00', '+R$ 3.000.000,00 (+15,00%)']);

    versionsTabForgetNotifications();
    $manager->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    $v2->refresh();
    $v1 = $plan['v1']->fresh();

    expect(versionsTabNotifications())->toBe([
        ['status' => 'success', 'title' => 'V2 ativada.', 'body' => 'As próximas medições deste plano usam esta versão.'],
    ])
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2026-07-01')
        ->and($v2->activated_by)->toBe($plan['actor']->id)
        ->and($v2->activation_progress_percent)->toBe('35.00')
        ->and($v1->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->superseded_by_version_id)->toBe($v2->id)
        ->and($v1->superseded_at->toDateTimeString())->toBe('2026-07-10 15:00:00');
});

it('shows in the activation modal the schedule the activation records, marking each recalculated cumulative', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Schedule, 'Junho acelerado com a construtora; agosto acrescentado.');
    $lines = versionsTabLines($v2);

    // Junho passa a 15% e agosto entra com 5%; os acumulados vão como a
    // pessoa os deixou, sem refazer a soma.
    $v2 = app(MeasurementPlanVersionService::class)->updateDraft($v2, $plan['actor'], [], [
        ['id' => $lines['2026-05']->id, 'sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '45.00', 'measurement_date' => '2026-05'],
        ['id' => $lines['2026-06']->id, 'sequence_number' => 2, 'planned_monthly_percent' => '15.00', 'planned_cumulative_percent' => '55.00', 'measurement_date' => '2026-06'],
        ['id' => $lines['2026-07']->id, 'sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '65.00', 'measurement_date' => '2026-07'],
        ['sequence_number' => 4, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '70.00', 'measurement_date' => '2026-08'],
    ], (int) $v2->revision);
    $this->actingAs($plan['actor']);

    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);

    // Ativada em 05/2026, a revisão soma o avanço atual (35%) e os previstos
    // mensais: maio fica como estava, o resto muda e vem marcado.
    expect(versionsTabActivationSchedule(versionsTabModalHtml($manager)))->toBe([
        'header' => ['Medição', 'Mês', 'Previsto mensal', 'Acumulado no rascunho', 'Acumulado na ativação'],
        'rows' => [
            ['01', '05/2026', '10,00%', '45,00%', '45,00%'],
            ['02', '06/2026', '15,00%', '55,00%', '60,00% (recalculado)'],
            ['03', '07/2026', '10,00%', '65,00%', '70,00% (recalculado)'],
            ['04', '08/2026', '5,00%', '70,00%', '75,00% (recalculado)'],
        ],
    ]);

    $manager->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    // O que o modal mostrou é o que a ativação gravou.
    expect($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and(array_map(fn (MeasurementPlanLine $line): string => $line->planned_cumulative_percent, versionsTabLines($v2)))->toBe([
            '2026-05' => '45.00',
            '2026-06' => '60.00',
            '2026-07' => '70.00',
            '2026-08' => '75.00',
        ]);
});

it('shows in the V1 activation modal the cumulative of every line recalculated over the initial progress', function () {
    // Planilha da obra com o acumulado somado desde zero; a obra já tinha 35%
    // executados até 30/04/2026.
    versionsTabTravelTo('2026-05-15');
    ['actor' => $actor, 'operation' => $operation] = versionsTabOperation();
    $planSet = versionsTabPlan($operation, $actor, 'Torre Aurora', '20000000.00', [
        '2026-05' => '10.00',
        '2026-06' => '10.00',
        '2026-07' => '10.00',
    ], '35.00', '2026-04-30');
    $this->actingAs($actor);

    $manager = versionsTabManager($operation)->mountTableAction('activateVersion', versionsTabVersion($planSet, 1));

    expect(versionsTabActivationSchedule(versionsTabModalHtml($manager))['rows'])->toBe([
        ['01', '05/2026', '10,00%', '10,00%', '45,00% (recalculado)'],
        ['02', '06/2026', '10,00%', '20,00%', '55,00% (recalculado)'],
        ['03', '07/2026', '10,00%', '30,00%', '65,00% (recalculado)'],
    ]);
});

it('marks in the V1 activation modal the planned measurement of a competence the operation already measured without the plan', function () {
    versionsTabTravelTo('2026-05-15');
    ['actor' => $actor, 'operation' => $operation] = versionsTabOperation();
    $tower = versionsTabPlan($operation, $actor, 'Torre Aurora', '20000000.00', ['2026-05' => '10.00', '2026-06' => '10.00']);
    versionsTabActivate(versionsTabVersion($tower, 1), $actor);

    // Maio é enviado só com a torre; o anexo entra depois, com maio previsto.
    $may = Scenario::measurement([
        'actor' => $actor,
        'operation' => $operation,
        'planSet' => $tower,
        'lines' => versionsTabLines(versionsTabVersion($tower, 1)),
    ], '2026-05');
    $annex = versionsTabPlan($operation, $actor, 'Torre Boreal', '8000000.00', ['2026-05' => '10.00', '2026-06' => '10.00']);
    $this->actingAs($actor);

    $manager = versionsTabManager($operation)->mountTableAction('activateVersion', versionsTabVersion($annex, 1));

    // Enquanto a medição de maio estiver de pé, a medição prevista de maio do
    // anexo não tem como ser medida: o modal a mostra fora do cálculo, e junho
    // parte do avanço atual sem ela.
    expect(versionsTabActivationSchedule(versionsTabModalHtml($manager))['rows'])->toBe([
        ['01', '05/2026', '10,00%', '10,00%', sprintf('fora do cálculo: competência já medida sem este plano (#%d)', $may->id)],
        ['02', '06/2026', '10,00%', '20,00%', '10,00% (recalculado)'],
    ]);
});

it('keeps the activation modal open and explains a refusal that has no field in it', function () {
    $plan = versionsTabActivePlan();
    // Revisão aberta sem justificativa (o serviço aceita o rascunho assim; é a
    // ativação que a exige).
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, null, '23000000.00');
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    // O modal de ativação não tem o campo da justificativa: a recusa vira aviso
    // e o modal continua aberto.
    $manager->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionHalted(TestAction::make('activateVersion')->table($v2));

    expect(versionsTabNotifications())->toBe([
        ['status' => 'danger', 'title' => 'Versão do plano não alterada.', 'body' => 'Justifique a revisão do plano antes de ativá-la.'],
    ])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before)
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($plan['v1']->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

it('refuses to activate a draft that someone else saved after the modal was opened', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $colleague = makeAdminUser();
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);

    // Com o modal aberto, outra pessoa grava outro Fundo de Obra no rascunho:
    // ativar o que a pessoa viu ativaria um custo que ela não conferiu.
    app(MeasurementPlanVersionService::class)->updateDraft($v2->fresh(), $colleague, ['construction_fund_amount' => '24000000.00'], null, 1);
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    $manager->callMountedTableAction()
        ->assertActionHalted(TestAction::make('activateVersion')->table($v2));

    expect(versionsTabNotifications())->toBe([
        ['status' => 'danger', 'title' => 'Versão do plano não alterada.', 'body' => sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V2')],
    ])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before)
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($v2->fresh()->construction_fund_amount)->toBe('24000000.00')
        ->and($plan['v1']->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

it('tells the person the draft was activated by someone else while the modal was open', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $colleague = makeAdminUser();
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);

    versionsTabActivate($v2, $colleague);
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    // A ação deixou de valer: o envio responde com o motivo, em vez de ser
    // ignorado calado, e o modal fecha.
    $manager->callMountedTableAction()->assertActionNotMounted();

    expect(versionsTabNotifications())->toBe([
        ['status' => 'danger', 'title' => 'Versão do plano não alterada.', 'body' => 'A situação da versão mudou desde que você abriu esta ação. Recarregue a página.'],
    ])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before)
        ->and($v2->fresh()->activated_by)->toBe($colleague->id);
});

it('tells the person they can no longer change the plans when the permission goes away with the modal open', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('activateVersion', $v2);

    // Continua participando e lendo a operação, mas deixa de poder alterá-la.
    $plan['actor']->syncRoles([]);
    $plan['actor']->givePermissionTo('operations.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    $manager->callMountedTableAction()->assertActionNotMounted();

    expect(versionsTabNotifications())->toBe([
        ['status' => 'danger', 'title' => 'Versão do plano não alterada.', 'body' => 'Você não pode mais alterar os planos de medição desta operação.'],
    ])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before)
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft);
});

// ── Edição do rascunho ───────────────────────────────────────────────────────

it('fills the draft editor with the draft and shows where the plan stands', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $lines = versionsTabLines($v2);
    $this->actingAs($plan['actor']);

    $manager = versionsTabManager($plan['operation'])->mountTableAction('editDraft', $v2);

    // O cronograma vem da cópia da V1, com o acumulado que a ativação da V1
    // gravou sobre os 35% iniciais (os campos numéricos guardam número; o
    // fundo, o texto mascarado).
    expect($manager->get('mountedActions.0.data.expected_revision'))->toBe(1)
        ->and($manager->get('mountedActions.0.data.construction_fund_amount'))->toBe('23.000.000,00')
        ->and($manager->get('mountedActions.0.data.revision_category'))->toBe('cost')
        ->and($manager->get('mountedActions.0.data.revision_reason'))->toBe('Reajuste do orçamento aprovado pelo comitê.')
        ->and(array_values($manager->get('mountedActions.0.data.lines')))->toBe([
            ['id' => $lines['2026-05']->id, 'sequence_number' => 1, 'planned_monthly_percent' => 10.0, 'planned_cumulative_percent' => 45.0, 'measurement_date' => '2026-05'],
            ['id' => $lines['2026-06']->id, 'sequence_number' => 2, 'planned_monthly_percent' => 10.0, 'planned_cumulative_percent' => 55.0, 'measurement_date' => '2026-06'],
            ['id' => $lines['2026-07']->id, 'sequence_number' => 3, 'planned_monthly_percent' => 10.0, 'planned_cumulative_percent' => 65.0, 'measurement_date' => '2026-07'],
        ]);

    // A revisão planeja só o que resta: o modal mostra o avanço atual e o
    // restante, que a revisão não muda.
    expect(versionsTabDefinitions(versionsTabModalHtml($manager)))->toBe([
        'Versão vigente' => 'V1 · desde 01/05/2026',
        'Vigência se ativado agora' => 'Desde 01/05/2026',
        'Avanço físico atual' => '35,00%',
        'Restante a planejar' => '65,00%',
    ]);
});

it('saves the whole draft schedule: updates by id, adds the new line and removes the missing one', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $lines = versionsTabLines($v2);
    $v1Before = versionsTabLines($plan['v1']);
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('editDraft', $v2);

    // O Repeater chaveia cada medição prevista por uuid, como no navegador:
    // junho sai, julho muda e agosto entra pelo "Adicionar medição".
    $schedule = $manager->get('mountedActions.0.data.lines');
    $keys = array_flip(array_map(fn (array $row): int => (int) $row['id'], $schedule));
    unset($schedule[$keys[$lines['2026-06']->id]]);
    $schedule[$keys[$lines['2026-07']->id]]['planned_monthly_percent'] = '15';
    $schedule[(string) Str::uuid()] = ['id' => null, 'sequence_number' => 4, 'planned_monthly_percent' => '10', 'planned_cumulative_percent' => '75', 'measurement_date' => '2026-08'];
    versionsTabForgetNotifications();

    $manager->set('mountedActions.0.data.lines', $schedule)
        ->set('mountedActions.0.data.construction_fund_amount', '24.500.000,00')
        ->set('mountedActions.0.data.revision_category', MeasurementPlanRevisionCategory::Multiple->value)
        ->set('mountedActions.0.data.revision_reason', 'Reajuste do orçamento e um mês a mais de obra.')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    $v2->refresh();
    $saved = versionsTabLines($v2);

    expect(versionsTabNotifications())->toBe([
        ['status' => 'success', 'title' => 'Rascunho da V2 salvo.', 'body' => null],
    ])
        ->and($v2->revision)->toBe(2)
        ->and($v2->construction_fund_amount)->toBe('24500000.00')
        ->and($v2->revision_category)->toBe(MeasurementPlanRevisionCategory::Multiple)
        ->and($v2->revision_reason)->toBe('Reajuste do orçamento e um mês a mais de obra.')
        ->and(array_map(fn (MeasurementPlanLine $line): array => [
            $line->sequence_number,
            $line->planned_monthly_percent,
            $line->planned_cumulative_percent,
        ], $saved))->toBe([
            '2026-05' => [1, '10.00', '45.00'],
            '2026-07' => [3, '15.00', '65.00'],
            '2026-08' => [4, '10.00', '75.00'],
        ])
        // Maio e julho são as mesmas linhas, na mesma linhagem da V1; junho
        // saiu; agosto é medição prevista nova, com linhagem nova.
        ->and($saved['2026-05']->id)->toBe($lines['2026-05']->id)
        ->and($saved['2026-07']->id)->toBe($lines['2026-07']->id)
        ->and($saved['2026-07']->lineage_key)->toBe($v1Before['2026-07']->lineage_key)
        ->and(MeasurementPlanLine::query()->whereKey($lines['2026-06']->id)->exists())->toBeFalse()
        ->and(collect($v1Before)->pluck('lineage_key')->all())->not->toContain($saved['2026-08']->lineage_key)
        // A versão vigente não muda com o rascunho.
        ->and(array_map(fn (MeasurementPlanLine $line): array => $line->getAttributes(), versionsTabLines($plan['v1']->fresh())))
        ->toBe(array_map(fn (MeasurementPlanLine $line): array => $line->getAttributes(), $v1Before));
});

it('refuses to overwrite a draft that someone else saved while the editor was open', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $lines = versionsTabLines($v2);
    $colleague = makeAdminUser();
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('editDraft', $v2);

    // A outra pessoa grava primeiro; o envio de quem abriu antes perderia a
    // mudança dela sem que ninguém a visse.
    app(MeasurementPlanVersionService::class)->updateDraft($v2->fresh(), $colleague, ['construction_fund_amount' => '24000000.00'], null, 1);
    $schedule = $manager->get('mountedActions.0.data.lines');
    $july = array_search($lines['2026-07']->id, array_map(fn (array $row): int => (int) $row['id'], $schedule), true);
    $schedule[$july]['planned_monthly_percent'] = '15';
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    $manager->set('mountedActions.0.data.lines', $schedule)
        ->set('mountedActions.0.data.construction_fund_amount', '25.000.000,00')
        ->callMountedTableAction()
        ->assertActionHalted(TestAction::make('editDraft')->table($v2));

    expect(versionsTabNotifications())->toBe([
        ['status' => 'danger', 'title' => 'Versão do plano não alterada.', 'body' => sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V2')],
    ])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before)
        ->and($v2->fresh()->construction_fund_amount)->toBe('24000000.00')
        ->and($lines['2026-07']->fresh()->planned_monthly_percent)->toBe('10.00');
});

it('shows the schedule validation errors on the repeater row that caused them', function (string $case, string $field, string $message) {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $lines = versionsTabLines($v2);
    $this->actingAs($plan['actor']);
    $manager = versionsTabManager($plan['operation'])->mountTableAction('editDraft', $v2);
    $schedule = $manager->get('mountedActions.0.data.lines');
    $keys = array_flip(array_map(fn (array $row): int => (int) $row['id'], $schedule));
    $newKey = (string) Str::uuid();

    match ($case) {
        // O próprio campo recusa: o previsto vai de 0% a 100%.
        'previsto acima de 100%' => $schedule[$keys[$lines['2026-07']->id]]['planned_monthly_percent'] = '150',
        // Só o serviço de versões enxerga o cronograma inteiro: a medição
        // prevista nova repete o número da de maio.
        'medição prevista repetida' => $schedule[$newKey] = ['id' => null, 'sequence_number' => 1, 'planned_monthly_percent' => '10', 'planned_cumulative_percent' => '75', 'measurement_date' => '2026-08'],
    };

    $row = $case === 'previsto acima de 100%' ? $keys[$lines['2026-07']->id] : $newKey;
    $before = versionsTabFootprint($plan['planSet']);
    versionsTabForgetNotifications();

    $manager->set('mountedActions.0.data.lines', $schedule)
        ->callMountedTableAction()
        ->assertActionHalted(TestAction::make('editDraft')->table($v2));

    // A mensagem fica no campo da linha que a causou, sem aviso à parte, e o
    // rascunho não muda.
    expect($manager->errors()->get("mountedActions.0.data.lines.{$row}.{$field}"))->toBe([$message])
        ->and(versionsTabNotifications())->toBe([])
        ->and(versionsTabFootprint($plan['planSet']))->toBe($before);
})->with([
    'previsto acima de 100%' => ['previsto acima de 100%', 'planned_monthly_percent', 'O campo previsto mensal (%) não deve ser maior que 100.'],
    'medição prevista repetida' => ['medição prevista repetida', 'sequence_number', 'A medição prevista 1 aparece mais de uma vez no cronograma.'],
]);

// ── Geração de linhas e cancelamento ─────────────────────────────────────────

it('generates monthly planned measurements at 0% in the draft from the chosen month', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Schedule, 'Prorrogação do prazo da obra.');
    $copied = versionsTabLines($v2);
    $this->actingAs($plan['actor']);

    // O mês sugerido é o corrente no calendário de negócio.
    $manager = versionsTabManager($plan['operation'])->mountTableAction('generateDraftLines', $v2);

    expect($manager->get('mountedActions.0.data.count'))->toBe(12)
        ->and($manager->get('mountedActions.0.data.start_date'))->toBe('2026-05')
        ->and($manager->get('mountedActions.0.data.expected_revision'))->toBe(0);

    // Mais de cinco anos de medições de uma vez não passa do campo.
    $before = versionsTabFootprint($plan['planSet']);
    $manager->set('mountedActions.0.data.count', 61)
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['count' => 'max']);

    expect(versionsTabFootprint($plan['planSet']))->toBe($before);

    versionsTabForgetNotifications();
    $manager->set('mountedActions.0.data.count', 3)
        ->set('mountedActions.0.data.start_date', '2026-08')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $generated = array_diff_key(versionsTabLines($v2), $copied);

    expect(versionsTabNotifications())->toBe([
        ['status' => 'success', 'title' => '3 medições previstas adicionadas ao rascunho.', 'body' => null],
    ])
        ->and($v2->fresh()->revision)->toBe(1)
        ->and(array_map(fn (MeasurementPlanLine $line): array => [
            $line->sequence_number,
            $line->planned_monthly_percent,
            $line->planned_cumulative_percent,
        ], $generated))->toBe([
            '2026-08' => [4, '0.00', '0.00'],
            '2026-09' => [5, '0.00', '0.00'],
            '2026-10' => [6, '0.00', '0.00'],
        ])
        ->and(count(versionsTabLines($plan['v1']->fresh())))->toBe(3);
});

it('cancels a revision draft only with a reason', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $this->actingAs($plan['actor']);
    $before = versionsTabFootprint($plan['planSet']);

    versionsTabManager($plan['operation'])
        ->callTableAction('cancelDraft', $v2, data: ['cancellation_reason' => ''])
        ->assertHasTableActionErrors(['cancellation_reason' => 'required']);

    expect(versionsTabFootprint($plan['planSet']))->toBe($before);

    versionsTabForgetNotifications();
    versionsTabManager($plan['operation'])
        ->callTableAction('cancelDraft', $v2, data: ['cancellation_reason' => 'O comitê manteve o orçamento original.'])
        ->assertHasNoTableActionErrors();

    $v2->refresh();

    // O rascunho fica registrado como cancelado, com o número que tinha; a
    // vigente não muda.
    expect(versionsTabNotifications())->toBe([
        ['status' => 'success', 'title' => 'Rascunho da V2 cancelado.', 'body' => null],
    ])
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($v2->version_number)->toBe(2)
        ->and($v2->cancellation_reason)->toBe('O comitê manteve o orçamento original.')
        ->and($v2->cancelled_by)->toBe($plan['actor']->id)
        ->and($v2->cancelled_at->toDateTimeString())->toBe('2026-05-15 15:00:00')
        ->and($plan['v1']->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

// ── Comparação ───────────────────────────────────────────────────────────────

it('compares the revision with the previous version in cost, completion, progress and schedule', function () {
    $plan = versionsTabActivePlan();
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Multiple, 'Reajuste do orçamento e três meses a mais de obra.', '23000000.00');
    $lines = versionsTabLines($v2);

    // Junho sai, julho passa a 15% e entram agosto, setembro e outubro.
    app(MeasurementPlanVersionService::class)->updateDraft($v2, $plan['actor'], [], [
        ['id' => $lines['2026-05']->id, 'sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '45.00', 'measurement_date' => '2026-05'],
        ['id' => $lines['2026-07']->id, 'sequence_number' => 3, 'planned_monthly_percent' => '15.00', 'planned_cumulative_percent' => '60.00', 'measurement_date' => '2026-07'],
        ['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '70.00', 'measurement_date' => '2026-08'],
        ['sequence_number' => 5, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '80.00', 'measurement_date' => '2026-09'],
        ['sequence_number' => 6, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '90.00', 'measurement_date' => '2026-10'],
    ], (int) $v2->revision);
    $this->actingAs($plan['actor']);

    $manager = versionsTabManager($plan['operation'])->mountTableAction('compareVersion', $v2);
    $html = versionsTabModalHtml($manager);

    // O término é o último dia do mês da última medição prevista com avanço:
    // de 31/07 para 31/10, 92 dias. O avanço físico é do plano, o mesmo nas
    // duas versões; o acumulado do rascunho é o que a ativação gravaria hoje.
    expect($manager->instance()->getMountedAction()->getModalHeading())->toBe('V2 comparada com a V1')
        ->and(versionsTabTexts($html, '//table/thead/tr/th'))->toBe(['', 'V1', 'V2', 'Variação'])
        ->and(versionsTabTableRows($html))->toBe([
            ['Custo previsto (Fundo de Obra)', 'R$ 20.000.000,00', 'R$ 23.000.000,00', '+R$ 3.000.000,00 (+15,00%)'],
            ['Término previsto', '31/07/2026', '31/10/2026', '+92 dias'],
            ['Avanço físico atual', '35,00%', '35,00%', 'inalterado'],
            ['Avanço físico restante', '65,00%', '65,00%', 'inalterado'],
            ['Acumulado previsto ao final', '65,00%', '90,00%', ''],
            ['Vigência desde', '01/05/2026', '01/05/2026 (se ativada agora)', ''],
        ])
        ->and(versionsTabTexts($html, '//p[starts-with(normalize-space(.), "Cronograma:")]'))
        ->toBe(['Cronograma: 3 nova(s), 1 removida(s), 1 alterada(s), 1 igual(is).'])
        ->and(versionsTabTexts($html, '//ul/li'))->toBe([
            'Nova: 04 (08/2026, mensal 10,00%, acumulado 70,00%)',
            'Nova: 05 (09/2026, mensal 10,00%, acumulado 80,00%)',
            'Nova: 06 (10/2026, mensal 10,00%, acumulado 90,00%)',
            'Removida: 02 (06/2026, mensal 10,00%, acumulado 55,00%)',
            'Alterada: 03 (07/2026, mensal 10,00%, acumulado 65,00%) → 03 (07/2026, mensal 15,00%, acumulado 60,00%)',
        ]);
});

it('warns before the activation that a late measurement of a month before the vigência is sent under the revision and its fund', function () {
    // Torre Aurora com a V1 vigente desde 05/2026 e 35% executados: maio
    // medido e aprovado sob a V1 (10%); junho ainda sem medição.
    $plan = versionsTabActivePlan();
    Scenario::measured([...$plan, 'lines' => versionsTabLines($plan['v1'])], '2026-05', 10);
    $v2 = versionsTabRevise($plan['planSet'], $plan['actor'], MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00');
    $this->actingAs($plan['actor']);
    $note = 'A medição de uma competência anterior à vigência enviada depois da ativação fica ligada à V2 e ao Fundo de Obra dela.';
    $pendingRow = ['Previsto ainda não medido antes da vigência', '10,00%', '10,00%', 'continua a medir, sob a versão vigente no envio'];

    // Ativada ainda em maio, a V2 valeria desde 05/2026: nenhuma competência
    // anterior à vigência fica por medir, e os modais não trazem o aviso.
    foreach (['compareVersion', 'activateVersion'] as $action) {
        $html = versionsTabModalHtml(versionsTabManager($plan['operation'])->mountTableAction($action, $v2));

        expect(versionsTabTableRows($html))->not->toContain($pendingRow)
            ->and(versionsTabTexts($html, '//p'))->not->toContain($note);
    }

    // Em 10/07 a vigência seria 07/2026, e o previsto de junho continua a
    // medir. A medição fica ligada à versão vigente no envio: a de junho,
    // enviada depois da ativação, usaria a V2 e o Fundo de Obra dela -- os
    // dois modais dizem isso antes de a pessoa decidir.
    versionsTabTravelTo('2026-07-10');

    foreach (['compareVersion', 'activateVersion'] as $action) {
        $html = versionsTabModalHtml(versionsTabManager($plan['operation'])->mountTableAction($action, $v2));

        expect(versionsTabTableRows($html))->toContain($pendingRow)
            ->and(versionsTabTexts($html, '//p'))->toContain($note);
    }
});

// ── Cronograma (Acompanhamento) ──────────────────────────────────────────────

it('tracks only the active version lines and opens the lineage measurement from the revised copy', function () {
    // Obra com 30% executados até 31/05/2026 e a V1 vigente desde 06/2026.
    versionsTabTravelTo('2026-06-01');
    ['actor' => $actor, 'operation' => $operation] = versionsTabOperation();
    $planSet = versionsTabPlan($operation, $actor, 'Torre Aurora', '20000000.00', [
        '2026-06' => '10.00',
        '2026-07' => '10.00',
        '2026-08' => '10.00',
        '2026-09' => '10.00',
    ], '30.00', '2026-05-31');
    $v1 = versionsTabActivate(versionsTabVersion($planSet, 1), $actor);
    $scenario = ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet->fresh()];

    // Junho medido e aprovado pela Engenharia sob a V1.
    versionsTabTravelTo('2026-06-20');
    $june = Scenario::measured([...$scenario, 'lines' => versionsTabLines($v1)], '2026-06', 10);

    // A V2 passa a valer em julho, e a medição de julho é enviada sob ela.
    versionsTabTravelTo('2026-07-01');
    $v2 = versionsTabActivate(versionsTabRevise($planSet, $actor, MeasurementPlanRevisionCategory::Cost, 'Reajuste do orçamento aprovado pelo comitê.', '23000000.00'), $actor);
    $v2Lines = versionsTabLines($v2);
    $july = Scenario::measurement([...$scenario, 'lines' => $v2Lines], '2026-07');

    // Rascunho seguinte e um plano ainda não ativado: nenhum dos dois é
    // cronograma em vigor.
    $v3 = versionsTabRevise($planSet, $actor, MeasurementPlanRevisionCategory::Schedule, 'Prorrogação do prazo da obra.');
    $pending = versionsTabPlan($operation, $actor, 'Torre Boreal', '8000000.00', ['2026-08' => '10.00']);
    $this->actingAs($actor);

    $schedule = Livewire::test(PlanLinesRelationManager::class, ['ownerRecord' => $operation->fresh(), 'pageClass' => ViewOperation::class])
        ->assertOk()
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords(array_values($v2Lines))
        ->assertCanNotSeeTableRecords([
            ...array_values(versionsTabLines($v1)),
            ...array_values(versionsTabLines($v3)),
            ...array_values(versionsTabLines(versionsTabVersion($pending, 1))),
        ]);

    foreach ($v2Lines as $line) {
        $schedule->assertTableColumnFormattedStateSet('version.version_number', 'V2', $line);
    }

    // A cópia de junho na V2 não guarda a medição: o "Arquivo" a encontra pela
    // linhagem, na aprovação feita sob a V1. Julho abre a medição que ocupa a
    // linha agora; agosto e setembro ainda não têm medição.
    expect($v2Lines['2026-06']->measurement_id)->toBeNull();

    $schedule->assertTableActionVisible('openMeasurement', $v2Lines['2026-06'])
        ->assertTableActionHasLabel('openMeasurement', 'Arquivo', $v2Lines['2026-06'])
        ->assertTableActionHasUrl('openMeasurement', MeasurementResource::getUrl('view', ['record' => $june->id]), $v2Lines['2026-06'])
        ->assertTableActionHasUrl('openMeasurement', MeasurementResource::getUrl('view', ['record' => $july->id]), $v2Lines['2026-07'])
        ->assertTableActionHidden('openMeasurement', $v2Lines['2026-08'])
        ->assertTableActionHidden('openMeasurement', $v2Lines['2026-09']);
});
