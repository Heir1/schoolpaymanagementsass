<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\GroupFee;
use App\Modules\Billing\Models\GroupFeeInstallment;
use App\Modules\Billing\Models\FeeType;
use App\Modules\Schools\Models\StudentGroup;
use App\Modules\Academic\Models\Student;
use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;
use Carbon\Carbon;

class GroupFeeController extends Controller
{
    /**
     * GET: Liste tous les frais d'un groupe d'étudiants
     * GET /api/v1/admin/student-groups/{groupId}/fees
     */
    public function index(Request $request, $groupId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
                    ], 403);
                }
            }
            
            // Construire la requête
            $query = GroupFee::where('group_id', $groupId)
                ->with([
                    'feeType',
                    'installments',
                    'createdBy',
                    'updatedBy'
                ]);
            
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
            $groupFees = $query->paginate($perPage);
            
            // Nombre d'étudiants dans le groupe
            $studentsCount = Student::where('student_group_id', $groupId)->count();
            
            // Transformer les données pour la réponse
            $transformedFees = $groupFees->getCollection()->map(function ($groupFee) {
                return [
                    'id' => $groupFee->id,
                    'group_id' => $groupFee->group_id,
                    'amount' => (float) $groupFee->amount,
                    'due_date' => $groupFee->due_date ? $groupFee->due_date->toDateString() : null,
                    'fee_type' => $groupFee->feeType ? [
                        'id' => $groupFee->feeType->id,
                        'name' => $groupFee->feeType->name,
                        'payable_by' => $groupFee->feeType->payable_by,
                    ] : null,
                    'installments' => $groupFee->installments->sortBy('installment_no')->map(function ($installment) {
                        return [
                            'id' => $installment->id,
                            'installment_no' => $installment->installment_no,
                            'amount' => (float) $installment->amount,
                            'due_date' => $installment->due_date->toDateString(),
                        ];
                    })->values(),
                    'created_by' => $groupFee->createdBy ? $groupFee->createdBy->full_name : null,
                    'updated_by' => $groupFee->updatedBy ? $groupFee->updatedBy->full_name : null,
                    'created_at' => $groupFee->created_at->toIso8601String(),
                    'updated_at' => $groupFee->updated_at->toIso8601String(),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des frais du groupe d\'étudiants récupérée avec succès',
                'data' => [
                    'student_group' => [
                        'id' => $studentGroup->id,
                        'name' => $studentGroup->name,
                        'description' => $studentGroup->description,
                        'school' => $studentGroup->school ? [
                            'id' => $studentGroup->school->id,
                            'name' => $studentGroup->school->name,
                        ] : null,
                        'students_count' => $studentsCount,
                    ],
                    'fees' => $transformedFees,
                    'pagination' => [
                        'total' => $groupFees->total(),
                        'per_page' => $groupFees->perPage(),
                        'current_page' => $groupFees->currentPage(),
                        'last_page' => $groupFees->lastPage(),
                        'from' => $groupFees->firstItem(),
                        'to' => $groupFees->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching group fees list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des frais du groupe d\'étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer un nouveau frais pour un groupe d'étudiants
     * POST /api/v1/admin/student-groups/{groupId}/fees
     */
    public function store(Request $request, $groupId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
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
            
            // Vérifier que le type de frais appartient à la même école que le groupe
            $feeType = FeeType::findOrFail($request->fee_type_id);
            if ($feeType->school_id !== $studentGroup->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le type de frais n\'appartient pas à la même école que le groupe d\'étudiants',
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
            
            // Créer le frais de groupe
            $groupFee = GroupFee::create([
                'group_id' => $groupId,
                'fee_type_id' => $request->fee_type_id,
                'amount' => $request->amount,
                'due_date' => $request->due_date,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Créer les tranches - si aucune tranche n'est spécifiée, créer une tranche unique par défaut
            if (empty($installments)) {
                // Créer une tranche unique par défaut avec le montant total et la date d'échéance du frais
                GroupFeeInstallment::create([
                    'group_fee_id' => $groupFee->id,
                    'installment_no' => 1,
                    'amount' => $request->amount,
                    'due_date' => $request->due_date, // Même date que le frais
                    'created_by' => $currentUser->id,
                    'updated_by' => $currentUser->id,
                ]);
            } else {
                // Créer les tranches spécifiées
                foreach ($installments as $installmentData) {
                    GroupFeeInstallment::create([
                        'group_fee_id' => $groupFee->id,
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
            $groupFee->load(['feeType', 'installments', 'createdBy', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe créé avec succès',
                'data' => $this->transformGroupFee($groupFee, $studentGroup),
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating group fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'group_id' => $groupId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du frais de groupe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher un frais de groupe spécifique
     * GET /api/v1/admin/student-groups/{groupId}/fees/{id}
     */
    public function show(Request $request, $groupId, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with([
                'feeType',
                'installments',
                'createdBy',
                'updatedBy'
            ])->where('group_id', $groupId)->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais de groupe',
                    ], 403);
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe récupéré avec succès',
                'data' => $this->transformGroupFee($groupFee, $studentGroup),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching group fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Frais de groupe non trouvé'
            ], 404);
        }
    }

    /**
     * PUT: Mettre à jour un frais de groupe de manière complète
     * PUT /api/v1/admin/student-groups/{groupId}/fees/{id}
     */
    public function update(Request $request, $groupId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with(['feeType', 'installments'])
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour modifier ce frais de groupe',
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
            if ($request->has('fee_type_id') && $request->fee_type_id != $groupFee->fee_type_id) {
                $newFeeType = FeeType::findOrFail($request->fee_type_id);
                
                // Vérifier que le type de frais appartient à la même école que le groupe
                if ($newFeeType->school_id !== $studentGroup->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le type de frais n\'appartient pas à la même école que le groupe d\'étudiants',
                    ], 422);
                }
                
                // Mettre à jour le type de frais
                $groupFee->fee_type_id = $request->fee_type_id;
            }
            
            // Gestion du montant
            $newAmount = $request->has('amount') ? $request->amount : $groupFee->amount;
            
            // Vérifier les tranches si spécifiées
            $installments = $request->input('installments', null);
            
            if ($installments !== null) { // Si installments est présent dans la requête (même vide)
                if (empty($installments)) {
                    // Si installments est vide, créer une tranche unique par défaut
                    $installments = [
                        [
                            'installment_no' => 1,
                            'amount' => $newAmount,
                            'due_date' => $request->has('due_date') ? $request->due_date : $groupFee->due_date,
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
                $totalInstallmentsAmount = $groupFee->installments->sum('amount');
                
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
                $groupFee->update($updateData);
            }
            
            // Mettre à jour les tranches si spécifiées
            if ($installments !== null) {
                // Supprimer les tranches existantes
                $groupFee->installments()->delete();
                
                // Créer les nouvelles tranches
                foreach ($installments as $installmentData) {
                    GroupFeeInstallment::create([
                        'group_fee_id' => $groupFee->id,
                        'installment_no' => $installmentData['installment_no'],
                        'amount' => $installmentData['amount'],
                        'due_date' => $installmentData['due_date'],
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            } elseif ($request->has('amount') && $groupFee->installments->isNotEmpty()) {
                // Si seul le montant est changé et qu'il y a des tranches, ajuster toutes les tranches proportionnellement
                $oldAmount = $groupFee->getOriginal('amount');
                if ($oldAmount > 0) {
                    $ratio = $newAmount / $oldAmount;
                    foreach ($groupFee->installments as $installment) {
                        $installment->update([
                            'amount' => $installment->amount * $ratio,
                            'updated_by' => $currentUser->id,
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            // Recharger les relations
            $groupFee->load(['feeType', 'installments', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe mis à jour avec succès',
                'data' => $this->transformGroupFee($groupFee, $studentGroup),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating group fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du frais de groupe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer un frais de groupe (soft delete)
     * DELETE /api/v1/admin/student-groups/{groupId}/fees/{id}
     */
    public function destroy(Request $request, $groupId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::where('group_id', $groupId)->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour supprimer ce frais de groupe',
                    ], 403);
                }
            }
            
            // Vérifier si le frais de groupe est utilisé dans des paiements
            // Vous devrez peut-être adapter cette vérification selon vos relations
            // if ($groupFee->payments()->exists()) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Impossible de supprimer ce frais de groupe car il est utilisé dans des paiements',
            //     ], 422);
            // }
            
            $groupFee->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe supprimé avec succès',
                'data' => [
                    'group_fee_id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting group fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du frais de groupe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer un frais de groupe supprimé
     * POST /api/v1/admin/student-groups/{groupId}/fees/{id}/restore
     */
    public function restore(Request $request, $groupId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::withTrashed()
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour restaurer ce frais de groupe',
                    ], 403);
                }
            }
            
            if (!$groupFee->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce frais de groupe n\'est pas supprimé',
                ], 422);
            }
            
            $groupFee->restore();
            $groupFee->update(['updated_by' => $currentUser->id]);
            
            // Restaurer également les tranches associées
            GroupFeeInstallment::withTrashed()
                ->where('group_fee_id', $id)
                ->restore();
            
            GroupFeeInstallment::where('group_fee_id', $id)
                ->update(['updated_by' => $currentUser->id]);
            
            DB::commit();
            
            $groupFee->load(['feeType']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe restauré avec succès',
                'data' => $this->transformGroupFee($groupFee, $studentGroup),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring group fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du frais de groupe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Lister les tranches d'un frais de groupe
     * GET /api/v1/admin/student-groups/{groupId}/fees/{id}/installments
     */
    public function listInstallments(Request $request, $groupId, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with(['feeType', 'installments'])
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais de groupe',
                    ], 403);
                }
            }
            
            $installments = $groupFee->installments()->orderBy('installment_no', 'asc')->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranches du frais de groupe récupérées avec succès',
                'data' => [
                    'group_fee' => [
                        'id' => $groupFee->id,
                        'amount' => (float) $groupFee->amount,
                        'due_date' => $groupFee->due_date ? $groupFee->due_date->toDateString() : null,
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
            Log::error('Error listing group fee installments: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des tranches'
            ], 500);
        }
    }

    /**
     * POST: Ajouter une tranche à un frais de groupe
     * POST /api/v1/admin/student-groups/{groupId}/fees/{id}/installments
     */
    public function addInstallment(Request $request, $groupId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with(['feeType', 'installments'])
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais de groupe',
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
            $existingInstallment = GroupFeeInstallment::where('group_fee_id', $groupFee->id)
                ->where('installment_no', $request->installment_no)
                ->first();
            
            if ($existingInstallment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Une tranche avec ce numéro existe déjà pour ce frais de groupe',
                ], 422);
            }
            
            // Calculer le nouveau total des tranches
            $currentInstallmentsTotal = $groupFee->installments->sum('amount');
            $newTotal = $currentInstallmentsTotal + $request->amount;
            
            // Vérifier que le nouveau total ne dépasse pas le montant du frais
            if ($newTotal > $groupFee->amount + 0.01) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $groupFee->amount . ')',
                    'current_total' => $currentInstallmentsTotal,
                    'new_amount' => $request->amount,
                    'fee_amount' => $groupFee->amount,
                ], 422);
            }
            
            // Créer la tranche
            $installment = GroupFeeInstallment::create([
                'group_fee_id' => $groupFee->id,
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
                    'group_fee' => [
                        'id' => $groupFee->id,
                        'current_installments_total' => $newTotal,
                        'remaining_amount' => $groupFee->amount - $newTotal,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding group fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
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
     * PUT: Mettre à jour une tranche d'un frais de groupe
     * PUT /api/v1/admin/student-groups/{groupId}/fees/{id}/installments/{installmentId}
     */
    public function updateInstallment(Request $request, $groupId, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with(['feeType', 'installments'])
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            $installment = GroupFeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais de groupe
            if ($installment->group_fee_id !== $groupFee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais de groupe',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais de groupe',
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
                $existingInstallment = GroupFeeInstallment::where('group_fee_id', $groupFee->id)
                    ->where('installment_no', $request->installment_no)
                    ->where('id', '!=', $installmentId)
                    ->first();
                
                if ($existingInstallment) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Une tranche avec ce numéro existe déjà pour ce frais de groupe',
                    ], 422);
                }
            }
            
            // Si le montant change, recalculer le total
            if ($request->has('amount')) {
                $otherInstallmentsTotal = $groupFee->installments
                    ->where('id', '!=', $installmentId)
                    ->sum('amount');
                $newTotal = $otherInstallmentsTotal + $request->amount;
                
                // Vérifier que le nouveau total ne dépasse pas le montant du frais
                if ($newTotal > $groupFee->amount + 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $groupFee->amount . ')',
                        'new_total' => $newTotal,
                        'fee_amount' => $groupFee->amount,
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
            Log::error('Error updating group fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
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
     * DELETE: Supprimer une tranche d'un frais de groupe
     * DELETE /api/v1/admin/student-groups/{groupId}/fees/{id}/installments/{installmentId}
     */
    public function removeInstallment(Request $request, $groupId, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            $groupFee = GroupFee::with(['feeType'])
                ->where('group_id', $groupId)
                ->findOrFail($id);
            
            $installment = GroupFeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais de groupe
            if ($installment->group_fee_id !== $groupFee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais de groupe',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais de groupe',
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
            Log::error('Error removing group fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'group_fee_id' => $id,
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
     * GET: Statistiques sur les frais de groupe
     * GET /api/v1/admin/student-groups/{groupId}/fees/statistics
     */
    public function statistics(Request $request, $groupId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
                    ], 403);
                }
            }
            
            // Nombre d'étudiants dans le groupe
            $studentsCount = Student::where('student_group_id', $groupId)->count();
            
            $query = GroupFee::where('group_id', $groupId);
            
            // Statistiques générales
            $totalFees = $query->count();
            $totalAmount = $query->sum('amount');
            $averageAmount = $totalFees > 0 ? $totalAmount / $totalFees : 0;
            
            // Frais par type
            $feesByType = GroupFee::selectRaw('fee_types.name, COUNT(group_fees.id) as count, SUM(group_fees.amount) as total_amount')
                ->join('fee_types', 'group_fees.fee_type_id', '=', 'fee_types.id')
                ->where('group_fees.group_id', $groupId)
                ->groupBy('fee_types.name')
                ->get();
            
            // Frais par mois (pour l'année en cours)
            $currentYear = now()->year;
            $feesByMonth = GroupFee::selectRaw('MONTH(due_date) as month, COUNT(id) as count, SUM(amount) as total_amount')
                ->where('group_id', $groupId)
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
                $totalInstallments = GroupFeeInstallment::whereIn('group_fee_id', $query->pluck('id'))
                    ->count();
                $avgInstallments = $totalInstallments / $feesWithInstallments;
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Statistiques des frais de groupe récupérées avec succès',
                'data' => [
                    'student_group' => [
                        'id' => $studentGroup->id,
                        'name' => $studentGroup->name,
                        'students_count' => $studentsCount,
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
            Log::error('Error fetching group fees statistics: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques des frais de groupe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Exporter les frais de groupe
     * GET /api/v1/admin/student-groups/{groupId}/fees/export
     */
    public function export(Request $request, $groupId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
                    ], 403);
                }
            }
            
            $groupFees = GroupFee::where('group_id', $groupId)
                ->with(['feeType', 'installments'])
                ->get();
            
            $exportData = $groupFees->map(function ($groupFee) {
                $installmentsInfo = $groupFee->installments->sortBy('installment_no')->map(function ($installment) {
                    return 'Tranche ' . $installment->installment_no . ': ' . $installment->amount . ' le ' . $installment->due_date->format('d/m/Y');
                })->join('; ');
                
                return [
                    'ID' => $groupFee->id,
                    'Type de frais' => $groupFee->feeType->name ?? '',
                    'Montant total' => $groupFee->amount,
                    'Date d\'échéance globale' => $groupFee->due_date ? $groupFee->due_date->format('Y-m-d') : '',
                    'Payable par' => $groupFee->feeType->payable_by ?? '',
                    'Tranches' => $installmentsInfo,
                    'Nombre de tranches' => $groupFee->installments->count(),
                    'Date de création' => $groupFee->created_at->format('Y-m-d H:i:s'),
                    'Date de mise à jour' => $groupFee->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            $fileName = 'frais_groupe_' . str_replace(' ', '_', $studentGroup->name) . '_' . date('Y-m-d_His') . '.xlsx';
            
            return (new FastExcel($exportData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error exporting group fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'exportation des frais de groupe'
            ], 500);
        }
    }

    /**
     * GET: Télécharger le template d'importation
     * GET /api/v1/admin/student-groups/{groupId}/fees/import-template
     */
    public function downloadImportTemplate(Request $request, $groupId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
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
            
            $fileName = 'template_import_frais_groupe_' . str_replace(' ', '_', $studentGroup->name) . '_' . date('Y-m-d') . '.xlsx';
            
            return (new FastExcel($templateData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error downloading group fees import template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du template'
            ], 500);
        }
    }

    /**
     * POST: Importer des frais de groupe depuis un fichier Excel
     * POST /api/v1/admin/student-groups/{groupId}/fees/import
     */
    public function import(Request $request, $groupId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que le groupe existe
            $studentGroup = StudentGroup::with(['school'])->findOrFail($groupId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($studentGroup->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce groupe d\'étudiants',
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
                    
                    // Vérifier que le type de frais appartient à la même école que le groupe
                    if ($feeType->school_id !== $studentGroup->school_id) {
                        $errors[] = "Ligne {$rowNumber}: Le type de frais n'appartient pas à la même école que le groupe d'étudiants";
                        $skippedCount++;
                        continue;
                    }
                    
                    // Créer ou mettre à jour le frais de groupe
                    $groupFee = GroupFee::updateOrCreate(
                        [
                            'group_id' => $groupId,
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
                            GroupFeeInstallment::updateOrCreate(
                                [
                                    'group_fee_id' => $groupFee->id,
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
                        GroupFeeInstallment::where('group_fee_id', $groupFee->id)
                            ->whereNotIn('installment_no', $installmentNos)
                            ->delete();
                    } else {
                        // Si aucune tranche n'est spécifiée, créer une tranche unique par défaut
                        GroupFeeInstallment::updateOrCreate(
                            [
                                'group_fee_id' => $groupFee->id,
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
                    
                    if ($groupFee->wasRecentlyCreated) {
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
            Log::error('Error importing group fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'group_id' => $groupId,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'importation des frais de groupe: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Lister tous les frais de groupe par école (pour super_admin)
     * GET /api/v1/admin/schools/{schoolId}/group-fees
     */
    public function getBySchool(Request $request, $schoolId)
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
            
            $query = GroupFee::whereHas('group', function ($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                })
                ->with(['group', 'feeType', 'installments'])
                ->orderBy('due_date', 'asc');
            
            // Filtres
            if ($request->has('group_id')) {
                $query->where('group_id', $request->group_id);
            }
            
            if ($request->has('fee_type_id')) {
                $query->where('fee_type_id', $request->fee_type_id);
            }
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $groupFees = $query->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de groupe de l\'école récupérés avec succès',
                'data' => [
                    'school' => $school,
                    'group_fees' => $groupFees->map(function ($groupFee) {
                        return $this->transformGroupFee($groupFee, $groupFee->group);
                    }),
                    'count' => $groupFees->count(),
                    'pagination' => [
                        'total' => $groupFees->total(),
                        'per_page' => $groupFees->perPage(),
                        'current_page' => $groupFees->currentPage(),
                        'last_page' => $groupFees->lastPage(),
                        'from' => $groupFees->firstItem(),
                        'to' => $groupFees->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching school group fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'school_id' => $schoolId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des frais de groupe de l\'école'
            ], 500);
        }
    }

    /**
     * Helper method pour transformer un frais de groupe
     */
    private function transformGroupFee($groupFee, $studentGroup = null)
    {
        return [
            'id' => $groupFee->id,
            'group_id' => $groupFee->group_id,
            'amount' => (float) $groupFee->amount,
            'due_date' => $groupFee->due_date ? $groupFee->due_date->toDateString() : null,
            'fee_type' => $groupFee->feeType ? [
                'id' => $groupFee->feeType->id,
                'name' => $groupFee->feeType->name,
                'description' => $groupFee->feeType->description,
                'payable_by' => $groupFee->feeType->payable_by,
            ] : null,
            'installments' => $groupFee->installments->sortBy('installment_no')->map(function ($installment) {
                return [
                    'id' => $installment->id,
                    'installment_no' => $installment->installment_no,
                    'amount' => (float) $installment->amount,
                    'due_date' => $installment->due_date->toDateString(),
                    'created_at' => $installment->created_at->toIso8601String(),
                    'updated_at' => $installment->updated_at->toIso8601String(),
                ];
            })->values(),
            'student_group' => $studentGroup ? [
                'id' => $studentGroup->id,
                'name' => $studentGroup->name,
                'description' => $studentGroup->description,
                'school' => $studentGroup->school ? [
                    'id' => $studentGroup->school->id,
                    'name' => $studentGroup->school->name,
                ] : null,
            ] : null,
            'created_by' => $groupFee->createdBy ? [
                'id' => $groupFee->createdBy->id,
                'full_name' => $groupFee->createdBy->full_name,
            ] : null,
            'updated_by' => $groupFee->updatedBy ? [
                'id' => $groupFee->updatedBy->id,
                'full_name' => $groupFee->updatedBy->full_name,
            ] : null,
            'created_at' => $groupFee->created_at->toIso8601String(),
            'updated_at' => $groupFee->updated_at->toIso8601String(),
            'deleted_at' => $groupFee->deleted_at ? $groupFee->deleted_at->toIso8601String() : null,
        ];
    }
}