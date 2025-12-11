<?php

namespace App\Modules\Billing\Models;

use App\Modules\Academic\Models\Student;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentInscriptionPayment extends Model
{
    use SoftDeletes;

    protected $table = 'student_inscription_payments';

    protected $fillable = [
        'student_id',
        'inscription_fee_id',
        'payment_method_id',
        'amount_paid',
        'transaction_reference',
        'payment_date',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'payment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function inscriptionFee(): BelongsTo
    {
        return $this->belongsTo(InscriptionFee::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
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