<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\AssignProposalRepresentative;
use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\Livewire\Forms\CreateProposalFormObject;
use App\Models\ProposalSector;
use App\Services\Security\PiiPseudonymizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('site.layout')]
#[Title('Envie sua Proposta - BSI Capital')]
class CreateProposalForm extends Component
{
    public const NO_SECTORS_MESSAGE = 'Nenhum setor de atuação está disponível no momento. Entre em contato com a BSI Capital.';

    private const IP_SUBMISSION_LIMIT = 10;

    private const IP_SUBMISSION_DECAY_SECONDS = 3600;

    private const IDENTITY_SUBMISSION_LIMIT = 5;

    private const IDENTITY_SUBMISSION_DECAY_SECONDS = 60;

    public CreateProposalFormObject $form;

    public function mount(): void
    {
        if ($this->form->submissionToken === '') {
            $this->form->submissionToken = (string) Str::uuid();
        }
    }

    /**
     * @return Collection<int, ProposalSector>
     */
    #[Computed]
    public function sectors(): Collection
    {
        return ProposalSector::query()->active()->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.proposals.create-proposal-form', [
            'sectors' => $this->sectors,
            'noSectorsMessage' => self::NO_SECTORS_MESSAGE,
        ]);
    }

    public function save(
        AssignProposalRepresentative $assignProposalRepresentative,
        SendProposalContinuationLink $sendProposalContinuationLink,
        UpdateProposalStatus $updateProposalStatus,
    ): void {
        $this->resetErrorBag('submission');

        if ($this->sectors->isEmpty()) {
            $this->addError('submission', self::NO_SECTORS_MESSAGE);

            return;
        }

        if (! $this->ensureSubmissionIsNotRateLimited()) {
            return;
        }

        try {
            $proposal = $this->form->submit(
                $assignProposalRepresentative,
                $updateProposalStatus,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Falha ao registrar proposta pública.', [
                'email_hash' => PiiPseudonymizer::email($this->form->email),
                'cnpj_hash' => PiiPseudonymizer::document($this->form->cnpj),
                'exception' => $exception::class,
            ]);

            $this->addError('submission', 'Não foi possível enviar sua solicitação agora. Tente novamente em alguns instantes ou entre em contato com a BSI Capital.');

            return;
        }

        try {
            $sendProposalContinuationLink->handle($proposal, forceNewAccess: false);
        } catch (\Throwable $exception) {
            Log::warning('Proposta salva, mas a comunicação de continuação não pôde ser enfileirada.', [
                'proposal_id' => $proposal->id,
                'exception' => $exception::class,
            ]);
        }

        session()->flash(
            'success',
            'Solicitação registrada com sucesso. Um link seguro para o preenchimento das informações complementares será enviado ao e-mail informado. Nossa equipe dará sequência à análise após o recebimento dos dados.',
        );

        $this->redirect(route('proposal.create'));
    }

    protected function ensureSubmissionIsNotRateLimited(): bool
    {
        if (
            RateLimiter::tooManyAttempts($this->submissionIpRateLimitKey(), self::IP_SUBMISSION_LIMIT)
            || RateLimiter::tooManyAttempts($this->submissionIdentityRateLimitKey(), self::IDENTITY_SUBMISSION_LIMIT)
        ) {
            $this->addError('submission', 'Você atingiu o limite de envios. Tente novamente em alguns instantes.');

            return false;
        }

        RateLimiter::hit($this->submissionIpRateLimitKey(), self::IP_SUBMISSION_DECAY_SECONDS);
        RateLimiter::hit($this->submissionIdentityRateLimitKey(), self::IDENTITY_SUBMISSION_DECAY_SECONDS);

        return true;
    }

    protected function submissionIpRateLimitKey(): string
    {
        return implode('|', [
            'proposal-submission',
            'ip',
            request()->ip(),
        ]);
    }

    protected function submissionIdentityRateLimitKey(): string
    {
        return implode('|', [
            'proposal-submission',
            'identity',
            mb_strtolower(trim($this->form->email)),
            Str::digitsOnly($this->form->cnpj),
        ]);
    }
}
