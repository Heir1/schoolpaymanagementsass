<?php

namespace App\Modules\Billing\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupFeeInstallment extends Model
{
    use SoftDeletes;

    protected $table = 'group_fee_installments';

    protected $fillable = [
        'group_fee_id',
        'installment_no',
        'amount',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'installment_no' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function groupFee(): BelongsTo
    {
        return $this->belongsTo(GroupFee::class, 'group_fee_id');
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