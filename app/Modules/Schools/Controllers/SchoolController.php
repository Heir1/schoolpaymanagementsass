<?php

namespace App\Modules\Schools\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\SchoolType;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    /**
     * Chemin de stockage des logos
     */
    private $logoPath = 'schools/logos';

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
     * Récupérer les règles de validation pour le logo
     */
    private function getLogoValidationRules()
    {
        return [
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048', // 2MB, svg pour les logos vectoriels
        ];
    }

    /**
     * Gérer l'upload d'un fichier logo
     */
    private function handleLogoUpload($file)
    {
        if (!$file->isValid()) {
            throw new \Exception('Le fichier uploadé n\'est pas valide.');
        }
        
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . $extension;
        $filePath = $this->logoPath . '/' . $fileName;
        
        // CORRECTION: Utiliser storeAs pour garantir le bon chemin
        $storedPath = $file->storeAs($this->logoPath, $fileName, 'public');
        
        // Log pour débogage
        Log::info('Logo uploaded', [
            'original_path' => $filePath,
            'stored_path' => $storedPath,
            'file_name' => $fileName,
            'disk' => 'public'
        ]);
        
        return $storedPath;
    }

    /**
     * Supprimer un fichier logo
     */
    private function deleteLogoFile($logoPath)
    {
        if ($logoPath && Storage::disk('public')->exists($logoPath)) {
            Storage::disk('public')->delete($logoPath);
            return true;
        }
        return false;
    }

    // /**
    //  * Obtenir l'URL complète du logo
    //  */
    // private function getLogoUrl($logoPath)
    // {
    //     if (!$logoPath) {
    //         return null;
    //     }
    //     return Storage::disk('public')->url($logoPath);
    // }

    /**
     * LISTER toutes les écoles (avec filtres et écoles supprimées)
     * GET /api/v1/admin/schools
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading ET avec les soft deleted
            $query = School::with(['type', 'createdBy', 'updatedBy', 'schoolYears'])
                        ->withTrashed();
            
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
            
            // Filtre par statut (pour les superadmins)
            if ($request->has('status') && $this->isSuperAdmin($currentUser)) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
                // 'all' est le comportement par défaut (withTrashed)
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $schools = $query->paginate($perPage);
            
            // Formater la réponse avec indicateur de suppression
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
                    'logo_url' => $school->logo_path ? $this->getLogoUrl($school->logo_path) : null,
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
                    'is_deleted' => !is_null($school->deleted_at), // Indicateur de suppression
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
     * VOIR une école spécifique (inclut les supprimées)
     * GET /api/v1/admin/schools/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Rechercher l'école même si elle est supprimée (avec withTrashed)
            $school = School::with(['type', 'createdBy', 'updatedBy', 'schoolYears'])
                ->withTrashed()
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
                'logo_url' => $school->logo_path ? $this->getLogoUrl($school->logo_path) : null,
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
                'is_deleted' => !is_null($school->deleted_at), // Indicateur de suppression
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
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent créer des écoles.',
                ], 403);
            }
            
            // Règles de validation combinées
            $validationRules = array_merge([
                'name' => 'required|string|max:255|unique:schools,name',
                'type_id' => 'required|integer|exists:school_types,id',
                'address' => 'nullable|string|max:500',
                'phone' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^\+?[1-9]\d{1,14}$/',
                ],
            ], $this->getLogoValidationRules());

            $validator = Validator::make($request->all(), $validationRules, [
                'name.required' => 'Le nom de l\'école est requis.',
                'name.unique' => 'Ce nom d\'école est déjà utilisé.',
                'type_id.required' => 'Le type d\'école est requis.',
                'type_id.exists' => 'Le type d\'école sélectionné n\'existe pas.',
                'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
                'logo.image' => 'Le fichier doit être une image.',
                'logo.max' => 'L\'image ne doit pas dépasser 2MB.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Gérer le logo AVANT de créer l'école
            $logoPath = null;
            if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
                $logoPath = $this->handleLogoUpload($request->file('logo'));
                Log::info('Logo path to save in DB:', ['logo_path' => $logoPath]);
            }
            
            // Créer l'école avec le logo_path
            $schoolData = [
                'name' => $request->name,
                'type_id' => $request->type_id,
                'address' => $request->address,
                'phone' => $request->phone,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ];

            // CORRECTION: Toujours inclure logo_path, même si null
            $schoolData['logo_path'] = $logoPath;

            $school = School::create($schoolData);
            
            // Charger les relations
            $school->load(['type', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('School created', [
                'admin_id' => $currentUser->id,
                'school_id' => $school->id,
                'school_name' => $school->name,
                'logo_path' => $school->logo_path, // Log du champ en base
                'has_logo' => !is_null($school->logo_path),
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
                        'logo_url' => $school->logo_path ? Storage::url($school->logo_path) : null,
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
                'request_data' => $request->except(['logo']) // Exclure le fichier binaire
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
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette école.',
                ], 403);
            }
            
            // CORRECTION: Récupérer l'ancien logo_path avant tout changement
            $oldLogoPath = $school->logo_path;
            
            // Règles de validation combinées
            $validationRules = array_merge([
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
            ], $this->getLogoValidationRules());

            $validator = Validator::make($request->all(), $validationRules, [
                'name.unique' => 'Ce nom d\'école est déjà utilisé.',
                'type_id.exists' => 'Le type d\'école sélectionné n\'existe pas.',
                'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
                'logo.image' => 'Le fichier doit être une image.',
                'logo.max' => 'L\'image ne doit pas dépasser 2MB.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Gérer le logo
            $newLogoPath = null;
            if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
                $newLogoPath = $this->handleLogoUpload($request->file('logo'));
            }
            
            // Mettre à jour l'école
            $updateData = [
                'updated_by' => $currentUser->id,
            ];
            
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
            
            // CORRECTION: Toujours mettre à jour logo_path si nouveau logo
            if ($newLogoPath) {
                $updateData['logo_path'] = $newLogoPath;
            }
            
            $school->update($updateData);
            
            // CORRECTION: Supprimer l'ancien logo APRÈS la mise à jour réussie
            if ($newLogoPath && $oldLogoPath) {
                $this->deleteLogoFile($oldLogoPath);
                Log::info('Old logo deleted after successful update', [
                    'old_logo_path' => $oldLogoPath,
                    'school_id' => $school->id
                ]);
            }
            
            // Recharger les relations
            $school->load(['type', 'createdBy', 'updatedBy']);
            
            DB::commit();
            
            Log::info('School updated', [
                'admin_id' => $currentUser->id,
                'school_id' => $school->id,
                'logo_updated' => !is_null($newLogoPath),
                'old_logo_deleted' => ($newLogoPath && $oldLogoPath),
                'new_logo_path' => $school->logo_path,
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
                        'logo_url' => $school->logo_path ? Storage::url($school->logo_path) : null,
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
                'request' => $request->except(['logo'])
            ]);
            
            // CORRECTION: Supprimer le nouveau logo si la transaction a échoué
            if (isset($newLogoPath)) {
                $this->deleteLogoFile($newLogoPath);
                Log::info('New logo deleted after failed update', [
                    'new_logo_path' => $newLogoPath
                ]);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de l\'école: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * UPLOADER un logo pour une école
     */
    public function uploadLogo(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette école.',
                ], 403);
            }
            
            // CORRECTION: Récupérer l'ancien logo avant tout
            $oldLogoPath = $school->logo_path;
            
            // Validation
            $validationRules = $this->getLogoValidationRules();
            $validationRules['logo'] = [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg,gif,webp,svg',
                'max:2048',
            ];
            
            $validator = Validator::make($request->all(), $validationRules, [
                'logo.required' => 'Veuillez sélectionner un fichier.',
                'logo.image' => 'Le fichier doit être une image.',
                'logo.mimes' => 'L\'image doit être au format jpeg, png, jpg, gif, webp ou svg.',
                'logo.max' => 'L\'image ne doit pas dépasser 2MB.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Récupérer et valider le fichier
            $logoFile = $request->file('logo');
            
            if (!$logoFile->isValid()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le fichier uploadé n\'est pas valide.',
                ], 422);
            }
            
            // Uploader le nouveau logo
            $newLogoPath = $this->handleLogoUpload($logoFile);
            
            // Mettre à jour l'école
            $school->update([
                'logo_path' => $newLogoPath,
                'updated_by' => $currentUser->id,
            ]);
            
            // CORRECTION: Supprimer l'ancien logo APRÈS la mise à jour réussie
            if ($oldLogoPath) {
                $this->deleteLogoFile($oldLogoPath);
                Log::info('Old logo deleted after uploadLogo', [
                    'old_logo_path' => $oldLogoPath,
                    'school_id' => $school->id
                ]);
            }
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Logo téléchargé avec succès',
                'data' => [
                    'school_id' => $school->id,
                    'name' => $school->name,
                    'logo_url' => Storage::url($newLogoPath),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // CORRECTION: Supprimer le nouveau logo si la transaction a échoué
            if (isset($newLogoPath)) {
                $this->deleteLogoFile($newLogoPath);
            }
            
            Log::error('Error uploading school logo:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_id' => $id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du logo: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * SUPPRIMER le logo d'une école
     */
    public function deleteLogo(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cette école.',
                ], 403);
            }
            
            if (!$school->logo_path) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette école n\'a pas de logo.',
                ], 404);
            }
            
            // CORRECTION: Récupérer le chemin avant de le supprimer de la base
            $logoPathToDelete = $school->logo_path;
            
            // Mettre à jour l'école (mettre logo_path à null)
            $school->update([
                'logo_path' => null,
                'updated_by' => $currentUser->id,
            ]);
            
            // CORRECTION: Supprimer le fichier APRÈS la mise à jour de la base
            $deleted = $this->deleteLogoFile($logoPathToDelete);
            
            if (!$deleted) {
                Log::warning('Logo file not found during deletion', [
                    'logo_path' => $logoPathToDelete,
                    'school_id' => $school->id
                ]);
            }
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Logo supprimé avec succès',
                'data' => [
                    'school_id' => $school->id,
                    'name' => $school->name,
                    'file_deleted' => $deleted,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting school logo:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_id' => $id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du logo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * SUPPRIMER une école (soft delete) - NE PAS SUPPRIMER LE LOGO
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $school = School::findOrFail($id);
            
            if (!$this->checkSchoolPermissions($currentUser, $school)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer cette école.',
                ], 403);
            }
            
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent supprimer des écoles.',
                ], 403);
            }
            
            $hasActiveSchoolYears = $school->schoolYears()
                ->where('is_active', true)
                ->exists();
            
            if ($hasActiveSchoolYears) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette école car elle a des années scolaires actives.',
                ], 400);
            }
            
            // CORRECTION: NE PAS supprimer le logo lors du soft delete
            // Le logo est conservé pour une éventuelle restauration
            Log::info('School soft deleted, logo preserved', [
                'school_id' => $school->id,
                'logo_path' => $school->logo_path,
                'preserved_for_restore' => true,
            ]);
            
            // Soft delete seulement
            $school->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'École supprimée avec succès',
                'data' => [
                    'school_id' => $school->id,
                    'name' => $school->name,
                    'deleted_at' => $school->deleted_at,
                    'can_be_restored' => true,
                    'logo_preserved' => true, // Indiquer que le logo est conservé
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
            
            // Rechercher uniquement dans les écoles supprimées
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
     * STATISTIQUES des écoles (inclut les supprimées pour les superadmins)
     * GET /api/v1/admin/schools/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Base query selon les permissions
            $baseQuery = School::query();
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $baseQuery->where('id', $adminSchoolId);
                } else {
                    // Si pas d'école assignée, retourner des statistiques vides
                    return response()->json([
                        'status' => 'success',
                        'data' => [
                            'total_schools' => 0,
                            'active_schools' => 0,
                            'deleted_schools' => 0,
                            'schools_by_type' => [],
                            'recent_schools_last_30_days' => 0,
                        ],
                        'message' => 'Statistiques récupérées avec succès',
                    ]);
                }
            }
            
            // Pour les statistiques totales, inclure les supprimées si superadmin
            $totalQuery = clone $baseQuery;
            if ($this->isSuperAdmin($currentUser)) {
                $totalQuery->withTrashed();
            }
            $totalSchools = $totalQuery->count();
            
            // Écoles actives (non supprimées)
            $activeSchools = $baseQuery->whereNull('deleted_at')->count();
            
            // Écoles supprimées
            $deletedQuery = School::query();
            
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que son école (même supprimée)
                if (isset($adminSchoolId)) {
                    $deletedQuery->where('id', $adminSchoolId);
                }
            }
            // Pour superadmin, pas besoin de filtre
            
            $deletedSchools = $deletedQuery->onlyTrashed()->count();
            
            // Statistiques par type (uniquement les actives)
            $schoolsByTypeQuery = School::select('type_id')
                ->selectRaw('COUNT(*) as count')
                ->whereNull('deleted_at');
                
            if (!$this->isSuperAdmin($currentUser) && isset($adminSchoolId)) {
                $schoolsByTypeQuery->where('id', $adminSchoolId);
            }
            
            $schoolsByType = $schoolsByTypeQuery
                ->groupBy('type_id')
                ->with('type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type_name' => $item->type->name ?? 'Inconnu',
                        'count' => $item->count,
                    ];
                });
            
            // Écoles créées récemment (30 derniers jours, actives seulement)
            $recentSchoolsQuery = School::where('created_at', '>=', now()->subDays(30))
                ->whereNull('deleted_at');
                
            if (!$this->isSuperAdmin($currentUser) && isset($adminSchoolId)) {
                $recentSchoolsQuery->where('id', $adminSchoolId);
            }
            
            $recentSchools = $recentSchoolsQuery->count();
            
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


    /**
     * Obtenir l'URL complète du logo
    */
    private function getLogoUrl($logoPath)
    {
        if (!$logoPath) {
            return null;
        }
        // CORRECTION: Utiliser Storage::url() correctement
        return Storage::url($logoPath);
    }
}