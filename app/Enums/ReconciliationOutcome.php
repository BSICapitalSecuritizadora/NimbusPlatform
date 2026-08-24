<?php

namespace App\Enums;

/**
 * What a reconciliation decided about one row of a spreadsheet.
 *
 * Replaces the old "already registered, discard" verdict: a row that matches an
 * existing record is now compared, and the answer is one of these.
 */
enum ReconciliationOutcome: string
{
    case New = 'novo';

    case Unchanged = 'sem_alteracao';

    case Update = 'atualizacao';

    case CriticalUpdate = 'atualizacao_critica';

    case Conflict = 'conflito';

    case Error = 'erro';

    case DuplicatedInFile = 'duplicada_na_planilha';

    case Empty = 'vazia';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Novo',
            self::Unchanged => 'Sem alteração',
            self::Update => 'Atualização',
            self::CriticalUpdate => 'Atualização crítica',
            self::Conflict => 'Conflito',
            self::Error => 'Erro',
            self::DuplicatedInFile => 'Duplicada na planilha',
            self::Empty => 'Linha vazia',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'success',
            self::Unchanged => 'gray',
            self::Update => 'info',
            self::CriticalUpdate => 'warning',
            self::Conflict, self::Error, self::DuplicatedInFile => 'danger',
            self::Empty => 'gray',
        };
    }

    /**
     * Whether the row stops the import. "Sem alteração" never does -- it is the
     * expected verdict for most of a monthly file.
     */
    public function blocksImport(): bool
    {
        return match ($this) {
            self::Conflict, self::Error, self::DuplicatedInFile => true,
            default => false,
        };
    }

    /**
     * Whether confirming the import writes anything for this row.
     */
    public function writesToDatabase(): bool
    {
        return match ($this) {
            self::New, self::Update, self::CriticalUpdate => true,
            default => false,
        };
    }

    public function isUpdate(): bool
    {
        return ($this === self::Update) || ($this === self::CriticalUpdate);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->reject(fn (self $outcome): bool => $outcome === self::Empty)
            ->mapWithKeys(fn (self $outcome): array => [$outcome->value => $outcome->label()])
            ->all();
    }
}
