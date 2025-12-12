<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
        'avatar_url',
        'password',
        'confirm_password',
        'remember_token', // ← AJOUTÉ
        'email_verified_at', // ← AJOUTÉ
    ];

    protected $hidden = [
        'password',
        'confirm_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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
}