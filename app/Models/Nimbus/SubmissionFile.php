<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class SubmissionFile extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_submission_files';

    protected $guarded = ['id'];

    protected $casts = [
        'visible_to_user' => 'boolean',
        'uploaded_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class, 'nimbus_submission_id');
    }

    public function versions()
    {
        return $this->hasMany(SubmissionFileVersion::class, 'nimbus_submission_file_id');
    }
}
