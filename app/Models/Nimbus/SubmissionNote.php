<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class SubmissionNote extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_submission_notes';

    protected $guarded = ['id'];

    public function submission()
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
