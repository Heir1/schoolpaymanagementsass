<?php

namespace App\Modules\Shared\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'action',
        'entity',
        'entity_id',
        'details',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'entity_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessor pour les détails JSON
    public function getDetailsAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    public function setDetailsAttribute($value)
    {
        $this->attributes['details'] = json_encode($value);
    }
}