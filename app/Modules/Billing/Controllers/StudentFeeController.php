<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\StudentFee;
use App\Modules\Billing\Models\StudentFeeInstallment;
use App\Modules\Billing\Models\FeeType;
use App\Modules\Academic\Models\Student;
use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;
use Carbon\Carbon;

class StudentFeeController extends Controller
{
    /**
     * GET: Liste tous les frais d'un étudiant
     * GET /api/v1/admin/students/{studentId}/fees
     */
    public function index(Request $request, $studentId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe avec withTrashed pour voir même les supprimés
            $student = Student::withTrashed()->with(['class.school'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            // Construire la requête avec withTrashed pour inclure les frais supprimés
            $query = StudentFee::where('student_id', $studentId)
                ->withTrashed()
                ->with([
                    'feeType',
                    'installments',
                    'createdBy',
                    'updatedBy'
                ]);
            
            // Filtre par statut (actif/supprimé/tous)
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
                // Si 'all' ou autre, on garde withTrashed (déjà appliqué)
            }
            
            // Filtres
            if ($request->has('fee_type_id')) {
                $query->where('fee_type_id', $request->fee_type_id);
            }
            
            if ($request->has('has_installments')) {
                if ($request->has_installments == 'yes') {
                    $query->has('installments');
                } elseif ($request->has_installments == 'no') {
                    $query->doesntHave('installments');
                }
            }
            
            if ($request->has('due_date_from')) {
                $query->whereDate('due_date', '>=', $request->due_date_from);
            }
            
            if ($request->has('due_date_to')) {
                $query->whereDate('due_date', '<=', $request->due_date_to);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('feeType', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    });
                });
            }
            
            // Trier par défaut par date d'échéance
            $query->orderBy('due_date', 'asc');
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $studentFees = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedFees = $studentFees->getCollection()->map(function ($studentFee) {
                return [
                    'id' => $studentFee->id,
                    'student_id' => $studentFee->student_id,
                    'amount' => (float) $studentFee->amount,
                    'due_date' => $studentFee->due_date ? $studentFee->due_date->toDateString() : null,
                    'fee_type' => $studentFee->feeType ? [
                        'id' => $studentFee->feeType->id,
                        'name' => $studentFee->feeType->name,
                        'payable_by' => $studentFee->feeType->payable_by,
                    ] : null,
                    'installments' => $studentFee->installments->sortBy('installment_no')->map(function ($installment) {
                        return [
                            'id' => $installment->id,
                            'installment_no' => $installment->installment_no,
                            'amount' => (float) $installment->amount,
                            'due_date' => $installment->due_date ? $installment->due_date->toDateString() : null,
                        ];
                    })->values(),
                    'created_by' => $studentFee->createdBy ? [
                        'id' => $studentFee->createdBy->id,
                        'name' => $studentFee->createdBy->full_name,
                    ] : null,
                    'updated_by' => $studentFee->updatedBy ? [
                        'id' => $studentFee->updatedBy->id,
                        'name' => $studentFee->updatedBy->full_name,
                    ] : null,
                    'created_at' => $studentFee->created_at ? $studentFee->created_at->toIso8601String() : null,
                    'updated_at' => $studentFee->updated_at ? $studentFee->updated_at->toIso8601String() : null,
                    'deleted_at' => $studentFee->deleted_at ? $studentFee->deleted_at->toIso8601String() : null,
                    'is_deleted' => !is_null($studentFee->deleted_at),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des frais de l\'étudiant récupérée avec succès',
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'code' => $student->student_code,
                        'full_name' => trim($student->first_name.' '.$student->last_name.' '.$student->middle_name),
                        'class' => $student->class ? [
                            'id' => $student->class->id,
                            'name' => $student->class->name,
                            'school' => $student->class->school ? [
                                'id' => $student->class->school->id,
                                'name' => $student->class->school->name,
                            ] : null,
                        ] : null,
                        'is_deleted' => !is_null($student->deleted_at),
                    ],
                    'fees' => $transformedFees,
                    'pagination' => [
                        'total' => $studentFees->total(),
                        'per_page' => $studentFees->perPage(),
                        'current_page' => $studentFees->currentPage(),
                        'last_page' => $studentFees->lastPage(),
                        'from' => $studentFees->firstItem(),
                        'to' => $studentFees->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching student fees list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des frais de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer un nouveau frais pour un étudiant
     * POST /api/v1/admin/students/{studentId}/fees
     */
    public function store(Request $request, $studentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'fee_type_id' => 'required|exists:fee_types,id',
                'amount' => 'required|numeric|min:0',
                'due_date' => 'required|date',
                'installments' => 'nullable|array',
                'installments.*.installment_no' => 'required_with:installments|integer|min:1',
                'installments.*.due_date' => 'required_with:installments|date',
                'installments.*.amount' => 'required_with:installments|numeric|min:0',
            ], [
                'fee_type_id.required' => 'Le type de frais est requis',
                'fee_type_id.exists' => 'Le type de frais sélectionné n\'existe pas',
                'amount.required' => 'Le montant est requis',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.required' => 'La date d\'échéance est requise',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
                'installments.array' => 'Les tranches doivent être un tableau',
                'installments.*.installment_no.required' => 'Le numéro de tranche est requis',
                'installments.*.installment_no.integer' => 'Le numéro de tranche doit être un entier',
                'installments.*.installment_no.min' => 'Le numéro de tranche doit être au moins 1',
                'installments.*.due_date.required' => 'La date d\'échéance de la tranche est requise',
                'installments.*.due_date.date' => 'La date d\'échéance de la tranche doit être valide',
                'installments.*.amount.required' => 'Le montant de la tranche est requis',
                'installments.*.amount.numeric' => 'Le montant de la tranche doit être un nombre',
                'installments.*.amount.min' => 'Le montant de la tranche doit être supérieur ou égal à 0',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que le type de frais appartient à la même école que l'étudiant
            $feeType = FeeType::findOrFail($request->fee_type_id);
            if ($feeType->school_id !== $student->class->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le type de frais n\'appartient pas à la même école que l\'étudiant',
                ], 422);
            }
            
            // Vérifier les tranches si spécifiées
            $installments = $request->input('installments', []);
            if (!empty($installments)) {
                // Vérifier que la somme des montants des tranches est égale au montant total
                $totalInstallmentsAmount = collect($installments)->sum('amount');
                
                if (abs($totalInstallmentsAmount - $request->amount) > 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $totalInstallmentsAmount . ') doit être égale au montant total (' . $request->amount . ')',
                        'total_installments_amount' => $totalInstallmentsAmount,
                        'total_amount' => $request->amount,
                    ], 422);
                }
                
                // Vérifier les numéros de tranche uniques
                $installmentNos = collect($installments)->pluck('installment_no')->toArray();
                if (count($installmentNos) !== count(array_unique($installmentNos))) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Les numéros de tranche doivent être uniques',
                    ], 422);
                }
            }
            
            // Créer le frais étudiant
            $studentFee = StudentFee::create([
                'student_id' => $studentId,
                'fee_type_id' => $request->fee_type_id,
                'amount' => $request->amount,
                'due_date' => $request->due_date,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Créer les tranches - si aucune tranche n'est spécifiée, créer une tranche unique par défaut
            if (empty($installments)) {
                // Créer une tranche unique par défaut avec le montant total et la date d'échéance du frais
                StudentFeeInstallment::create([
                    'student_fee_id' => $studentFee->id,
                    'installment_no' => 1,
                    'amount' => $request->amount,
                    'due_date' => $request->due_date, // Même date que le frais
                    'created_by' => $currentUser->id,
                    'updated_by' => $currentUser->id,
                ]);
            } else {
                // Créer les tranches spécifiées
                foreach ($installments as $installmentData) {
                    StudentFeeInstallment::create([
                        'student_fee_id' => $studentFee->id,
                        'installment_no' => $installmentData['installment_no'],
                        'amount' => $installmentData['amount'],
                        'due_date' => $installmentData['due_date'],
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            }
            
            DB::commit();
            
            // Charger les relations pour la réponse
            $studentFee->load(['feeType', 'installments', 'createdBy', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais étudiant créé avec succès',
                'data' => $this->transformStudentFee($studentFee, $student),
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating student fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'student_id' => $studentId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du frais étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher un frais étudiant spécifique
     * GET /api/v1/admin/students/{studentId}/fees/{id}
     */
    public function show(Request $request, $studentId, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with([
                'feeType',
                'installments',
                'createdBy',
                'updatedBy'
            ])->where('student_id', $studentId)->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais étudiant',
                    ], 403);
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais étudiant récupéré avec succès',
                'data' => $this->transformStudentFee($studentFee, $student),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching student fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Frais étudiant non trouvé'
            ], 404);
        }
    }

    /**
     * PUT: Mettre à jour un frais étudiant de manière complète
     * PUT /api/v1/admin/students/{studentId}/fees/{id}
     */
    public function update(Request $request, $studentId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with(['feeType', 'installments'])
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour modifier ce frais étudiant',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'fee_type_id' => 'sometimes|exists:fee_types,id',
                'amount' => 'sometimes|numeric|min:0',
                'due_date' => 'sometimes|date',
                'installments' => 'nullable|array',
                'installments.*.installment_no' => 'required_with:installments|integer|min:1',
                'installments.*.due_date' => 'required_with:installments|date',
                'installments.*.amount' => 'required_with:installments|numeric|min:0',
            ], [
                'fee_type_id.exists' => 'Le type de frais sélectionné n\'existe pas',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
                'installments.array' => 'Les tranches doivent être un tableau',
                'installments.*.installment_no.required' => 'Le numéro de tranche est requis',
                'installments.*.installment_no.integer' => 'Le numéro de tranche doit être un entier',
                'installments.*.installment_no.min' => 'Le numéro de tranche doit être au moins 1',
                'installments.*.due_date.required' => 'La date d\'échéance de la tranche est requise',
                'installments.*.due_date.date' => 'La date d\'échéance de la tranche doit être valide',
                'installments.*.amount.required' => 'Le montant de la tranche est requis',
                'installments.*.amount.numeric' => 'Le montant de la tranche doit être un nombre',
                'installments.*.amount.min' => 'Le montant de la tranche doit être supérieur ou égal à 0',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier le type de frais si modifié
            if ($request->has('fee_type_id') && $request->fee_type_id != $studentFee->fee_type_id) {
                $newFeeType = FeeType::findOrFail($request->fee_type_id);
                
                // Vérifier que le type de frais appartient à la même école que l'étudiant
                if ($newFeeType->school_id !== $student->class->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le type de frais n\'appartient pas à la même école que l\'étudiant',
                    ], 422);
                }
                
                // Mettre à jour le type de frais
                $studentFee->fee_type_id = $request->fee_type_id;
            }
            
            // Gestion du montant
            $newAmount = $request->has('amount') ? $request->amount : $studentFee->amount;
            
            // Vérifier les tranches si spécifiées
            $installments = $request->input('installments', null);
            
            if ($installments !== null) { // Si installments est présent dans la requête (même vide)
                if (empty($installments)) {
                    // Si installments est vide, créer une tranche unique par défaut
                    $installments = [
                        [
                            'installment_no' => 1,
                            'amount' => $newAmount,
                            'due_date' => $request->has('due_date') ? $request->due_date : $studentFee->due_date,
                        ]
                    ];
                }
                
                // Vérifier que la somme des montants des tranches est égale au montant total
                $totalInstallmentsAmount = collect($installments)->sum('amount');
                
                if (abs($totalInstallmentsAmount - $newAmount) > 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $totalInstallmentsAmount . ') doit être égale au montant total (' . $newAmount . ')',
                        'total_installments_amount' => $totalInstallmentsAmount,
                        'total_amount' => $newAmount,
                    ], 422);
                }
                
                // Vérifier les numéros de tranche uniques
                $installmentNos = collect($installments)->pluck('installment_no')->toArray();
                if (count($installmentNos) !== count(array_unique($installmentNos))) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Les numéros de tranche doivent être uniques',
                    ], 422);
                }
            } elseif ($request->has('amount')) {
                // Si seul le montant est modifié (sans spécifier de tranches)
                // Vérifier que le nouveau montant correspond aux tranches existantes
                $totalInstallmentsAmount = $studentFee->installments->sum('amount');
                
                if (abs($totalInstallmentsAmount - $newAmount) > 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le nouveau montant (' . $newAmount . ') ne correspond pas à la somme des tranches existantes (' . $totalInstallmentsAmount . '). Veuillez mettre à jour les tranches également.',
                        'current_installments_total' => $totalInstallmentsAmount,
                        'new_amount' => $newAmount,
                    ], 422);
                }
            }
            
            // Mettre à jour les attributs de base
            $updateData = [];
            if ($request->has('amount')) $updateData['amount'] = $newAmount;
            if ($request->has('due_date')) $updateData['due_date'] = $request->due_date;
            
            if (!empty($updateData)) {
                $updateData['updated_by'] = $currentUser->id;
                $studentFee->update($updateData);
            }
            
            // Mettre à jour les tranches si spécifiées
            if ($installments !== null) {
                // Supprimer les tranches existantes
                $studentFee->installments()->delete();
                
                // Créer les nouvelles tranches
                foreach ($installments as $installmentData) {
                    StudentFeeInstallment::create([
                        'student_fee_id' => $studentFee->id,
                        'installment_no' => $installmentData['installment_no'],
                        'amount' => $installmentData['amount'],
                        'due_date' => $installmentData['due_date'],
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            } elseif ($request->has('amount') && $studentFee->installments->isNotEmpty()) {
                // Si seul le montant est changé et qu'il y a des tranches, ajuster toutes les tranches proportionnellement
                $oldAmount = $studentFee->getOriginal('amount');
                if ($oldAmount > 0) {
                    $ratio = $newAmount / $oldAmount;
                    foreach ($studentFee->installments as $installment) {
                        $installment->update([
                            'amount' => $installment->amount * $ratio,
                            'updated_by' => $currentUser->id,
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            // Recharger les relations
            $studentFee->load(['feeType', 'installments', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais étudiant mis à jour avec succès',
                'data' => $this->transformStudentFee($studentFee, $student),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating student fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du frais étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer un frais étudiant (soft delete)
     * DELETE /api/v1/admin/students/{studentId}/fees/{id}
     */
    public function destroy(Request $request, $studentId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::where('student_id', $studentId)->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour supprimer ce frais étudiant',
                    ], 403);
                }
            }
            
            // Vérifier si le frais étudiant est utilisé dans des paiements
            // Vous devrez peut-être adapter cette vérification selon vos relations
            // if ($studentFee->payments()->exists()) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Impossible de supprimer ce frais étudiant car il est utilisé dans des paiements',
            //     ], 422);
            // }
            
            $studentFee->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais étudiant supprimé avec succès',
                'data' => [
                    'student_fee_id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting student fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du frais étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer un frais étudiant supprimé
     * POST /api/v1/admin/students/{studentId}/fees/{id}/restore
     */
    public function restore(Request $request, $studentId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::withTrashed()
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour restaurer ce frais étudiant',
                    ], 403);
                }
            }
            
            if (!$studentFee->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce frais étudiant n\'est pas supprimé',
                ], 422);
            }
            
            $studentFee->restore();
            $studentFee->update(['updated_by' => $currentUser->id]);
            
            // Restaurer également les tranches associées
            StudentFeeInstallment::withTrashed()
                ->where('student_fee_id', $id)
                ->restore();
            
            StudentFeeInstallment::where('student_fee_id', $id)
                ->update(['updated_by' => $currentUser->id]);
            
            DB::commit();
            
            $studentFee->load(['feeType']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais étudiant restauré avec succès',
                'data' => $this->transformStudentFee($studentFee, $student),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring student fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du frais étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Lister les tranches d'un frais étudiant
     * GET /api/v1/admin/students/{studentId}/fees/{id}/installments
     */
    public function listInstallments(Request $request, $studentId, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with(['feeType', 'installments'])
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais étudiant',
                    ], 403);
                }
            }
            
            $installments = $studentFee->installments()->orderBy('installment_no', 'asc')->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranches du frais étudiant récupérées avec succès',
                'data' => [
                    'student_fee' => [
                        'id' => $studentFee->id,
                        'amount' => (float) $studentFee->amount,
                        'due_date' => $studentFee->due_date ? $studentFee->due_date->toDateString() : null,
                    ],
                    'installments' => $installments->map(function ($installment) {
                        return [
                            'id' => $installment->id,
                            'installment_no' => $installment->installment_no,
                            'amount' => (float) $installment->amount,
                            'due_date' => $installment->due_date->toDateString(),
                            'created_at' => $installment->created_at->toIso8601String(),
                            'updated_at' => $installment->updated_at->toIso8601String(),
                        ];
                    }),
                    'total_amount' => $installments->sum('amount'),
                    'installments_count' => $installments->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing student fee installments: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des tranches'
            ], 500);
        }
    }

    /**
     * POST: Ajouter une tranche à un frais étudiant
     * POST /api/v1/admin/students/{studentId}/fees/{id}/installments
     */
    public function addInstallment(Request $request, $studentId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with(['feeType', 'installments'])
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais étudiant',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'installment_no' => 'required|integer|min:1',
                'amount' => 'required|numeric|min:0',
                'due_date' => 'required|date',
            ], [
                'installment_no.required' => 'Le numéro de tranche est requis',
                'installment_no.integer' => 'Le numéro de tranche doit être un entier',
                'installment_no.min' => 'Le numéro de tranche doit être au moins 1',
                'amount.required' => 'Le montant est requis',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.required' => 'La date d\'échéance est requise',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que le numéro de tranche n'existe pas déjà
            $existingInstallment = StudentFeeInstallment::where('student_fee_id', $studentFee->id)
                ->where('installment_no', $request->installment_no)
                ->first();
            
            if ($existingInstallment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Une tranche avec ce numéro existe déjà pour ce frais étudiant',
                ], 422);
            }
            
            // Calculer le nouveau total des tranches
            $currentInstallmentsTotal = $studentFee->installments->sum('amount');
            $newTotal = $currentInstallmentsTotal + $request->amount;
            
            // Vérifier que le nouveau total ne dépasse pas le montant du frais
            if ($newTotal > $studentFee->amount + 0.01) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $studentFee->amount . ')',
                    'current_total' => $currentInstallmentsTotal,
                    'new_amount' => $request->amount,
                    'fee_amount' => $studentFee->amount,
                ], 422);
            }
            
            // Créer la tranche
            $installment = StudentFeeInstallment::create([
                'student_fee_id' => $studentFee->id,
                'installment_no' => $request->installment_no,
                'amount' => $request->amount,
                'due_date' => $request->due_date,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranche ajoutée avec succès',
                'data' => [
                    'installment' => [
                        'id' => $installment->id,
                        'installment_no' => $installment->installment_no,
                        'amount' => (float) $installment->amount,
                        'due_date' => $installment->due_date->toDateString(),
                    ],
                    'student_fee' => [
                        'id' => $studentFee->id,
                        'current_installments_total' => $newTotal,
                        'remaining_amount' => $studentFee->amount - $newTotal,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding student fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout de la tranche',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * PUT: Mettre à jour une tranche d'un frais étudiant
     * PUT /api/v1/admin/students/{studentId}/fees/{id}/installments/{installmentId}
     */
    public function updateInstallment(Request $request, $studentId, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with(['feeType', 'installments'])
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            $installment = StudentFeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais étudiant
            if ($installment->student_fee_id !== $studentFee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais étudiant',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais étudiant',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'installment_no' => 'sometimes|integer|min:1',
                'amount' => 'sometimes|numeric|min:0',
                'due_date' => 'sometimes|date',
            ], [
                'installment_no.integer' => 'Le numéro de tranche doit être un entier',
                'installment_no.min' => 'Le numéro de tranche doit être au moins 1',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Si le numéro de tranche change, vérifier qu'il n'existe pas déjà
            if ($request->has('installment_no') && $request->installment_no !== $installment->installment_no) {
                $existingInstallment = StudentFeeInstallment::where('student_fee_id', $studentFee->id)
                    ->where('installment_no', $request->installment_no)
                    ->where('id', '!=', $installmentId)
                    ->first();
                
                if ($existingInstallment) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Une tranche avec ce numéro existe déjà pour ce frais étudiant',
                    ], 422);
                }
            }
            
            // Si le montant change, recalculer le total
            if ($request->has('amount')) {
                $otherInstallmentsTotal = $studentFee->installments
                    ->where('id', '!=', $installmentId)
                    ->sum('amount');
                $newTotal = $otherInstallmentsTotal + $request->amount;
                
                // Vérifier que le nouveau total ne dépasse pas le montant du frais
                if ($newTotal > $studentFee->amount + 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $studentFee->amount . ')',
                        'new_total' => $newTotal,
                        'fee_amount' => $studentFee->amount,
                    ], 422);
                }
            }
            
            // Mettre à jour la tranche
            $installment->update(array_merge(
                $request->only(['installment_no', 'amount', 'due_date']),
                ['updated_by' => $currentUser->id]
            ));
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranche mise à jour avec succès',
                'data' => [
                    'installment' => [
                        'id' => $installment->id,
                        'installment_no' => $installment->installment_no,
                        'amount' => (float) $installment->amount,
                        'due_date' => $installment->due_date->toDateString(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating student fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
                'installment_id' => $installmentId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la tranche',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer une tranche d'un frais étudiant
     * DELETE /api/v1/admin/students/{studentId}/fees/{id}/installments/{installmentId}
     */
    public function removeInstallment(Request $request, $studentId, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            $studentFee = StudentFee::with(['feeType'])
                ->where('student_id', $studentId)
                ->findOrFail($id);
            
            $installment = StudentFeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais étudiant
            if ($installment->student_fee_id !== $studentFee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais étudiant',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais étudiant',
                    ], 403);
                }
            }
            
            $installment->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranche supprimée avec succès',
                'data' => [
                    'installment_id' => $installmentId,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error removing student fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'student_fee_id' => $id,
                'installment_id' => $installmentId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de la tranche',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Statistiques sur les frais étudiants
     * GET /api/v1/admin/students/{studentId}/fees/statistics
     */
    public function statistics(Request $request, $studentId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            $query = StudentFee::where('student_id', $studentId);
            
            // Statistiques générales
            $totalFees = $query->count();
            $totalAmount = $query->sum('amount');
            $averageAmount = $totalFees > 0 ? $totalAmount / $totalFees : 0;
            
            // Frais par type
            $feesByType = StudentFee::selectRaw('fee_types.name, COUNT(student_fees.id) as count, SUM(student_fees.amount) as total_amount')
                ->join('fee_types', 'student_fees.fee_type_id', '=', 'fee_types.id')
                ->where('student_fees.student_id', $studentId)
                ->groupBy('fee_types.name')
                ->get();
            
            // Frais par mois (pour l'année en cours)
            $currentYear = now()->year;
            $feesByMonth = StudentFee::selectRaw('MONTH(due_date) as month, COUNT(id) as count, SUM(amount) as total_amount')
                ->where('student_id', $studentId)
                ->whereYear('due_date', $currentYear)
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            
            // Frais avec/sans tranches
            $feesWithInstallments = $query->has('installments')->count();
            $feesWithoutInstallments = $totalFees - $feesWithInstallments;
            
            // Nombre moyen de tranches par frais
            $avgInstallments = 0;
            if ($feesWithInstallments > 0) {
                $totalInstallments = StudentFeeInstallment::whereIn('student_fee_id', $query->pluck('id'))
                    ->count();
                $avgInstallments = $totalInstallments / $feesWithInstallments;
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Statistiques des frais étudiants récupérées avec succès',
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'code' => $student->student_code,
                        'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                    ],
                    'general' => [
                        'total_fees' => $totalFees,
                        'total_amount' => (float) $totalAmount,
                        'average_amount' => (float) $averageAmount,
                        'fees_with_installments' => $feesWithInstallments,
                        'fees_without_installments' => $feesWithoutInstallments,
                        'percentage_with_installments' => $totalFees > 0 ? ($feesWithInstallments / $totalFees) * 100 : 0,
                        'average_installments_per_fee' => round($avgInstallments, 2),
                    ],
                    'by_type' => $feesByType->map(function ($item) {
                        return [
                            'type' => $item->name,
                            'count' => $item->count,
                            'total_amount' => (float) $item->total_amount,
                            'average_amount' => $item->count > 0 ? (float) $item->total_amount / $item->count : 0,
                        ];
                    }),
                    'by_month' => $feesByMonth->map(function ($item) {
                        $monthNames = [
                            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
                        ];
                        return [
                            'month' => $monthNames[$item->month] ?? $item->month,
                            'count' => $item->count,
                            'total_amount' => (float) $item->total_amount,
                        ];
                    }),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching student fees statistics: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques des frais étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Exporter les frais étudiants
     * GET /api/v1/admin/students/{studentId}/fees/export
     */
    public function export(Request $request, $studentId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            $studentFees = StudentFee::where('student_id', $studentId)
                ->with(['feeType', 'installments'])
                ->get();
            
            $exportData = $studentFees->map(function ($studentFee) {
                $installmentsInfo = $studentFee->installments->sortBy('installment_no')->map(function ($installment) {
                    return 'Tranche ' . $installment->installment_no . ': ' . $installment->amount . ' le ' . $installment->due_date->format('d/m/Y');
                })->join('; ');
                
                return [
                    'ID' => $studentFee->id,
                    'Type de frais' => $studentFee->feeType->name ?? '',
                    'Montant total' => $studentFee->amount,
                    'Date d\'échéance globale' => $studentFee->due_date ? $studentFee->due_date->format('Y-m-d') : '',
                    'Payable par' => $studentFee->feeType->payable_by ?? '',
                    'Tranches' => $installmentsInfo,
                    'Nombre de tranches' => $studentFee->installments->count(),
                    'Date de création' => $studentFee->created_at->format('Y-m-d H:i:s'),
                    'Date de mise à jour' => $studentFee->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            $fileName = 'frais_etudiant_' . $student->code . '_' . date('Y-m-d_His') . '.xlsx';
            
            return (new FastExcel($exportData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error exporting student fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'exportation des frais étudiants'
            ], 500);
        }
    }

    /**
     * GET: Télécharger le template d'importation
     * GET /api/v1/admin/students/{studentId}/fees/import-template
     */
    public function downloadImportTemplate(Request $request, $studentId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            $templateData = collect([
                [
                    'Type de frais (ID)' => '1',
                    'Montant total' => '500000',
                    'Date d\'échéance globale (YYYY-MM-DD)' => '2024-12-31',
                    'Tranches (format: no:montant:date)' => '1:250000:2024-06-30;2:250000:2024-12-31',
                ]
            ]);
            
            $fileName = 'template_import_frais_etudiant_' . $student->code . '_' . date('Y-m-d') . '.xlsx';
            
            return (new FastExcel($templateData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error downloading student fees import template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du template'
            ], 500);
        }
    }

    /**
     * POST: Importer des frais étudiants depuis un fichier Excel
     * POST /api/v1/admin/students/{studentId}/fees/import
     */
    public function import(Request $request, $studentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ], [
                'file.required' => 'Le fichier est requis',
                'file.mimes' => 'Le fichier doit être au format Excel (xlsx, xls) ou CSV',
                'file.max' => 'Le fichier ne doit pas dépasser 10MB',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $file = $request->file('file');
            $rows = (new FastExcel)->import($file);
            
            $importedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $errors = [];
            
            foreach ($rows as $index => $row) {
                try {
                    $rowNumber = $index + 2;
                    
                    if (empty($row['Type de frais (ID)'])) {
                        $errors[] = "Ligne {$rowNumber}: L'ID du type de frais est requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    if (empty($row['Montant total'])) {
                        $errors[] = "Ligne {$rowNumber}: Le montant total est requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    if (empty($row['Date d\'échéance globale (YYYY-MM-DD)'])) {
                        $errors[] = "Ligne {$rowNumber}: La date d'échéance globale est requise";
                        $skippedCount++;
                        continue;
                    }
                    
                    $feeTypeId = $row['Type de frais (ID)'];
                    $amount = $row['Montant total'];
                    $dueDate = $row['Date d\'échéance globale (YYYY-MM-DD)'];
                    
                    // Vérifier que le type de frais existe
                    $feeType = FeeType::find($feeTypeId);
                    if (!$feeType) {
                        $errors[] = "Ligne {$rowNumber}: Le type de frais avec l'ID {$feeTypeId} n'existe pas";
                        $skippedCount++;
                        continue;
                    }
                    
                    // Vérifier que le type de frais appartient à la même école que l'étudiant
                    if ($feeType->school_id !== $student->class->school_id) {
                        $errors[] = "Ligne {$rowNumber}: Le type de frais n'appartient pas à la même école que l'étudiant";
                        $skippedCount++;
                        continue;
                    }
                    
                    // Créer ou mettre à jour le frais étudiant
                    $studentFee = StudentFee::updateOrCreate(
                        [
                            'student_id' => $studentId,
                            'fee_type_id' => $feeTypeId,
                            'due_date' => $dueDate,
                        ],
                        [
                            'amount' => $amount,
                            'created_by' => $currentUser->id,
                            'updated_by' => $currentUser->id,
                        ]
                    );
                    
                    // Gérer les tranches si spécifiées
                    if (!empty($row['Tranches (format: no:montant:date)'])) {
                        $installmentsStr = $row['Tranches (format: no:montant:date)'];
                        $installmentParts = explode(';', $installmentsStr);
                        
                        $totalInstallmentsAmount = 0;
                        $installmentNos = [];
                        
                        foreach ($installmentParts as $part) {
                            $part = trim($part);
                            if (empty($part)) continue;
                            
                            if (substr_count($part, ':') !== 2) {
                                $errors[] = "Ligne {$rowNumber}: Format de tranche invalide: {$part} (attendu: no:montant:date)";
                                continue 2; // Passe à la ligne suivante
                            }
                            
                            list($installmentNo, $installmentAmount, $installmentDueDate) = explode(':', $part, 3);
                            
                            $installmentNo = intval($installmentNo);
                            $installmentAmount = floatval($installmentAmount);
                            
                            if (in_array($installmentNo, $installmentNos)) {
                                $errors[] = "Ligne {$rowNumber}: Numéro de tranche en double: {$installmentNo}";
                                continue 2;
                            }
                            
                            $installmentNos[] = $installmentNo;
                            $totalInstallmentsAmount += $installmentAmount;
                            
                            // Créer ou mettre à jour la tranche
                            StudentFeeInstallment::updateOrCreate(
                                [
                                    'student_fee_id' => $studentFee->id,
                                    'installment_no' => $installmentNo,
                                ],
                                [
                                    'amount' => $installmentAmount,
                                    'due_date' => $installmentDueDate,
                                    'created_by' => $currentUser->id,
                                    'updated_by' => $currentUser->id,
                                ]
                            );
                        }
                        
                        // Vérifier que la somme des tranches correspond au montant total
                        if (abs($totalInstallmentsAmount - $amount) > 0.01) {
                            $errors[] = "Ligne {$rowNumber}: La somme des montants des tranches ({$totalInstallmentsAmount}) ne correspond pas au montant total ({$amount})";
                        }
                        
                        // Supprimer les tranches qui ne sont plus dans l'import
                        StudentFeeInstallment::where('student_fee_id', $studentFee->id)
                            ->whereNotIn('installment_no', $installmentNos)
                            ->delete();
                    } else {
                        // Si aucune tranche n'est spécifiée, créer une tranche unique par défaut
                        StudentFeeInstallment::updateOrCreate(
                            [
                                'student_fee_id' => $studentFee->id,
                                'installment_no' => 1,
                            ],
                            [
                                'amount' => $amount,
                                'due_date' => $dueDate,
                                'created_by' => $currentUser->id,
                                'updated_by' => $currentUser->id,
                            ]
                        );
                    }
                    
                    if ($studentFee->wasRecentlyCreated) {
                        $importedCount++;
                    } else {
                        $updatedCount++;
                    }
                    
                } catch (\Exception $e) {
                    $errors[] = "Ligne {$rowNumber}: " . $e->getMessage();
                    $skippedCount++;
                    continue;
                }
            }
            
            DB::commit();
            
            $response = [
                'status' => 'success',
                'message' => 'Importation terminée avec succès',
                'data' => [
                    'imported_count' => $importedCount,
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'total_processed' => $importedCount + $updatedCount + $skippedCount,
                ],
            ];
            
            if (!empty($errors)) {
                $response['warnings'] = [
                    'error_count' => count($errors),
                    'errors' => $errors,
                ];
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing student fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'importation des frais étudiants: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Helper method pour transformer un frais étudiant
     */
    private function transformStudentFee($studentFee, $student = null)
    {
        return [
            'id' => $studentFee->id,
            'student_id' => $studentFee->student_id,
            'amount' => (float) $studentFee->amount,
            'due_date' => $studentFee->due_date ? $studentFee->due_date->toDateString() : null,
            'fee_type' => $studentFee->feeType ? [
                'id' => $studentFee->feeType->id,
                'name' => $studentFee->feeType->name,
                'description' => $studentFee->feeType->description,
                'payable_by' => $studentFee->feeType->payable_by,
            ] : null,
            'installments' => $studentFee->installments->sortBy('installment_no')->map(function ($installment) {
                return [
                    'id' => $installment->id,
                    'installment_no' => $installment->installment_no,
                    'amount' => (float) $installment->amount,
                    'due_date' => $installment->due_date->toDateString(),
                    'created_at' => $installment->created_at->toIso8601String(),
                    'updated_at' => $installment->updated_at->toIso8601String(),
                ];
            })->values(),
            'student' => $student ? [
                'id' => $student->id,
                'code' => $student->student_code,
                'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                'class' => $student->class ? [
                    'id' => $student->class->id,
                    'name' => $student->class->name,
                ] : null,

            ] : null,
            'created_by' => $studentFee->createdBy ? [
                'id' => $studentFee->createdBy->id,
                'full_name' => $studentFee->createdBy->full_name,
            ] : null,
            'updated_by' => $studentFee->updatedBy ? [
                'id' => $studentFee->updatedBy->id,
                'full_name' => $studentFee->updatedBy->full_name,
            ] : null,
            'created_at' => $studentFee->created_at->toIso8601String(),
            'updated_at' => $studentFee->updated_at->toIso8601String(),
            'deleted_at' => $studentFee->deleted_at ? $studentFee->deleted_at->toIso8601String() : null,
        ];
    }


    /**
     * GET: Lister tous les étudiants avec des frais spécifiques
     * GET /api/v1/admin/students-with-specific-fees
    */
    public function listStudentsWithSpecificFees(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Construire la requête de base
            $query = Student::with([
                'class.school',
                'studentFees.feeType',
                'studentFees.installments'
            ])
            ->whereHas('studentFees') // Seulement les étudiants avec des frais spécifiques
            ->withCount(['studentFees']) // Compter le nombre de frais spécifiques
            ->withSum(['studentFees'], 'amount'); // Somme des montants des frais spécifiques
            
            // Si c'est un school_admin, on filtre par son école
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if ($schoolId) {
                    $query->whereHas('class', function ($q) use ($schoolId) {
                        $q->where('school_id', $schoolId);
                    });
                } else {
                    // Si un school_admin n'a pas de school_id, on ne retourne rien
                    $query->whereRaw('1 = 0');
                }
            }
            
            // Pour super_admin, on peut filtrer par école si demandé
            if ($currentUser->isSuperAdmin() && $request->has('school_id')) {
                $query->whereHas('class', function ($q) use ($request) {
                    $q->where('school_id', $request->school_id);
                });
            }
            
            // Filtres supplémentaires
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }
            
            if ($request->has('fee_type_id')) {
                $query->whereHas('studentFees', function ($q) use ($request) {
                    $q->where('fee_type_id', $request->fee_type_id);
                });
            }
            
            if ($request->has('has_installments')) {
                if ($request->has_installments == 'yes') {
                    $query->whereHas('studentFees.installments');
                } elseif ($request->has_installments == 'no') {
                    $query->whereHas('studentFees', function ($q) {
                        $q->doesntHave('installments');
                    });
                }
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
                });
            }
            
            // Trier par défaut par nom
            $query->orderBy('last_name')->orderBy('first_name');
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $students = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedStudents = $students->getCollection()->map(function ($student) {
                return [
                    'id' => $student->id,
                    'code' => $student->student_code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                    'gender' => $student->gender,
                    'date_of_birth' => $student->birth_date ? $student->birth_date->toDateString() : null,
                    'class' => $student->class ? [
                        'id' => $student->class->id,
                        'name' => $student->class->name,
                        'school' => $student->class->school ? [
                            'id' => $student->class->school->id,
                            'name' => $student->class->school->name,
                        ] : null,
                    ] : null,
                    'specific_fees_summary' => [
                        'count' => $student->student_fees_count,
                        'total_amount' => (float) $student->student_fees_sum_amount,
                        'average_amount' => $student->student_fees_count > 0 ? 
                            (float) $student->student_fees_sum_amount / $student->student_fees_count : 0,
                    ],
                    'specific_fees' => $student->studentFees->map(function ($studentFee) {
                        return [
                            'id' => $studentFee->id,
                            'amount' => (float) $studentFee->amount,
                            'due_date' => $studentFee->due_date ? $studentFee->due_date->toDateString() : null,
                            'fee_type' => $studentFee->feeType ? [
                                'id' => $studentFee->feeType->id,
                                'name' => $studentFee->feeType->name,
                                'payable_by' => $studentFee->feeType->payable_by,
                            ] : null,
                            'installments_count' => $studentFee->installments->count(),
                            'created_at' => $studentFee->created_at->toIso8601String(),
                        ];
                    }),
                ];
            });
            
            // Statistiques globales
            $totalStudents = $students->total();
            $totalSpecificFees = $students->sum('student_fees_count');
            $totalSpecificFeesAmount = $students->sum('student_fees_sum_amount');
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des étudiants avec frais spécifiques récupérée avec succès',
                'data' => [
                    'students' => $transformedStudents,
                    'statistics' => [
                        'total_students' => $totalStudents,
                        'total_specific_fees' => $totalSpecificFees,
                        'total_specific_fees_amount' => (float) $totalSpecificFeesAmount,
                        'average_fees_per_student' => $totalStudents > 0 ? $totalSpecificFees / $totalStudents : 0,
                        'average_amount_per_student' => $totalStudents > 0 ? $totalSpecificFeesAmount / $totalStudents : 0,
                    ],
                    'pagination' => [
                        'total' => $students->total(),
                        'per_page' => $students->perPage(),
                        'current_page' => $students->currentPage(),
                        'last_page' => $students->lastPage(),
                        'from' => $students->firstItem(),
                        'to' => $students->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing students with specific fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des étudiants avec frais spécifiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Lister les étudiants avec des frais spécifiques pour une école spécifique
     * GET /api/v1/admin/students-with-specific-fees/schools/{schoolId}
    */
    public function listStudentsWithSpecificFeesBySchool(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'école existe
            $school = School::findOrFail($schoolId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $userSchoolId = $currentUser->getSchoolId();
                if ($school->id !== $userSchoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette école',
                    ], 403);
                }
            }
            
            // Construire la requête
            $query = Student::with([
                'class.school',
                'studentFees.feeType',
                'studentFees.installments'
            ])
            ->whereHas('class', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->whereHas('studentFees') // Seulement les étudiants avec des frais spécifiques
            ->withCount(['studentFees']) // Compter le nombre de frais spécifiques
            ->withSum(['studentFees'], 'amount'); // Somme des montants des frais spécifiques
            
            // Filtres
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }
            
            if ($request->has('fee_type_id')) {
                $query->whereHas('studentFees', function ($q) use ($request) {
                    $q->where('fee_type_id', $request->fee_type_id);
                });
            }
            
            if ($request->has('has_installments')) {
                if ($request->has_installments == 'yes') {
                    $query->whereHas('studentFees.installments');
                } elseif ($request->has_installments == 'no') {
                    $query->whereHas('studentFees', function ($q) {
                        $q->doesntHave('installments');
                    });
                }
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
                });
            }
            
            // Trier par défaut par nom
            $query->orderBy('last_name')->orderBy('first_name');
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $students = $query->paginate($perPage);
            
            // Statistiques par type de frais pour cette école
            $feesByType = StudentFee::selectRaw('fee_types.name, COUNT(student_fees.id) as student_count, SUM(student_fees.amount) as total_amount')
                ->join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->join('fee_types', 'student_fees.fee_type_id', '=', 'fee_types.id')
                ->where('classes.school_id', $schoolId)
                ->groupBy('fee_types.name')
                ->get();
            
            // Transformer les données pour la réponse
            $transformedStudents = $students->getCollection()->map(function ($student) {
                return [
                    'id' => $student->id,
                    'code' => $student->student_code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                    'class' => $student->class ? [
                        'id' => $student->class->id,
                        'name' => $student->class->name,
                    ] : null,
                    'specific_fees_summary' => [
                        'count' => $student->student_fees_count,
                        'total_amount' => (float) $student->student_fees_sum_amount,
                    ],
                    'specific_fees' => $student->studentFees->take(3)->map(function ($studentFee) {
                        return [
                            'id' => $studentFee->id,
                            'amount' => (float) $studentFee->amount,
                            'due_date' => $studentFee->due_date ? $studentFee->due_date->toDateString() : null,
                            'fee_type' => $studentFee->feeType ? [
                                'id' => $studentFee->feeType->id,
                                'name' => $studentFee->feeType->name,
                            ] : null,
                        ];
                    }),
                ];
            });
            
            // Statistiques globales pour l'école
            $totalStudents = $students->total();
            $totalSpecificFees = $students->sum('student_fees_count');
            $totalSpecificFeesAmount = $students->sum('student_fees_sum_amount');
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des étudiants avec frais spécifiques pour l\'école récupérée avec succès',
                'data' => [
                    'school' => [
                        'id' => $school->id,
                        'name' => $school->name,
                    ],
                    'students' => $transformedStudents,
                    'statistics' => [
                        'total_students' => $totalStudents,
                        'total_specific_fees' => $totalSpecificFees,
                        'total_specific_fees_amount' => (float) $totalSpecificFeesAmount,
                        'average_fees_per_student' => $totalStudents > 0 ? $totalSpecificFees / $totalStudents : 0,
                        'average_amount_per_student' => $totalStudents > 0 ? $totalSpecificFeesAmount / $totalStudents : 0,
                    ],
                    'fees_by_type' => $feesByType->map(function ($item) {
                        return [
                            'type' => $item->name,
                            'student_count' => $item->student_count,
                            'total_amount' => (float) $item->total_amount,
                            'average_amount' => $item->student_count > 0 ? (float) $item->total_amount / $item->student_count : 0,
                        ];
                    }),
                    'pagination' => [
                        'total' => $students->total(),
                        'per_page' => $students->perPage(),
                        'current_page' => $students->currentPage(),
                        'last_page' => $students->lastPage(),
                        'from' => $students->firstItem(),
                        'to' => $students->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing students with specific fees by school: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'school_id' => $schoolId,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des étudiants avec frais spécifiques pour l\'école',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Statistiques des frais spécifiques pour une école
     * GET /api/v1/admin/schools/{schoolId}/specific-fees-statistics
    */
    public function specificFeesStatistics(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'école existe
            $school = School::findOrFail($schoolId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $userSchoolId = $currentUser->getSchoolId();
                if ($school->id !== $userSchoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette école',
                    ], 403);
                }
            }
            
            // Nombre total d'étudiants dans l'école
            $totalStudents = Student::whereHas('class', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })->count();
            
            // Nombre d'étudiants avec des frais spécifiques
            $studentsWithSpecificFees = Student::whereHas('class', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->whereHas('studentFees')
            ->count();
            
            // Statistiques globales des frais spécifiques
            $totalSpecificFees = StudentFee::join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->count();
            
            $totalSpecificFeesAmount = StudentFee::join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->sum('student_fees.amount');
            
            // Frais spécifiques par class
            $feesByClass = StudentFee::selectRaw('classes.name, COUNT(student_fees.id) as fee_count, SUM(student_fees.amount) as total_amount')
                ->join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->groupBy('classes.id', 'classes.name')
                ->get();
            
            // Frais spécifiques par type
            $feesByType = StudentFee::selectRaw('fee_types.name, COUNT(student_fees.id) as fee_count, SUM(student_fees.amount) as total_amount')
                ->join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->join('fee_types', 'student_fees.fee_type_id', '=', 'fee_types.id')
                ->where('classes.school_id', $schoolId)
                ->groupBy('fee_types.id', 'fee_types.name')
                ->get();
            
            // Frais spécifiques avec/sans tranches
            $feesWithInstallments = StudentFee::join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->has('installments')
                ->count();
            
            $feesWithoutInstallments = $totalSpecificFees - $feesWithInstallments;
            
            // Évolution mensuelle (pour l'année en cours)
            $currentYear = now()->year;
            $monthlyEvolution = StudentFee::selectRaw('MONTH(student_fees.due_date) as month, COUNT(student_fees.id) as fee_count, SUM(student_fees.amount) as total_amount')
                ->join('students', 'student_fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->whereYear('student_fees.due_date', $currentYear)
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Statistiques des frais spécifiques récupérées avec succès',
                'data' => [
                    'school' => [
                        'id' => $school->id,
                        'name' => $school->name,
                        'total_students' => $totalStudents,
                    ],
                    'general_statistics' => [
                        'students_with_specific_fees' => $studentsWithSpecificFees,
                        'students_without_specific_fees' => $totalStudents - $studentsWithSpecificFees,
                        'percentage_with_specific_fees' => $totalStudents > 0 ? ($studentsWithSpecificFees / $totalStudents) * 100 : 0,
                        'total_specific_fees' => $totalSpecificFees,
                        'total_specific_fees_amount' => (float) $totalSpecificFeesAmount,
                        'average_fees_per_student' => $studentsWithSpecificFees > 0 ? $totalSpecificFees / $studentsWithSpecificFees : 0,
                        'average_amount_per_student' => $studentsWithSpecificFees > 0 ? $totalSpecificFeesAmount / $studentsWithSpecificFees : 0,
                        'fees_with_installments' => $feesWithInstallments,
                        'fees_without_installments' => $feesWithoutInstallments,
                        'percentage_with_installments' => $totalSpecificFees > 0 ? ($feesWithInstallments / $totalSpecificFees) * 100 : 0,
                    ],
                    'by_class' => $feesByClass->map(function ($item) {
                        return [
                            'class' => $item->name,
                            'fee_count' => $item->fee_count,
                            'total_amount' => (float) $item->total_amount,
                            'average_amount' => $item->fee_count > 0 ? (float) $item->total_amount / $item->fee_count : 0,
                        ];
                    }),
                    'by_type' => $feesByType->map(function ($item) {
                        return [
                            'type' => $item->name,
                            'fee_count' => $item->fee_count,
                            'total_amount' => (float) $item->total_amount,
                            'average_amount' => $item->fee_count > 0 ? (float) $item->total_amount / $item->fee_count : 0,
                        ];
                    }),
                    'monthly_evolution' => $monthlyEvolution->map(function ($item) {
                        $monthNames = [
                            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
                        ];
                        return [
                            'month' => $monthNames[$item->month] ?? $item->month,
                            'fee_count' => $item->fee_count,
                            'total_amount' => (float) $item->total_amount,
                        ];
                    }),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching specific fees statistics: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'school_id' => $schoolId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques des frais spécifiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

}