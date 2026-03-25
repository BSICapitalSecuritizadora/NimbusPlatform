<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalSector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProposalController extends Controller
{
    /**
     * Show the proposal submission form.
     */
    public function create()
    {
        $sectors = ProposalSector::all();
        return view('site.proposal.create', compact('sectors'));
    }

    /**
     * Store a newly created proposal in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cnpj' => 'required|string|max:18',
            'nome_empresa' => 'required|string|max:255',
            'ie' => 'nullable|string|max:255',
            'site' => 'nullable|url|max:255',
            'setores' => 'required|array',
            'setores.*' => 'exists:proposal_sectors,id',
            
            'cep' => 'required|string|max:9',
            'logradouro' => 'required|string|max:255',
            'numero' => 'required|string|max:255',
            'complemento' => 'nullable|string|max:255',
            'bairro' => 'required|string|max:255',
            'cidade' => 'required|string|max:255',
            'estado' => 'required|string|size:2',

            'nome_contato' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefone_pessoal' => 'required|string|max:20',
            'whatsapp' => 'nullable|boolean',
            'telefone_empresa' => 'nullable|string|max:20',
            'cargo' => 'nullable|string|max:255',
            
            'observacoes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // 1. Create or update Company
            $company = ProposalCompany::updateOrCreate(
                ['cnpj' => $validated['cnpj']],
                [
                    'name' => $validated['nome_empresa'],
                    'ie' => $validated['ie'],
                    'site' => $validated['site'],
                    'cep' => $validated['cep'],
                    'logradouro' => $validated['logradouro'],
                    'numero' => $validated['numero'],
                    'complemento' => $validated['complemento'],
                    'bairro' => $validated['bairro'],
                    'cidade' => $validated['cidade'],
                    'estado' => $validated['estado'],
                ]
            );

            // 2. Sync Sectors
            $company->sectors()->sync($validated['setores']);

            $contact = ProposalContact::create([
                'company_id' => $company->id,
                'name' => $validated['nome_contato'],
                'email' => $validated['email'],
                'phone_personal' => $validated['telefone_pessoal'],
                'whatsapp' => (bool) $request->input('whatsapp'),
                'phone_company' => $validated['telefone_empresa'],
                'cargo' => $validated['cargo'],
            ]);

            // 4. Create Proposal
            Proposal::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'observations' => $validated['observacoes'],
                'status' => 'pending',
            ]);

            DB::commit();

            return redirect()->route('site.proposal.create')->with('success', 'Sua proposta foi enviada com sucesso! Nossa equipe entrará em contato em breve.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Ocorreu um erro ao enviar sua proposta. Por favor, tente novamente mais tarde.');
        }
    }
}
