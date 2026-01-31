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
            
            // Construction de la requête avec eager loading et withTrashed pour inclure les supprimées
            $query = ClassModel::with(['school', 'schoolYear', 'createdBy', 'updatedBy', 'requiredDocuments.document'])
                            ->withTrashed(); // AJOUT: Inclure les classes supprimées
            
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
            
            // MODIFICATION: Amélioration du filtre status pour gérer withTrashed()
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                } elseif ($request->status === 'all') {
                    // Avec withTrashed() déjà appliqué, on garde tout
                }
                // Si aucune valeur spécifique, on garde withTrashed()
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $classes = $query->paginate($perPage);
            
            // Formater la réponse avec is_deleted
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
                    'is_deleted' => !is_null($class->deleted_at), // AJOUT: Champ is_deleted
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
            
            // Déterminer l'école selon le type d'utilisateur
            if ($this->isSuperAdmin($currentUser)) {
                // Super admin doit fournir l'école
                $validator = Validator::make($request->all(), [
                    'school_id' => 'required|integer|exists:schools,id',
                    'name' => 'required|string|max:255',
                    'level' => 'required|string|max:50',
                    'school_year_id' => 'nullable|integer|exists:school_years,id',
                ], [
                    'school_id.required' => 'L\'école est requise pour le super administrateur.',
                    'school_id.exists' => 'L\'école sélectionnée n\'existe pas.',
                    'name.required' => 'Le nom de la classe est requis.',
                    'name.max' => 'Le nom de la classe ne doit pas dépasser 255 caractères.',
                    'level.required' => 'Le niveau de la classe est requis.',
                    'level.max' => 'Le niveau ne doit pas dépasser 50 caractères.',
                    'school_year_id.exists' => 'L\'année scolaire sélectionnée n\'existe pas.',
                ]);
                
                if ($validator->fails()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation échouée',
                        'errors' => $validator->errors(),
                    ], 422);
                }
                
                $schoolId = $request->school_id;
                
                // Pour super admin : si school_year_id n'est pas fourni, prendre l'année active
                if ($request->has('school_year_id') && $request->school_year_id) {
                    $schoolYearId = $request->school_year_id;
                    // Vérifier que l'année scolaire appartient à l'école
                    $schoolYear = SchoolYear::find($schoolYearId);
                    if ($schoolYear && $schoolYear->school_id != $schoolId) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'L\'année scolaire sélectionnée n\'appartient pas à cette école.',
                        ], 422);
                    }
                } else {
                    // Prendre l'année active par défaut
                    $activeSchoolYear = $this->getActiveSchoolYear($schoolId);
                    if (!$activeSchoolYear) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Aucune année scolaire active trouvée pour cette école.',
                        ], 422);
                    }
                    $schoolYearId = $activeSchoolYear->id;
                }
            } else {
                // School admin : récupérer l'école automatiquement
                $validator = Validator::make($request->all(), [
                    'name' => 'required|string|max:255',
                    'level' => 'required|string|max:50',
                ], [
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
                
                // Récupérer l'école du school_admin
                $schoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if (!$schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'êtes pas associé à une école.',
                    ], 403);
                }
                
                // Pour school admin : toujours prendre l'année active par défaut
                $activeSchoolYear = $this->getActiveSchoolYear($schoolId);
                if (!$activeSchoolYear) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Aucune année scolaire active trouvée pour votre école.',
                    ], 422);
                }
                $schoolYearId = $activeSchoolYear->id;
            }
            
            // Vérifier les permissions
            if (!$this->checkClassPermissions($currentUser, null, $schoolId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour créer une classe dans cette école.',
                ], 403);
            }
            
            // Vérifier si une classe avec le même nom existe déjà dans la même école et année scolaire
            $existingClass = ClassModel::where('school_id', $schoolId)
                ->where('school_year_id', $schoolYearId)
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
                'school_id' => $schoolId,
                'school_year_id' => $schoolYearId,
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
                    'note' => $this->isSuperAdmin($currentUser) 
                        ? 'Créée par super administrateur' 
                        : 'Créée par administrateur d\'école',
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
            
            // Règles de validation différentes selon le type d'utilisateur
            if ($this->isSuperAdmin($currentUser)) {
                // Super admin peut changer l'école et l'année scolaire
                $validator = Validator::make($request->all(), [
                    'school_id' => 'sometimes|required|integer|exists:schools,id',
                    'school_year_id' => 'sometimes|required|integer|exists:school_years,id',
                    'name' => [
                        'sometimes',
                        'required',
                        'string',
                        'max:255',
                        function ($attribute, $value, $fail) use ($request, $class, $id) {
                            $schoolId = $request->has('school_id') ? $request->school_id : $class->school_id;
                            $schoolYearId = $request->has('school_year_id') ? $request->school_year_id : $class->school_year_id;
                            
                            $existing = ClassModel::where('school_id', $schoolId)
                                ->where('school_year_id', $schoolYearId)
                                ->where('name', $value)
                                ->where('id', '!=', $id)
                                ->whereNull('deleted_at')
                                ->exists();
                            
                            if ($existing) {
                                $fail('Une classe avec ce nom existe déjà dans cette école et année scolaire.');
                            }
                        },
                    ],
                    'level' => 'sometimes|required|string|max:50',
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
            } else {
                // School admin peut seulement changer le nom, le niveau et l'année scolaire
                $validator = Validator::make($request->all(), [
                    'school_year_id' => 'sometimes|required|integer|exists:school_years,id',
                    'name' => [
                        'sometimes',
                        'required',
                        'string',
                        'max:255',
                        function ($attribute, $value, $fail) use ($class, $id, $request) {
                            $schoolYearId = $request->has('school_year_id') ? $request->school_year_id : $class->school_year_id;
                            
                            $existing = ClassModel::where('school_id', $class->school_id)
                                ->where('school_year_id', $schoolYearId)
                                ->where('name', $value)
                                ->where('id', '!=', $id)
                                ->whereNull('deleted_at')
                                ->exists();
                            
                            if ($existing) {
                                $fail('Une classe avec ce nom existe déjà dans votre école et année scolaire.');
                            }
                        },
                    ],
                    'level' => 'sometimes|required|string|max:50',
                ], [
                    'school_year_id.required' => 'L\'année scolaire est requise.',
                    'school_year_id.exists' => 'L\'année scolaire sélectionnée n\'existe pas.',
                    'name.required' => 'Le nom de la classe est requis.',
                    'name.max' => 'Le nom de la classe ne doit pas dépasser 255 caractères.',
                    'level.required' => 'Le niveau de la classe est requis.',
                    'level.max' => 'Le niveau ne doit pas dépasser 50 caractères.',
                ]);
            }
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour la classe
            $updateData = [];
            
            // Gestion du school_id (uniquement pour super admin)
            if ($request->has('school_id')) {
                if ($this->isSuperAdmin($currentUser)) {
                    $updateData['school_id'] = $request->school_id;
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous ne pouvez pas changer l\'école d\'une classe.',
                    ], 403);
                }
            }
            
            // Gestion du school_year_id
            if ($request->has('school_year_id')) {
                $schoolYear = SchoolYear::find($request->school_year_id);
                $targetSchoolId = $request->has('school_id') ? $request->school_id : $class->school_id;
                
                if ($schoolYear && $schoolYear->school_id != $targetSchoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'L\'année scolaire sélectionnée n\'appartient pas à cette école.',
                    ], 422);
                }
                
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
                    'permissions_note' => $this->isSuperAdmin($currentUser) 
                        ? 'Modifiée par super administrateur (école modifiable)' 
                        : 'Modifiée par administrateur d\'école (école non modifiable)',
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
            
            // Fonction pour créer une requête de base avec permissions
            $createBaseQuery = function () use ($currentUser) {
                $query = ClassModel::withTrashed();
                
                // Appliquer les filtres selon les permissions
                if (!$this->isSuperAdmin($currentUser)) {
                    $adminSchoolId = $currentUser->userRoles()
                        ->whereHas('role', function ($q) {
                            $q->where('name', 'school_admin');
                        })
                        ->value('school_id');
                    
                    if ($adminSchoolId) {
                        $query->where('school_id', $adminSchoolId);
                    }
                }
                
                return $query;
            };
            
            // Statistiques principales avec des instances distinctes
            $totalClasses = $createBaseQuery()->count();
            $activeClasses = $createBaseQuery()->whereNull('deleted_at')->count();
            $deletedClasses = $createBaseQuery()->onlyTrashed()->count();
            
            // Classes par école (uniquement les classes actives)
            $classesBySchoolQuery = ClassModel::whereNull('deleted_at');
            
            if (!$this->isSuperAdmin($currentUser)) {
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($q) {
                        $q->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $classesBySchoolQuery->where('school_id', $adminSchoolId);
                }
            }
            
            $classesBySchool = $classesBySchoolQuery
                ->select('school_id', DB::raw('COUNT(*) as count'))
                ->groupBy('school_id')
                ->with('school')
                ->get()
                ->map(function ($item) {
                    return [
                        'school_name' => $item->school->name ?? 'Inconnu',
                        'count' => $item->count,
                    ];
                });
            
            // Classes par niveau (actives seulement)
            $classesByLevelQuery = ClassModel::whereNull('deleted_at');
            
            if (!$this->isSuperAdmin($currentUser)) {
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($q) {
                        $q->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $classesByLevelQuery->where('school_id', $adminSchoolId);
                }
            }
            
            $classesByLevel = $classesByLevelQuery
                ->select('level', DB::raw('COUNT(*) as count'))
                ->groupBy('level')
                ->orderBy('level')
                ->get();
            
            // Classes créées récemment (30 derniers jours) - actives + supprimées
            $recentClasses = $createBaseQuery()
                ->where('created_at', '>=', now()->subDays(30))
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


    /**
     * Récupérer l'année scolaire active d'une école
    */
    private function getActiveSchoolYear($schoolId)
    {
        return SchoolYear::where('school_id', $schoolId)
            ->where('is_active', true)
            ->first();
    }


    /**
     * Récupérer les options pour la création de classes
     * GET /api/v1/admin/classes/options
     */
    public function getOptions(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $options = [];
            
            if ($this->isSuperAdmin($currentUser)) {
                // Super admin : toutes les écoles et leurs années scolaires
                $options['schools'] = School::select('id', 'name')
                    ->orderBy('name')
                    ->get();
                
                $options['school_years'] = SchoolYear::select('id', 'year_label', 'school_id')
                    ->with('school:id,name')
                    ->orderBy('year_label', 'desc')
                    ->get();
            } else {
                // School admin : seulement son école
                $schoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($schoolId) {
                    $options['school'] = School::select('id', 'name')
                        ->find($schoolId);
                    
                    $options['school_years'] = SchoolYear::where('school_id', $schoolId)
                        ->select('id', 'year_label')
                        ->orderBy('year_label', 'desc')
                        ->get();
                    
                    // Année scolaire active par défaut
                    $activeSchoolYear = $this->getActiveSchoolYear($schoolId);
                    if ($activeSchoolYear) {
                        $options['default_school_year'] = [
                            'id' => $activeSchoolYear->id,
                            'year_label' => $activeSchoolYear->year_label,
                        ];
                    }
                }
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $options,
                'message' => 'Options récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting class options:', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des options',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

}