<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::guard('nimbus')->user();
        $submissions = Submission::where('nimbus_portal_user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->get();

        return view('nimbus.submissions.index', compact('submissions'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('nimbus.submissions.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'responsible_name' => 'required|string|max:255',
            'company_cnpj' => 'required|string|max:20',
            'company_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'net_worth' => 'required|string',
            'annual_revenue' => 'required|string',
            'registrant_name' => 'required|string|max:255',
            'registrant_cpf' => 'required|string|max:20',
            // File validations
            'ultimo_balanco' => 'required|file|mimes:pdf|max:10240',
            'dre' => 'required|file|mimes:pdf|max:10240',
            'politicas' => 'required|file|mimes:pdf|max:10240',
            'cartao_cnpj' => 'required|file|mimes:pdf|max:10240',
            'procuracao' => 'required|file|mimes:pdf|max:10240',
            'ata' => 'required|file|mimes:pdf|max:10240',
            'contrato_social' => 'required|file|mimes:pdf|max:10240',
            'estatuto' => 'required|file|mimes:pdf|max:10240',
        ]);

        $user = Auth::guard('nimbus')->user();

        // Decode shareholders JSON
        $shareholders = json_decode($request->input('shareholders', '[]'), true) ?? [];

        try {
            DB::beginTransaction();

            // Format monetary values
            $cleanNetWorth = str_replace(',', '.', str_replace(['R$', '.', ' '], '', $request->net_worth));
            $cleanAnnualRevenue = str_replace(',', '.', str_replace(['R$', '.', ' '], '', $request->annual_revenue));

            $submission = Submission::create([
                'nimbus_portal_user_id' => $user->id,
                'status' => 'PENDING',
                'responsible_name' => $request->responsible_name,
                'company_cnpj' => $request->company_cnpj,
                'company_name' => $request->company_name,
                'main_activity' => $request->main_activity,
                'phone' => $request->phone,
                'website' => $request->website,
                'net_worth' => is_numeric($cleanNetWorth) ? $cleanNetWorth : 0,
                'annual_revenue' => is_numeric($cleanAnnualRevenue) ? $cleanAnnualRevenue : 0,
                'registrant_name' => $request->registrant_name,
                'registrant_position' => $request->registrant_position,
                'registrant_rg' => $request->registrant_rg,
                'registrant_cpf' => $request->registrant_cpf,
                'is_us_person' => $request->boolean('is_us_person'),
                'is_pep' => $request->boolean('is_pep'),
                'is_none_compliant' => $request->boolean('is_none_compliant'),
                'submitted_at' => now(),
            ]);

            // Save Shareholders
            foreach ($shareholders as $share) {
                if (! empty($share['name'])) {
                    $submission->shareholders()->create([
                        'name' => $share['name'],
                        'document_cpf' => $share['rg'] ?? null, // In JS org, rg mapped to document_cpf or similar
                        'document_cnpj' => $share['cnpj'] ?? null,
                        'percentage' => floatval($share['percentage'] ?? 0),
                    ]);
                }
            }

            // Save Files
            $docFields = ['ultimo_balanco', 'dre', 'politicas', 'cartao_cnpj', 'procuracao', 'ata', 'contrato_social', 'estatuto'];
            foreach ($docFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $path = $file->store('nimbus/submissions/'.$submission->id, 'local');

                    $submission->files()->create([
                        'requirement_type' => $field,
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $user->id,
                        'status' => 'PENDING_REVIEW',
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('nimbus.submissions.show', $submission->id)
                ->with('success', 'Solicitação enviada com sucesso! Nossa equipe analisará os documentos em breve.');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Ocorreu um erro ao processar sua solicitação: '.$e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Submission $submission)
    {
        $user = Auth::guard('nimbus')->user();

        // Ensure user can only see their own submissions
        if ($submission->nimbus_portal_user_id !== $user->id) {
            abort(403, 'Acesso negado.');
        }

        return view('nimbus.submissions.show', compact('submission'));
    }
}
