<?php

use App\Actions\Nimbus\LookupNimbusCnpj;
use App\Actions\Proposals\SendProposalContinuationLink;
use App\DTOs\Nimbus\LookupNimbusCnpjDTO;
use App\Livewire\Forms\CreateProposalFormObject;
use App\Livewire\Proposals\CreateProposalForm;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use App\Services\Security\PiiPseudonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->logFile = storage_path('logs/pii-redaction-test.log');

    if (file_exists($this->logFile)) {
        unlink($this->logFile);
    }

    config([
        'logging.default' => 'pii_redaction_test',
        'logging.channels.pii_redaction_test' => [
            'driver' => 'single',
            'path' => $this->logFile,
            'level' => 'debug',
        ],
    ]);

    Log::forgetChannel();
});

afterEach(function () {
    if (file_exists($this->logFile)) {
        unlink($this->logFile);
    }
});

function writtenLogContents(string $logFile): string
{
    expect($logFile)->toBeReadableFile();

    return (string) file_get_contents($logFile);
}

it('does not write the proponent e-mail or cnpj when the public proposal submission fails', function () {
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $state = proposalCreateFormState($sector);
    $state['email'] = 'proponente.sigiloso@example.com';
    $state['cnpj'] = '12.345.678/0001-90';

    fakeProposalCreateLookups($state);

    $this->mock(SendProposalContinuationLink::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('falha simulada no envio do link'));

    $component = Livewire::test(CreateProposalForm::class);

    foreach ($state as $property => $value) {
        if (! property_exists(CreateProposalFormObject::class, $property)) {
            continue;
        }

        $component->set("form.{$property}", $value);
    }

    $component->call('save')->assertHasErrors(['submission']);

    $log = writtenLogContents($this->logFile);

    expect($log)
        ->toContain('Falha ao registrar proposta pública.')
        ->not->toContain('proponente.sigiloso@example.com')
        ->not->toContain('12.345.678/0001-90')
        ->not->toContain('12345678000190')
        ->and($log)->toContain(PiiPseudonymizer::email('proponente.sigiloso@example.com'))
        ->and($log)->toContain(PiiPseudonymizer::document('12.345.678/0001-90'));
});

it('does not write the cnpj when the nimbus portal lookup fails', function () {
    Http::fake(function (): never {
        throw new ConnectionException('tempo limite excedido');
    });

    $result = app(LookupNimbusCnpj::class)->handle(new LookupNimbusCnpjDTO(cnpj: '12345678000190'));

    expect($result['status'])->toBe(502);

    expect(writtenLogContents($this->logFile))
        ->toContain('Falha ao consultar CNPJ no portal Nimbus.')
        ->not->toContain('12345678000190')
        ->toContain(PiiPseudonymizer::document('12345678000190'));
});

it('does not write the cnpj or the postal code when the public proposal lookups fail', function () {
    Http::fake(function (): never {
        throw new ConnectionException('tempo limite excedido');
    });

    Livewire::test(CreateProposalForm::class)
        ->set('form.cnpj', '98.765.432/0001-10')
        ->set('form.postalCode', '04567-000');

    $log = writtenLogContents($this->logFile);

    expect($log)
        ->toContain('Falha ao consultar dados públicos de CNPJ.')
        ->toContain('Falha ao consultar endereço pelo CEP.')
        ->not->toContain('98.765.432/0001-10')
        ->not->toContain('98765432000110')
        ->not->toContain('04567-000')
        ->not->toContain('04567000')
        ->and($log)->toContain(PiiPseudonymizer::document('98765432000110'))
        ->and($log)->toContain(PiiPseudonymizer::document('04567000'));
});

it('produces stable, non-reversible and value-specific tokens', function () {
    $token = PiiPseudonymizer::email('Contato@Example.com ');

    expect($token)
        ->toStartWith('pii_')
        ->toBe(PiiPseudonymizer::email('contato@example.com'))
        ->not->toBe(PiiPseudonymizer::email('outro@example.com'))
        ->and(PiiPseudonymizer::document('12.345.678/0001-90'))
        ->toBe(PiiPseudonymizer::document('12345678000190'))
        ->and(PiiPseudonymizer::email(null))->toBeNull()
        ->and(PiiPseudonymizer::document(''))->toBeNull()
        ->and(PiiPseudonymizer::value('  '))->toBeNull();
});
