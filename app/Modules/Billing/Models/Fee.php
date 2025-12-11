<?php

namespace App\Modules\Billing\Models;

use App\Modules\Shared\Models\Pivots\ClassesFee;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fee extends Model
{
    protected $table = 'fees';
    use SoftDeletes;

    protected $fillable = [
        'fee_type_id',
        'amount',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Ajouter ces relations
    public function installments(): HasMany
    {
        return $this->hasMany(FeeInstallment::class, 'fee_id');
    }

    public function classFees(): HasMany
    {
        return $this->hasMany(ClassesFee::class, 'fee_id');
    }
    
}