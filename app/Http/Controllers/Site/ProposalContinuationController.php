<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalContinuationRequest;
use App\Http\Requests\VerifyProposalContinuationRequest;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProposalContinuationController extends Controller
{
    public function showAccess(Request $request, ProposalContinuationAccess $access): View|RedirectResponse
    {
        abort_unless($request->hasValidSignature() && $access->isActive(), 403);

        $access->markLinkOpened();

        $request->session()->put($this->magicLinkSessionKey($access), true);

        if ($this->isAuthorized($request, $access)) {
            return redirect()->route('site.proposal.continuation.form', $access);
        }

        return view('site.proposal.access', [
            'access' => $access,
            'proposal' => $this->loadProposal($access),
        ]);
    }

    public function verify(
        VerifyProposalContinuationRequest $request,
        ProposalContinuationAccess $access,
    ): RedirectResponse {
        $this->ensureMagicLinkConfirmed($request, $access);

        $access->markLinkOpened();

        $proposal = $this->loadProposal($access);

        if ($this->normalizeCnpj($request->validated('cnpj')) !== $this->normalizeCnpj((string) $proposal->company?->cnpj)) {
            throw ValidationException::withMessages([
                'cnpj' => 'O CNPJ informado não corresponde à proposta enviada.',
            ]);
        }

        if (! $access->matchesCode($request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => 'O código informado é inválido.',
            ]);
        }

        $request->session()->put($this->verifiedSessionKey($access), true);

        $access->markVerified();

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Acesso validado. Você já pode continuar o preenchimento.');
    }

    public function showForm(Request $request, ProposalContinuationAccess $access): View
    {
        $this->ensureAuthorized($request, $access);

        return view('site.proposal.continuation', [
            'access' => $access,
            'proposal' => $this->loadProposal($access),
        ]);
    }

    public function store(
        StoreProposalContinuationRequest $request,
        ProposalContinuationAccess $access,
    ): RedirectResponse {
        $this->ensureAuthorized($request, $access);

        $proposal = $this->loadProposal($access);
        $validated = $request->validated();

        DB::transaction(function () use ($proposal, $validated, $request): void {
            $proposal->projects()->delete();

            $sharedPayload = [
                'company_name' => $validated['nome'],
                'site' => $validated['site'] ?? null,
                'value_requested' => $validated['valor_solicitado'],
                'land_market_value' => $validated['valor_mercado_terreno'] ?? null,
                'land_area' => $validated['area_terreno'],
                'cep' => $validated['cep'],
                'logradouro' => $validated['logradouro'],
                'numero' => $validated['numero'],
                'complemento' => $validated['complemento'] ?? null,
                'bairro' => $validated['bairro'],
                'cidade' => $validated['cidade'],
                'estado' => $validated['estado'],
                'launch_date' => $this->monthToDate($validated['data_lancamento']),
                'sales_launch_date' => $this->monthToDate($validated['lancamento_vendas']),
                'construction_start_date' => $this->monthToDate($validated['inicio_obras']),
                'delivery_forecast_date' => $this->monthToDate($validated['previsao_entrega']),
            ];

            $remainingMonths = $this->calculateRemainingMonths(
                $sharedPayload['construction_start_date'],
                $sharedPayload['delivery_forecast_date'],
            );

            foreach ($validated['nome_empreendimento'] as $index => $projectName) {
                $project = $proposal->projects()->create([
                    ...$sharedPayload,
                    'name' => $projectName,
                    'remaining_months' => $validated['prazo_remanescente'] ?? $remainingMonths,
                    'units_exchanged' => $validated['unidades_permutadas'][$index] ?? 0,
                    'units_paid' => $validated['unidades_quitadas'][$index] ?? 0,
                    'units_unpaid' => $validated['unidades_nao_quitadas'][$index] ?? 0,
                    'units_stock' => $validated['unidades_estoque'][$index] ?? 0,
                    'cost_incurred' => $validated['custo_incidido'][$index] ?? null,
                    'cost_to_incur' => $validated['custo_a_incorrer'][$index] ?? null,
                    'value_paid' => $validated['valor_quitadas'][$index] ?? null,
                    'value_unpaid' => $validated['valor_nao_quitadas'][$index] ?? null,
                    'value_stock' => $validated['valor_estoque'][$index] ?? null,
                    'value_received' => $validated['valor_ja_recebido'][$index] ?? null,
                    'value_until_keys' => $validated['valor_ate_chaves'][$index] ?? null,
                    'value_post_keys' => $validated['valor_chaves_pos'][$index] ?? null,
                ]);

                $characteristic = $project->characteristics()->create([
                    'blocks' => $validated['car_bloco'],
                    'floors' => $validated['car_pavimentos'],
                    'typical_floors' => $validated['car_andares_tipo'],
                    'units_per_floor' => $validated['car_unidades_andar'],
                    'total_units' => $validated['car_total']
                        ?? ($validated['car_bloco'] * $validated['car_andares_tipo'] * $validated['car_unidades_andar']),
                ]);

                foreach ($validated['tipo_total'] as $typeIndex => $totalUnits) {
                    $averagePrice = $this->normalizeMoney($validated['tipo_preco_medio'][$typeIndex] ?? null);
                    $usefulArea = (float) ($validated['tipo_area'][$typeIndex] ?? 0);

                    $characteristic->unitTypes()->create([
                        'order' => $typeIndex + 1,
                        'total_units' => $totalUnits,
                        'bedrooms' => $validated['tipo_dormitorios'][$typeIndex] ?? null,
                        'parking_spaces' => $validated['tipo_vagas'][$typeIndex] ?? null,
                        'useful_area' => $usefulArea,
                        'average_price' => $averagePrice,
                        'price_per_m2' => $usefulArea > 0 ? round($averagePrice / $usefulArea, 2) : 0,
                    ]);
                }
            }

            if ($request->hasFile('arquivos')) {
                foreach ($request->file('arquivos') as $file) {
                    $storedPath = $file->store("proposal-files/{$proposal->id}", 'local');

                    $proposal->files()->create([
                        'disk' => 'local',
                        'file_path' => $storedPath,
                        'file_name' => basename($storedPath),
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $proposal->forceFill([
                'status' => Proposal::STATUS_IN_REVIEW,
                'completed_at' => now(),
            ])->save();
        });

        return redirect()
            ->route('site.proposal.continuation.form', $access)
            ->with('success', 'Empreendimento(s) salvo(s) com sucesso.');
    }

    public function downloadFile(
        Request $request,
        ProposalContinuationAccess $access,
        ProposalFile $file,
    ) {
        $this->ensureAuthorized($request, $access);

        abort_unless($file->proposal_id === $access->proposal_id, 404);

        return Storage::disk($file->disk)->download($file->file_path, $file->original_name);
    }

    protected function loadProposal(ProposalContinuationAccess $access): Proposal
    {
        return $access->proposal()
            ->with([
                'company.sectors',
                'contact',
                'projects.characteristics.unitTypes',
                'files',
            ])
            ->firstOrFail();
    }

    protected function ensureAuthorized(Request $request, ProposalContinuationAccess $access): void
    {
        $this->ensureMagicLinkConfirmed($request, $access);

        abort_unless($this->isAuthorized($request, $access), 403);

        $access->markAuthorizedUsage();
    }

    protected function ensureMagicLinkConfirmed(Request $request, ProposalContinuationAccess $access): void
    {
        abort_unless($request->session()->has($this->magicLinkSessionKey($access)) && $access->isActive(), 403);
    }

    protected function isAuthorized(Request $request, ProposalContinuationAccess $access): bool
    {
        return $request->session()->has($this->verifiedSessionKey($access)) && $access->isActive();
    }

    protected function magicLinkSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_magic_link.{$access->id}";
    }

    protected function verifiedSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_verified.{$access->id}";
    }

    protected function normalizeCnpj(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    protected function monthToDate(string $value): string
    {
        return Carbon::createFromFormat('Y-m', $value)->startOfMonth()->toDateString();
    }

    protected function calculateRemainingMonths(string $startDate, string $endDate): int
    {
        return Carbon::parse($startDate)->diffInMonths(Carbon::parse($endDate));
    }

    protected function normalizeMoney(null|string|float|int $value): float
    {
        if (($value === null) || ($value === '')) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace(['R$', ' '], '', $value);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? round((float) $normalized, 2) : 0.0;
    }
}
