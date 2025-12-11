<?php

namespace App\Modules\Billing\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeDependency extends Model
{
    use SoftDeletes;

    protected $table = 'fee_dependencies';

    protected $fillable = [
        'required_fee_id',
        'dependent_fee_id',
        'required_fee_type',
        'dependent_fee_type',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Note: Ces relations sont complexes car elles peuvent pointer vers différents types de frais
    // On utilisera des accessors personnalisés ou des relations polymorphes

    public function requiredFee()
    {
        return $this->morphTo(__FUNCTION__, 'required_fee_type', 'required_fee_id');
    }

    public function dependentFee()
    {
        return $this->morphTo(__FUNCTION__, 'dependent_fee_type', 'dependent_fee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}