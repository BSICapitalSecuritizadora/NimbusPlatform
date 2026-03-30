<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class GeneralDocument extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_general_documents';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(DocumentCategory::class, 'nimbus_category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }
}
