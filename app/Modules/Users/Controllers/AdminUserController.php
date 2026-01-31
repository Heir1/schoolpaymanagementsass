<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\User;
use App\Modules\Shared\Models\Pivots\UserRole;
use App\Modules\Users\Models\ParentModel;
use App\Modules\Users\Models\Role;
use App\Modules\Schools\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;


class AdminUserController extends Controller
{
    /**
     * Chemin de stockage des avatars
     */
    private $avatarPath = 'avatars';

    /**
     * Générer un mot de passe sécurisé
     */
    private function generatePassword($length = 12)
    {
        $uppercase = Str::upper(Str::random(2));
        $lowercase = Str::lower(Str::random(3));
        $numbers = rand(100, 999);
        $symbols = '@$!%*#?&';
        $symbol = $symbols[rand(0, strlen($symbols) - 1)];
        
        $password = $uppercase . $lowercase . $numbers . $symbol;
        return str_shuffle($password);
    }


    /**
     * Vérifier si un utilisateur est super admin
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
    private function checkAdminPermissions(User $adminUser, $targetUser = null)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que les utilisateurs de son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        // Si on vérifie un utilisateur cible
        if ($targetUser) {
            $userSchoolId = $targetUser->userRoles()
                ->where('school_id', $adminSchoolId)
                ->value('school_id');
            
            return $userSchoolId == $adminSchoolId;
        }

        return true;
    }


    /**
     * LISTER tous les utilisateurs (avec filtres)
     * GET /api/v1/admin/users
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête avec eager loading ET avec les soft deleted
            // AJOUT: withTrashed() pour inclure les utilisateurs supprimés
            $query = User::with(['userRoles.role', 'userRoles.school', 'parentProfile'])
                        ->withTrashed();
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les utilisateurs de son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->whereHas('userRoles', function (Builder $query) use ($adminSchoolId) {
                        $query->where('school_id', $adminSchoolId);
                    });
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
                    $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone_or_email', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('role_id')) {
                $query->whereHas('userRoles', function (Builder $query) use ($request) {
                    $query->where('role_id', $request->role_id);
                });
            }
            
            if ($request->has('school_id')) {
                $query->whereHas('userRoles', function (Builder $query) use ($request) {
                    $query->where('school_id', $request->school_id);
                });
            }
            
            // MODIFICATION: Simplification du filtre status
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
                // Si 'all' ou aucune valeur spécifique, on garde avecTrashed()
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_dir', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $users = $query->paginate($perPage);
            
            // Formater la réponse
            $users->getCollection()->transform(function ($user) {
                return [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'phone_or_email' => $user->phone_or_email,
                    'avatar_url' => $user->avatar_url,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'deleted_at' => $user->deleted_at, // AJOUT: Inclure la date de suppression
                    'is_deleted' => !is_null($user->deleted_at), // AJOUT: Flag pour savoir si supprimé
                    'roles' => $user->userRoles->map(function ($userRole) {
                        return [
                            'id' => $userRole->id,
                            'role_id' => $userRole->role_id,
                            'role_name' => $userRole->role->name ?? null,
                            'school_id' => $userRole->school_id,
                            'school_name' => $userRole->school->name ?? null,
                        ];
                    }),
                    'is_parent' => $user->parentProfile ? true : false,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $users,
                'message' => 'Liste des utilisateurs récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin user index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des utilisateurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * Gérer le téléchargement d'avatar
     */
    private function handleAvatarUpload($file, $existingAvatar = null)
    {
        try {
            // Supprimer l'ancien avatar si existe
            if ($existingAvatar) {
                $this->deleteAvatarFile($existingAvatar);
            }
            
            // Générer un nom de fichier unique
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            
            // Chemin de stockage
            $filePath = $this->avatarPath . '/' . $fileName;
            
            // Stocker le fichier
            Storage::disk('public')->put($filePath, File::get($file));
            
            return $filePath;
            
        } catch (\Exception $e) {
            Log::error('Avatar upload error:', ['error' => $e->getMessage()]);
            throw new \Exception('Erreur lors du téléchargement de l\'avatar: ' . $e->getMessage());
        }
    }

    /**
     * Supprimer un fichier avatar
     */
    private function deleteAvatarFile($avatarPath)
    {
        try {
            if ($avatarPath && Storage::disk('public')->exists($avatarPath)) {
                Storage::disk('public')->delete($avatarPath);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Avatar delete error:', ['error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * Obtenir l'URL complète d'un avatar
     */
    private function getAvatarUrl($avatarPath)
    {
        if (!$avatarPath) {
            return null;
        }
        
        return Storage::disk('public')->url($avatarPath);
    }

    /**
     * Validation des règles pour l'avatar
     */
    private function getAvatarValidationRules()
    {
        return [
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
        ];
    }

    /**
     * CRÉER un nouvel utilisateur avec rôle et avatar
     * POST /api/v1/admin/users
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Règles de validation combinées
            $validationRules = array_merge([
                'full_name' => 'required|string|max:255',
                'phone_or_email' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::when(
                        filter_var($request->phone_or_email, FILTER_VALIDATE_EMAIL),
                        ['email'],
                        ['regex:/^\+?[1-9]\d{1,14}$/']
                    ),
                    'unique:users,phone_or_email'
                ],
                'role_id' => [
                    'required',
                    'integer',
                    Rule::exists('roles', 'id'),
                    function ($attribute, $value, $fail) use ($currentUser) {
                        $role = Role::find($value);
                        if ($role && in_array($role->name, ['superadmin', 'system_admin']) && !$this->isSuperAdmin($currentUser)) {
                            $fail('Seuls les superadministrateurs peuvent créer des rôles système.');
                        }
                    }
                ],
                'school_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schools', 'id'),
                    function ($attribute, $value, $fail) use ($request) {
                        if ($value) {
                            $role = Role::find($request->role_id);
                            if ($role && in_array($role->name, ['superadmin', 'system_admin'])) {
                                $fail('Les rôles système ne peuvent pas être associés à une école.');
                            }
                        } elseif (!$value) {
                            $role = Role::find($request->role_id);
                            if ($role && in_array($role->name, ['teacher', 'school_admin', 'school_staff', 'accountant'])) {
                                $fail('Ce rôle nécessite une école.');
                            }
                        }
                    }
                ],
                'send_credentials' => 'boolean',
            ], $this->getAvatarValidationRules());

            $validator = Validator::make($request->all(), $validationRules, [
                'phone_or_email.unique' => 'Cet email/téléphone est déjà utilisé.',
                'phone_or_email.email' => 'Veuillez fournir un email valide.',
                'phone_or_email.regex' => 'Veuillez fournir un numéro de téléphone valide.',
                'avatar.image' => 'Le fichier doit être une image.',
                'avatar.max' => 'L\'image ne doit pas dépasser 2MB.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Vérifier les permissions pour l'école
            if ($request->school_id && !$this->isSuperAdmin($currentUser)) {
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId != $request->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous ne pouvez créer des utilisateurs que pour votre école.',
                    ], 403);
                }
            }

            // Générer un mot de passe initial
            $initialPassword = $this->generatePassword();
            $plainPassword = $initialPassword;

            // Gérer l'avatar
            $avatarPath = null;
            if ($request->hasFile('avatar')) {
                $avatarPath = $this->handleAvatarUpload($request->file('avatar'));
            }

            // Créer l'utilisateur
            $userData = [
                'full_name' => $request->full_name,
                'phone_or_email' => $request->phone_or_email,
                'password' => Hash::make($initialPassword),
                'confirm_password' => Hash::make($initialPassword),
                'remember_token' => Str::random(60),
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ];

            if ($avatarPath) {
                $userData['avatar_path'] = $avatarPath; // Stocker le chemin, pas l'URL
            }

            $user = User::create($userData);

            // Assigner le rôle
            $userRole = UserRole::create([
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'school_id' => $request->school_id,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);

            // Si c'est un parent, créer aussi l'entrée dans la table parents
            $role = Role::find($request->role_id);
            if ($role && $role->name === 'parent') {
                ParentModel::create([
                    'user_id' => $user->id,
                ]);
            }

            DB::commit();

            // Log pour audit
            Log::info('Admin created user', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'user_id' => $user->id,
                'user_name' => $user->full_name,
                'role_id' => $request->role_id,
                'school_id' => $request->school_id,
            ]);

            // Préparer la réponse
            $response = [
                'status' => 'success',
                'message' => 'Utilisateur créé avec succès',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'role' => $role->name,
                        'school_id' => $request->school_id,
                        'avatar_url' => $avatarPath ? $this->getAvatarUrl($avatarPath) : null,
                    ],
                    'initial_password' => $plainPassword,
                    'instructions' => 'Communiquez ce mot de passe à l\'utilisateur. Il devra le changer à sa première connexion.',
                ],
            ];

            // Si demandé, "envoyer" les identifiants
            if ($request->input('send_credentials', false)) {
                $response['data']['credentials_sent'] = true;
                $response['data']['sent_to'] = $user->phone_or_email;
                $response['data']['test_mode_note'] = 'En production, les identifiants seraient envoyés par email/SMS.';
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating admin user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de l\'utilisateur: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR un utilisateur avec avatar
     * PUT /api/v1/admin/users/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $user = User::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cet utilisateur.',
                ], 403);
            }
            
            // Règles de validation combinées
            $validationRules = array_merge([
                'full_name' => 'sometimes|required|string|max:255',
                'phone_or_email' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::when(
                        filter_var($request->phone_or_email, FILTER_VALIDATE_EMAIL),
                        ['email'],
                        ['regex:/^\+?[1-9]\d{1,14}$/']
                    ),
                    Rule::unique('users', 'phone_or_email')->ignore($id),
                ],
                'role_id' => [
                    'sometimes',
                    'required',
                    'integer',
                    Rule::exists('roles', 'id'),
                    function ($attribute, $value, $fail) use ($currentUser, $user) {
                        $role = Role::find($value);
                        if ($role && in_array($role->name, ['superadmin', 'system_admin']) && !$this->isSuperAdmin($currentUser)) {
                            $fail('Seuls les superadministrateurs peuvent attribuer des rôles système.');
                        }
                        
                        if ($user->id === $currentUser->id && in_array($role->name, ['superadmin', 'school_admin'])) {
                            $fail('Vous ne pouvez pas modifier votre propre rôle administrateur.');
                        }
                    }
                ],
                'school_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schools', 'id'),
                    function ($attribute, $value, $fail) use ($request, $user) {
                        if ($request->has('role_id')) {
                            $roleId = $request->role_id;
                        } else {
                            $userRole = $user->userRoles()->first();
                            $roleId = $userRole ? $userRole->role_id : null;
                        }
                        
                        if ($roleId) {
                            $role = Role::find($roleId);
                            if ($role && in_array($role->name, ['superadmin', 'system_admin']) && $value) {
                                $fail('Les rôles système ne peuvent pas être associés à une école.');
                            }
                        }
                    }
                ],
            ], $this->getAvatarValidationRules());

            $validator = Validator::make($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Mettre à jour les informations de base de l'utilisateur
            $updateData = [];
            if ($request->has('full_name')) {
                $updateData['full_name'] = $request->full_name;
            }
            if ($request->has('phone_or_email')) {
                $updateData['phone_or_email'] = $request->phone_or_email;
            }
            
            // Gérer l'avatar
            if ($request->hasFile('avatar')) {
                $avatarPath = $this->handleAvatarUpload($request->file('avatar'), $user->avatar_path);
                $updateData['avatar_path'] = $avatarPath;
            }
            
            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Mettre à jour le rôle si demandé
            if ($request->has('role_id') || $request->has('school_id')) {
                $userRole = $user->userRoles()->first();
                
                if ($userRole) {
                    $updateRoleData = [
                        'updated_by' => $currentUser->id,
                    ];
                    
                    if ($request->has('role_id')) {
                        $updateRoleData['role_id'] = $request->role_id;
                        
                        // Gérer le profil parent
                        $newRole = Role::find($request->role_id);
                        if ($newRole->name === 'parent' && !$user->parentProfile) {
                            ParentModel::create(['user_id' => $user->id]);
                        } elseif ($newRole->name !== 'parent' && $user->parentProfile) {
                            $user->parentProfile->delete();
                        }
                    }
                    
                    if ($request->has('school_id')) {
                        $updateRoleData['school_id'] = $request->school_id;
                    }
                    
                    $userRole->update($updateRoleData);
                } else {
                    UserRole::create([
                        'user_id' => $user->id,
                        'role_id' => $request->role_id ?? 7,
                        'school_id' => $request->school_id,
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                }
            }

            DB::commit();

            // Recharger les relations
            $user->load(['userRoles.role', 'userRoles.school', 'parentProfile']);

            return response()->json([
                'status' => 'success',
                'message' => 'Utilisateur mis à jour avec succès',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'avatar_url' => $user->avatar_path ? $this->getAvatarUrl($user->avatar_path) : null,
                        'roles' => $user->userRoles->map(function ($userRole) {
                            return [
                                'role_name' => $userRole->role->name ?? null,
                                'school_name' => $userRole->school->name ?? null,
                            ];
                        }),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $id,
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de l\'utilisateur: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER un utilisateur avec son avatar
     * DELETE /api/v1/admin/users/{id}
    */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $user = User::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer cet utilisateur.',
                ], 403);
            }
            
            // Empêcher un utilisateur de se supprimer lui-même
            if ($user->id === $currentUser->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
                ], 403);
            }
            
            // Empêcher la suppression d'un super admin
            if ($this->isSuperAdmin($user) && !$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent supprimer d\'autres superadministrateurs.',
                ], 403);
            }
            
            // Supprimer l'avatar
            if ($user->avatar_path) {
                $this->deleteAvatarFile($user->avatar_path);
            }
            
            // Soft delete (désactivation)
            $user->delete();
            
            DB::commit();
            
            Log::info('User deactivated by admin', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'user_id' => $user->id,
                'user_name' => $user->full_name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Utilisateur désactivé avec succès',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                    'deleted_at' => $user->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'utilisateur: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER uniquement l'avatar d'un utilisateur
     * DELETE /api/v1/admin/users/{id}/avatar
    */
    public function removeAvatar(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $user = User::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cet utilisateur.',
                ], 403);
            }
            
            if (!$user->avatar_path) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'Cet utilisateur n\'a pas d\'avatar.',
                ]);
            }
            
            // Supprimer le fichier
            $this->deleteAvatarFile($user->avatar_path);
            
            // Mettre à jour l'utilisateur
            $user->update(['avatar_path' => null]);
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Avatar supprimé avec succès',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error removing avatar:', [
                'error' => $e->getMessage(),
                'user_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'avatar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Télécharger un avatar via FormData (fichier)
     * POST /api/v1/admin/users/{id}/avatar
    */
    public function uploadAvatar(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $user = User::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier cet utilisateur.',
                ], 403);
            }
            
            // Utiliser les mêmes règles de validation que dans store()
            $validationRules = $this->getAvatarValidationRules();
            $validationRules['avatar'] = [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg,gif,webp',
                'max:2048', // 2MB comme dans store()
            ];
            
            $validator = Validator::make($request->all(), $validationRules, [
                'avatar.required' => 'Veuillez sélectionner un fichier.',
                'avatar.image' => 'Le fichier doit être une image.',
                'avatar.mimes' => 'L\'image doit être au format jpeg, png, jpg, gif ou webp.',
                'avatar.max' => 'L\'image ne doit pas dépasser 2MB.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Récupérer le fichier
            $avatarFile = $request->file('avatar');
            
            if (!$avatarFile->isValid()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le fichier uploadé n\'est pas valide.',
                ], 422);
            }
            
            // Utiliser la même méthode que dans store() pour gérer l'upload
            $avatarPath = $this->handleAvatarUpload($avatarFile);
            
            // Supprimer l'ancien avatar
            if ($user->avatar_path) {
                $this->deleteAvatarFile($user->avatar_path);
            }
            
            // Mettre à jour l'utilisateur
            $user->update(['avatar_path' => $avatarPath]);
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Avatar téléchargé avec succès',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                    'avatar_url' => $this->getAvatarUrl($avatarPath),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error uploading avatar:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $id,
                'file_name' => $request->file('avatar') ? $request->file('avatar')->getClientOriginalName() : 'none'
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement de l\'avatar: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ... [Les autres méthodes restent inchangées] ...

    /**
     * VOIR un utilisateur spécifique
     * GET /api/v1/admin/users/{id}
    */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $user = User::with(['userRoles.role', 'userRoles.school', 'parentProfile'])->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir cet utilisateur.',
                ], 403);
            }
            
            // Formater la réponse
            $formattedUser = [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'phone_or_email' => $user->phone_or_email,
                'avatar_url' => $user->avatar_url,
                'email_verified_at' => $user->email_verified_at,
                'initial_password_set' => $user->initial_password_set ?? false,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'deleted_at' => $user->deleted_at,
                'roles' => $user->userRoles->map(function ($userRole) {
                    return [
                        'id' => $userRole->id,
                        'role' => [
                            'id' => $userRole->role_id,
                            'name' => $userRole->role->name ?? null,
                            'description' => $userRole->role->description ?? null,
                        ],
                        'school' => $userRole->school ? [
                            'id' => $userRole->school_id,
                            'name' => $userRole->school->name,
                            'type' => $userRole->school->type->name ?? null,
                        ] : null,
                        'created_by' => $userRole->created_by,
                        'created_at' => $userRole->created_at,
                    ];
                }),
                'parent_profile' => $user->parentProfile ? [
                    'id' => $user->parentProfile->id,
                    'created_at' => $user->parentProfile->created_at,
                ] : null,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedUser,
                'message' => 'Utilisateur récupéré avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin user show error:', [
                'error' => $e->getMessage(),
                'user_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER un utilisateur désactivé
     * POST /api/v1/admin/users/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Seuls les super admins peuvent restaurer
            if (!$this->isSuperAdmin($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent restaurer des utilisateurs.',
                ], 403);
            }
            
            $user = User::onlyTrashed()->findOrFail($id);
            $user->restore();
            
            DB::commit();
            
            Log::info('User restored by admin', [
                'admin_id' => $currentUser->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Utilisateur restauré avec succès',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RÉINITIALISER le mot de passe d'un utilisateur
     * POST /api/v1/admin/users/{id}/reset-password
     */
    public function resetPassword(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $user = User::findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkAdminPermissions($currentUser, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour réinitialiser le mot de passe de cet utilisateur.',
                ], 403);
            }
            
            // Générer un nouveau mot de passe
            $newPassword = $this->generatePassword();
            $plainPassword = $newPassword;
            
            // Mettre à jour le mot de passe
            $user->update([
                'password' => Hash::make($newPassword),
                'confirm_password' => Hash::make($newPassword),
                'initial_password_set' => true,
            ]);
            
            // Révoquer tous les tokens existants (déconnexion forcée)
            $user->tokens()->delete();
            
            DB::commit();
            
            Log::info('Password reset by admin', [
                'admin_id' => $currentUser->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Mot de passe réinitialisé avec succès',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                    ],
                    'new_password' => $plainPassword,
                    'instructions' => 'Communiquez ce nouveau mot de passe à l\'utilisateur.',
                    'security_note' => 'L\'utilisateur a été déconnecté de toutes ses sessions.',
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resetting password:', [
                'error' => $e->getMessage(),
                'user_id' => $id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la réinitialisation du mot de passe',
            ], 500);
        }
    }

    /**
     * GÉNÉRER un nouveau mot de passe initial (pour l'utilisateur lui-même)
     * POST /api/v1/generate-initial-password
     */
    public function generateNewInitialPassword(Request $request)
    {
        try {

            $request->validate([
                'phone_or_email' => 'required|string',
            ]);

            $user = User::where('phone_or_email', $request->phone_or_email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucun utilisateur trouvé avec cet identifiant.',
                ], 404);
            }

            $newPassword = $this->generatePassword();
            
            // En production, vous enverriez ceci par email/SMS
            // Mail::to($user->phone_or_email)->send(new PasswordResetMail($newPassword));
            
            return response()->json([
                'status' => 'success',
                'message' => 'Un nouveau mot de passe a été généré.',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                    'password_sent_to' => $user->phone_or_email,
                    'test_mode_note' => 'En mode test, le mot de passe est : ' . $newPassword,
                    'production_note' => 'En production, le mot de passe serait envoyé par email/SMS.',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating new password:', [
                'error' => $e->getMessage(),
                'phone_or_email' => $request->phone_or_email
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la génération du nouveau mot de passe',
            ], 500);
        }
    }


    /**
     * Changer le mot de passe de l'utilisateur connecté
     * PUT /api/v1/admin/users/change-password
    */
    public function changePassword(Request $request)
    {

        DB::beginTransaction();
        
        try {
            $user = $request->user();
            
            // Validation
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'logout_other_devices' => 'boolean',
            ], [
                'current_password.required' => 'Le mot de passe actuel est requis.',
                'new_password.required' => 'Le nouveau mot de passe est requis.',
                'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
                'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            ]);
            
            // Vérifier si le mot de passe actuel est correct
            if (!Hash::check($request->current_password, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ]);
            }
            
            // Empêcher de réutiliser l'ancien mot de passe
            if (Hash::check($request->new_password, $user->password)) {
                throw ValidationException::withMessages([
                    'new_password' => ['Le nouveau mot de passe doit être différent de l\'ancien.'],
                ]);
            }
            
            // Mettre à jour le mot de passe
            $user->update([
                'password' => Hash::make($request->new_password),
                'confirm_password' => Hash::make($request->new_password),
                'initial_password_set' => false, // L'utilisateur a maintenant défini son propre mot de passe
            ]);
            
            // Optionnel : Déconnecter de tous les autres appareils
            $logoutOtherDevices = $request->input('logout_other_devices', false);
            if ($logoutOtherDevices) {
                // Supprimer tous les tokens sauf le token actuel
                $currentToken = $request->user()->currentAccessToken();
                $user->tokens()->where('id', '!=', $currentToken->id)->delete();
                
                Log::info('User logged out from other devices', [
                    'user_id' => $user->id,
                    'token_id_kept' => $currentToken->id,
                ]);
            }
            
            DB::commit();
            
            // Log de l'action
            Log::info('User changed password', [
                'user_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Mot de passe changé avec succès.',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                    'password_changed_at' => now(),
                    'logout_other_devices' => $logoutOtherDevices,
                ],
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error changing password:', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du changement de mot de passe.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Vérifier si le mot de passe a été défini initialement
     * GET /api/v1/admin/users/check-password-status
     */
    public function checkPasswordStatus(Request $request)
    {
        try {
            $user = $request->user();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'user_id' => $user->id,
                    'full_name' => $user->full_name,
                    'initial_password_set' => $user->initial_password_set ?? false,
                    'password_change_required' => $user->initial_password_set ?? false,
                    'last_password_change' => $user->updated_at,
                    'message' => $user->initial_password_set ? 
                        'Vous devez changer votre mot de passe initial.' : 
                        'Votre mot de passe a déjà été personnalisé.',
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error checking password status:', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? 'unknown',
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la vérification du statut du mot de passe.',
            ], 500);
        }
    }

    /**
     * STATISTIQUES des utilisateurs
     * GET /api/v1/admin/users/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Initialiser les statistiques
            $stats = [
                'total_users' => 0,
                'active_users' => 0,
                'deleted_users' => 0,
                'users_by_role' => [],
                'users_by_school' => [],
                'new_users_today' => 0,
                'new_users_this_week' => 0,
                'new_users_this_month' => 0,
                'users_without_avatar' => 0,
                'email_verified_users' => 0,
                'phone_users' => 0,
                'email_users' => 0,
            ];

            // Construire la requête de base
            $query = User::with(['userRoles.role', 'userRoles.school'])
                        ->withTrashed();
            
            // Appliquer les filtres selon les permissions
            if (!$this->isSuperAdmin($currentUser)) {
                // School admin ne voit que les statistiques de son école
                $adminSchoolId = $currentUser->userRoles()
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'school_admin');
                    })
                    ->value('school_id');
                
                if ($adminSchoolId) {
                    $query->whereHas('userRoles', function (Builder $query) use ($adminSchoolId) {
                        $query->where('school_id', $adminSchoolId);
                    });
                    
                    // Limiter les écoles à celle de l'admin
                    $schoolFilterId = $adminSchoolId;
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions nécessaires.',
                    ], 403);
                }
            } else {
                $schoolFilterId = null;
            }

            // Statistiques globales
            $stats['total_users'] = User::count();
            $stats['active_users'] = User::whereNull('deleted_at')->count();
            $stats['deleted_users'] = User::onlyTrashed()->count();
            
            // Statistiques par rôle
            $roles = Role::all();
            foreach ($roles as $role) {
                $roleQuery = User::whereHas('userRoles', function (Builder $query) use ($role, $schoolFilterId) {
                    $query->where('role_id', $role->id);
                    if ($schoolFilterId) {
                        $query->where('school_id', $schoolFilterId);
                    }
                });
                
                $stats['users_by_role'][$role->name] = [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'total' => $roleQuery->count(),
                    'active' => $roleQuery->whereNull('deleted_at')->count(),
                    'deleted' => $roleQuery->onlyTrashed()->count(),
                ];
            }

            // Statistiques par école (seulement pour super admin)
            if ($this->isSuperAdmin($currentUser)) {
                $schools = School::all();
                foreach ($schools as $school) {
                    $schoolQuery = User::whereHas('userRoles', function (Builder $query) use ($school) {
                        $query->where('school_id', $school->id);
                    });
                    
                    $stats['users_by_school'][$school->name] = [
                        'id' => $school->id,
                        'name' => $school->name,
                        'total' => $schoolQuery->count(),
                        'active' => $schoolQuery->whereNull('deleted_at')->count(),
                        'deleted' => $schoolQuery->onlyTrashed()->count(),
                    ];
                }
            } elseif ($schoolFilterId) {
                // Pour school admin, statistiques de son école seulement
                $school = School::find($schoolFilterId);
                if ($school) {
                    $schoolQuery = User::whereHas('userRoles', function (Builder $query) use ($schoolFilterId) {
                        $query->where('school_id', $schoolFilterId);
                    });
                    
                    $stats['users_by_school'][$school->name] = [
                        'id' => $school->id,
                        'name' => $school->name,
                        'total' => $schoolQuery->count(),
                        'active' => $schoolQuery->whereNull('deleted_at')->count(),
                        'deleted' => $schoolQuery->onlyTrashed()->count(),
                    ];
                }
            }

            // Nouveaux utilisateurs
            $today = now()->startOfDay();
            $startOfWeek = now()->startOfWeek();
            $startOfMonth = now()->startOfMonth();
            
            $stats['new_users_today'] = User::where('created_at', '>=', $today)->count();
            $stats['new_users_this_week'] = User::where('created_at', '>=', $startOfWeek)->count();
            $stats['new_users_this_month'] = User::where('created_at', '>=', $startOfMonth)->count();
            
            // Autres statistiques
            $stats['users_without_avatar'] = User::whereNull('avatar_path')->count();
            $stats['email_verified_users'] = User::whereNotNull('email_verified_at')->count();
            
            // Utilisateurs avec email vs téléphone
            $stats['email_users'] = User::where('phone_or_email', 'LIKE', '%@%')->count();
            $stats['phone_users'] = $stats['total_users'] - $stats['email_users'];
            
            // Distribution par date de création (derniers 30 jours)
            $creationDistribution = [];
            for ($i = 30; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $count = User::whereDate('created_at', $date)->count();
                $creationDistribution[$date] = $count;
            }
            $stats['creation_distribution_last_30_days'] = $creationDistribution;
            
            // Top 5 écoles avec le plus d'utilisateurs (seulement pour super admin)
            if ($this->isSuperAdmin($currentUser)) {
                // Compter les utilisateurs par école via la table user_roles
                $topSchools = DB::table('schools')
                    ->leftJoin('user_roles', 'schools.id', '=', 'user_roles.school_id')
                    ->leftJoin('users', 'user_roles.user_id', '=', 'users.id')
                    ->select(
                        'schools.id',
                        'schools.name',
                        DB::raw('COUNT(DISTINCT users.id) as user_count')
                    )
                    ->whereNull('users.deleted_at')
                    ->groupBy('schools.id', 'schools.name')
                    ->orderBy('user_count', 'desc')
                    ->take(5)
                    ->get()
                    ->map(function ($school) {
                        return [
                            'id' => $school->id,
                            'name' => $school->name,
                            'user_count' => (int) $school->user_count,
                        ];
                    });
                
                $stats['top_schools_by_user_count'] = $topSchools;
            }
            
            // Pourcentage d'utilisateurs actifs
            $stats['active_percentage'] = $stats['total_users'] > 0 
                ? round(($stats['active_users'] / $stats['total_users']) * 100, 2)
                : 0;
            
            // Pourcentage d'utilisateurs vérifiés
            $stats['verified_percentage'] = $stats['total_users'] > 0 
                ? round(($stats['email_verified_users'] / $stats['total_users']) * 100, 2)
                : 0;
            
            // Pourcentage d'utilisateurs avec avatar
            $stats['with_avatar_percentage'] = $stats['total_users'] > 0 
                ? round((($stats['total_users'] - $stats['users_without_avatar']) / $stats['total_users']) * 100, 2)
                : 0;
            
            // Résumé des permissions
            $stats['permissions'] = [
                'is_super_admin' => $this->isSuperAdmin($currentUser),
                'can_see_all_schools' => $this->isSuperAdmin($currentUser),
                'school_id' => $schoolFilterId ?? null,
            ];

            // Ajouter des métriques additionnelles
            $stats['metrics'] = [
                'avg_users_per_role' => count($stats['users_by_role']) > 0 
                    ? round(array_sum(array_column($stats['users_by_role'], 'total')) / count($stats['users_by_role']), 2)
                    : 0,
                'avg_users_per_school' => count($stats['users_by_school']) > 0 
                    ? round(array_sum(array_column($stats['users_by_school'], 'total')) / count($stats['users_by_school']), 2)
                    : 0,
                'growth_rate_this_week' => $stats['new_users_this_week'] > 0 
                    ? round(($stats['new_users_this_week'] / max(1, $stats['total_users'] - $stats['new_users_this_week'])) * 100, 2)
                    : 0,
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats,
                'message' => 'Statistiques des utilisateurs récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Admin user statistics error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

}