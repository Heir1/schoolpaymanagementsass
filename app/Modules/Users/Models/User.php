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
        'phone_or_email', // Maintenant obligatoire et unique
        'avatar_path',
        'password',
        'confirm_password',
        'remember_token', // ← AJOUTÉ
        'email_verified_at', // ← AJOUTÉ
    ];

    // Ajoutez ce cast
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

    // Ajoutez cet accessor pour l'URL complète
    public function getAvatarUrlAttribute()
    {
        if (!$this->avatar_path) {
            return null;
        }
        
        return Storage::disk('public')->url($this->avatar_path);
    }

    // Méthode pour supprimer l'avatar
    public function deleteAvatar()
    {
        if ($this->avatar_path && Storage::disk('public')->exists($this->avatar_path)) {
            Storage::disk('public')->delete($this->avatar_path);
            $this->update(['avatar_path' => null]);
            return true;
        }
        return false;
    }
    
}