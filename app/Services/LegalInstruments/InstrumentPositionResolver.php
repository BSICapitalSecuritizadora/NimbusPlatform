<?php

namespace App\Services\LegalInstruments;

use App\DTOs\LegalInstruments\ConsolidatedFieldData;
use App\DTOs\LegalInstruments\InstrumentPositionData;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Models\Guarantee;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconstrói a posição jurídica de um instrumento numa data (§9, §10 e §12).
 *
 * A regra é simples e é o que faz a consulta retroativa funcionar sem tabela
 * extra: para cada campo, vale a versão **confirmada** de maior `effective_date`
 * que não ultrapasse a data consultada. Empate de data é desfeito pelo `id`, que
 * respeita a ordem de confirmação.
 *
 * Só linhas `confirmed` entram. Uma extração pendente nunca altera a posição
 * vigente — é exatamente o que impede a IA de mudar informação sozinha (§20).
 */
class InstrumentPositionResolver
{
    /**
     * Posição do instrumento na data informada (hoje, por padrão).
     */
    public function resolve(LegalInstrument $instrument, CarbonInterface|string|null $asOf = null): InstrumentPositionData
    {
        $asOf = $this->normalizeDate($asOf);

        $fields = $this->resolveFields($instrument, $asOf);

        return new InstrumentPositionData(
            instrument: $instrument,
            asOf: $asOf,
            fields: $fields,
            guarantees: $this->resolveGuarantees($instrument, $asOf),
        );
    }

    /**
     * Campos consolidados, indexados pelo `field_key`.
     *
     * @return Collection<string, ConsolidatedFieldData>
     */
    private function resolveFields(LegalInstrument $instrument, CarbonInterface $asOf): Collection
    {
        $versions = $this->confirmedVersionsUpTo($instrument, $asOf);

        return $versions
            ->map(function (Collection $fieldVersions, string $fieldKey): ?ConsolidatedFieldData {
                /** @var LegalInstrumentField|null $current */
                $current = $fieldVersions->first();

                if ($current === null) {
                    return null;
                }

                $key = $current->field_key;

                if (! $key instanceof LegalInstrumentFieldKey) {
                    return null;
                }

                return new ConsolidatedFieldData(
                    key: $key,
                    current: $current,
                    previous: $fieldVersions->skip(1)->first(),
                );
            })
            ->filter(fn (?ConsolidatedFieldData $field): bool => $field instanceof ConsolidatedFieldData);
    }

    /**
     * Estados que já foram verdade confirmada em algum momento.
     *
     * `Superseded` entra junto com `Confirmed` porque é exatamente o que uma
     * consulta retroativa precisa: a cobertura de 120% foi substituída pelos
     * 130%, mas em dezembro de 2024 ela era a posição vigente. Excluí-la
     * apagaria o passado que §12 exige reconstruir.
     *
     * `PendingReview` e `Rejected` nunca entram: o primeiro ainda não foi
     * aceito por ninguém, o segundo foi recusado.
     *
     * @var list<string>
     */
    private const POSITION_STATUSES = [
        LegalInstrumentFieldStatus::Confirmed->value,
        LegalInstrumentFieldStatus::Superseded->value,
    ];

    /**
     * Versões vigentes até a data, agrupadas por campo e ordenadas da mais
     * recente para a mais antiga.
     *
     * Uma versão sem `effective_date` é tratada como sempre vigente: o
     * documento existe e foi confirmado, e descartá-la por falta de data
     * esconderia informação que ninguém contestou.
     *
     * @return Collection<string, Collection<int, LegalInstrumentField>>
     */
    private function confirmedVersionsUpTo(LegalInstrument $instrument, CarbonInterface $asOf): Collection
    {
        $asOfString = $asOf->toDateString();

        return $instrument->fields()
            ->with(['document', 'instrumentDocument.document', 'reviewer'])
            ->whereIn('status', self::POSITION_STATUSES)
            ->where(function ($query) use ($asOfString): void {
                $query->whereNull('effective_date')->orWhere('effective_date', '<=', $asOfString);
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (LegalInstrumentField $field): string => $field->field_key?->value ?? 'unknown');
    }

    /**
     * Garantias do instrumento vigentes na data.
     *
     * @return Collection<int, Guarantee>
     */
    private function resolveGuarantees(LegalInstrument $instrument, CarbonInterface $asOf): Collection
    {
        return $instrument->guarantees()
            ->with(['events', 'valuations'])
            ->get()
            ->filter(fn (Guarantee $guarantee): bool => $guarantee->contributesToCoverageOn($asOf))
            ->values();
    }

    /**
     * Posição de uma garantia filha: os campos que pertencem a ela, e não ao
     * instrumento (§14 do escopo).
     *
     * @return Collection<string, ConsolidatedFieldData>
     */
    public function resolveGuaranteeFields(Guarantee $guarantee, CarbonInterface|string|null $asOf = null): Collection
    {
        $asOf = $this->normalizeDate($asOf);
        $asOfString = $asOf->toDateString();

        return $guarantee->instrumentFields()
            ->with(['document', 'instrumentDocument.document', 'reviewer'])
            ->whereIn('status', self::POSITION_STATUSES)
            ->where(function ($query) use ($asOfString): void {
                $query->whereNull('effective_date')->orWhere('effective_date', '<=', $asOfString);
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (LegalInstrumentField $field): string => $field->field_key?->value ?? 'unknown')
            ->map(function (Collection $versions): ?ConsolidatedFieldData {
                /** @var LegalInstrumentField|null $current */
                $current = $versions->first();

                if ($current === null || ! $current->field_key instanceof LegalInstrumentFieldKey) {
                    return null;
                }

                return new ConsolidatedFieldData(
                    key: $current->field_key,
                    current: $current,
                    previous: $versions->skip(1)->first(),
                );
            })
            ->filter(fn (?ConsolidatedFieldData $field): bool => $field instanceof ConsolidatedFieldData);
    }

    /**
     * Aceita a interface porque datas de model chegam como `CarbonImmutable`
     * (a aplicação usa `Date::use(CarbonImmutable::class)`), e o chamador não
     * deveria precisar converter antes.
     */
    private function normalizeDate(CarbonInterface|string|null $asOf): CarbonInterface
    {
        if ($asOf instanceof CarbonInterface) {
            return $asOf->copy()->endOfDay();
        }

        if (is_string($asOf)) {
            return Carbon::parse($asOf)->endOfDay();
        }

        return Carbon::now()->endOfDay();
    }
}
