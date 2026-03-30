<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_tags';

    protected $guarded = ['id'];

    public function submissions()
    {
        return $this->belongsToMany(Submission::class, 'nimbus_submission_tags', 'nimbus_tag_id', 'nimbus_submission_id');
    }
}
