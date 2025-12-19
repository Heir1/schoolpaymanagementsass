<?php

namespace App\Modules\Schools\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\SchoolType;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
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
     * Vérifier les permissions d'administration
     */
    private function checkSchoolPermissions(User $adminUser, School $school = null)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        // Si on vérifie une école spécifique
        if ($school) {
            return $school->id == $adminSchoolId;
        }

        return true;
    }

    /**
     * LISTER toutes les écoles (avec filtres)
     * GET /api/v1/admin/schools
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading
            $query = School::with(['type', 'createdBy', 'updatedBy', 'schoolYears']);
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->where('id', $adminSchoolId);
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
                      ->orWhere('address', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('type_id')) {
                $query->where('type_id', $request->type_id);
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
            
            $schools = $query->paginate($perPage);
            
            // Formater la réponse
            $schools->getCollection()->transform(function ($school) {
                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'type' => $school->type ? [
                        'id' => $school->type->id,
                        'name' => $school->type->name,
                    ] : null,
                    'address' => $school->address,
                    'phone' => $school->phone,
                    'school_years_count' => $school->schoolYears->count(),
                    'created_by' => $school->createdBy ? [
                        'id' => $school->createdBy->id,
                        'name' => $school->createdBy->full_name,
                    ] : null,
                    'updated_by' => $school->updatedBy ? [
                        'id' => $school->updatedBy->id,
                        'name' => $school->updatedBy->full_name,
                    ] : null,
                    'created_at' => $school->created_at,
                    'updated_at' => $school->updated_at,
                    'deleted_at' => $school->deleted_at,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $schools,
                'message' => 'Liste des écoles récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('School index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des écoles',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * VOIR une école spécifique
     * GET /api/v1/admin/schools/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $school = School::with(['type', 'createdBy', 'updatedBy', 'schoolYears'])
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir cette école.',
                ], 403);
            }
            
            // Formater la réponse
            $formattedSchool = [
                'id' => $school->id,
                'name' => $school->name,
                'type' => $school->type ? [
                    'id' => $school->type->id,
                    'name' => $school->type->name,
                ] : null,
                'address' => $school->address,
                'phone' => $school->phone,
                'created_by' => $school->createdBy ? [
                    'id' => $school->createdBy->id,
                    'name' => $school->createdBy->full_name,
                ] : null,
                'updated_by' => $school->updatedBy ? [
                    'id' => $school->updatedBy->id,
                    'name' => $school->updatedBy->full_name,
                ] : null,
                'school_years' => $school->schoolYears->map(function ($schoolYear) {
                    return [
                        'id' => $schoolYear->id,
                        'name' => $schoolYear->name,
                        'start_date' => $schoolYear->start_date,
                        'end_date' => $schoolYear->end_date,
                        'is_active' => $schoolYear->is_active,
                    ];
                }),
                'created_at' => $school->created_at,
                'updated_at' => $school->updated_at,
                'deleted_at' => $school->deleted_at,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedSchool,
                'message' => 'École récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('School show error:', [
                'error' => $e->getMessage(),
                'school_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'école',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * CRÉER une nouvelle école
     * POST /api/v1/admin/schools
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions (seul superadmin peut créer des écoles)
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent créer des écoles.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:schools,name',
                'type_id' => 'required|integer|exists:school_types,id',
                'address' => 'nullable|string|max:500',
                'phone' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^\+?[1-9]\d{1,14}$/',
                ],
            ], [
                'name.required' => 'Le nom de l\'école est requis.',
                'name.unique' => 'Ce nom d\'école est déjà utilisé.',
                'type_id.required' => 'Le type d\'école est requis.',
                'type_id.exists' => 'Le type d\'école sélectionné n\'existe pas.',
                'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Créer l'école
            $school = School::create([
                'name' => $request->name,
                'type_id' => $request->type_id,
                'address' => $request->address,
                'phone' => $request->phone,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Charger les relations
            $school->load(['type', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            // Log pour audit
            Log::info('School created', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'school_id' => $school->id,
                'school_name' => $school->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'École créée avec succès',
                'data' => [
                    'school' => [
                        'id' => $school->id,
                        'name' => $school->name,
                        'type' => $school->type->name,
                        'address' => $school->address,
                        'phone' => $school->phone,
                        'created_by' => $school->createdBy->full_name,
                        'created_at' => $school->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating school:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de l\'école: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR une école
     * PUT /api/v1/admin/schools/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette école.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('schools', 'name')->ignore($id),
                ],
                'type_id' => 'sometimes|required|integer|exists:school_types,id',
                'address' => 'nullable|string|max:500',
                'phone' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^\+?[1-9]\d{1,14}$/',
                ],
            ], [
                'name.unique' => 'Ce nom d\'école est déjà utilisé.',
                'type_id.exists' => 'Le type d\'école sélectionné n\'existe pas.',
                'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour l'école
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('type_id')) {
                $updateData['type_id'] = $request->type_id;
            }
            
            if ($request->has('address')) {
                $updateData['address'] = $request->address;
            }
            
            if ($request->has('phone')) {
                $updateData['phone'] = $request->phone;
            }
            
            // Toujours mettre à jour le champ updated_by
            $updateData['updated_by'] = $currentUser->id;
            
            $school->update($updateData);
            
            // Recharger les relations
            $school->load(['type', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('School updated', [
                'admin_id' => $currentUser->id,
                'school_id' => $school->id,
                'changes' => $updateData,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'École mise à jour avec succès',
                'data' => [
                    'school' => [
                        'id' => $school->id,
                        'name' => $school->name,
                        'type' => $school->type->name,
                        'address' => $school->address,
                        'phone' => $school->phone,
                        'updated_by' => $school->updatedBy->full_name,
                        'updated_at' => $school->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating school:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de l\'école: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER une école (soft delete)
     * DELETE /api/v1/admin/schools/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer cette école.',
                ], 403);
            }
            
            // Seul le superadmin peut supprimer définitivement les écoles
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent supprimer des écoles.',
                ], 403);
            }
            
            // Vérifier si l'école a des années scolaires actives
            $hasActiveSchoolYears = $school->schoolYears()
                ->where('is_active', true)
                ->exists();
            
            if ($hasActiveSchoolYears) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette école car elle a des années scolaires actives.',
                ], 400);
            }
            
            // Soft delete
            $school->delete();
            
            DB::commit();
            
            Log::info('School deleted', [
                'admin_id' => $currentUser->id,
                'school_id' => $school->id,
                'school_name' => $school->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'École supprimée avec succès',
                'data' => [
                    'school_id' => $school->id,
                    'name' => $school->name,
                    'deleted_at' => $school->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting school:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'école: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER une école supprimée
     * POST /api/v1/admin/schools/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Seul le superadmin peut restaurer
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent restaurer des écoles.',
                ], 403);
            }
            
            $school = School::onlyTrashed()->findOrFail($id);
            $school->restore();
            
            DB::commit();
            
            Log::info('School restored', [
                'admin_id' => $currentUser->id,
                'school_id' => $school->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'École restaurée avec succès',
                'data' => [
                    'school_id' => $school->id,
                    'name' => $school->name,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring school:', [
                'error' => $e->getMessage(),
                'school_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de l\'école',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les types d'écoles
     * GET /api/v1/admin/school-types
     */
    public function listSchoolTypes(Request $request)
    {
        try {
            $schoolTypes = SchoolType::all();
            
            return response()->json([
                'status' => 'success',
                'data' => $schoolTypes,
                'message' => 'Types d\'écoles récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing school types:', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des types d\'écoles',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STATISTIQUES des écoles
     * GET /api/v1/admin/schools/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = School::query();
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->where('id', $adminSchoolId);
                }
            }
            
            $totalSchools = $query->count();
            $activeSchools = $query->whereNull('deleted_at')->count();
            $deletedSchools = $query->onlyTrashed()->count();
            
            // Statistiques par type
            $schoolsByType = School::select('type_id')
                ->selectRaw('COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupBy('type_id')
                ->with('type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type_name' => $item->type->name ?? 'Inconnu',
                        'count' => $item->count,
                    ];
                });
            
            // Écoles créées récemment (30 derniers jours)
            $recentSchools = School::where('created_at', '>=', now()->subDays(30))
                ->count();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_schools' => $totalSchools,
                    'active_schools' => $activeSchools,
                    'deleted_schools' => $deletedSchools,
                    'schools_by_type' => $schoolsByType,
                    'recent_schools_last_30_days' => $recentSchools,
                ],
                'message' => 'Statistiques récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting school statistics:', [
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