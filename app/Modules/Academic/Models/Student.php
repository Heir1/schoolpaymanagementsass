<?php

namespace App\Modules\Academic\Models;

use App\Modules\Billing\Models\StudentFee;
use App\Modules\Billing\Models\StudentInscriptionPayment;
use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\StudentGroup;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $table = 'students';
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'class_id',
        'student_code',
        'first_name',
        'last_name',
        'middle_name',
        'gender',
        'birth_date',
        'province_id',
        'city_id',
        'street',
        'student_group_id',
        'is_approved',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_approved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function studentGroup(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class);
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
    public function studentFees(): HasMany
    {
        return $this->hasMany(StudentFee::class);
    }

    public function inscriptionPayments(): HasMany
    {
        return $this->hasMany(StudentInscriptionPayment::class);
    }


    // Dans la classe Student, ajoutez cette relation :
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id');
    }

    // Et cette méthode pour vérifier les documents requis :
    public function getMissingRequiredDocuments($classId = null)
    {
        $classId = $classId ?? $this->class_id;
        
        if (!$classId) {
            return collect();
        }
        
        $requiredDocs = ClassRequiredDocument::where('class_id', $classId)
            ->where('is_mandatory', true)
            ->get();
        
        $submittedDocs = $this->documents()->pluck('document_id')->toArray();
        
        return $requiredDocs->filter(function ($requiredDoc) use ($submittedDocs) {
            return !in_array($requiredDoc->document_id, $submittedDocs);
        });
    }

}