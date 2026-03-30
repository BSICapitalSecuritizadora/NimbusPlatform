<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $table = 'nimbus_document_categories';

    protected $guarded = ['id'];

    public function generalDocuments()
    {
        return $this->hasMany(GeneralDocument::class, 'nimbus_category_id');
    }
}
