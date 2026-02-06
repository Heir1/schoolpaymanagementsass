<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Fee;
use App\Modules\Billing\Models\FeeType;
use App\Modules\Billing\Models\FeeInstallment;
use App\Modules\Shared\Models\Pivots\ClassFee;
use App\Modules\Schools\Models\School;
use App\Modules\Academic\Models\ClassModel;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;
use Carbon\Carbon;

class FeeController extends Controller
{
    /**
     * GET: Liste tous les frais avec pagination et filtres
     * GET /api/v1/admin/fees
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Construire la requête avec withTrashed pour inclure les supprimés
            $query = Fee::withTrashed()->with([
                'feeType.school',
                'installments',
                'classFees.class',
                'createdBy',
                'updatedBy'
            ]);
            
            // Si c'est un school_admin, on filtre par son école via les fee_types
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if ($schoolId) {
                    $query->whereHas('feeType', function ($q) use ($schoolId) {
                        $q->where('school_id', $schoolId);
                    });
                } else {
                    // Si un school_admin n'a pas de school_id, on ne retourne rien
                    $query->whereRaw('1 = 0');
                }
            } elseif (!$currentUser->isSuperAdmin()) {
                // Si l'utilisateur n'est ni school_admin ni super_admin, accès refusé
                return response()->json([
                    'status' => 'error',
                    'message' => 'Accès non autorisé. Rôle requis: school_admin ou super_admin'
                ], 403);
            }
            
            // Pour super_admin, on peut filtrer par école si demandé
            if ($currentUser->isSuperAdmin() && $request->has('school_id')) {
                $query->whereHas('feeType', function ($q) use ($request) {
                    $q->where('school_id', $request->school_id);
                });
            }
            
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
            
            if ($request->has('class_id')) {
                $query->whereHas('classFees', function ($q) use ($request) {
                    $q->where('class_id', $request->class_id);
                });
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
            $fees = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedFees = $fees->getCollection()->map(function ($fee) {
                return [
                    'id' => $fee->id,
                    'amount' => (float) $fee->amount,
                    'due_date' => $fee->due_date ? $fee->due_date->toDateString() : null,
                    'fee_type' => $fee->feeType ? [
                        'id' => $fee->feeType->id,
                        'name' => $fee->feeType->name,
                        'payable_by' => $fee->feeType->payable_by,
                        'school' => $fee->feeType->school ? [
                            'id' => $fee->feeType->school->id,
                            'name' => $fee->feeType->school->name,
                        ] : null,
                    ] : null,
                    'installments' => $fee->installments->sortBy('installment_no')->map(function ($installment) {
                        return [
                            'id' => $installment->id,
                            'installment_no' => $installment->installment_no,
                            'amount' => (float) $installment->amount,
                            'due_date' => $installment->due_date ? $installment->due_date->toDateString() : null,
                        ];
                    })->values(),
                    'associated_classes_count' => $fee->classFees->count(),
                    'created_by' => $fee->createdBy ? [
                        'id' => $fee->createdBy->id,
                        'name' => $fee->createdBy->full_name,
                    ] : null,
                    'updated_by' => $fee->updatedBy ? [
                        'id' => $fee->updatedBy->id,
                        'name' => $fee->updatedBy->full_name,
                    ] : null,
                    'created_at' => $fee->created_at ? $fee->created_at->toIso8601String() : null,
                    'updated_at' => $fee->updated_at ? $fee->updated_at->toIso8601String() : null,
                    'deleted_at' => $fee->deleted_at ? $fee->deleted_at->toIso8601String() : null,
                    'is_deleted' => !is_null($fee->deleted_at),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des frais récupérée avec succès',
                'data' => [
                    'fees' => $transformedFees,
                    'pagination' => [
                        'total' => $fees->total(),
                        'per_page' => $fees->perPage(),
                        'current_page' => $fees->currentPage(),
                        'last_page' => $fees->lastPage(),
                        'from' => $fees->firstItem(),
                        'to' => $fees->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching fees list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer un nouveau frais avec tranches/échéances et assignation aux classes
     * POST /api/v1/admin/fees
    */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation - ENLEVER la validation de installment_no
            $validator = Validator::make($request->all(), [
                'fee_type_id' => 'required|exists:fee_types,id',
                'amount' => 'required|numeric|min:0',
                'due_date' => 'required|date',
                'installments' => 'nullable|array',
                'installments.*.due_date' => 'required_with:installments|date',
                'installments.*.amount' => 'required_with:installments|numeric|min:0',
                'class_ids' => 'nullable|array',
                'class_ids.*' => 'exists:classes,id',
            ], [
                'fee_type_id.required' => 'Le type de frais est requis',
                'fee_type_id.exists' => 'Le type de frais sélectionné n\'existe pas',
                'amount.required' => 'Le montant est requis',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.required' => 'La date d\'échéance est requise',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
                'installments.array' => 'Les tranches doivent être un tableau',
                'installments.*.due_date.required' => 'La date d\'échéance de la tranche est requise',
                'installments.*.due_date.date' => 'La date d\'échéance de la tranche doit être valide',
                'installments.*.amount.required' => 'Le montant de la tranche est requis',
                'installments.*.amount.numeric' => 'Le montant de la tranche doit être un nombre',
                'installments.*.amount.min' => 'Le montant de la tranche doit être supérieur ou égal à 0',
                'class_ids.array' => 'Les classes doivent être un tableau',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier les permissions sur le type de frais
            $feeType = FeeType::findOrFail($request->fee_type_id);
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour créer un frais pour ce type de frais',
                    ], 403);
                }
            }
            
            // Vérifier les classes si spécifiées
            $classIds = $request->input('class_ids', []);
            if (!empty($classIds)) {
                foreach ($classIds as $classId) {
                    $classe = ClassModel::find($classId);
                    if (!$classe) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Classe non trouvée: ' . $classId,
                        ], 404);
                    }
                    
                    // Vérifier que la classe appartient à la même école que le type de frais
                    if ($classe->school_id !== $feeType->school_id) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'La classe ' . $classe->name . ' n\'appartient pas à la même école que le type de frais',
                        ], 422);
                    }
                }
            }
            
            // Vérifier les tranches si spécifiées
            $installments = $request->input('installments', []);
            if (!empty($installments)) {
                // Vérifier que la somme des montants des tranches est égale au montant total
                $totalInstallmentsAmount = collect($installments)->sum('amount');
                
                if (abs($totalInstallmentsAmount - $request->amount) > 0.01) { // Tolérance de 0.01
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $totalInstallmentsAmount . ') doit être égale au montant total (' . $request->amount . ')',
                        'total_installments_amount' => $totalInstallmentsAmount,
                        'total_amount' => $request->amount,
                    ], 422);
                }
                
                // Vérifier que les dates des tranches sont cohérentes
                foreach ($installments as $index => $installment) {
                    // Optionnel : vérifier que la date de la tranche n'est pas après la date du frais
                    if (Carbon::parse($installment['due_date'])->gt(Carbon::parse($request->due_date))) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'La date de la tranche ' . ($index + 1) . ' ne peut pas être après la date d\'échéance du frais',
                        ], 422);
                    }
                }
            }
            
            // Créer le frais
            $fee = Fee::create([
                'fee_type_id' => $request->fee_type_id,
                'amount' => $request->amount,
                'due_date' => $request->due_date,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Créer les tranches - si aucune tranche n'est spécifiée, créer une tranche unique par défaut
            if (empty($installments)) {
                // Créer une tranche unique par défaut avec le montant total et la date d'échéance du frais
                FeeInstallment::create([
                    'fee_id' => $fee->id,
                    'installment_no' => 1,
                    'amount' => $request->amount,
                    'due_date' => $request->due_date, // Même date que le frais
                    'created_by' => $currentUser->id,
                    'updated_by' => $currentUser->id,
                ]);
            } else {
                // Créer les tranches spécifiées avec auto-incrémentation
                $installmentNo = 1;
                foreach ($installments as $installmentData) {
                    FeeInstallment::create([
                        'fee_id' => $fee->id,
                        'installment_no' => $installmentNo++,
                        'amount' => $installmentData['amount'],
                        'due_date' => $installmentData['due_date'],
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            }
            
            // Associer aux classes si spécifiées
            if (!empty($classIds)) {
                foreach ($classIds as $classId) {
                    // Vérifier d'abord si une entrée existe déjà (même soft deleted)
                    $existingClassFee = ClassFee::withTrashed()
                        ->where('fee_id', $fee->id)
                        ->where('class_id', $classId)
                        ->first();
                    
                    if ($existingClassFee) {
                        // Si elle existe en soft deleted, la restaurer
                        if ($existingClassFee->trashed()) {
                            $existingClassFee->restore();
                            $existingClassFee->update([
                                'updated_by' => $currentUser->id,
                                'updated_at' => now(),
                            ]);
                        }
                        // Sinon, elle existe déjà, on ne fait rien (ne devrait pas arriver en création)
                    } else {
                        // Créer une nouvelle association
                        ClassFee::create([
                            'class_id' => $classId,
                            'fee_id' => $fee->id,
                            'created_by' => $currentUser->id,
                            'updated_by' => $currentUser->id,
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            // Charger les relations pour la réponse
            $fee->load(['feeType.school', 'installments', 'classFees.class', 'createdBy', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais créé avec succès',
                'data' => $this->transformFee($fee),
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher un frais spécifique
     * GET /api/v1/admin/fees/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $fee = Fee::with([
                'feeType.school',
                'installments',
                'classFees.class',
                'createdBy',
                'updatedBy'
            ])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
                    ], 403);
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais récupéré avec succès',
                'data' => $this->transformFee($fee),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Frais non trouvé'
            ], 404);
        }
    }


    /**
     * PUT: Mettre à jour un frais de manière complète
     * PUT /api/v1/admin/fees/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {

            $currentUser = $request->user();

            $fee = Fee::with(['feeType', 'installments', 'classFees'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour modifier ce frais',
                    ], 403);
                }
            }
            
            // Validation - ENLEVER la validation de installment_no
            $validator = Validator::make($request->all(), [
                'fee_type_id' => 'sometimes|exists:fee_types,id',
                'amount' => 'sometimes|numeric|min:0',
                'due_date' => 'sometimes|date',
                'installments' => 'nullable|array',
                'installments.*.due_date' => 'required_with:installments|date',
                'installments.*.amount' => 'required_with:installments|numeric|min:0',
                'class_ids' => 'nullable|array',
                'class_ids.*' => 'exists:classes,id',
            ], [
                'fee_type_id.exists' => 'Le type de frais sélectionné n\'existe pas',
                'amount.numeric' => 'Le montant doit être un nombre',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0',
                'due_date.date' => 'La date d\'échéance doit être une date valide',
                'installments.array' => 'Les tranches doivent être un tableau',
                'installments.*.due_date.required' => 'La date d\'échéance de la tranche est requise',
                'installments.*.due_date.date' => 'La date d\'échéance de la tranche doit être valide',
                'installments.*.amount.required' => 'Le montant de la tranche est requis',
                'installments.*.amount.numeric' => 'Le montant de la tranche doit être un nombre',
                'installments.*.amount.min' => 'Le montant de la tranche doit être supérieur ou égal à 0',
                'class_ids.array' => 'Les classes doivent être un tableau',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier le type de frais si modifié
            if ($request->has('fee_type_id') && $request->fee_type_id != $fee->fee_type_id) {
                $newFeeType = FeeType::findOrFail($request->fee_type_id);
                
                // Vérifier les permissions sur le nouveau type de frais
                if ($currentUser->isSchoolAdmin()) {
                    $schoolId = $currentUser->getSchoolId();
                    if ($newFeeType->school_id !== $schoolId) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Vous n\'avez pas les permissions pour utiliser ce type de frais',
                        ], 403);
                    }
                }
                
                // Mettre à jour le type de frais
                $fee->fee_type_id = $request->fee_type_id;
            }
            
            // Gestion du montant
            $newAmount = $request->has('amount') ? $request->amount : $fee->amount;
            
            // Vérifier les tranches si spécifiées
            $installments = $request->input('installments', null);
            
            if ($installments !== null) { // Si installments est présent dans la requête (même vide)
                if (empty($installments)) {
                    // Si installments est vide, créer une tranche unique par défaut
                    $installments = [
                        [
                            'amount' => $newAmount,
                            'due_date' => $request->has('due_date') ? $request->due_date : $fee->due_date,
                        ]
                    ];
                }
                
                // Vérifier que la somme des montants des tranches est égale au montant total
                $totalInstallmentsAmount = collect($installments)->sum('amount');
                
                if (abs($totalInstallmentsAmount - $newAmount) > 0.01) { // Tolérance de 0.01
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $totalInstallmentsAmount . ') doit être égale au montant total (' . $newAmount . ')',
                        'total_installments_amount' => $totalInstallmentsAmount,
                        'total_amount' => $newAmount,
                    ], 422);
                }
            } elseif ($request->has('amount')) {
                // Si seul le montant est modifié (sans spécifier de tranches)
                // Vérifier que le nouveau montant correspond aux tranches existantes
                $totalInstallmentsAmount = $fee->installments->sum('amount');
                
                if (abs($totalInstallmentsAmount - $newAmount) > 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le nouveau montant (' . $newAmount . ') ne correspond pas à la somme des tranches existantes (' . $totalInstallmentsAmount . '). Veuillez mettre à jour les tranches également.',
                        'current_installments_total' => $totalInstallmentsAmount,
                        'new_amount' => $newAmount,
                    ], 422);
                }
            }
            
            // Vérifier les classes si spécifiées
            if ($request->has('class_ids')) {
                $classIds = $request->input('class_ids', []);
                
                if (!empty($classIds)) {
                    $schoolId = $fee->feeType->school_id;
                    foreach ($classIds as $classId) {
                        $classe = ClassModel::find($classId);
                        if (!$classe) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Classe non trouvée: ' . $classId,
                            ], 404);
                        }
                        
                        // Vérifier que la classe appartient à la même école que le frais
                        if ($classe->school_id !== $schoolId) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'La classe ' . $classe->name . ' n\'appartient pas à la même école que le frais',
                            ], 422);
                        }
                    }
                }
            }
            
            // Mettre à jour les attributs de base
            $updateData = [];
            if ($request->has('amount')) $updateData['amount'] = $newAmount;
            if ($request->has('due_date')) $updateData['due_date'] = $request->due_date;
            
            if (!empty($updateData)) {
                $updateData['updated_by'] = $currentUser->id;
                $fee->update($updateData);
            }
            
            // Mettre à jour les tranches si spécifiées
            if ($installments !== null) {
                // Supprimer les tranches existantes
                $fee->installments()->delete();
                
                // Créer les nouvelles tranches avec auto-incrémentation
                $installmentNo = 1;
                foreach ($installments as $installmentData) {
                    FeeInstallment::create([
                        'fee_id' => $fee->id,
                        'installment_no' => $installmentNo++,
                        'amount' => $installmentData['amount'],
                        'due_date' => $installmentData['due_date'],
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            } elseif ($request->has('amount') && $fee->installments->isNotEmpty()) {
                // Si seul le montant est changé et qu'il y a des tranches, ajuster toutes les tranches proportionnellement
                $oldAmount = $fee->getOriginal('amount');
                if ($oldAmount > 0) {
                    $ratio = $newAmount / $oldAmount;
                    foreach ($fee->installments as $installment) {
                        $installment->update([
                            'amount' => $installment->amount * $ratio,
                            'updated_by' => $currentUser->id,
                        ]);
                    }
                }
            }
            
            // Mettre à jour les classes si spécifiées
            if ($request->has('class_ids')) {
                $classIds = $request->input('class_ids', []);
                
                // Récupérer les associations existantes (y compris les soft deleted)
                $existingClassIds = ClassFee::withTrashed()
                    ->where('fee_id', $id)
                    ->pluck('class_id')
                    ->toArray();
                
                // Trouver les nouvelles classes à ajouter
                $newClassIds = array_diff($classIds, $existingClassIds);
                
                // Trouver les classes à restaurer (celles qui sont soft deleted)
                $deletedClassIds = ClassFee::onlyTrashed()
                    ->where('fee_id', $id)
                    ->whereIn('class_id', $classIds)
                    ->pluck('class_id')
                    ->toArray();
                
                // Trouver les classes à supprimer (celles qui ne sont plus dans la liste)
                $classIdsToRemove = array_diff($existingClassIds, $classIds);
                
                // RESTAURER les classes qui étaient dissociées (soft deleted)
                if (!empty($deletedClassIds)) {
                    ClassFee::onlyTrashed()
                        ->where('fee_id', $id)
                        ->whereIn('class_id', $deletedClassIds)
                        ->restore();
                    
                    // Mettre à jour les champs updated_by et updated_at
                    ClassFee::where('fee_id', $id)
                        ->whereIn('class_id', $deletedClassIds)
                        ->update([
                            'updated_by' => $currentUser->id,
                            'updated_at' => now(),
                        ]);
                }
                
                // AJOUTER les nouvelles classes
                foreach ($newClassIds as $classId) {
                    // Vérifier d'abord si une entrée existe déjà (même soft deleted)
                    $existingClassFee = ClassFee::withTrashed()
                        ->where('fee_id', $id)
                        ->where('class_id', $classId)
                        ->first();
                    
                    if ($existingClassFee) {
                        // Si elle existe en soft deleted, la restaurer
                        if ($existingClassFee->trashed()) {
                            $existingClassFee->restore();
                            $existingClassFee->update([
                                'updated_by' => $currentUser->id,
                                'updated_at' => now(),
                            ]);
                        }
                        // Sinon, elle existe déjà, on ne fait rien
                    } else {
                        // Créer une nouvelle association
                        ClassFee::create([
                            'class_id' => $classId,
                            'fee_id' => $id,
                            'created_by' => $currentUser->id,
                            'updated_by' => $currentUser->id,
                        ]);
                    }
                }
                
                // SUPPRIMER (soft delete) les classes qui ne sont plus dans la liste
                if (!empty($classIdsToRemove)) {
                    ClassFee::where('fee_id', $id)
                        ->whereIn('class_id', $classIdsToRemove)
                        ->delete();
                    
                    // Mettre à jour les champs updated_by et updated_at
                    ClassFee::withTrashed()
                        ->where('fee_id', $id)
                        ->whereIn('class_id', $classIdsToRemove)
                        ->update([
                            'updated_by' => $currentUser->id,
                            'updated_at' => now(),
                        ]);
                }
            }
            
            DB::commit();
            
            // Recharger les relations
            $fee->load(['feeType.school', 'installments', 'classFees.class', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais mis à jour avec succès',
                'data' => $this->transformFee($fee),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer un frais (soft delete)
     * DELETE /api/v1/admin/fees/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour supprimer ce frais',
                    ], 403);
                }
            }
            
            // Vérifier si le frais est utilisé dans des paiements
            // Vous devrez peut-être adapter cette vérification selon vos relations
            // if ($fee->payments()->exists()) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Impossible de supprimer ce frais car il est utilisé dans des paiements',
            //     ], 422);
            // }
            
            $fee->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais supprimé avec succès',
                'data' => [
                    'fee_id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer un frais supprimé
     * POST /api/v1/admin/fees/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::withTrashed()->with(['feeType'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour restaurer ce frais',
                    ], 403);
                }
            }
            
            if (!$fee->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce frais n\'est pas supprimé',
                ], 422);
            }
            
            $fee->restore();
            $fee->update(['updated_by' => $currentUser->id]);
            
            DB::commit();
            
            $fee->load(['feeType.school']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais restauré avec succès',
                'data' => $this->transformFee($fee),
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring fee: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Récupérer les frais par type de frais
     * GET /api/v1/admin/fee-types/{feeTypeId}/fees
     */
    public function getByFeeType(Request $request, $feeTypeId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que le type de frais existe
            $feeType = FeeType::with(['school'])->findOrFail($feeTypeId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce type de frais',
                    ], 403);
                }
            }
            
            $fees = Fee::where('fee_type_id', $feeTypeId)
                ->with(['installments', 'classFees.class', 'createdBy', 'updatedBy'])
                ->orderBy('due_date', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais du type de frais récupérés avec succès',
                'data' => [
                    'fee_type' => $feeType,
                    'fees' => $fees->map(function ($fee) {
                        return $this->transformFee($fee);
                    }),
                    'count' => $fees->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching fee type fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_type_id' => $feeTypeId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des frais du type de frais'
            ], 500);
        }
    }

    /**
     * GET: Récupérer les frais par école
     * GET /api/v1/admin/schools/{schoolId}/fees
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
            
            $fees = Fee::whereHas('feeType', function ($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                })
                ->with(['feeType', 'installments', 'classFees.class', 'createdBy', 'updatedBy'])
                ->orderBy('due_date', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de l\'école récupérés avec succès',
                'data' => [
                    'school' => $school,
                    'fees' => $fees->map(function ($fee) {
                        return $this->transformFee($fee);
                    }),
                    'count' => $fees->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching school fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'school_id' => $schoolId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des frais de l\'école'
            ], 500);
        }
    }

    /**
     * GET: Récupérer les frais par classe
     * GET /api/v1/admin/classes/{classId}/fees
     */
    public function getByClass(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que la classe existe
            $classe = ClassModel::with(['school'])->findOrFail($classId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($classe->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette classe',
                    ], 403);
                }
            }
            
            $fees = Fee::whereHas('classFees', function ($q) use ($classId) {
                    $q->where('class_id', $classId);
                })
                ->with(['feeType', 'installments', 'createdBy', 'updatedBy'])
                ->orderBy('due_date', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais de la classe récupérés avec succès',
                'data' => [
                    'class' => $classe,
                    'fees' => $fees->map(function ($fee) {
                        return $this->transformFee($fee);
                    }),
                    'count' => $fees->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching class fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'class_id' => $classId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des frais de la classe'
            ], 500);
        }
    }

    /**
     * POST: Assigner des frais à une classe
     * POST /api/v1/admin/classes/{classId}/fees/assign
     */
    public function assignFeesToClass(Request $request, $classId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que la classe existe
            $classe = ClassModel::with(['school'])->findOrFail($classId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($classe->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette classe',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'fee_ids' => 'required|array',
                'fee_ids.*' => 'exists:fees,id',
            ], [
                'fee_ids.required' => 'La liste des frais est requise',
                'fee_ids.array' => 'Les frais doivent être un tableau',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que les frais appartiennent à la même école que la classe
            $feeIds = $request->fee_ids;
            $invalidFees = [];
            
            foreach ($feeIds as $feeId) {
                $fee = Fee::with(['feeType'])->find($feeId);
                if ($fee && $fee->feeType->school_id !== $classe->school_id) {
                    $invalidFees[] = $feeId;
                }
            }
            
            if (!empty($invalidFees)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Certains frais n\'appartiennent pas à la même école que la classe',
                    'invalid_fee_ids' => $invalidFees,
                ], 422);
            }
            
            // Associer les frais à la classe (éviter les doublons)
            $existingFeeIds = ClassFee::where('class_id', $classId)
                ->whereIn('fee_id', $feeIds)
                ->pluck('fee_id')
                ->toArray();
            
            $newFeeIds = array_diff($feeIds, $existingFeeIds);
            
            foreach ($newFeeIds as $feeId) {
                ClassFee::create([
                    'class_id' => $classId,
                    'fee_id' => $feeId,
                    'created_by' => $currentUser->id,
                    'updated_by' => $currentUser->id,
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais assignés à la classe avec succès',
                'data' => [
                    'class_id' => $classId,
                    'assigned_count' => count($newFeeIds),
                    'already_assigned_count' => count($existingFeeIds),
                    'total_assigned' => count($feeIds),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error assigning fees to class: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'class_id' => $classId,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'assignation des frais à la classe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Lister les tranches/échéances d'un frais
     * GET /api/v1/admin/fees/{id}/installments
     */
    public function listInstallments(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType', 'installments'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
                    ], 403);
                }
            }
            
            $installments = $fee->installments()->orderBy('installment_no', 'asc')->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tranches du frais récupérées avec succès',
                'data' => [
                    'fee' => [
                        'id' => $fee->id,
                        'amount' => (float) $fee->amount,
                        'due_date' => $fee->due_date ? $fee->due_date->toDateString() : null,
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
            Log::error('Error listing fee installments: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des tranches'
            ], 500);
        }
    }

    /**
     * POST: Ajouter une tranche/échéance à un frais
     * POST /api/v1/admin/fees/{id}/installments
     */
    public function addInstallment(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType', 'installments'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
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
            $existingInstallment = FeeInstallment::where('fee_id', $fee->id)
                ->where('installment_no', $request->installment_no)
                ->first();
            
            if ($existingInstallment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Une tranche avec ce numéro existe déjà pour ce frais',
                ], 422);
            }
            
            // Calculer le nouveau total des tranches
            $currentInstallmentsTotal = $fee->installments->sum('amount');
            $newTotal = $currentInstallmentsTotal + $request->amount;
            
            // Vérifier que le nouveau total ne dépasse pas le montant du frais
            if ($newTotal > $fee->amount + 0.01) { // Tolérance de 0.01
                return response()->json([
                    'status' => 'error',
                    'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $fee->amount . ')',
                    'current_total' => $currentInstallmentsTotal,
                    'new_amount' => $request->amount,
                    'fee_amount' => $fee->amount,
                ], 422);
            }
            
            // Créer la tranche
            $installment = FeeInstallment::create([
                'fee_id' => $fee->id,
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
                    'fee' => [
                        'id' => $fee->id,
                        'current_installments_total' => $newTotal,
                        'remaining_amount' => $fee->amount - $newTotal,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
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
     * PUT: Mettre à jour une tranche/échéance
     * PUT /api/v1/admin/fees/{id}/installments/{installmentId}
     */
    public function updateInstallment(Request $request, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType', 'installments'])->findOrFail($id);
            $installment = FeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais
            if ($installment->fee_id !== $fee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
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
                $existingInstallment = FeeInstallment::where('fee_id', $fee->id)
                    ->where('installment_no', $request->installment_no)
                    ->where('id', '!=', $installmentId)
                    ->first();
                
                if ($existingInstallment) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Une tranche avec ce numéro existe déjà pour ce frais',
                    ], 422);
                }
            }
            
            // Si le montant change, recalculer le total
            if ($request->has('amount')) {
                $otherInstallmentsTotal = $fee->installments
                    ->where('id', '!=', $installmentId)
                    ->sum('amount');
                $newTotal = $otherInstallmentsTotal + $request->amount;
                
                // Vérifier que le nouveau total ne dépasse pas le montant du frais
                if ($newTotal > $fee->amount + 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'La somme des montants des tranches (' . $newTotal . ') ne peut pas dépasser le montant total du frais (' . $fee->amount . ')',
                        'new_total' => $newTotal,
                        'fee_amount' => $fee->amount,
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
            Log::error('Error updating fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
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
     * DELETE: Supprimer une tranche/échéance
     * DELETE /api/v1/admin/fees/{id}/installments/{installmentId}
     */
    public function removeInstallment(Request $request, $id, $installmentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType'])->findOrFail($id);
            $installment = FeeInstallment::findOrFail($installmentId);
            
            // Vérifier que la tranche appartient au frais
            if ($installment->fee_id !== $fee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette tranche n\'appartient pas à ce frais',
                ], 422);
            }
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
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
            Log::error('Error removing fee installment: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
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
     * GET: Lister les classes associées à un frais
     * GET /api/v1/admin/fees/{id}/classes
    */
    public function listAssociatedClasses(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType', 'classFees.class.school'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
                    ], 403);
                }
            }
            
            $classes = $fee->classFees->map(function ($classFee) {
                return $classFee->class;
            })->filter();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Classes associées au frais récupérées avec succès',
                'data' => [
                    'fee' => [
                        'id' => $fee->id,
                        'name' => $fee->feeType->name,
                        'amount' => (float) $fee->amount,
                    ],
                    'classes' => $classes->map(function ($classe) {
                        return [
                            'id' => $classe->id,
                            'name' => $classe->name,
                            'school' => $classe->school ? [
                                'id' => $classe->school->id,
                                'name' => $classe->school->name,
                            ] : null,
                        ];
                    }),
                    'count' => $classes->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing associated classes: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des classes associées'
            ], 500);
        }
    }

    /**
     * POST: Associer un frais à des classes
     * POST /api/v1/admin/fees/{id}/classes/associate
     */
    public function associateToClasses(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'class_ids' => 'required|array',
                'class_ids.*' => 'exists:classes,id',
            ], [
                'class_ids.required' => 'La liste des classes est requise',
                'class_ids.array' => 'Les classes doivent être un tableau',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que les classes appartiennent à la même école que le frais
            $classIds = $request->class_ids;
            $invalidClasses = [];
            
            foreach ($classIds as $classId) {
                $classe = ClassModel::find($classId);
                if ($classe && $classe->school_id !== $fee->feeType->school_id) {
                    $invalidClasses[] = $classId;
                }
            }
            
            if (!empty($invalidClasses)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Certaines classes n\'appartiennent pas à la même école que le frais',
                    'invalid_class_ids' => $invalidClasses,
                ], 422);
            }
            
            // Associer le frais aux classes (restaurer si soft deleted, ignorer si déjà actif)
            $successfullyAssociated = 0;
            $alreadyAssociated = 0;
            $restored = 0;
            
            foreach ($classIds as $classId) {
                // Vérifier si l'association existe déjà (y compris soft deleted)
                $existingClassFee = ClassFee::withTrashed()
                    ->where('class_id', $classId)
                    ->where('fee_id', $id)
                    ->first();
                
                if ($existingClassFee) {
                    if ($existingClassFee->trashed()) {
                        // Restaurer l'association soft deleted
                        $existingClassFee->restore();
                        $existingClassFee->update([
                            'updated_by' => $currentUser->id,
                            'updated_at' => now(),
                        ]);
                        $restored++;
                        $successfullyAssociated++;
                    } else {
                        // L'association existe déjà
                        $alreadyAssociated++;
                    }
                } else {
                    // Créer une nouvelle association
                    ClassFee::create([
                        'class_id' => $classId,
                        'fee_id' => $id,
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                    $successfullyAssociated++;
                }
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais associé aux classes avec succès',
                'data' => [
                    'fee_id' => $id,
                    'newly_associated_count' => $successfullyAssociated - $restored,
                    'restored_count' => $restored,
                    'already_associated_count' => $alreadyAssociated,
                    'total_processed' => count($classIds),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error associating fee to classes: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'association du frais aux classes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Dissocier un frais de classes
     * DELETE /api/v1/admin/fees/{id}/classes/dissociate
     */
    public function dissociateFromClasses(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $fee = Fee::with(['feeType'])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($fee->feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à ce frais',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'class_ids' => 'required|array',
                'class_ids.*' => 'exists:classes,id',
            ], [
                'class_ids.required' => 'La liste des classes est requise',
                'class_ids.array' => 'Les classes doivent être un tableau',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Dissocier le frais des classes
            $deletedCount = ClassFee::where('fee_id', $id)
                ->whereIn('class_id', $request->class_ids)
                ->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais dissocié des classes avec succès',
                'data' => [
                    'fee_id' => $id,
                    'dissociated_count' => $deletedCount,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error dissociating fee from classes: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la dissociation du frais des classes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Statistiques sur les frais
     * GET /api/v1/admin/fees/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = Fee::query();
            
            // Si c'est un school_admin, on filtre par son école
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($schoolId) {
                    $query->whereHas('feeType', function ($q) use ($schoolId) {
                        $q->where('school_id', $schoolId);
                    });
                }
            }
            
            // Pour super_admin, on peut filtrer par école si demandé
            if ($currentUser->isSuperAdmin() && $request->has('school_id')) {
                $query->whereHas('feeType', function ($q) use ($request) {
                    $q->where('school_id', $request->school_id);
                });
            }
            
            // Statistiques générales
            $totalFees = $query->count();
            $totalAmount = $query->sum('amount');
            $averageAmount = $totalFees > 0 ? $totalAmount / $totalFees : 0;
            
            // Frais par type
            $feesByType = Fee::selectRaw('fee_types.name, COUNT(fees.id) as count, SUM(fees.amount) as total_amount')
                ->join('fee_types', 'fees.fee_type_id', '=', 'fee_types.id')
                ->when($currentUser->isSchoolAdmin() && $schoolId, function ($q) use ($schoolId) {
                    $q->where('fee_types.school_id', $schoolId);
                })
                ->when($currentUser->isSuperAdmin() && $request->has('school_id'), function ($q) use ($request) {
                    $q->where('fee_types.school_id', $request->school_id);
                })
                ->groupBy('fee_types.name')
                ->get();
            
            // Frais par mois (pour l'année en cours)
            $currentYear = now()->year;
            $feesByMonth = Fee::selectRaw('MONTH(due_date) as month, COUNT(id) as count, SUM(amount) as total_amount')
                ->whereYear('due_date', $currentYear)
                ->when($currentUser->isSchoolAdmin() && $schoolId, function ($q) use ($schoolId) {
                    $q->whereHas('feeType', function ($q2) use ($schoolId) {
                        $q2->where('school_id', $schoolId);
                    });
                })
                ->when($currentUser->isSuperAdmin() && $request->has('school_id'), function ($q) use ($request) {
                    $q->whereHas('feeType', function ($q2) use ($request) {
                        $q2->where('school_id', $request->school_id);
                    });
                })
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            
            // Frais avec/sans tranches
            $feesWithInstallments = $query->has('installments')->count();
            $feesWithoutInstallments = $totalFees - $feesWithInstallments;
            
            // Nombre moyen de tranches par frais
            $avgInstallments = 0;
            if ($feesWithInstallments > 0) {
                $totalInstallments = FeeInstallment::whereIn('fee_id', $query->pluck('id'))
                    ->count();
                $avgInstallments = $totalInstallments / $feesWithInstallments;
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Statistiques des frais récupérées avec succès',
                'data' => [
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
            Log::error('Error fetching fees statistics: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques des frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Exporter les frais avec FastExcel
     * GET /api/v1/admin/fees/export
     */
    public function export(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = Fee::with(['feeType.school', 'installments']);
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($schoolId) {
                    $query->whereHas('feeType', function ($q) use ($schoolId) {
                        $q->where('school_id', $schoolId);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
            
            $fees = $query->get();
            
            $exportData = $fees->map(function ($fee) {
                $installmentsInfo = $fee->installments->sortBy('installment_no')->map(function ($installment) {
                    return 'Tranche ' . $installment->installment_no . ': ' . $installment->amount . ' le ' . $installment->due_date->format('d/m/Y');
                })->join('; ');
                
                return [
                    'ID' => $fee->id,
                    'Type de frais' => $fee->feeType->name ?? '',
                    'École' => $fee->feeType->school->name ?? '',
                    'Montant total' => $fee->amount,
                    'Date d\'échéance globale' => $fee->due_date ? $fee->due_date->format('Y-m-d') : '',
                    'Payable par' => $fee->feeType->payable_by ?? '',
                    'Tranches' => $installmentsInfo,
                    'Nombre de tranches' => $fee->installments->count(),
                    'Date de création' => $fee->created_at->format('Y-m-d H:i:s'),
                    'Date de mise à jour' => $fee->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            $fileName = 'frais_' . date('Y-m-d_His') . '.xlsx';
            
            return (new FastExcel($exportData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error exporting fees with FastExcel: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'exportation des frais'
            ], 500);
        }
    }

    /**
     * GET: Télécharger le template d'importation
     * GET /api/v1/admin/fees/import-template
     */
    public function downloadImportTemplate(Request $request)
    {
        try {
            $templateData = collect([
                [
                    'Type de frais (ID)' => '1',
                    'Montant total' => '500000',
                    'Date d\'échéance globale (YYYY-MM-DD)' => '2024-12-31',
                    'Tranches (format: no:montant:date)' => '1:250000:2024-06-30;2:250000:2024-12-31',
                ]
            ]);
            
            $fileName = 'template_import_frais_' . date('Y-m-d') . '.xlsx';
            
            return (new FastExcel($templateData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error downloading import template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du template'
            ], 500);
        }
    }

    /**
     * POST: Importer des frais depuis un fichier Excel
     * POST /api/v1/admin/fees/import
     */
    public function import(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
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
                    
                    // Vérifier les permissions
                    if ($currentUser->isSchoolAdmin()) {
                        $schoolId = $currentUser->getSchoolId();
                        if ($feeType->school_id !== $schoolId) {
                            $errors[] = "Ligne {$rowNumber}: Vous n'avez pas accès à ce type de frais";
                            $skippedCount++;
                            continue;
                        }
                    }
                    
                    // Créer ou mettre à jour le frais
                    $fee = Fee::updateOrCreate(
                        [
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
                            FeeInstallment::updateOrCreate(
                                [
                                    'fee_id' => $fee->id,
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
                        FeeInstallment::where('fee_id', $fee->id)
                            ->whereNotIn('installment_no', $installmentNos)
                            ->delete();
                    }
                    
                    if ($fee->wasRecentlyCreated) {
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
            Log::error('Error importing fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'importation des frais: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Helper method pour transformer un frais
     */
    private function transformFee($fee)
    {
        return [
            'id' => $fee->id,
            'amount' => (float) $fee->amount,
            'due_date' => $fee->due_date ? $fee->due_date->toDateString() : null,
            'fee_type' => $fee->feeType ? [
                'id' => $fee->feeType->id,
                'name' => $fee->feeType->name,
                'description' => $fee->feeType->description,
                'payable_by' => $fee->feeType->payable_by,
                'school' => $fee->feeType->school ? [
                    'id' => $fee->feeType->school->id,
                    'name' => $fee->feeType->school->name,
                ] : null,
            ] : null,
            'installments' => $fee->installments->sortBy('installment_no')->map(function ($installment) {
                return [
                    'id' => $installment->id,
                    'installment_no' => $installment->installment_no,
                    'amount' => (float) $installment->amount,
                    'due_date' => $installment->due_date->toDateString(),
                    'created_at' => $installment->created_at->toIso8601String(),
                    'updated_at' => $installment->updated_at->toIso8601String(),
                ];
            })->values(),
            'associated_classes' => $fee->classFees->map(function ($classFee) {
                return $classFee->class ? [
                    'id' => $classFee->class->id,
                    'name' => $classFee->class->name,
                ] : null;
            })->filter()->values(),
            'created_by' => $fee->createdBy ? [
                'id' => $fee->createdBy->id,
                'full_name' => $fee->createdBy->full_name,
            ] : null,
            'updated_by' => $fee->updatedBy ? [
                'id' => $fee->updatedBy->id,
                'full_name' => $fee->updatedBy->full_name,
            ] : null,
            'created_at' => $fee->created_at->toIso8601String(),
            'updated_at' => $fee->updated_at->toIso8601String(),
            'deleted_at' => $fee->deleted_at ? $fee->deleted_at->toIso8601String() : null,
        ];
    }
}