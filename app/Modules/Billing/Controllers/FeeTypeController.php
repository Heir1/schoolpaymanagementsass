<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\FeeType;
use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;

class FeeTypeController extends Controller
{
    /**
     * GET: Liste tous les types de frais avec pagination et filtres
     * GET /api/v1/admin/fee-types
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Construire la requête
            $query = FeeType::with([
                'school',
                'createdBy',
                'updatedBy'
            ]);
            
            // Si c'est un school_admin, on filtre par son école
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if ($schoolId) {
                    $query->where('school_id', $schoolId);
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
                $query->where('school_id', $request->school_id);
            }
            
            // Filtres
            if ($request->has('payable_by')) {
                $query->where('payable_by', $request->payable_by);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            // Trier par défaut par date de création
            $query->orderBy('created_at', 'desc');
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $feeTypes = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedFeeTypes = $feeTypes->getCollection()->map(function ($feeType) {
                return [
                    'id' => $feeType->id,
                    'name' => $feeType->name,
                    'description' => $feeType->description,
                    'payable_by' => $feeType->payable_by,
                    'school' => $feeType->school ? [
                        'id' => $feeType->school->id,
                        'name' => $feeType->school->name,
                    ] : null,
                    'created_by' => $feeType->createdBy ? $feeType->createdBy->full_name : null,
                    'updated_by' => $feeType->updatedBy ? $feeType->updatedBy->full_name : null,
                    'created_at' => $feeType->created_at->toIso8601String(),
                    'updated_at' => $feeType->updated_at->toIso8601String(),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des types de frais récupérée avec succès',
                'data' => [
                    'fee_types' => $transformedFeeTypes,
                    'pagination' => [
                        'total' => $feeTypes->total(),
                        'per_page' => $feeTypes->perPage(),
                        'current_page' => $feeTypes->currentPage(),
                        'last_page' => $feeTypes->lastPage(),
                        'from' => $feeTypes->firstItem(),
                        'to' => $feeTypes->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching fee types list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des types de frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer un nouveau type de frais
     * POST /api/v1/admin/fee-types
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'payable_by' => 'required|in:student,parent,both',
            ], [
                'name.required' => 'Le nom du type de frais est requis',
                'name.max' => 'Le nom ne doit pas dépasser 100 caractères',
                'description.max' => 'La description ne doit pas dépasser 500 caractères',
                'payable_by.required' => 'Le payeur est requis',
                'payable_by.in' => 'Le payeur doit être: student, parent ou both',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Déterminer le school_id
            $schoolId = null;
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if (!$schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous devez être associé à une école pour créer un type de frais',
                    ], 403);
                }
            } elseif ($currentUser->isSuperAdmin()) {
                // Pour super_admin, school_id peut être spécifié
                if ($request->has('school_id')) {
                    $schoolId = $request->school_id;
                    
                    // Vérifier que l'école existe
                    $school = School::find($schoolId);
                    if (!$school) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'École non trouvée',
                        ], 404);
                    }
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le champ school_id est requis pour un super_admin',
                    ], 422);
                }
            }
            
            // Vérifier si un type de frais avec le même nom existe déjà pour cette école
            $existingFeeType = FeeType::where('school_id', $schoolId)
                ->where('name', $request->name)
                ->first();
            
            if ($existingFeeType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Un type de frais avec ce nom existe déjà pour cette école',
                ], 422);
            }
            
            // Créer le type de frais
            $feeType = FeeType::create([
                'school_id' => $schoolId,
                'name' => $request->name,
                'description' => $request->description,
                'payable_by' => $request->payable_by,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            // Charger les relations pour la réponse
            $feeType->load(['school', 'createdBy', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Type de frais créé avec succès',
                'data' => $feeType,
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating fee type: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du type de frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher un type de frais spécifique
     * GET /api/v1/admin/fee-types/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $feeType = FeeType::with([
                'school',
                'createdBy',
                'updatedBy'
            ])->findOrFail($id);
            
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
            
            return response()->json([
                'status' => 'success',
                'message' => 'Type de frais récupéré avec succès',
                'data' => $feeType,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching fee type: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_type_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Type de frais non trouvé'
            ], 404);
        }
    }

    /**
     * PUT: Mettre à jour un type de frais
     * PUT /api/v1/admin/fee-types/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $feeType = FeeType::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour modifier ce type de frais',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:100',
                'description' => 'nullable|string|max:500',
                'payable_by' => 'sometimes|in:student,parent,both',
            ], [
                'name.max' => 'Le nom ne doit pas dépasser 100 caractères',
                'description.max' => 'La description ne doit pas dépasser 500 caractères',
                'payable_by.in' => 'Le payeur doit être: student, parent ou both',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier si le nom est modifié et s'il existe déjà pour cette école
            if ($request->has('name') && $request->name !== $feeType->name) {
                $existingFeeType = FeeType::where('school_id', $feeType->school_id)
                    ->where('name', $request->name)
                    ->where('id', '!=', $id)
                    ->first();
                
                if ($existingFeeType) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Un type de frais avec ce nom existe déjà pour cette école',
                    ], 422);
                }
            }
            
            // Mettre à jour le type de frais
            $feeType->update(array_merge(
                $request->only(['name', 'description', 'payable_by']),
                ['updated_by' => $currentUser->id]
            ));
            
            DB::commit();
            
            // Recharger les relations
            $feeType->load(['school', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Type de frais mis à jour avec succès',
                'data' => $feeType,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating fee type: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_type_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du type de frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer un type de frais (soft delete)
     * DELETE /api/v1/admin/fee-types/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $feeType = FeeType::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour supprimer ce type de frais',
                    ], 403);
                }
            }
            
            // Vérifier si le type de frais est utilisé dans des frais étudiants
            // Vous devrez peut-être adapter cette vérification selon vos relations
            // if ($feeType->studentFees()->exists()) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Impossible de supprimer ce type de frais car il est utilisé dans des frais étudiants',
            //     ], 422);
            // }
            
            $feeType->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Type de frais supprimé avec succès',
                'data' => [
                    'fee_type_id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting fee type: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_type_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du type de frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer un type de frais supprimé
     * POST /api/v1/admin/fee-types/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $feeType = FeeType::withTrashed()->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($feeType->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour restaurer ce type de frais',
                    ], 403);
                }
            }
            
            if (!$feeType->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce type de frais n\'est pas supprimé',
                ], 422);
            }
            
            $feeType->restore();
            $feeType->update(['updated_by' => $currentUser->id]);
            
            DB::commit();
            
            $feeType->load(['school']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Type de frais restauré avec succès',
                'data' => $feeType,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring fee type: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'fee_type_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du type de frais',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Récupérer les types de frais par école
     * GET /api/v1/admin/schools/{schoolId}/fee-types
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
            
            $feeTypes = FeeType::where('school_id', $schoolId)
                ->with(['createdBy', 'updatedBy'])
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Types de frais de l\'école récupérés avec succès',
                'data' => [
                    'school' => $school,
                    'fee_types' => $feeTypes,
                    'count' => $feeTypes->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching school fee types: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'school_id' => $schoolId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des types de frais de l\'école'
            ], 500);
        }
    }

    /**
     * GET: Exporter les types de frais avec FastExcel
     * GET /api/v1/admin/fee-types/export
     */
    public function export(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = FeeType::with(['school']);
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($schoolId) {
                    $query->where('school_id', $schoolId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
            
            $feeTypes = $query->get();
            
            $exportData = $feeTypes->map(function ($feeType) {
                return [
                    'ID' => $feeType->id,
                    'Nom' => $feeType->name,
                    'Description' => $feeType->description ?? '',
                    'Payable par' => $feeType->payable_by,
                    'École' => $feeType->school->name ?? '',
                    'Date de création' => $feeType->created_at->format('Y-m-d H:i:s'),
                    'Date de mise à jour' => $feeType->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            $fileName = 'types_de_frais_' . date('Y-m-d_His') . '.xlsx';
            
            return (new FastExcel($exportData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error exporting fee types with FastExcel: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'exportation des types de frais'
            ], 500);
        }
    }

    /**
     * GET: Télécharger le template d'importation
     * GET /api/v1/admin/fee-types/import-template
     */
    public function downloadImportTemplate(Request $request)
    {
        try {
            $templateData = collect([
                [
                    'Nom' => 'Frais de scolarité',
                    'Description' => 'Frais annuels de scolarité',
                    'Payable par' => 'parent',
                ]
            ]);
            
            $fileName = 'template_import_types_frais_' . date('Y-m-d') . '.xlsx';
            
            return (new FastExcel($templateData))->download($fileName, function ($row) {
                return [
                    'Nom' => $row['Nom'] ?? '',
                    'Description' => $row['Description'] ?? '',
                    'Payable par' => $row['Payable par'] ?? 'parent',
                ];
            });
            
        } catch (\Exception $e) {
            Log::error('Error downloading import template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du template'
            ], 500);
        }
    }

    /**
     * POST: Importer des types de frais depuis un fichier Excel
     * POST /api/v1/admin/fee-types/import
     */
    public function import(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
                'school_id' => 'nullable|exists:schools,id',
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
            
            // Déterminer le school_id
            $schoolId = null;
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if (!$schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous devez être associé à une école pour importer des types de frais',
                    ], 403);
                }
            } elseif ($currentUser->isSuperAdmin()) {
                if ($request->has('school_id')) {
                    $schoolId = $request->school_id;
                    
                    $school = School::find($schoolId);
                    if (!$school) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'École non trouvée',
                        ], 404);
                    }
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le champ school_id est requis pour un super_admin',
                    ], 422);
                }
            }
            
            $rows = (new FastExcel)->import($file);
            
            $importedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $errors = [];
            
            foreach ($rows as $index => $row) {
                try {
                    $rowNumber = $index + 2;
                    
                    if (empty($row['Nom'])) {
                        $errors[] = "Ligne {$rowNumber}: Le nom est requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    if (empty($row['Payable par'])) {
                        $errors[] = "Ligne {$rowNumber}: Le champ 'Payable par' est requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    $payableBy = strtolower($row['Payable par']);
                    if (!in_array($payableBy, ['student', 'parent', 'both'])) {
                        $errors[] = "Ligne {$rowNumber}: 'Payable par' doit être 'student', 'parent' ou 'both'";
                        $skippedCount++;
                        continue;
                    }
                    
                    $existingFeeType = FeeType::where('school_id', $schoolId)
                        ->where('name', $row['Nom'])
                        ->first();
                    
                    $feeTypeData = [
                        'school_id' => $schoolId,
                        'name' => $row['Nom'],
                        'description' => $row['Description'] ?? null,
                        'payable_by' => $payableBy,
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ];
                    
                    if ($existingFeeType) {
                        $existingFeeType->update($feeTypeData);
                        $updatedCount++;
                    } else {
                        FeeType::create($feeTypeData);
                        $importedCount++;
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
            Log::error('Error importing fee types: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'importation des types de frais: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}