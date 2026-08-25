<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementFileMigration extends Model
{
    public const STATE_PREPARING = 'preparing';

    public const STATE_PREPARED = 'prepared';

    public const STATE_SWITCHED = 'switched';

    public const STATE_VERIFIED = 'verified';

    public const STATE_PUBLIC_RESIDUE = 'public_residue';

    public const STATE_COMPLETED = 'completed';

    protected $fillable = [
        'migratable_type',
        'migratable_id',
        'file_role',
        'source_disk',
        'source_path',
        'source_sha256',
        'destination_disk',
        'destination_path',
        'recovery_path',
        'state',
        'last_error',
        'prepared_at',
        'switched_at',
        'verified_at',
        'cleaned_at',
    ];

    protected function casts(): array
    {
        return [
            'prepared_at' => 'datetime',
            'switched_at' => 'datetime',
            'verified_at' => 'datetime',
            'cleaned_at' => 'datetime',
        ];
    }
}
