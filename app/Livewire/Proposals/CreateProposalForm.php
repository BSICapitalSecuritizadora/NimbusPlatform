<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\AssignProposalRepresentative;
use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\Livewire\Forms\CreateProposalFormObject;
use App\Models\ProposalSector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('site.layout')]
#[Title('Envie sua Proposta - BSI Capital')]
class CreateProposalForm extends Component
{
    public CreateProposalFormObject $form;

    public function render(): View
    {
        return view('livewire.proposals.create-proposal-form', [
            'sectors' => ProposalSector::query()->orderBy('name')->get(),
        ]);
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

        try {
            $proposal = $this->form->submit(
                $assignProposalRepresentative,
                $updateProposalStatus,
            );
            $sendProposalContinuationLink->handle($proposal);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Falha ao registrar proposta pública.', [
                'email' => $this->form->email,
                'cnpj' => $this->form->cnpj,
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
            mb_strtolower(trim($this->form->email)),
            Str::digitsOnly($this->form->cnpj),
        ]);
    }
}
