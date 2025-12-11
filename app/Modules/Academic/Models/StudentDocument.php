<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\InscriptionDocument;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    use SoftDeletes;

    protected $table = 'student_documents';

    protected $fillable = [
        'student_id',
        'document_id',
        'file_url',
        'is_correct',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relations
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InscriptionDocument::class, 'document_id');
    }

    // Accessor pour l'état du document
    public function getStatusAttribute(): string
    {
        if ($this->is_correct === true) {
            return 'validé';
        } elseif ($this->is_correct === false) {
            return 'rejeté';
        } else {
            return 'en attente';
        }
    }

    // Scope pour les documents validés
    public function scopeValidated($query)
    {
        return $query->where('is_correct', true);
    }

    // Scope pour les documents rejetés
    public function scopeRejected($query)
    {
        return $query->where('is_correct', false);
    }

    // Scope pour les documents en attente
    public function scopePending($query)
    {
        return $query->whereNull('is_correct');
    }

    // Méthode pour obtenir le nom du fichier depuis l'URL
    public function getFileNameAttribute(): string
    {
        return basename($this->file_url);
    }

    // Méthode pour obtenir l'extension du fichier
    public function getFileExtensionAttribute(): string
    {
        return pathinfo($this->file_url, PATHINFO_EXTENSION);
    }

    // Méthode pour vérifier si le document a un commentaire
    public function hasComment(): bool
    {
        return !empty($this->comment);
    }
}