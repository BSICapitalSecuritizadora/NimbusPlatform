<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class SubmissionFileVersion extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_submission_file_versions';

    protected $guarded = ['id'];

    public function file()
    {
        return $this->belongsTo(SubmissionFile::class, 'nimbus_submission_file_id');
    }
}
