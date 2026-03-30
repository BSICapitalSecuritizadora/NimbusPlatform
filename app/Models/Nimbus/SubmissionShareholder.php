<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class SubmissionShareholder extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_submission_shareholders';

    protected $guarded = ['id'];

    protected $casts = [
        'percentage' => 'decimal:2',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }
}
