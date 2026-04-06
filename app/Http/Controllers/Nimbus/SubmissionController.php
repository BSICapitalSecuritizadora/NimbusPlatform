<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nimbus\StoreSubmissionReplyRequest;
use App\Http\Requests\Nimbus\StoreSubmissionRequest;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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

    public function store(StoreSubmissionRequest $request): RedirectResponse
    {
        $user = Auth::guard('nimbus')->user();
        $shareholders = json_decode($request->input('shareholders', '[]'), true) ?? [];

        try {
            $submission = DB::transaction(function () use ($request, $user, $shareholders): Submission {
                $cleanNetWorth = $this->normalizeMoneyInput((string) $request->net_worth);
                $cleanAnnualRevenue = $this->normalizeMoneyInput((string) $request->annual_revenue);

                $submission = Submission::create([
                    'nimbus_portal_user_id' => $user->id,
                    'reference_code' => $this->generateReferenceCode(),
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

                return $submission;
            });

            return redirect()->route('nimbus.submissions.show', $submission->id)
                ->with('success', 'Solicitação enviada com sucesso! Nossa equipe analisará os documentos em breve.');

        } catch (Throwable $e) {
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
            'portalVisibleNotes',
        ]);

        return view('nimbus.submissions.show', compact('submission'));
    }

    public function reply(StoreSubmissionReplyRequest $request, Submission $submission): RedirectResponse
    {
        $user = Auth::guard('nimbus')->user();

        if ($submission->nimbus_portal_user_id !== $user->id) {
            abort(403, 'Acesso negado.');
        }

        abort_unless($submission->status === Submission::STATUS_NEEDS_CORRECTION, 403);

        DB::transaction(function () use ($request, $submission, $user): void {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $file->store("nimbus/submissions/{$submission->id}/corrections", 'local');
                $storedName = basename($path);
                $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

                $submissionFile = $submission->files()->create([
                    'document_type' => 'OTHER',
                    'origin' => 'USER',
                    'visible_to_user' => true,
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
                    'notes' => 'Arquivo enviado pelo solicitante em resposta a uma solicitação de correção.',
                ]);
            }

            $comment = trim((string) $request->input('comment'));

            if ($comment !== '') {
                $submission->notes()->create([
                    'user_id' => null,
                    'visibility' => 'ADMIN_ONLY',
                    'message' => $comment,
                ]);
            }

            $submission->update([
                'status' => Submission::STATUS_UNDER_REVIEW,
                'status_updated_at' => now(),
                'status_updated_by' => null,
            ]);
        });

        return redirect()
            ->route('nimbus.submissions.show', $submission)
            ->with('success', 'Correção enviada com sucesso. Sua solicitação voltou para análise.');
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
