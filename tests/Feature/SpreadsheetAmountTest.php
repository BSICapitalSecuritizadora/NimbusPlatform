<?php

use App\Enums\AmountTextAmbiguity;
use App\Support\Imports\SpreadsheetAmount;

/**
 * A leitura de valor das planilhas de importação.
 *
 * Antes, "553,919" virava R$ 553.919,00 -- mil vezes o valor --, em silêncio. A
 * vírgula passa a ser decimal, como se escreve em pt-BR; o texto que admite duas
 * leituras é lido numa delas e marcado, com a outra ao lado.
 *
 * Dataset de textos e números, nunca de closures: o caso é o valor da célula.
 */
it('reads a cell in cents, flags the ambiguous text and carries the other reading', function (mixed $cell, ?int $cents, ?AmountTextAmbiguity $ambiguity, ?int $alternative) {
    $amount = SpreadsheetAmount::read($cell);

    expect($amount->cents)->toBe($cents)
        ->and($amount->ambiguity)->toBe($ambiguity)
        ->and($amount->alternativeCents)->toBe($alternative);
})->with([
    'vírgula com três casas é decimal' => ['553,919', 55392, AmountTextAmbiguity::CommaAsDecimal, 55391900],
    'ponto com três casas é milhar' => ['553.919', 55391900, AmountTextAmbiguity::DotAsThousands, 55392],
    'mil e quinhentos com vírgula' => ['1,500', 150, AmountTextAmbiguity::CommaAsDecimal, 150000],
    'mil e quinhentos com ponto' => ['1.500', 150000, AmountTextAmbiguity::DotAsThousands, 150],
    'convenção brasileira completa' => ['1.553,92', 155392, null, null],
    'convenção americana completa' => ['1,553.92', 155392, null, null],
    'ponto decimal com duas casas' => ['10000.00', 1000000, null, null],
    'quatro dígitos antes do ponto' => ['1553.919', 155392, null, null],
    'grupos de milhar com ponto' => ['1.000.000', 100000000, null, null],
    'grupos de milhar com vírgula' => ['1,000,000', 100000000, null, null],
    'vírgula decimal curta' => ['0,5', 50, null, null],
    'negativo brasileiro' => ['-1.234,56', -123456, null, null],
    'texto que não é número' => ['abc', null, null, null],
    'célula numérica com três casas' => [553.919, 55392, null, null],
    'célula numérica inteira' => [7500, 750000, null, null],
]);

it('writes the warning with both readings and how to write the other one', function () {
    expect(SpreadsheetAmount::read('553,919')->warning('Valor pago'))
        ->toBe("Valor pago '553,919' lido como R$ 553,92 (vírgula como separador decimal). Se o valor é R$ 553.919,00, escreva 553.919,00 ou use célula numérica.")
        ->and(SpreadsheetAmount::read('553.919')->warning('Valor previsto'))
        ->toBe("Valor previsto '553.919' lido como R$ 553.919,00 (ponto como separador de milhar). Se o valor é R$ 553,92, escreva 553,92 ou use célula numérica.")
        ->and(SpreadsheetAmount::read('1.553,92')->warning('Valor pago'))->toBeNull()
        ->and(SpreadsheetAmount::read(553.919)->warning('Valor pago'))->toBeNull();
});
