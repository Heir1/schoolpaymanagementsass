<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Modules\Shared\Models\Pivots\UserRole;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'full_name',
        'phone_or_email',
        'avatar_path',
        'password',
        'confirm_password',
        'remember_token',
        'email_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'initial_password_set' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'confirm_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Déterminer si le phone_or_email est un email
     */
    public function isEmail(): bool
    {
        return filter_var($this->phone_or_email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Déterminer si le phone_or_email est un téléphone
     */
    public function isPhone(): bool
    {
        return !$this->isEmail();
    }

    /**
     * Récupérer l'identifiant pour la connexion
     */
    public function getAuthIdentifierName(): string
    {
        return 'phone_or_email';
    }

    public function userRoles()
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    public function parentProfile()
    {
        return $this->hasOne(ParentModel::class, 'user_id');
    }

    public function parent()
    {
        return $this->hasOne(ParentModel::class, 'user_id');
    }

    /**
     * Accessor pour l'URL complète de l'avatar
     */
    public function getAvatarUrlAttribute()
    {
        if (!$this->avatar_path) {
            return null;
        }
        
        return Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Méthode pour supprimer l'avatar
     */
    public function deleteAvatar()
    {
        if ($this->avatar_path && Storage::disk('public')->exists($this->avatar_path)) {
            Storage::disk('public')->delete($this->avatar_path);
            $this->update(['avatar_path' => null]);
            return true;
        }
        return false;
    }

    /**
     * Relation many-to-many avec les rôles via la table pivot
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
                    ->withPivot('school_id', 'created_by', 'updated_by', 'deleted_at')
                    ->wherePivotNull('deleted_at') // Exclure les relations soft deleted
                    ->withTimestamps();
    }

    /**
     * Vérifier si l'utilisateur a un rôle spécifique
     * 
     * @param string $roleName Nom du rôle
     * @param int|null $schoolId ID de l'école (optionnel)
     * @return bool
     */
    public function hasRole($roleName, $schoolId = null): bool
    {
        $query = $this->roles()->where('name', $roleName);
        
        // Si un school_id est spécifié, filtrer par school_id
        if ($schoolId !== null) {
            $query->wherePivot('school_id', $schoolId);
        }
        // Sinon, accepter le rôle quel que soit le school_id
        // (y compris null pour les rôles système comme super_admin)
        
        return $query->exists();
    }

    /**
     * Vérifier si l'utilisateur a au moins un des rôles spécifiés
     * 
     * @param array $roleNames Liste des noms de rôles
     * @param int|null $schoolId ID de l'école (optionnel)
     * @return bool
     */
    public function hasAnyRole(array $roleNames, $schoolId = null): bool
    {
        if (empty($roleNames)) {
            return false;
        }
        
        $query = $this->roles()->whereIn('name', $roleNames);
        
        // Si un school_id est spécifié, filtrer par school_id
        if ($schoolId !== null) {
            $query->wherePivot('school_id', $schoolId);
        }
        // Sinon, accepter les rôles quel que soit le school_id
        
        return $query->exists();
    }

    /**
     * Obtenir le school_id de l'utilisateur (pour school_admin)
     */
    public function getSchoolId()
    {
        // Chercher un rôle school_admin avec un school_id non null
        $schoolAdminRole = $this->roles()
            ->where('name', 'school_admin')
            ->whereNotNull('school_id')
            ->first();
        
        return $schoolAdminRole ? $schoolAdminRole->pivot->school_id : null;
    }


    /**
     * Vérifier si l'utilisateur est un parent
    */
    public function isParent()
    {
        // Méthode 1: Vérifier par le rôle
        return $this->hasRole('parent');
        
        // OU Méthode 2: Vérifier par la relation parent
        // return $this->parent()->exists();
    }


    /**
     * Vérifier si l'utilisateur est super_admin
     */
    public function isSuperAdmin(): bool
    {
        // Un super_admin n'a pas de school_id dans le pivot
        return $this->roles()
            ->where('name', 'super_admin')
            ->whereNull('school_id')
            ->exists();
    }

    /**
     * Vérifier si l'utilisateur est school_admin
     * 
     * @param int|null $schoolId ID de l'école (optionnel)
     */
    public function isSchoolAdmin($schoolId = null): bool
    {
        return $this->hasRole('school_admin', $schoolId);
    }

    /**
     * Obtenir tous les rôles avec leurs détails de pivot
     */
    public function getRolesWithDetails()
    {
        return $this->roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'school_id' => $role->pivot->school_id ?? null,
                'created_at' => $role->pivot->created_at ?? null,
                'updated_at' => $role->pivot->updated_at ?? null,
            ];
        });
    }

    /**
     * Attribuer un rôle à l'utilisateur
     * 
     * @param string $roleName Nom du rôle
     * @param int|null $schoolId ID de l'école (pour school_admin)
     * @param int|null $createdBy ID de l'utilisateur qui crée l'attribution
     * @return bool
     */
    public function assignRole($roleName, $schoolId = null, $createdBy = null)
    {
        $role = Role::where('name', $roleName)->first();
        
        if (!$role) {
            return false;
        }
        
        // Vérifier si l'utilisateur a déjà ce rôle avec ce school_id
        $existing = $this->roles()
            ->where('name', $roleName)
            ->wherePivot('school_id', $schoolId)
            ->exists();
        
        if ($existing) {
            return true; // Déjà assigné
        }
        
        // Attacher le rôle avec les données du pivot
        $this->roles()->attach($role->id, [
            'school_id' => $schoolId,
            'created_by' => $createdBy ?? auth()->id(),
            'updated_by' => $createdBy ?? auth()->id(),
        ]);
        
        return true;
    }

    /**
     * Retirer un rôle à l'utilisateur (soft delete)
     * 
     * @param string $roleName Nom du rôle
     * @param int|null $schoolId ID de l'école
     * @return bool
     */
    public function removeRole($roleName, $schoolId = null)
    {
        $role = $this->roles()
            ->where('name', $roleName)
            ->wherePivot('school_id', $schoolId)
            ->first();
        
        if (!$role) {
            return false;
        }
        
        // Soft delete via le pivot
        $this->roles()->updateExistingPivot($role->id, [
            'deleted_at' => now(),
            'updated_by' => auth()->id(),
        ]);
        
        return true;
    }
}