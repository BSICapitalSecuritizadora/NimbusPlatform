<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategory extends Model
{
    use HasFactory;

    protected $table = 'nimbus_document_categories';

    protected $guarded = ['id'];

    public function generalDocuments(): HasMany
    {
        return $this->hasMany(GeneralDocument::class, 'nimbus_category_id');
    }
}
