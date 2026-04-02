<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\AssignProposalRepresentative;
use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\DTOs\Proposals\ProposalStatusHistoryDTO;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalSector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('site.layout')]
#[Title('Envie sua Proposta - BSI Capital')]
class CreateProposalForm extends Component
{
    #[Validate('required', message: 'O CNPJ da empresa é obrigatório.')]
    #[Validate('regex:/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}\-?\d{2}$/', message: 'Informe um CNPJ válido no formato 00.000.000/0000-00.')]
    public string $cnpj = '';

    #[Validate('required', message: 'A razão social da empresa é obrigatória.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $companyName = '';

    #[Validate('nullable', onUpdate: false)]
    #[Validate('string', onUpdate: false)]
    #[Validate('max:255', onUpdate: false)]
    public ?string $stateRegistration = null;

    #[Validate('nullable', onUpdate: false)]
    #[Validate('url', message: 'Informe um site válido começando com http:// ou https://.', onUpdate: false)]
    #[Validate('max:255', onUpdate: false)]
    public ?string $website = null;

    #[Validate('required', message: 'Selecione o setor de atuação.')]
    #[Validate('exists:proposal_sectors,id', message: 'Selecione um setor de atuação válido.')]
    public ?string $sectorId = null;

    #[Validate('required', message: 'O CEP é obrigatório.')]
    #[Validate('regex:/^\d{5}\-?\d{3}$/', message: 'Informe um CEP válido no formato 00000-000.')]
    public string $postalCode = '';

    #[Validate('required', message: 'O logradouro é obrigatório.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $street = '';

    #[Validate('required', message: 'O número do endereço é obrigatório.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $addressNumber = '';

    #[Validate('nullable', onUpdate: false)]
    #[Validate('string', onUpdate: false)]
    #[Validate('max:255', onUpdate: false)]
    public ?string $addressComplement = null;

    #[Validate('required', message: 'O bairro é obrigatório.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $neighborhood = '';

    #[Validate('required', message: 'A cidade é obrigatória.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $city = '';

    #[Validate('required', message: 'O estado (UF) é obrigatório.')]
    #[Validate('size:2', message: 'Informe a UF com 2 letras.')]
    public string $state = '';

    #[Validate('required', message: 'O nome do responsável pelo contato é obrigatório.')]
    #[Validate('string')]
    #[Validate('max:255')]
    public string $contactName = '';

    #[Validate('required', message: 'O e-mail de contato é obrigatório.')]
    #[Validate('email', message: 'Informe um endereço de e-mail válido.')]
    #[Validate('max:255')]
    public string $email = '';

    #[Validate('required', message: 'O telefone pessoal do contato é obrigatório.')]
    #[Validate('regex:/^\(?\d{2}\)?\s?\d{4,5}\-?\d{4}$/', message: 'Informe um número de telefone pessoal válido.')]
    public string $personalPhone = '';

    #[Validate('boolean')]
    public bool $hasWhatsapp = true;

    #[Validate('nullable', onUpdate: false)]
    #[Validate('string', onUpdate: false)]
    #[Validate('regex:/^\(?\d{2}\)?\s?\d{4,5}\-?\d{4}$/', message: 'Informe um número de telefone da empresa válido.', onUpdate: false)]
    public ?string $companyPhone = null;

    #[Validate('nullable', onUpdate: false)]
    #[Validate('string', onUpdate: false)]
    #[Validate('max:255', onUpdate: false)]
    public ?string $jobTitle = null;

    #[Validate('nullable', onUpdate: false)]
    #[Validate('string', onUpdate: false)]
    public ?string $observations = null;

    public function render(): View
    {
        return view('livewire.proposals.create-proposal-form', [
            'sectors' => ProposalSector::query()->orderBy('name')->get(),
        ]);
    }

    public function updatedCnpj(string $value): void
    {
        $formattedValue = $this->formatCnpj($value);

        if ($formattedValue !== $this->cnpj) {
            $this->cnpj = $formattedValue;

            return;
        }

        $digits = $this->digitsOnly($formattedValue);

        if (strlen($digits) !== 14) {
            return;
        }

        $this->lookupCompanyByCnpj($digits);
    }

    public function updatedPostalCode(string $value): void
    {
        $formattedValue = $this->formatPostalCode($value);

        if ($formattedValue !== $this->postalCode) {
            $this->postalCode = $formattedValue;

            return;
        }

        $digits = $this->digitsOnly($formattedValue);

        if (strlen($digits) !== 8) {
            return;
        }

        $this->lookupAddressByPostalCode($digits);
    }

    public function updatedState(string $value): void
    {
        $this->state = Str::upper(substr(trim($value), 0, 2));
    }

    public function save(
        AssignProposalRepresentative $assignProposalRepresentative,
        SendProposalContinuationLink $sendProposalContinuationLink,
        UpdateProposalStatus $updateProposalStatus,
    ): void {
        $this->resetErrorBag('submission');

        if (! $this->ensureSubmissionIsNotRateLimited()) {
            return;
        }

        $this->normalizeOptionalFields();

        $validated = $this->validate();
        $proposal = null;

        try {
            $proposal = DB::transaction(function () use ($assignProposalRepresentative, $updateProposalStatus, $validated): Proposal {
                $company = ProposalCompany::query()->create([
                    'name' => trim($validated['companyName']),
                    'cnpj' => $this->formatCnpj($validated['cnpj']),
                    'ie' => $this->nullableString($validated['stateRegistration']),
                    'site' => $this->nullableString($validated['website']),
                    'cep' => $this->formatPostalCode($validated['postalCode']),
                    'logradouro' => trim($validated['street']),
                    'numero' => trim($validated['addressNumber']),
                    'complemento' => $this->nullableString($validated['addressComplement']),
                    'bairro' => trim($validated['neighborhood']),
                    'cidade' => trim($validated['city']),
                    'estado' => Str::upper(trim($validated['state'])),
                ]);

                $company->sectors()->sync([$validated['sectorId']]);

                $contact = ProposalContact::query()->create([
                    'company_id' => $company->id,
                    'name' => trim($validated['contactName']),
                    'email' => trim($validated['email']),
                    'phone_personal' => trim($validated['personalPhone']),
                    'whatsapp' => (bool) $validated['hasWhatsapp'],
                    'phone_company' => $this->nullableString($validated['companyPhone']),
                    'cargo' => $this->nullableString($validated['jobTitle']),
                ]);

                $proposal = Proposal::query()->create([
                    'company_id' => $company->id,
                    'contact_id' => $contact->id,
                    'observations' => $this->nullableString($validated['observations']),
                    'status' => Proposal::STATUS_AWAITING_COMPLETION,
                ]);

                $assignProposalRepresentative->handle($proposal);
                $updateProposalStatus->recordHistory(
                    $proposal,
                    ProposalStatusHistoryDTO::fromArray([
                        'previousStatus' => null,
                        'status' => Proposal::STATUS_AWAITING_COMPLETION,
                        'note' => 'Proposta recebida e aguardando complementação do cliente.',
                    ]),
                );

                return $proposal->fresh(['company', 'contact', 'representative']);
            });

            $sendProposalContinuationLink->handle($proposal);
        } catch (\Throwable $exception) {
            Log::error('Falha ao registrar proposta pública.', [
                'proposal_id' => $proposal?->id,
                'message' => $exception->getMessage(),
            ]);

            $this->addError('submission', 'Ocorreu um erro ao enviar sua proposta. Por favor, tente novamente mais tarde.');

            return;
        }

        session()->flash(
            'success',
            'Sua proposta foi enviada com sucesso. Enviamos um link seguro para o e-mail informado para continuar o preenchimento.',
        );

        $this->redirect(route('proposal.create'), navigate: true);
    }

    protected function ensureSubmissionIsNotRateLimited(): bool
    {
        $key = $this->submissionRateLimitKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('submission', 'Você atingiu o limite de envios. Tente novamente em alguns instantes.');

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    protected function submissionRateLimitKey(): string
    {
        return implode('|', [
            'proposal-submission',
            request()->ip(),
            mb_strtolower(trim($this->email)),
            $this->digitsOnly($this->cnpj),
        ]);
    }

    protected function lookupCompanyByCnpj(string $cnpj): void
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar dados públicos de CNPJ.', [
                'cnpj' => $cnpj,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        $payload = $response->json();
        $establishment = $payload['estabelecimento'] ?? [];

        $this->companyName = (string) ($payload['razao_social'] ?? $this->companyName);
        $this->stateRegistration = $this->resolveStateRegistration($establishment['inscricoes_estaduais'] ?? []);

        if (filled($establishment['cep'] ?? null)) {
            $this->postalCode = $this->formatPostalCode((string) $establishment['cep']);
        }

        $this->street = (string) ($establishment['logradouro'] ?? $this->street);
        $this->addressNumber = (string) ($establishment['numero'] ?? $this->addressNumber);
        $this->addressComplement = (string) ($establishment['complemento'] ?? $this->addressComplement);
        $this->neighborhood = (string) ($establishment['bairro'] ?? $this->neighborhood);
        $this->city = (string) data_get($establishment, 'cidade.nome', $this->city);
        $this->state = Str::upper((string) data_get($establishment, 'estado.sigla', $this->state));

        $website = (string) ($establishment['site'] ?? '');

        if (filled($website)) {
            $this->website = $this->normalizeWebsite($website);
        }
    }

    protected function lookupAddressByPostalCode(string $postalCode): void
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get("https://viacep.com.br/ws/{$postalCode}/json/");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar endereço pelo CEP.', [
                'postal_code' => $postalCode,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || $response->json('erro')) {
            return;
        }

        $this->street = (string) ($response->json('logradouro') ?? $this->street);
        $this->neighborhood = (string) ($response->json('bairro') ?? $this->neighborhood);
        $this->city = (string) ($response->json('localidade') ?? $this->city);
        $this->state = Str::upper((string) ($response->json('uf') ?? $this->state));
    }

    protected function resolveStateRegistration(array $stateRegistrations): ?string
    {
        foreach ($stateRegistrations as $stateRegistration) {
            $number = trim((string) ($stateRegistration['inscricao_estadual'] ?? ''));

            if ($number !== '') {
                return $number;
            }
        }

        return $this->stateRegistration;
    }

    protected function formatCnpj(string $value): string
    {
        $digits = substr($this->digitsOnly($value), 0, 14);

        if (strlen($digits) <= 2) {
            return $digits;
        }

        if (strlen($digits) <= 5) {
            return substr($digits, 0, 2).'.'.substr($digits, 2);
        }

        if (strlen($digits) <= 8) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5);
        }

        if (strlen($digits) <= 12) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8);
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12);
    }

    protected function formatPostalCode(string $value): string
    {
        $digits = substr($this->digitsOnly($value), 0, 8);

        if (strlen($digits) <= 5) {
            return $digits;
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5);
    }

    protected function normalizeWebsite(string $website): string
    {
        return Str::startsWith(Str::lower($website), ['http://', 'https://'])
            ? $website
            : 'https://'.$website;
    }

    protected function normalizeOptionalFields(): void
    {
        $this->stateRegistration = $this->nullableString($this->stateRegistration);
        $this->website = $this->nullableString($this->website);
        $this->addressComplement = $this->nullableString($this->addressComplement);
        $this->companyPhone = $this->nullableString($this->companyPhone);
        $this->jobTitle = $this->nullableString($this->jobTitle);
        $this->observations = $this->nullableString($this->observations);
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    protected function nullableString(?string $value): ?string
    {
        $trimmedValue = trim((string) $value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }
}
