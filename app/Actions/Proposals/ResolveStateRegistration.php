<?php

namespace App\Actions\Proposals;

use Illuminate\Support\Str;

class ResolveStateRegistration
{
    /** @param array<int, mixed> $stateRegistrations */
    public function handle(array $stateRegistrations): ?string
    {
        $validRegistrations = collect($stateRegistrations)
            ->filter(fn (mixed $registration): bool => is_array($registration))
            ->map(fn (array $registration): array => [
                'number' => trim((string) ($registration['inscricao_estadual'] ?? '')),
                'active' => $this->isActive($registration),
            ])
            ->filter(fn (array $registration): bool => $registration['number'] !== '')
            ->values();

        return $validRegistrations->firstWhere('active', true)['number']
            ?? $validRegistrations->first()['number']
            ?? null;
    }

    /** @param array<string, mixed> $registration */
    private function isActive(array $registration): bool
    {
        foreach (['ativo', 'ativa'] as $activeKey) {
            if (! array_key_exists($activeKey, $registration)) {
                continue;
            }

            if ($registration[$activeKey] === true || $registration[$activeKey] === 1 || $registration[$activeKey] === '1') {
                return true;
            }

            if ($registration[$activeKey] === false || $registration[$activeKey] === 0 || $registration[$activeKey] === '0') {
                return false;
            }
        }

        $status = Str::of((string) ($registration['situacao'] ?? $registration['status'] ?? ''))
            ->ascii()
            ->lower()
            ->trim()
            ->toString();

        return in_array($status, ['ativa', 'ativo', 'habilitada', 'habilitado'], true);
    }
}
