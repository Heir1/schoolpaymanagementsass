<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Models\ClassModel;
use App\Modules\Academic\Models\InscriptionDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassRequiredDocument extends Model
{
    use SoftDeletes;

    protected $table = 'class_required_documents';

    protected $fillable = [
        'class_id',
        'document_id',
        'is_mandatory',
        'created_at',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relations
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InscriptionDocument::class, 'document_id');
    }

    // Scope pour les documents obligatoires seulement
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    // Scope pour les documents facultatifs seulement
    public function scopeOptional($query)
    {
        return $query->where('is_mandatory', false);
    }
}