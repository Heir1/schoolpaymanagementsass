<?php

namespace App\Modules\Users\Models;

use App\Modules\Shared\Models\Pivots\UserRole;
use App\Modules\Users\Models\User; // Ajout de l'import User
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;

    protected $table = 'roles';

    /**
     * Champs remplissables en masse.
     * Note: 'guard_name' est REQUIS par Spatie Permission
     */
    protected $fillable = [
        'name',
        'guard_name', // ◀ IMPORTANT: Ajouté pour Spatie
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot du modèle pour définir des valeurs par défaut
     */
    protected static function boot()
    {
        parent::boot();

        // Définir 'web' comme guard_name par défaut si non fourni
        static::creating(function ($model) {
            if (empty($model->guard_name)) {
                $model->guard_name = 'web';
            }
        });
    }

    /**
     * Relation avec la table pivot UserRole
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Relation avec l'utilisateur qui a créé ce rôle
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relation avec l'utilisateur qui a modifié ce rôle
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope pour filtrer par guard_name
     */
    public function scopeForGuard($query, $guardName)
    {
        return $query->where('guard_name', $guardName);
    }

    /**
     * Vérifie si ce rôle est assignable à une école
     * (Utile pour la logique métier avec school_id nullable)
     */
    public function isSchoolAssignable(): bool
    {
        // Exemple: certains rôles comme 'superadmin' ne devraient pas être liés à une école
        $nonSchoolRoles = ['superadmin', 'system_admin'];
        return !in_array($this->name, $nonSchoolRoles);
    }
}