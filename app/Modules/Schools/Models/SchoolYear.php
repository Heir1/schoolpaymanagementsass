<?php

namespace App\Modules\Schools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Academic\Models\ClassModel; // IMPORTANT: Ajoutez cette ligne
use App\Modules\Users\Models\User;

class SchoolYear extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'year_label',
        'start_date',
        'end_date',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // AJOUTEZ CETTE MÉTHODE POUR LA RELATION AVEC LES CLASSES
    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class, 'school_year_id');
    }
}