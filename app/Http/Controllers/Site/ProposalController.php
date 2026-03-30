<?php

namespace App\Http\Controllers\Site;

use App\Actions\Proposals\AssignProposalRepresentative;
use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalRequest;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalSector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProposalController extends Controller
{
    public function create()
    {
        $sectors = ProposalSector::all();

        return view('site.proposal.create', compact('sectors'));
    }

    public function store(
        StoreProposalRequest $request,
        AssignProposalRepresentative $assignProposalRepresentative,
        SendProposalContinuationLink $sendProposalContinuationLink,
        UpdateProposalStatus $updateProposalStatus,
    ) {
        $validated = $request->validated();
        $proposal = null;

        try {
            $proposal = DB::transaction(function () use ($validated, $request, $assignProposalRepresentative, $updateProposalStatus): Proposal {
                $company = ProposalCompany::create([
                    'name' => $validated['nome_empresa'],
                    'cnpj' => $validated['cnpj'],
                    'ie' => $validated['ie'],
                    'site' => $validated['site'],
                    'cep' => $validated['cep'],
                    'logradouro' => $validated['logradouro'],
                    'numero' => $validated['numero'],
                    'complemento' => $validated['complemento'],
                    'bairro' => $validated['bairro'],
                    'cidade' => $validated['cidade'],
                    'estado' => $validated['estado'],
                ]);

                $company->sectors()->sync($validated['setores']);

                $contact = ProposalContact::create([
                    'company_id' => $company->id,
                    'name' => $validated['nome_contato'],
                    'email' => $validated['email'],
                    'phone_personal' => $validated['telefone_pessoal'],
                    'whatsapp' => $request->boolean('whatsapp'),
                    'phone_company' => $validated['telefone_empresa'],
                    'cargo' => $validated['cargo'],
                ]);

                $proposal = Proposal::create([
                    'company_id' => $company->id,
                    'contact_id' => $contact->id,
                    'observations' => $validated['observacoes'] ?? null,
                    'status' => Proposal::STATUS_AWAITING_COMPLETION,
                ]);

                $assignProposalRepresentative->handle($proposal);
                $updateProposalStatus->recordHistory(
                    $proposal,
                    null,
                    Proposal::STATUS_AWAITING_COMPLETION,
                    null,
                    'Proposta recebida e aguardando complementação do cliente.',
                );

                return $proposal->fresh(['company', 'contact', 'representative']);
            });

            $sendProposalContinuationLink->handle($proposal);

            return redirect()->route('site.proposal.create')->with(
                'success',
                'Sua proposta foi enviada com sucesso. Enviamos um link seguro para o e-mail informado para continuar o preenchimento.',
            );
        } catch (\Throwable $exception) {
            Log::error('Falha ao registrar proposta pública.', [
                'proposal_id' => $proposal?->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Ocorreu um erro ao enviar sua proposta. Por favor, tente novamente mais tarde.');
        }
    }
}
