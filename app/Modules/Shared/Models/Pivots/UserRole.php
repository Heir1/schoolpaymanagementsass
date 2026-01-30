<?php

namespace App\Modules\Shared\Models\Pivots;

use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\Role;
use Illuminate\Database\Eloquent\Relations\Pivot; // ← IMPORTANT: Étendre Pivot au lieu de Model
use Illuminate\Database\Eloquent\SoftDeletes;

class UserRole extends Pivot  // ← CHANGEMENT ICI
{
    use SoftDeletes;

    protected $table = 'user_roles';

    // Dans la classe UserRole qui étend Pivot
    public $timestamps = true; // Active les timestamps pour les pivots

    // Note: Pivot n'a pas de $fillable par défaut, mais nous pouvons l'ajouter
    protected $fillable = [
        'user_id',
        'school_id',
        'role_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Les relations BelongsTo doivent être ajustées car Pivot a un comportement différent
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isSystemWide(): bool
    {
        return is_null($this->school_id);
    }

    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeSystemWide($query)
    {
        return $query->whereNull('school_id');
    }
}