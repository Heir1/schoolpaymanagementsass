<?php

namespace App\Modules\Schools\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schools\Models\SchoolYear;
use App\Modules\Schools\Models\School;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SchoolYearController extends Controller
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
     * Vérifier les permissions pour les années scolaires
     */
    private function checkSchoolYearPermissions(User $adminUser, SchoolYear $schoolYear = null, $schoolId = null)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que les années scolaires de son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        // Si on vérifie une année scolaire spécifique
        if ($schoolYear) {
            return $schoolYear->school_id == $adminSchoolId;
        }

        // Si on vérifie par school_id
        if ($schoolId) {
            return $schoolId == $adminSchoolId;
        }

        return true;
    }

    /**
     * LISTER toutes les années scolaires (avec filtres)
     * GET /api/v1/admin/school-years
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading
            $query = SchoolYear::with(['school', 'createdBy', 'updatedBy']);
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les années scolaires de son école
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
                    $q->where('year_label', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }
            
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
            }
            
            // Filtre par date
            if ($request->has('start_date')) {
                $query->where('start_date', '>=', $request->start_date);
            }
            
            if ($request->has('end_date')) {
                $query->where('end_date', '<=', $request->end_date);
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'start_date');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $schoolYears = $query->paginate($perPage);
            
            // Formater la réponse
            $schoolYears->getCollection()->transform(function ($schoolYear) {
                return [
                    'id' => $schoolYear->id,
                    'year_label' => $schoolYear->year_label,
                    'start_date' => $schoolYear->start_date,
                    'end_date' => $schoolYear->end_date,
                    'is_active' => $schoolYear->is_active,
                    'school' => $schoolYear->school ? [
                        'id' => $schoolYear->school->id,
                        'name' => $schoolYear->school->name,
                    ] : null,
                    'created_by' => $schoolYear->createdBy ? [
                        'id' => $schoolYear->createdBy->id,
                        'name' => $schoolYear->createdBy->full_name,
                    ] : null,
                    'updated_by' => $schoolYear->updatedBy ? [
                        'id' => $schoolYear->updatedBy->id,
                        'name' => $schoolYear->updatedBy->full_name,
                    ] : null,
                    'created_at' => $schoolYear->created_at,
                    'updated_at' => $schoolYear->updated_at,
                    'deleted_at' => $schoolYear->deleted_at,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $schoolYears,
                'message' => 'Liste des années scolaires récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('School year index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des années scolaires',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * VOIR une année scolaire spécifique
     * GET /api/v1/admin/school-years/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $schoolYear = SchoolYear::with(['school', 'createdBy', 'updatedBy'])
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, $schoolYear)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir cette année scolaire.',
                ], 403);
            }
            
            // Formater la réponse
            $formattedSchoolYear = [
                'id' => $schoolYear->id,
                'year_label' => $schoolYear->year_label,
                'start_date' => $schoolYear->start_date,
                'end_date' => $schoolYear->end_date,
                'is_active' => $schoolYear->is_active,
                'school' => $schoolYear->school ? [
                    'id' => $schoolYear->school->id,
                    'name' => $schoolYear->school->name,
                    'type' => $schoolYear->school->type->name ?? null,
                ] : null,
                'created_by' => $schoolYear->createdBy ? [
                    'id' => $schoolYear->createdBy->id,
                    'name' => $schoolYear->createdBy->full_name,
                ] : null,
                'updated_by' => $schoolYear->updatedBy ? [
                    'id' => $schoolYear->updatedBy->id,
                    'name' => $schoolYear->updatedBy->full_name,
                ] : null,
                'created_at' => $schoolYear->created_at,
                'updated_at' => $schoolYear->updated_at,
                'deleted_at' => $schoolYear->deleted_at,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedSchoolYear,
                'message' => 'Année scolaire récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('School year show error:', [
                'error' => $e->getMessage(),
                'school_year_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'année scolaire',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * CRÉER une nouvelle année scolaire
     * POST /api/v1/admin/school-years
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'school_id' => 'required|integer|exists:schools,id',
                'year_label' => 'required|string|max:255',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'is_active' => 'boolean',
            ], [
                'school_id.required' => 'L\'école est requise.',
                'school_id.exists' => 'L\'école sélectionnée n\'existe pas.',
                'year_label.required' => 'Le libellé de l\'année scolaire est requis.',
                'start_date.required' => 'La date de début est requise.',
                'start_date.date' => 'La date de début doit être une date valide.',
                'end_date.required' => 'La date de fin est requise.',
                'end_date.date' => 'La date de fin doit être une date valide.',
                'end_date.after' => 'La date de fin doit être après la date de début.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, null, $request->school_id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour créer une année scolaire pour cette école.',
                ], 403);
            }
            
            // Vérifier les chevauchements de dates
            $overlappingYear = SchoolYear::where('school_id', $request->school_id)
                ->where(function ($query) use ($request) {
                    $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                        ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                        ->orWhere(function ($q) use ($request) {
                            $q->where('start_date', '<=', $request->start_date)
                              ->where('end_date', '>=', $request->end_date);
                        });
                })
                ->whereNull('deleted_at')
                ->first();
            
            if ($overlappingYear) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette période chevauche une année scolaire existante (' . $overlappingYear->year_label . ').',
                ], 422);
            }
            
            // Si on active cette année, désactiver les autres années de la même école
            $isActive = $request->input('is_active', false);
            if ($isActive) {
                SchoolYear::where('school_id', $request->school_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->update(['is_active' => false]);
            }
            
            // Créer l'année scolaire
            $schoolYear = SchoolYear::create([
                'school_id' => $request->school_id,
                'year_label' => $request->year_label,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_active' => $isActive,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            // Charger les relations
            $schoolYear->load(['school', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            // Log pour audit
            Log::info('School year created', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'school_year_id' => $schoolYear->id,
                'school_year_label' => $schoolYear->year_label,
                'school_id' => $schoolYear->school_id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Année scolaire créée avec succès',
                'data' => [
                    'school_year' => [
                        'id' => $schoolYear->id,
                        'year_label' => $schoolYear->year_label,
                        'start_date' => $schoolYear->start_date,
                        'end_date' => $schoolYear->end_date,
                        'is_active' => $schoolYear->is_active,
                        'school' => $schoolYear->school->name,
                        'created_by' => $schoolYear->createdBy->full_name,
                        'created_at' => $schoolYear->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating school year:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de l\'année scolaire: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR une année scolaire
     * PUT /api/v1/admin/school-years/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $schoolYear = SchoolYear::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, $schoolYear)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette année scolaire.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'year_label' => 'sometimes|required|string|max:255',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'sometimes|required|date|after:start_date',
                'is_active' => 'boolean',
            ], [
                'year_label.required' => 'Le libellé de l\'année scolaire est requis.',
                'start_date.required' => 'La date de début est requise.',
                'start_date.date' => 'La date de début doit être une date valide.',
                'end_date.required' => 'La date de fin est requise.',
                'end_date.date' => 'La date de fin doit être une date valide.',
                'end_date.after' => 'La date de fin doit être après la date de début.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier les chevauchements de dates (sauf avec elle-même)
            if ($request->has('start_date') || $request->has('end_date')) {
                $startDate = $request->has('start_date') ? $request->start_date : $schoolYear->start_date;
                $endDate = $request->has('end_date') ? $request->end_date : $schoolYear->end_date;
                
                $overlappingYear = SchoolYear::where('school_id', $schoolYear->school_id)
                    ->where('id', '!=', $id)
                    ->where(function ($query) use ($startDate, $endDate) {
                        $query->whereBetween('start_date', [$startDate, $endDate])
                            ->orWhereBetween('end_date', [$startDate, $endDate])
                            ->orWhere(function ($q) use ($startDate, $endDate) {
                                $q->where('start_date', '<=', $startDate)
                                  ->where('end_date', '>=', $endDate);
                            });
                    })
                    ->whereNull('deleted_at')
                    ->first();
                
                if ($overlappingYear) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cette période chevauche une année scolaire existante (' . $overlappingYear->year_label . ').',
                    ], 422);
                }
            }
            
            // Mettre à jour l'année scolaire
            $updateData = [];
            
            if ($request->has('year_label')) {
                $updateData['year_label'] = $request->year_label;
            }
            
            if ($request->has('start_date')) {
                $updateData['start_date'] = $request->start_date;
            }
            
            if ($request->has('end_date')) {
                $updateData['end_date'] = $request->end_date;
            }
            
            // Gérer l'activation
            if ($request->has('is_active')) {
                $isActive = $request->is_active;
                if ($isActive && !$schoolYear->is_active) {
                    // Désactiver toutes les autres années de la même école
                    SchoolYear::where('school_id', $schoolYear->school_id)
                        ->where('id', '!=', $id)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->update(['is_active' => false]);
                }
                $updateData['is_active'] = $isActive;
            }
            
            // Toujours mettre à jour le champ updated_by
            $updateData['updated_by'] = $currentUser->id;
            
            $schoolYear->update($updateData);
            
            // Recharger les relations
            $schoolYear->load(['school', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('School year updated', [
                'admin_id' => $currentUser->id,
                'school_year_id' => $schoolYear->id,
                'changes' => $updateData,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Année scolaire mise à jour avec succès',
                'data' => [
                    'school_year' => [
                        'id' => $schoolYear->id,
                        'year_label' => $schoolYear->year_label,
                        'start_date' => $schoolYear->start_date,
                        'end_date' => $schoolYear->end_date,
                        'is_active' => $schoolYear->is_active,
                        'school' => $schoolYear->school->name,
                        'updated_by' => $schoolYear->updatedBy->full_name,
                        'updated_at' => $schoolYear->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating school year:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_year_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de l\'année scolaire: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER une année scolaire (soft delete)
     * DELETE /api/v1/admin/school-years/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $schoolYear = SchoolYear::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, $schoolYear)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer cette année scolaire.',
                ], 403);
            }
            
            // Empêcher la suppression d'une année scolaire active
            if ($schoolYear->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer une année scolaire active. Désactivez-la d\'abord.',
                ], 400);
            }
            
            // Soft delete
            $schoolYear->delete();
            
            DB::commit();
            
            Log::info('School year deleted', [
                'admin_id' => $currentUser->id,
                'school_year_id' => $schoolYear->id,
                'school_year_label' => $schoolYear->year_label,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Année scolaire supprimée avec succès',
                'data' => [
                    'school_year_id' => $schoolYear->id,
                    'year_label' => $schoolYear->year_label,
                    'deleted_at' => $schoolYear->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting school year:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_year_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'année scolaire: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER une année scolaire supprimée
     * POST /api/v1/admin/school-years/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $schoolYear = SchoolYear::onlyTrashed()->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, $schoolYear)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour restaurer cette année scolaire.',
                ], 403);
            }
            
            $schoolYear->restore();
            
            DB::commit();
            
            Log::info('School year restored', [
                'admin_id' => $currentUser->id,
                'school_year_id' => $schoolYear->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Année scolaire restaurée avec succès',
                'data' => [
                    'school_year_id' => $schoolYear->id,
                    'year_label' => $schoolYear->year_label,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring school year:', [
                'error' => $e->getMessage(),
                'school_year_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de l\'année scolaire',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ACTIVER/DÉSACTIVER une année scolaire
     * POST /api/v1/admin/school-years/{id}/toggle-active
     */
    public function toggleActive(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $schoolYear = SchoolYear::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, $schoolYear)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette année scolaire.',
                ], 403);
            }
            
            $newStatus = !$schoolYear->is_active;
            
            if ($newStatus) {
                // Désactiver toutes les autres années de la même école
                SchoolYear::where('school_id', $schoolYear->school_id)
                    ->where('id', '!=', $id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->update(['is_active' => false]);
            }
            
            $schoolYear->update([
                'is_active' => $newStatus,
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            Log::info('School year active status toggled', [
                'admin_id' => $currentUser->id,
                'school_year_id' => $schoolYear->id,
                'new_status' => $newStatus,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => $newStatus ? 
                    'Année scolaire activée avec succès' : 
                    'Année scolaire désactivée avec succès',
                'data' => [
                    'school_year_id' => $schoolYear->id,
                    'year_label' => $schoolYear->year_label,
                    'is_active' => $schoolYear->is_active,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error toggling school year active status:', [
                'error' => $e->getMessage(),
                'school_year_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification du statut de l\'année scolaire',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les années scolaires par école
     * GET /api/v1/admin/schools/{schoolId}/school-years
     */
    public function listBySchool(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, null, $schoolId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les années scolaires de cette école.',
                ], 403);
            }
            
            $schoolYears = SchoolYear::where('school_id', $schoolId)
                ->whereNull('deleted_at')
                ->orderBy('start_date', 'desc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $schoolYears,
                'message' => 'Années scolaires récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing school years by school:', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des années scolaires',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RÉCUPÉRER l'année scolaire active d'une école
     * GET /api/v1/admin/schools/{schoolId}/active-school-year
     */
    public function getActiveSchoolYear(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkSchoolYearPermissions($currentUser, null, $schoolId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les années scolaires de cette école.',
                ], 403);
            }
            
            $activeSchoolYear = SchoolYear::where('school_id', $schoolId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            
            if (!$activeSchoolYear) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'Aucune année scolaire active pour cette école.',
                    'data' => null,
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $activeSchoolYear,
                'message' => 'Année scolaire active récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting active school year:', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'année scolaire active',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}