<?php

namespace App\Modules\Academic\Models;

use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\SchoolYear;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassModel extends Model
{
    use SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'school_year_id',
        'name',
        'level',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Dans la classe ClassModel, ajoutez cette relation :
    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(ClassRequiredDocument::class, 'class_id');
    }

    // Et cette méthode pour obtenir tous les documents requis :
    public function getRequiredDocuments($mandatoryOnly = false)
    {
        $query = $this->requiredDocuments()->with('document');
        
        if ($mandatoryOnly) {
            $query->where('is_mandatory', true);
        }
        
        return $query->get();
    }
    
}