<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\ClassModel;
use App\Modules\Academic\Models\ClassRequiredDocument;
use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\SchoolYear;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    /**
     * Vérifier si l'utilisateur est super admin
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'superadmin');
            })
            ->exists();
    }

    /**
     * Vérifier les permissions pour les classes
     */
    private function checkClassPermissions(User $adminUser, ClassModel $class = null, $schoolId = null)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que les classes de son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        // Si on vérifie une classe spécifique
        if ($class) {
            return $class->school_id == $adminSchoolId;
        }

        // Si on vérifie par school_id
        if ($schoolId) {
            return $schoolId == $adminSchoolId;
        }

        return true;
    }

    /**
     * LISTER toutes les classes (avec filtres)
     * GET /api/v1/admin/classes
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading
            $query = ClassModel::with(['school', 'schoolYear', 'createdBy', 'updatedBy', 'requiredDocuments.document']);
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les classes de son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->where('school_id', $adminSchoolId);
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions nécessaires.',
                    ], 403);
                }
            }
            
            // Filtres optionnels
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('level', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }
            
            if ($request->has('school_year_id')) {
                $query->where('school_year_id', $request->school_year_id);
            }
            
            if ($request->has('level')) {
                $query->where('level', $request->level);
            }
            
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $classes = $query->paginate($perPage);
            
            // Formater la réponse
            $classes->getCollection()->transform(function ($class) {
                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'level' => $class->level,
                    'school' => $class->school ? [
                        'id' => $class->school->id,
                        'name' => $class->school->name,
                    ] : null,
                    'school_year' => $class->schoolYear ? [
                        'id' => $class->schoolYear->id,
                        'year_label' => $class->schoolYear->year_label,
                    ] : null,
                    'required_documents_count' => $class->requiredDocuments->count(),
                    'mandatory_documents_count' => $class->requiredDocuments->where('is_mandatory', true)->count(),
                    'created_by' => $class->createdBy ? [
                        'id' => $class->createdBy->id,
                        'name' => $class->createdBy->full_name,
                    ] : null,
                    'updated_by' => $class->updatedBy ? [
                        'id' => $class->updatedBy->id,
                        'name' => $class->updatedBy->full_name,
                    ] : null,
                    'created_at' => $class->created_at,
                    'updated_at' => $class->updated_at,
                    'deleted_at' => $class->deleted_at,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $classes,
                'message' => 'Liste des classes récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Class index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des classes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * VOIR une classe spécifique
     * GET /api/v1/admin/classes/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $class = ClassModel::with([
                'school', 
                'schoolYear', 
                'createdBy', 
                'updatedBy', 
                'requiredDocuments.document'
            ])->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir cette classe.',
                ], 403);
            }
            
            // Formater la réponse
            $formattedClass = [
                'id' => $class->id,
                'name' => $class->name,
                'level' => $class->level,
                'school' => $class->school ? [
                    'id' => $class->school->id,
                    'name' => $class->school->name,
                    'type' => $class->school->type->name ?? null,
                ] : null,
                'school_year' => $class->schoolYear ? [
                    'id' => $class->schoolYear->id,
                    'year_label' => $class->schoolYear->year_label,
                    'start_date' => $class->schoolYear->start_date,
                    'end_date' => $class->schoolYear->end_date,
                    'is_active' => $class->schoolYear->is_active,
                ] : null,
                'created_by' => $class->createdBy ? [
                    'id' => $class->createdBy->id,
                    'name' => $class->createdBy->full_name,
                ] : null,
                'updated_by' => $class->updatedBy ? [
                    'id' => $class->updatedBy->id,
                    'name' => $class->updatedBy->full_name,
                ] : null,
                'required_documents' => $class->requiredDocuments->map(function ($requiredDoc) {
                    return [
                        'id' => $requiredDoc->id,
                        'document' => $requiredDoc->document ? [
                            'id' => $requiredDoc->document->id,
                            'name' => $requiredDoc->document->name,
                            'description' => $requiredDoc->document->description,
                        ] : null,
                        'is_mandatory' => $requiredDoc->is_mandatory,
                        'created_at' => $requiredDoc->created_at,
                    ];
                }),
                'created_at' => $class->created_at,
                'updated_at' => $class->updated_at,
                'deleted_at' => $class->deleted_at,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedClass,
                'message' => 'Classe récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Class show error:', [
                'error' => $e->getMessage(),
                'class_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la classe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * CRÉER une nouvelle classe
     * POST /api/v1/admin/classes
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'school_id' => 'required|integer|exists:schools,id',
                'school_year_id' => 'required|integer|exists:school_years,id',
                'name' => 'required|string|max:255',
                'level' => 'required|string|max:50',
            ], [
                'school_id.required' => 'L\'école est requise.',
                'school_id.exists' => 'L\'école sélectionnée n\'existe pas.',
                'school_year_id.required' => 'L\'année scolaire est requise.',
                'school_year_id.exists' => 'L\'année scolaire sélectionnée n\'existe pas.',
                'name.required' => 'Le nom de la classe est requis.',
                'name.max' => 'Le nom de la classe ne doit pas dépasser 255 caractères.',
                'level.required' => 'Le niveau de la classe est requis.',
                'level.max' => 'Le niveau ne doit pas dépasser 50 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, null, $request->school_id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour créer une classe dans cette école.',
                ], 403);
            }
            
            // Vérifier que l'année scolaire appartient à la même école
            $schoolYear = SchoolYear::find($request->school_year_id);
            if ($schoolYear && $schoolYear->school_id != $request->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'L\'année scolaire sélectionnée n\'appartient pas à cette école.',
                ], 422);
            }
            
            // Vérifier si une classe avec le même nom existe déjà dans la même école et année scolaire
            $existingClass = ClassModel::where('school_id', $request->school_id)
                ->where('school_year_id', $request->school_year_id)
                ->where('name', $request->name)
                ->whereNull('deleted_at')
                ->first();
            
            if ($existingClass) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Une classe avec ce nom existe déjà dans cette école et année scolaire.',
                ], 422);
            }
            
            // Créer la classe
            $class = ClassModel::create([
                'school_id' => $request->school_id,
                'school_year_id' => $request->school_year_id,
                'name' => $request->name,
                'level' => $request->level,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Charger les relations
            $class->load(['school', 'schoolYear', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            // Log pour audit
            Log::info('Class created', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'class_id' => $class->id,
                'class_name' => $class->name,
                'school_id' => $class->school_id,
                'school_year_id' => $class->school_year_id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Classe créée avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                        'school' => $class->school->name,
                        'school_year' => $class->schoolYear->year_label,
                        'created_by' => $class->createdBy->full_name,
                        'created_at' => $class->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating class:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la classe: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR une classe
     * PUT /api/v1/admin/classes/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette classe.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'school_year_id' => 'sometimes|required|integer|exists:school_years,id',
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('classes', 'name')
                        ->where('school_id', $class->school_id)
                        ->where('school_year_id', $request->has('school_year_id') ? $request->school_year_id : $class->school_year_id)
                        ->ignore($id),
                ],
                'level' => 'sometimes|required|string|max:50',
            ], [
                'school_year_id.required' => 'L\'année scolaire est requise.',
                'school_year_id.exists' => 'L\'année scolaire sélectionnée n\'existe pas.',
                'name.required' => 'Le nom de la classe est requis.',
                'name.unique' => 'Une classe avec ce nom existe déjà dans cette école et année scolaire.',
                'name.max' => 'Le nom de la classe ne doit pas dépasser 255 caractères.',
                'level.required' => 'Le niveau de la classe est requis.',
                'level.max' => 'Le niveau ne doit pas dépasser 50 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que l'année scolaire appartient à la même école
            if ($request->has('school_year_id')) {
                $schoolYear = SchoolYear::find($request->school_year_id);
                if ($schoolYear && $schoolYear->school_id != $class->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'L\'année scolaire sélectionnée n\'appartient pas à cette école.',
                    ], 422);
                }
            }
            
            // Mettre à jour la classe
            $updateData = [];
            
            if ($request->has('school_year_id')) {
                $updateData['school_year_id'] = $request->school_year_id;
            }
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('level')) {
                $updateData['level'] = $request->level;
            }
            
            // Toujours mettre à jour le champ updated_by
            $updateData['updated_by'] = $currentUser->id;
            
            $class->update($updateData);
            
            // Recharger les relations
            $class->load(['school', 'schoolYear', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('Class updated', [
                'admin_id' => $currentUser->id,
                'class_id' => $class->id,
                'changes' => $updateData,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Classe mise à jour avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                        'school' => $class->school->name,
                        'school_year' => $class->schoolYear->year_label,
                        'updated_by' => $class->updatedBy->full_name,
                        'updated_at' => $class->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating class:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la classe: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER une classe (soft delete)
     * DELETE /api/v1/admin/classes/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer cette classe.',
                ], 403);
            }
            
            // Vérifier si la classe a des documents requis associés
            $hasRequiredDocuments = $class->requiredDocuments()->exists();
            
            if ($hasRequiredDocuments) {
                // On peut soit supprimer les documents requis d'abord, soit empêcher la suppression
                // Pour l'instant, on empêche la suppression
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette classe car elle a des documents requis associés. Supprimez d\'abord les documents requis.',
                ], 400);
            }
            
            // Soft delete
            $class->delete();
            
            DB::commit();
            
            Log::info('Class deleted', [
                'admin_id' => $currentUser->id,
                'class_id' => $class->id,
                'class_name' => $class->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Classe supprimée avec succès',
                'data' => [
                    'class_id' => $class->id,
                    'name' => $class->name,
                    'deleted_at' => $class->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting class:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de la classe: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER une classe supprimée
     * POST /api/v1/admin/classes/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $class = ClassModel::onlyTrashed()->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour restaurer cette classe.',
                ], 403);
            }
            
            $class->restore();
            
            DB::commit();
            
            Log::info('Class restored', [
                'admin_id' => $currentUser->id,
                'class_id' => $class->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Classe restaurée avec succès',
                'data' => [
                    'class_id' => $class->id,
                    'name' => $class->name,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring class:', [
                'error' => $e->getMessage(),
                'class_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de la classe',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les classes par école
     * GET /api/v1/admin/schools/{schoolId}/classes
     */
    public function listBySchool(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, null, $schoolId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les classes de cette école.',
                ], 403);
            }
            
            $classes = ClassModel::where('school_id', $schoolId)
                ->whereNull('deleted_at')
                ->with(['schoolYear', 'requiredDocuments.document'])
                ->orderBy('level', 'asc')
                ->orderBy('name', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $classes,
                'message' => 'Classes récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing classes by school:', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des classes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les classes par année scolaire
     * GET /api/v1/admin/school-years/{schoolYearId}/classes
     */
    public function listBySchoolYear(Request $request, $schoolYearId)
    {
        try {
            $currentUser = $request->user();
            
            // Récupérer l'année scolaire pour vérifier les permissions
            $schoolYear = SchoolYear::findOrFail($schoolYearId);
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, null, $schoolYear->school_id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les classes de cette année scolaire.',
                ], 403);
            }
            
            $classes = ClassModel::where('school_year_id', $schoolYearId)
                ->whereNull('deleted_at')
                ->with(['school', 'requiredDocuments.document'])
                ->orderBy('level', 'asc')
                ->orderBy('name', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $classes,
                'message' => 'Classes récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing classes by school year:', [
                'error' => $e->getMessage(),
                'school_year_id' => $schoolYearId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des classes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STATISTIQUES des classes
     * GET /api/v1/admin/classes/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = ClassModel::query();
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les classes de son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->where('school_id', $adminSchoolId);
                }
            }
            
            $totalClasses = $query->count();
            $activeClasses = $query->whereNull('deleted_at')->count();
            $deletedClasses = $query->onlyTrashed()->count();
            
            // Classes par école
            $classesBySchool = ClassModel::select('school_id')
                ->selectRaw('COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupBy('school_id')
                ->with('school')
                ->get()
                ->map(function ($item) {
                    return [
                        'school_name' => $item->school->name ?? 'Inconnu',
                        'count' => $item->count,
                    ];
                });
            
            // Classes par niveau
            $classesByLevel = ClassModel::select('level')
                ->selectRaw('COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupBy('level')
                ->orderBy('level')
                ->get();
            
            // Classes créées récemment (30 derniers jours)
            $recentClasses = ClassModel::where('created_at', '>=', now()->subDays(30))
                ->count();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_classes' => $totalClasses,
                    'active_classes' => $activeClasses,
                    'deleted_classes' => $deletedClasses,
                    'classes_by_school' => $classesBySchool,
                    'classes_by_level' => $classesByLevel,
                    'recent_classes_last_30_days' => $recentClasses,
                ],
                'message' => 'Statistiques récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting class statistics:', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}