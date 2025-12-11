<?php

namespace App\Modules\Academic\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InscriptionDocument extends Model
{
    use SoftDeletes;

    protected $table = 'inscription_documents';

    protected $fillable = [
        'name',
        'description',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relations
    public function classRequiredDocuments(): HasMany
    {
        return $this->hasMany(ClassRequiredDocument::class, 'document_id');
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'document_id');
    }

    // Accessor pour le nom complet du document
    public function getFullNameAttribute(): string
    {
        return $this->name . ($this->description ? ' - ' . $this->description : '');
    }
}