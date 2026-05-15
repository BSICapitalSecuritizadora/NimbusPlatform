<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('remuneration_indexer')->nullable();
            $table->decimal('remuneration_rate', 8, 2)->nullable();
        });

        DB::table('emissions')
            ->select(['id', 'remuneration'])
            ->whereNotNull('remuneration')
            ->orderBy('id')
            ->get()
            ->each(function (object $emission): void {
                ['indexer' => $indexer, 'rate' => $rate] = $this->parseRemuneration($emission->remuneration);

                if ($indexer === null && $rate === null) {
                    return;
                }

                DB::table('emissions')
                    ->where('id', $emission->id)
                    ->update([
                        'remuneration_indexer' => $indexer,
                        'remuneration_rate' => $rate,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropColumn([
                'remuneration_indexer',
                'remuneration_rate',
            ]);
        });
    }

    /**
     * @return array{indexer: ?string, rate: ?float}
     */
    private function parseRemuneration(?string $remuneration): array
    {
        if ($remuneration === null) {
            return ['indexer' => null, 'rate' => null];
        }

        if (preg_match('/^\s*(CDI|IPCA)\s*\+\s*([\d.,]+)\s*%\s*(?:a\.a\.)?\s*$/iu', $remuneration, $matches) !== 1) {
            return ['indexer' => null, 'rate' => null];
        }

        return [
            'indexer' => strtoupper($matches[1]),
            'rate' => (float) str_replace(['.', ','], ['', '.'], $matches[2]),
        ];
    }
};
