<?php

use App\Actions\Clients\ClientSpreadsheetTemplate;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetTemplate;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetTemplate;
use App\Actions\Contracts\ContractSpreadsheetTemplate;
use App\Support\TemporarySpreadsheetFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

/**
 * Os geradores de modelo escrevem no diretório de temporários do sistema, que é
 * compartilhado com outros processos -- inclusive com outra execução da suíte.
 * Por isso nenhum teste aqui varre `/tmp` por prefixo: cada um guarda o caminho
 * exato que o fluxo devolveu e afirma sobre ele e sobre o caminho-base derivado
 * dele. É a diferença entre provar que este download não deixou resíduo e
 * apostar que ninguém mais estava usando a máquina.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * O arquivo que o `tempnam` cria para reservar o nome, e que a implementação
 * antiga abandonava a cada download.
 */
function templateReservationPath(string $path): string
{
    expect($path)->toEndWith('.xlsx');

    return mb_substr($path, 0, -mb_strlen('.xlsx'));
}

/**
 * @return array<string, array{0: class-string, 1: string, 2: string, 3: int}>
 */
dataset('geradores de modelo', [
    // Quatro linhas de exemplo: uma delas é um contrato com dois compradores.
    'contratos' => [ContractSpreadsheetTemplate::class, 'admin.contracts.template.download', 'Contratos', 4],
    'parcelas' => [ContractInstallmentSpreadsheetTemplate::class, 'admin.contract-installments.template.download', 'Parcelas', 3],
    'clientes' => [ClientSpreadsheetTemplate::class, 'admin.clients.template.download', 'Clientes', 2],
    'unidades' => [ConstructionUnitSpreadsheetTemplate::class, 'admin.construction-units.template.download', 'Unidades', 4],
]);

it('builds a valid xlsx and leaves no base file behind', function (string $template, string $route, string $dataSheet, int $exampleRows) {
    $path = app($template)->build();
    $reservation = templateReservationPath($path);

    expect($path)->toBeFile()
        ->and($reservation)->not->toBeFile()
        // Assinatura de container ZIP: um `.xlsx` que não abre no Excel falha aqui.
        ->and(file_get_contents($path, false, null, 0, 2))->toBe('PK')
        ->and(mime_content_type($path))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $headers = SimpleExcelReader::create($path)->getOriginalHeaders();

    expect(SimpleExcelReader::create($path)->getRows()->all())->toBe([])
        ->and($headers)->not->toBeEmpty()
        ->and($template::DATA_SHEET)->toBe($dataSheet)
        ->and(SimpleExcelReader::create($path)->fromSheetName($template::EXAMPLE_SHEET)->getRows()->all())
        ->toHaveCount($exampleRows);

    unlink($path);
})->with('geradores de modelo');

it('removes both the served file and its base after the download is sent', function (string $template, string $route) {
    $this->actingAs(makeAdminUser());

    $response = $this->get(route($route))->assertSuccessful();

    $path = $response->baseResponse->getFile()->getPathname();
    $reservation = templateReservationPath($path);

    expect($path)->toBeFile();

    // O cliente de teste monta a resposta mas nunca a envia, e é o envio que
    // dispara `deleteFileAfterSend()`. Enviar aqui é o que torna este teste uma
    // prova do ciclo completo em vez de uma verificação do caminho feliz.
    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    expect($path)->not->toBeFile()
        ->and($reservation)->not->toBeFile();
})->with('geradores de modelo');

it('gives every download its own path and leaks nothing across repeated builds', function (string $template) {
    $paths = [];

    foreach (range(1, 10) as $ignored) {
        $paths[] = app($template)->build();
    }

    expect($paths)->toHaveCount(10)
        ->and(array_unique($paths))->toHaveCount(10);

    foreach ($paths as $path) {
        expect($path)->toBeFile()
            ->and(templateReservationPath($path))->not->toBeFile();
    }

    foreach ($paths as $path) {
        unlink($path);
    }

    foreach ($paths as $path) {
        expect($path)->not->toBeFile()
            ->and(templateReservationPath($path))->not->toBeFile();
    }
})->with('geradores de modelo');

/**
 * Sem isto, corrigir o vazamento do `tempnam` só teria mudado o momento em
 * que o órfão aparece: `deleteFileAfterSend()` nunca roda quando a geração
 * lança antes de existir uma resposta.
 */
it('removes the temporary file when the generation fails', function () {
    $capturedPath = null;

    // `fn () =>` captura por valor, e a referência de `$capturedPath` se
    // perderia na cópia -- o caminho precisa atravessar uma closure completa.
    $generate = function () use (&$capturedPath): string {
        return TemporarySpreadsheetFile::write(
            'template-generation-failure-',
            function (SimpleExcelWriter $writer) use (&$capturedPath): void {
                $capturedPath = $writer->getPath();

                $writer->addHeader(['Coluna']);

                throw new RuntimeException('falha ao montar a planilha');
            },
        );
    };

    expect($generate)->toThrow(RuntimeException::class, 'falha ao montar a planilha');

    expect($capturedPath)->toBeString()
        ->and($capturedPath)->not->toBeFile()
        ->and(templateReservationPath((string) $capturedPath))->not->toBeFile();
});

it('hands the caller a path it owns and nothing else', function () {
    $path = TemporarySpreadsheetFile::write('template-ownership-', function (SimpleExcelWriter $writer): void {
        $writer->nameCurrentSheet('Dados')->addHeader(['Coluna']);
        $writer->addRow(['Coluna' => 'valor']);
    });

    expect($path)->toBeFile()
        ->and(templateReservationPath($path))->not->toBeFile()
        ->and(SimpleExcelReader::create($path)->getRows()->pluck('Coluna')->all())->toBe(['valor']);

    unlink($path);

    expect($path)->not->toBeFile();
});
