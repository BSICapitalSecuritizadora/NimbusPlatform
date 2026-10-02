<?php

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\AccessPermission;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardSource;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * O schema do Quadro de Vendas contra o que a fonte realmente aceita.
 *
 * O SQLite ignora a largura de um varchar e guarda qualquer inteiro em 64 bits;
 * o MySQL de produção, em modo estrito, recusa o insert inteiro. Um valor que a
 * fonte aceita e o snapshot não comporta passa na suíte e derruba a geração da
 * competência só em produção -- por isso o arquivo roda também no MySQL.
 */
pest()->group('parity');

/**
 * Um identificador com exatamente `$length` caracteres, acentuados de propósito:
 * o MySQL conta caracteres, não bytes, e é isso que se quer provar.
 */
function schemaParityIdentifier(string $prefix, int $length): string
{
    return mb_str_pad($prefix, $length, 'ÃçÉ');
}

/**
 * @return list<string>
 */
function schemaParitySalesBoardTables(): array
{
    return collect(Schema::getTableListing(Schema::getCurrentSchemaListing(), false))
        ->filter(fn (string $table): bool => str_starts_with($table, 'sales_board') || str_starts_with($table, 'sales_discount'))
        ->values()
        ->all();
}

it('freezes block and unit identifiers as wide as the construction unit accepts', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $block = schemaParityIdentifier('Torre Residencial Jardim das Acácias - Bloco B Norte ', 255);
    $unit = schemaParityIdentifier('Loja térrea de esquina com mezanino ', 200);

    $units[0]->forceFill(['block' => $block])->save();
    $units[1]->forceFill(['block' => $block, 'unit' => $unit])->save();

    $sale = DerivationFixture::contract($units[1]->fresh(), '2026-07-05', '480000.00');
    DerivationFixture::installment($sale, '001', '2026-08-05', '480000.00');

    $result = CycleFixture::generate($construction);

    expect($result->wasGenerated())->toBeTrue();

    $baseline = CycleFixture::currentBaseline($result->cycle);
    $stockLine = $baseline->lines->firstWhere('construction_unit_id', $units[0]->id);
    $soldLine = $baseline->lines->firstWhere('construction_unit_id', $units[1]->id);
    $saleMovement = $baseline->movements->firstWhere('contract_id', $sale->id);

    expect(mb_strlen($block))->toBe(255)
        ->and(mb_strlen($unit))->toBe(200)
        ->and($stockLine->block)->toBe($block)
        ->and($soldLine->block)->toBe($block)
        ->and($soldLine->unit)->toBe($unit)
        ->and($saleMovement->block)->toBe($block)
        ->and($saleMovement->unit)->toBe($unit)
        // O que voltou do banco reconstrói o mesmo fingerprint, e a fonte viva
        // não acusa alteração: nada foi truncado no caminho.
        ->and(SalesBoardComparableSnapshot::fromBaseline($baseline->fresh())->snapshot->fingerprint())
        ->toBe($baseline->snapshot_fingerprint)
        ->and(CycleFixture::check($result->cycle)->isStale())->toBeFalse();
});

/**
 * A referência simbólica de R$ 1,00 contra uma venda de R$ 600 mil já não
 * congela: está a uma ordem de grandeza da tabela, e a apuração recusa a fonte.
 * O ágio de -5.999.990.000 basis points que este arquivo provava caber no banco
 * deixou de ser alcançável por uma versão congelada.
 */
it('refuses to freeze a sale ten times the unit reference', function () {
    [$construction] = CycleFixture::readyConstruction(1);

    $unit = DerivationFixture::unit($construction, '900', '1.00');
    $sale = DerivationFixture::contract($unit, '2026-07-10', '600000.00');
    DerivationFixture::installment($sale, '001', '2026-12-10', '600000.00');

    $result = CycleFixture::generate($construction);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and($result->readiness->blockingIssueCounts())->toHaveKey(SalesBoardIssueCode::SaleValueOutOfScale->value)
        ->and($result->cycle)->toBeNull();
});

/**
 * O ágio mais largo que ainda está dentro da escala -- um centavo abaixo de dez
 * vezes a tabela -- congela com o basis point exato, e o que volta do banco
 * reconstrói o mesmo fingerprint.
 */
it('freezes the widest premium still inside the scale', function () {
    [$construction] = CycleFixture::readyConstruction(1);

    $unit = DerivationFixture::unit($construction, '900', '500000.00');
    $sale = DerivationFixture::contract($unit, '2026-07-10', '4999999.99');
    DerivationFixture::installment($sale, '001', '2026-12-10', '4999999.99');

    $result = CycleFixture::generate($construction);

    expect($result->wasGenerated())->toBeTrue();

    $baseline = CycleFixture::currentBaseline($result->cycle);
    $movement = SalesBoardCycleMovement::query()
        ->where('sales_board_cycle_baseline_id', $baseline->id)
        ->where('contract_id', $sale->id)
        ->sole();

    expect($movement->effective_discount_basis_points)->toBe(-90_000)
        ->and(SalesBoardComparableSnapshot::fromBaseline($baseline->fresh())->snapshot->fingerprint())
        ->toBe($baseline->snapshot_fingerprint);
});

/**
 * Os avisos congelados com a versão voltam do banco como foram gravados. O
 * MySQL reordena as chaves de um objeto JSON, então a comparação ordena as
 * chaves antes: o que importa é o conteúdo, e a ordem da lista, que é a da
 * versão, se mantém.
 */
it('round-trips the frozen warnings of a version through the database', function () {
    [$construction] = CycleFixture::readyConstruction(1);

    $unit = DerivationFixture::unit($construction, schemaParityIdentifier('Torre ', 255), '500000.00');
    $atypical = DerivationFixture::contract($unit, '2026-07-10', '1200000.00');
    DerivationFixture::installment($atypical, '001', '2026-12-10', '1200000.00');

    $result = CycleFixture::generate($construction);
    $written = SalesBoardFrozenWarnings::fromPosition($result->position);

    $stored = SalesBoardCycleBaseline::query()->findOrFail($result->baseline->id)->frozenWarnings();

    expect($result->wasGenerated())->toBeTrue()
        ->and($written)->not->toBe([])
        ->and(array_column($stored, 'code'))->toBe(array_column($written, 'code'))
        ->and(Arr::sortRecursive($stored))->toBe(Arr::sortRecursive($written))
        ->and(mb_strlen((string) $stored[0]['unit_label']))->toBe(mb_strlen((string) $written[0]['unit_label']));
});

it('freezes a reviewer whose name and e-mail use the full width of the users table', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $domain = '@construtora.example';
    $name = schemaParityIdentifier('Responsável Comercial da Construtora ', 255);
    $email = str_repeat('a', 255 - strlen($domain)).$domain;
    $actor = User::factory()->create(['name' => $name, 'email' => $email]);
    $actor->givePermissionTo(AccessPermission::SalesBoardsUpdate->value);

    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review, $actor)->fresh();

    expect(mb_strlen($name))->toBe(255)
        ->and(strlen($email))->toBe(255)
        ->and($submitted->reviewer_name)->toBe($name)
        ->and($submitted->reviewer_email)->toBe($email);
});

it('keeps no Sales Board index that only repeats the leading columns of another', function () {
    $redundant = [];

    foreach (schemaParitySalesBoardTables() as $table) {
        $indexes = collect(Schema::getIndexes($table))->reject(fn (array $index): bool => $index['primary']);

        foreach ($indexes->reject(fn (array $index): bool => $index['unique']) as $index) {
            $width = count($index['columns']);

            foreach ($indexes as $other) {
                $coversIt = $other['name'] !== $index['name']
                    && count($other['columns']) >= $width
                    && array_slice($other['columns'], 0, $width) === $index['columns'];

                if ($coversIt) {
                    $redundant[] = "{$table}.{$index['name']} repete o início de {$other['name']}";
                }
            }
        }
    }

    expect(schemaParitySalesBoardTables())->toContain('sales_board_cycle_movements', 'sales_board_rollout_recipients')
        ->and($redundant)->toBe([]);
});

it('still indexes the foreign keys whose separate index was dropped', function (string $table, string $column, string $unique) {
    $leadingColumns = collect(Schema::getIndexes($table))
        ->filter(fn (array $index): bool => $index['columns'][0] === $column)
        ->pluck('name')
        ->all();

    expect(collect(Schema::getForeignKeys($table))->pluck('columns')->all())->toContain([$column])
        ->and($leadingColumns)->toContain($unique);
})->with([
    'versão dos movimentos' => [
        'sales_board_cycle_movements',
        'sales_board_cycle_baseline_id',
        'sales_board_cycle_movements_baseline_type_contract_unique',
    ],
    'Emissão dos destinatários' => [
        'sales_board_rollout_recipients',
        'emission_id',
        'sb_rollout_recipients_emission_role_user_unique',
    ],
    'ciclo das publicações' => [
        'sales_board_publications',
        'sales_board_cycle_id',
        'sales_board_publications_cycle_sequence_unique',
    ],
    'ciclo das retificações' => [
        'sales_board_cycle_rectifications',
        'sales_board_cycle_id',
        'sb_rectifications_cycle_sequence_unique',
    ],
]);

it('keeps the rollout migration frozen, independent of application code', function () {
    $source = file_get_contents(database_path('migrations/2026_09_07_100000_add_sales_board_rollout_to_emissions_table.php'));

    /**
     * O literal da migration e o enum precisam continuar iguais -- é o default
     * da coluna que impede um deploy de automatizar a carteira. Se o enum mudar,
     * este teste acusa e a mudança pede uma migration nova, em vez de reescrever
     * o que uma base já migrada nunca vai reexecutar.
     */
    expect($source)->not->toContain('use App\\')
        ->and($source)->toContain("->default('legacy')")
        ->and(SalesBoardSource::Legacy->value)->toBe('legacy');
});
