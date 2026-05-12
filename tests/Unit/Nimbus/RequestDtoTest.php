<?php

use App\Http\Requests\LookupNimbusCnpjRequest;
use App\Http\Requests\Nimbus\StoreSubmissionReplyRequest;
use App\Http\Requests\Nimbus\StoreSubmissionRequest;
use App\Http\Requests\StoreAdminSubmissionResponseFilesRequest;
use App\Http\Requests\VerifyProposalContinuationRequest;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Tests\TestCase;

uses(TestCase::class, WithFaker::class);

it('maps the nimbus submission request into a typed dto', function () {
    $request = buildValidatedRequest(StoreSubmissionRequest::class, [
        'responsible_name' => 'Kamilly Regina Bernardes',
        'company_cnpj' => '11.257.352/0001-43',
        'company_name' => 'BSI Capital Securitizadora S/A',
        'main_activity' => 'Securitizacao de creditos',
        'phone' => '(11) 4330-9780',
        'website' => 'https://bsicapital.com.br/',
        'net_worth' => 'R$ 10,00',
        'annual_revenue' => 'R$ 100,00',
        'registrant_name' => 'Kamilly Regina Bernardes',
        'registrant_position' => 'Diretora',
        'registrant_rg' => '49.424.335-1',
        'registrant_cpf' => '019.348.404-83',
        'is_anbima_affiliated' => '0',
        'is_us_person' => '1',
        'is_pep' => '0',
        'shareholders' => json_encode([
            [
                'name' => 'Socio Principal',
                'rg' => '12.345.678-9',
                'cnpj' => '11.111.111/0001-11',
                'percentage' => 100,
            ],
        ], JSON_THROW_ON_ERROR),
    ], [
        'ultimo_balanco' => UploadedFile::fake()->create('ultimo-balanco.pdf', 100, 'application/pdf'),
        'dre' => UploadedFile::fake()->create('dre.pdf', 100, 'application/pdf'),
        'politicas' => UploadedFile::fake()->create('politicas.pdf', 100, 'application/pdf'),
        'cartao_cnpj' => UploadedFile::fake()->create('cartao-cnpj.pdf', 100, 'application/pdf'),
        'procuracao' => UploadedFile::fake()->create('procuracao.pdf', 100, 'application/pdf'),
        'ata' => UploadedFile::fake()->create('ata.pdf', 100, 'application/pdf'),
        'contrato_social' => UploadedFile::fake()->create('contrato-social.pdf', 100, 'application/pdf'),
        'estatuto' => UploadedFile::fake()->create('estatuto.pdf', 100, 'application/pdf'),
    ]);

    $dto = $request->toDTO();

    expect($dto->companyName)->toBe('BSI Capital Securitizadora S/A')
        ->and($dto->companyCnpj)->toBe('11.257.352/0001-43')
        ->and($dto->netWorth)->toBe(10.0)
        ->and($dto->annualRevenue)->toBe(100.0)
        ->and($dto->isUsPerson)->toBeTrue()
        ->and($dto->isPep)->toBeFalse()
        ->and($dto->isAnbimaAffiliated)->toBeFalse()
        ->and($dto->shareholders)->toHaveCount(1)
        ->and($dto->shareholderData()[0]['name'])->toBe('Socio Principal')
        ->and($dto->documentFiles)->toHaveCount(8)
        ->and($dto->documentFiles['dre']->getClientOriginalName())->toBe('dre.pdf');
});

it('uses the configured nimbus submission upload limits', function () {
    $request = new StoreSubmissionRequest;

    expect($request->rules()['ultimo_balanco'])->toContain('max:51200')
        ->and($request->messages()['ultimo_balanco.max'])->toBe('Cada documento pode ter no máximo 50 MB.')
        ->and(config('uploads.submission.total_max_bytes'))->toBe(50 * 1024 * 1024);
});

it('maps the submission reply request into a typed dto', function () {
    $request = buildValidatedRequest(StoreSubmissionReplyRequest::class, [
        'comment' => '  Documento corrigido enviado.  ',
    ], [
        'file' => UploadedFile::fake()->create('correcao.pdf', 120, 'application/pdf'),
    ]);

    $dto = $request->toDTO();

    expect($dto->comment)->toBe('Documento corrigido enviado.')
        ->and($dto->file)->not->toBeNull()
        ->and($dto->file?->getClientOriginalName())->toBe('correcao.pdf');
});

it('maps the admin response files request into a typed dto', function () {
    $request = buildValidatedRequest(StoreAdminSubmissionResponseFilesRequest::class, [
        'visible_to_user' => '0',
    ], [
        'response_files' => [
            UploadedFile::fake()->create('parecer.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('planilha.xlsx', 200, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ],
    ]);

    $dto = $request->toDTO();

    expect($dto->visibleToUser)->toBeFalse()
        ->and($dto->responseFiles)->toHaveCount(2)
        ->and($dto->responseFiles[0]->getClientOriginalName())->toBe('parecer.pdf');
});

it('uses the configured admin response file size limit', function () {
    $request = new StoreAdminSubmissionResponseFilesRequest;

    expect($request->rules()['response_files.*'])->toContain('max:102400')
        ->and($request->messages()['response_files.*.max'])->toBe('Cada arquivo de resposta pode ter no máximo 100 MB.');
});

it('raises the global Livewire temporary upload ceiling to 100 MB', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toBe([
        'required',
        'file',
        'max:102400',
    ])
        ->and(config('livewire.temporary_file_upload.max_upload_time'))->toBe(15);
});

it('maps the cnpj lookup request into a typed dto', function () {
    $request = LookupNimbusCnpjRequest::create('/lookup', 'POST', [
        'cnpj' => '11.257.352/0001-43',
    ]);

    invokeProtected($request, 'prepareForValidation');

    attachValidator($request, [
        'cnpj' => $request->input('cnpj'),
    ]);

    $dto = $request->toDTO();

    expect($dto->cnpj)->toBe('11257352000143');
});

it('maps the proposal continuation verification request into a typed dto', function () {
    $request = VerifyProposalContinuationRequest::create('/verify', 'POST', [
        'cnpj' => '11.257.352/0001-43',
        'code' => '12 34 56',
    ]);

    invokeProtected($request, 'prepareForValidation');

    attachValidator($request, [
        'cnpj' => $request->input('cnpj'),
        'code' => $request->input('code'),
    ]);

    $dto = $request->toDTO();

    expect($dto->cnpj)->toBe('11.257.352/0001-43')
        ->and($dto->code)->toBe('123456');
});

/**
 * @param  class-string<\Illuminate\Foundation\Http\FormRequest>  $requestClass
 * @param  array<string, mixed>  $inputs
 * @param  array<string, mixed>  $files
 */
function buildValidatedRequest(string $requestClass, array $inputs, array $files = []): object
{
    /** @var \Illuminate\Foundation\Http\FormRequest $request */
    $request = $requestClass::create('/test', 'POST', $inputs, [], $files);

    attachValidator($request, array_merge($inputs, $files));

    return $request;
}

/**
 * @param  \Illuminate\Foundation\Http\FormRequest  $request
 * @param  array<string, mixed>  $payload
 */
function attachValidator(object $request, array $payload): void
{
    /** @var Validator $validator */
    $validator = validator($payload, $request->rules(), $request->messages());

    if (method_exists($request, 'withValidator')) {
        $request->withValidator($validator);
    }

    $request
        ->setContainer(app())
        ->setRedirector(app('redirect'))
        ->setValidator($validator);
}

function invokeProtected(object $target, string $method): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target);
}
