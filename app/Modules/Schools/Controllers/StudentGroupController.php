<?php

namespace App\Modules\Schools\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schools\Models\StudentGroup;
use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StudentGroupController extends Controller
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
     * Vérifier les permissions pour les groupes d'étudiants
     */
    private function checkStudentGroupPermissions(User $adminUser, StudentGroup $studentGroup = null, $schoolId = null)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que les groupes de son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        // Si on vérifie un groupe spécifique
        if ($studentGroup) {
            return $studentGroup->school_id == $adminSchoolId;
        }

        // Si on vérifie par school_id
        if ($schoolId) {
            return $schoolId == $adminSchoolId;
        }

        return true;
    }

    /**
     * LISTER tous les groupes d'étudiants (avec filtres)
     * GET /api/v1/admin/student-groups
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading et withTrashed pour inclure les supprimés
            $query = StudentGroup::with(['school', 'createdBy', 'updatedBy', 'groupFees'])
                                ->withTrashed(); // AJOUT: Inclure les groupes supprimés
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les groupes de son école
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
                    ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }
            
            // MODIFICATION: Amélioration du filtre status pour gérer withTrashed()
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed(); // Uniquement les supprimés
                } elseif ($request->status === 'all') {
                    // Avec withTrashed() déjà appliqué, on garde tout
                }
                // Si aucune valeur spécifique, on garde withTrashed()
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $studentGroups = $query->paginate($perPage);
            
            // Formater la réponse avec is_deleted
            $studentGroups->getCollection()->transform(function ($studentGroup) {
                return [
                    'id' => $studentGroup->id,
                    'name' => $studentGroup->name,
                    'description' => $studentGroup->description,
                    'school' => $studentGroup->school ? [
                        'id' => $studentGroup->school->id,
                        'name' => $studentGroup->school->name,
                    ] : null,
                    'group_fees_count' => $studentGroup->groupFees->count(),
                    'created_by' => $studentGroup->createdBy ? [
                        'id' => $studentGroup->createdBy->id,
                        'name' => $studentGroup->createdBy->full_name,
                    ] : null,
                    'updated_by' => $studentGroup->updatedBy ? [
                        'id' => $studentGroup->updatedBy->id,
                        'name' => $studentGroup->updatedBy->full_name,
                    ] : null,
                    'created_at' => $studentGroup->created_at,
                    'updated_at' => $studentGroup->updated_at,
                    'deleted_at' => $studentGroup->deleted_at,
                    'is_deleted' => !is_null($studentGroup->deleted_at), // AJOUT: Champ is_deleted
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $studentGroups,
                'message' => 'Liste des groupes d\'étudiants récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Student group index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des groupes d\'étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * VOIR un groupe d'étudiants spécifique
     * GET /api/v1/admin/student-groups/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $studentGroup = StudentGroup::with(['school', 'createdBy', 'updatedBy', 'groupFees'])
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, $studentGroup)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir ce groupe d\'étudiants.',
                ], 403);
            }
            
            // Formater la réponse
            $formattedStudentGroup = [
                'id' => $studentGroup->id,
                'name' => $studentGroup->name,
                'description' => $studentGroup->description,
                'school' => $studentGroup->school ? [
                    'id' => $studentGroup->school->id,
                    'name' => $studentGroup->school->name,
                    'type' => $studentGroup->school->type->name ?? null,
                ] : null,
                'created_by' => $studentGroup->createdBy ? [
                    'id' => $studentGroup->createdBy->id,
                    'name' => $studentGroup->createdBy->full_name,
                ] : null,
                'updated_by' => $studentGroup->updatedBy ? [
                    'id' => $studentGroup->updatedBy->id,
                    'name' => $studentGroup->updatedBy->full_name,
                ] : null,
                'group_fees' => $studentGroup->groupFees->map(function ($groupFee) {
                    return [
                        'id' => $groupFee->id,
                        'name' => $groupFee->name,
                        'amount' => $groupFee->amount,
                        'currency' => $groupFee->currency,
                        'due_date' => $groupFee->due_date,
                        'is_active' => $groupFee->is_active,
                    ];
                }),
                'created_at' => $studentGroup->created_at,
                'updated_at' => $studentGroup->updated_at,
                'deleted_at' => $studentGroup->deleted_at,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedStudentGroup,
                'message' => 'Groupe d\'étudiants récupéré avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Student group show error:', [
                'error' => $e->getMessage(),
                'student_group_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération du groupe d\'étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * CRÉER un nouveau groupe d'étudiants
     * POST /api/v1/admin/student-groups
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'school_id' => 'required|integer|exists:schools,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
            ], [
                'school_id.required' => 'L\'école est requise.',
                'school_id.exists' => 'L\'école sélectionnée n\'existe pas.',
                'name.required' => 'Le nom du groupe est requis.',
                'name.max' => 'Le nom du groupe ne doit pas dépasser 255 caractères.',
                'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, null, $request->school_id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour créer un groupe pour cette école.',
                ], 403);
            }
            
            // Vérifier si un groupe avec le même nom existe déjà dans la même école
            $existingGroup = StudentGroup::where('school_id', $request->school_id)
                ->where('name', $request->name)
                ->whereNull('deleted_at')
                ->first();
            
            if ($existingGroup) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Un groupe avec ce nom existe déjà dans cette école.',
                ], 422);
            }
            
            // Créer le groupe d'étudiants
            $studentGroup = StudentGroup::create([
                'school_id' => $request->school_id,
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Charger les relations
            $studentGroup->load(['school', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            // Log pour audit
            Log::info('Student group created', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'student_group_id' => $studentGroup->id,
                'student_group_name' => $studentGroup->name,
                'school_id' => $studentGroup->school_id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Groupe d\'étudiants créé avec succès',
                'data' => [
                    'student_group' => [
                        'id' => $studentGroup->id,
                        'name' => $studentGroup->name,
                        'description' => $studentGroup->description,
                        'school' => $studentGroup->school->name,
                        'created_by' => $studentGroup->createdBy->full_name,
                        'created_at' => $studentGroup->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating student group:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du groupe d\'étudiants: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR un groupe d'étudiants
     * PUT /api/v1/admin/student-groups/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $studentGroup = StudentGroup::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, $studentGroup)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier ce groupe d\'étudiants.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('student_groups', 'name')
                        ->where('school_id', $studentGroup->school_id)
                        ->ignore($id),
                ],
                'description' => 'nullable|string|max:1000',
            ], [
                'name.required' => 'Le nom du groupe est requis.',
                'name.unique' => 'Un groupe avec ce nom existe déjà dans cette école.',
                'name.max' => 'Le nom du groupe ne doit pas dépasser 255 caractères.',
                'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour le groupe d'étudiants
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }
            
            // Toujours mettre à jour le champ updated_by
            $updateData['updated_by'] = $currentUser->id;
            
            $studentGroup->update($updateData);
            
            // Recharger les relations
            $studentGroup->load(['school', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('Student group updated', [
                'admin_id' => $currentUser->id,
                'student_group_id' => $studentGroup->id,
                'changes' => $updateData,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Groupe d\'étudiants mis à jour avec succès',
                'data' => [
                    'student_group' => [
                        'id' => $studentGroup->id,
                        'name' => $studentGroup->name,
                        'description' => $studentGroup->description,
                        'school' => $studentGroup->school->name,
                        'updated_by' => $studentGroup->updatedBy->full_name,
                        'updated_at' => $studentGroup->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating student group:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_group_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du groupe d\'étudiants: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER un groupe d'étudiants (soft delete)
     * DELETE /api/v1/admin/student-groups/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $studentGroup = StudentGroup::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, $studentGroup)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer ce groupe d\'étudiants.',
                ], 403);
            }
            
            // Vérifier si le groupe a des frais associés
            $hasGroupFees = $studentGroup->groupFees()->exists();
            
            if ($hasGroupFees) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer ce groupe car il a des frais associés.',
                ], 400);
            }
            
            // Soft delete
            $studentGroup->delete();
            
            DB::commit();
            
            Log::info('Student group deleted', [
                'admin_id' => $currentUser->id,
                'student_group_id' => $studentGroup->id,
                'student_group_name' => $studentGroup->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Groupe d\'étudiants supprimé avec succès',
                'data' => [
                    'student_group_id' => $studentGroup->id,
                    'name' => $studentGroup->name,
                    'deleted_at' => $studentGroup->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting student group:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_group_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du groupe d\'étudiants: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER un groupe d'étudiants supprimé
     * POST /api/v1/admin/student-groups/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $studentGroup = StudentGroup::onlyTrashed()->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, $studentGroup)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour restaurer ce groupe d\'étudiants.',
                ], 403);
            }
            
            $studentGroup->restore();
            
            DB::commit();
            
            Log::info('Student group restored', [
                'admin_id' => $currentUser->id,
                'student_group_id' => $studentGroup->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Groupe d\'étudiants restauré avec succès',
                'data' => [
                    'student_group_id' => $studentGroup->id,
                    'name' => $studentGroup->name,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring student group:', [
                'error' => $e->getMessage(),
                'student_group_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du groupe d\'étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les groupes d'étudiants par école
     * GET /api/v1/admin/schools/{schoolId}/student-groups
     */
    public function listBySchool(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkStudentGroupPermissions($currentUser, null, $schoolId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les groupes d\'étudiants de cette école.',
                ], 403);
            }
            
            $studentGroups = StudentGroup::where('school_id', $schoolId)
                ->whereNull('deleted_at')
                ->orderBy('name', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $studentGroups,
                'message' => 'Groupes d\'étudiants récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing student groups by school:', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des groupes d\'étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STATISTIQUES des groupes d'étudiants
     * GET /api/v1/admin/student-groups/statistics
    */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Fonction pour créer une requête de base avec permissions
            $createBaseQuery = function () use ($currentUser) {
                $query = StudentGroup::withTrashed();
                
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
            
            // Statistiques principales
            $totalGroups = $createBaseQuery()->count();
            $activeGroups = $createBaseQuery()->whereNull('deleted_at')->count();
            $deletedGroups = $createBaseQuery()->onlyTrashed()->count();
            
            // Groupes par école (actifs seulement)
            $groupsBySchoolQuery = StudentGroup::whereNull('deleted_at');
            
            if (!$this->isSuperAdmin($currentUser)) {
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($q) {
                        $q->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $groupsBySchoolQuery->where('school_id', $adminSchoolId);
                }
            }
            
            $groupsBySchool = $groupsBySchoolQuery
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
            
            // Groupes récents
            $recentGroups = $createBaseQuery()
                ->where('created_at', '>=', now()->subDays(30))
                ->count();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_groups' => $totalGroups,
                    'active_groups' => $activeGroups,
                    'deleted_groups' => $deletedGroups,
                    'groups_by_school' => $groupsBySchool,
                    'recent_groups_last_30_days' => $recentGroups,
                ],
                'message' => 'Statistiques récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting student group statistics:', [
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