<?php

namespace App\DTOs\Measurements;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MeasurementOperationalExceptionResult
{
    /**
     * @param  list<MeasurementOperationalException>  $items
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public array $items,
        public array $counts,
        public int $total,
        public int $currentPage,
        public int $perPage,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return LengthAwarePaginator<int, MeasurementOperationalException>
     */
    public function paginator(string $path, array $query = []): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->items,
            $this->total,
            $this->perPage,
            $this->currentPage,
            [
                'path' => $path,
                'query' => $query,
            ],
        );
    }
}
