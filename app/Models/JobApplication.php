<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'vacancy_id', 'name', 'email', 'phone', 
        'linkedin_url', 'resume_path', 'message'
    ];

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class);
    }
}
