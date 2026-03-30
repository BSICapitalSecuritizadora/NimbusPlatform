<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_submissions';

    protected $guarded = ['id'];

    protected $casts = [
        'is_us_person' => 'boolean',
        'is_pep' => 'boolean',
        'shareholder_data' => 'array',
        'submitted_at' => 'datetime',
        'status_updated_at' => 'datetime',
        'net_worth' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
    ];

    public function portalUser()
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function statusUpdatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'status_updated_by');
    }

    public function shareholders()
    {
        return $this->hasMany(SubmissionShareholder::class, 'nimbus_submission_id');
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class, 'nimbus_submission_id');
    }

    public function notes()
    {
        return $this->hasMany(SubmissionNote::class, 'nimbus_submission_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'nimbus_submission_tags', 'nimbus_submission_id', 'nimbus_tag_id');
    }
}
