<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SubmissionController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const DOCUMENT_TYPE_MAP = [
        'ultimo_balanco' => 'BALANCE_SHEET',
        'dre' => 'DRE',
        'politicas' => 'POLICIES',
        'cartao_cnpj' => 'CNPJ_CARD',
        'procuracao' => 'POWER_OF_ATTORNEY',
        'ata' => 'MINUTES',
        'contrato_social' => 'ARTICLES_OF_INCORPORATION',
        'estatuto' => 'BYLAWS',
    ];

    public function index(): View
    {
        $user = Auth::guard('nimbus')->user();
        $submissions = Submission::where('nimbus_portal_user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->get();

        return view('nimbus.submissions.index', compact('submissions'));
    }

    public function create(): View
    {
        return view('nimbus.submissions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'responsible_name' => 'required|string|max:190',
            'company_cnpj' => 'required|string|max:18',
            'company_name' => 'required|string|max:190',
            'main_activity' => 'nullable|string|max:255',
            'phone' => 'required|string|max:50',
            'website' => 'nullable|url|max:255',
            'net_worth' => 'required|string',
            'annual_revenue' => 'required|string',
            'registrant_name' => 'required|string|max:190',
            'registrant_position' => 'nullable|string|max:100',
            'registrant_rg' => 'nullable|string|max:20',
            'registrant_cpf' => 'required|string|max:14',
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
        $shareholders = json_decode($request->input('shareholders', '[]'), true) ?? [];

        try {
            DB::beginTransaction();

            $cleanNetWorth = $this->normalizeMoneyInput((string) $request->net_worth);
            $cleanAnnualRevenue = $this->normalizeMoneyInput((string) $request->annual_revenue);
            $referenceCode = $this->generateReferenceCode();

            $submission = Submission::create([
                'nimbus_portal_user_id' => $user->id,
                'reference_code' => $referenceCode,
                'submission_type' => 'REGISTRATION',
                'title' => Str::limit("Solicitação de cadastro - {$request->company_name}", 190, ''),
                'message' => null,
                'status' => 'PENDING',
                'responsible_name' => $request->responsible_name,
                'company_cnpj' => $request->company_cnpj,
                'company_name' => $request->company_name,
                'main_activity' => $request->main_activity,
                'phone' => $request->phone,
                'website' => $request->website,
                'net_worth' => is_numeric($cleanNetWorth) ? $cleanNetWorth : 0,
                'annual_revenue' => is_numeric($cleanAnnualRevenue) ? $cleanAnnualRevenue : 0,
                'shareholder_data' => $shareholders,
                'registrant_name' => $request->registrant_name,
                'registrant_position' => $request->registrant_position,
                'registrant_rg' => $request->registrant_rg,
                'registrant_cpf' => $request->registrant_cpf,
                'is_us_person' => $request->boolean('is_us_person'),
                'is_pep' => $request->boolean('is_pep'),
                'created_ip' => $request->ip(),
                'created_user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'submitted_at' => now(),
            ]);

            foreach ($shareholders as $share) {
                if (! empty($share['name'])) {
                    $submission->shareholders()->create([
                        'name' => $share['name'],
                        'document_rg' => $share['rg'] ?? null,
                        'document_cnpj' => $share['cnpj'] ?? null,
                        'percentage' => (float) ($share['percentage'] ?? 0),
                    ]);
                }
            }

            foreach (self::DOCUMENT_TYPE_MAP as $field => $documentType) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $path = $file->store('nimbus/submissions/'.$submission->id, 'local');
                    $storedName = basename($path);
                    $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

                    $submissionFile = $submission->files()->create([
                        'document_type' => $documentType,
                        'origin' => 'USER',
                        'visible_to_user' => false,
                        'original_name' => $file->getClientOriginalName(),
                        'stored_name' => $storedName,
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                        'storage_path' => $path,
                        'checksum' => $checksum,
                        'uploaded_at' => now(),
                    ]);

                    $submissionFile->versions()->create([
                        'version' => 1,
                        'original_name' => $file->getClientOriginalName(),
                        'stored_name' => $storedName,
                        'storage_path' => $path,
                        'size_bytes' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'checksum' => $checksum,
                        'uploaded_by_type' => 'PORTAL_USER',
                        'uploaded_by_id' => $user->id,
                        'notes' => null,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('nimbus.submissions.show', $submission->id)
                ->with('success', 'Solicitação enviada com sucesso! Nossa equipe analisará os documentos em breve.');

        } catch (Throwable $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Ocorreu um erro ao processar sua solicitação: '.$e->getMessage());
        }
    }

    public function show(Submission $submission): View
    {
        $user = Auth::guard('nimbus')->user();

        if ($submission->nimbus_portal_user_id !== $user->id) {
            abort(403, 'Acesso negado.');
        }

        $submission->loadMissing([
            'shareholders',
            'userUploadedFiles',
            'portalVisibleResponseFiles',
        ]);

        return view('nimbus.submissions.show', compact('submission'));
    }

    public function downloadFile(Submission $submission, SubmissionFile $file): StreamedResponse
    {
        $user = Auth::guard('nimbus')->user();

        if ($submission->nimbus_portal_user_id !== $user->id) {
            abort(403, 'Acesso negado.');
        }

        abort_unless($file->nimbus_submission_id === $submission->id, 404);

        if (($file->origin === 'ADMIN') && (! $file->visible_to_user)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($file->storage_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->storage_path, $file->original_name);
    }

    private function normalizeMoneyInput(string $value): string
    {
        return str_replace(',', '.', str_replace(['R$', '.', ' '], '', $value));
    }

    private function generateReferenceCode(): string
    {
        return 'NMB-'.Str::upper((string) Str::ulid());
    }
}
