<?php

namespace App\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Modules\Shared\Models\Pivots\UserRole;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Models\ParentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Rules\PhoneOrEmail;

class AuthController extends Controller
{
    /**
     * Récupérer le rôle principal d'un utilisateur
     */
    private function getUserRoleData(User $user): ?array
    {
        try {
            $userRole = $user->userRoles()->first();
            
            if ($userRole && $userRole->role) {
                return [
                    'id' => $userRole->role->id,
                    'name' => $userRole->role->name,
                    'description' => $userRole->role->description,
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération du rôle', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Format de réponse utilisateur standardisé
     */
    private function formatUserResponse(User $user, string $message = '', array $additionalData = []): array
    {
        $baseResponse = [
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'phone_or_email' => $user->phone_or_email,
                'avatar_url' => $user->avatar_url,
                'is_email' => $user->isEmail(),
                'is_phone' => $user->isPhone(),
                'created_at' => $user->created_at->toISOString(),
                'role' => $this->getUserRoleData($user),
            ],
            'message' => $message,
        ];

        return array_merge($baseResponse, $additionalData);
    }

    /**
     * Connexion avec email ou téléphone
     */
    public function login(Request $request)
    {
        $request->validate([
            'phone_or_email' => ['required', new PhoneOrEmail],
            'password' => 'required|string',
        ]);

        $credentials = $request->only('phone_or_email', 'password');
        
        if (Auth::attempt(['phone_or_email' => $credentials['phone_or_email'], 'password' => $credentials['password']])) {
            $user = Auth::user();
            
            // Révoquer les anciens tokens (optionnel)
            $user->tokens()->delete();
            
            // Créer un nouveau token
            $token = $user->createToken('schoolpay-api-token')->plainTextToken;

            $response = $this->formatUserResponse($user, 'Connexion réussie');
            $response['token'] = $token;
            $response['token_type'] = 'Bearer';
            $response['expires_in'] = config('sanctum.expiration', null);

            return response()->json($response);
        }

        throw ValidationException::withMessages([
            'phone_or_email' => ['Les identifiants fournis sont incorrects.'],
        ]);
    }

    /**
     * Inscription générique avec email ou téléphone
     */
    public function register(Request $request)
    {
        DB::beginTransaction();
        
        try {
            // Transformer confirm_password en password_confirmation pour la validation
            if ($request->has('confirm_password')) {
                $request->merge(['password_confirmation' => $request->input('confirm_password')]);
            }
            
            // Validation avec messages personnalisés
            $request->validate([
                'full_name' => 'required|string|max:255',
                'phone_or_email' => [
                    'required', 
                    'string', 
                    'max:255',
                    new PhoneOrEmail, 
                    'unique:users,phone_or_email'
                ],
                'password' => 'required|string|min:8|confirmed',
                'avatar_url' => 'nullable|url|max:500',
            ], [
                'phone_or_email.required' => 'L\'email ou le téléphone est obligatoire.',
                'phone_or_email.unique' => 'Cet email/téléphone est déjà utilisé.',
                'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            ]);

            // Préparer les données d'insertion
            $userData = [
                'full_name' => $request->full_name,
                'phone_or_email' => $request->phone_or_email,
                'password' => Hash::make($request->password),
                'confirm_password' => Hash::make($request->input('confirm_password', $request->input('password_confirmation', $request->password))),
                'avatar_url' => $request->avatar_url,
                'remember_token' => Str::random(60),
                'email_verified_at' => now(),
            ];

            // Créer l'utilisateur
            $user = User::create($userData);

            // Connexion automatique après inscription
            Auth::login($user);
            
            // Créer un token d'accès
            $token = $user->createToken('schoolpay-api-token')->plainTextToken;
            
            // Validation de la transaction
            DB::commit();
            
            $response = $this->formatUserResponse($user, 'Inscription réussie');
            $response['token'] = $token;
            $response['token_type'] = 'Bearer';

            return response()->json($response, 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'inscription',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Inscription spécifique pour les parents avec assignation automatique de rôle
     */
    public function registerParent(Request $request)
    {
        DB::beginTransaction();
        
        try {
            // Transformer confirm_password en password_confirmation pour la validation
            if ($request->has('confirm_password')) {
                $request->merge(['password_confirmation' => $request->input('confirm_password')]);
            }
            
            // Validation avec messages personnalisés
            $request->validate([
                'full_name' => 'required|string|max:255',
                'phone_or_email' => [
                    'required', 
                    'string', 
                    'max:255',
                    new PhoneOrEmail, 
                    'unique:users,phone_or_email'
                ],
                'password' => 'required|string|min:8|confirmed',
                'avatar_url' => 'nullable|url|max:500',
            ], [
                'phone_or_email.required' => 'L\'email ou le téléphone est obligatoire.',
                'phone_or_email.unique' => 'Cet email/téléphone est déjà utilisé.',
                'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            ]);

            // Créer l'utilisateur
            $user = User::create([
                'full_name' => $request->full_name,
                'phone_or_email' => $request->phone_or_email,
                'password' => Hash::make($request->password),
                'confirm_password' => Hash::make($request->input('confirm_password', $request->input('password_confirmation', $request->password))),
                'avatar_url' => $request->avatar_url,
                'remember_token' => Str::random(60),
                'email_verified_at' => now(),
            ]);

            // Assigner le rôle "parent" (id = 6) dans user_roles
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => 6, // ID du rôle parent selon votre seeder
                'school_id' => null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            // Créer l'entrée dans la table parents
            ParentModel::create([
                'user_id' => $user->id,
            ]);

            // Connexion automatique après inscription
            Auth::login($user);
            
            // Créer un token d'accès
            $token = $user->createToken('schoolpay-api-token')->plainTextToken;
            
            // Validation de la transaction
            DB::commit();
            
            $response = $this->formatUserResponse($user, 'Inscription parent réussie');
            $response['token'] = $token;
            $response['token_type'] = 'Bearer';
            $response['role_assigned'] = 'parent';
            $response['parent_record_created'] = true;

            return response()->json($response, 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Parent registration failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'inscription parent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Inscription avec rôle spécifié
     */
    public function registerWithRole(Request $request)
    {
        DB::beginTransaction();
        
        try {
            if ($request->has('confirm_password')) {
                $request->merge(['password_confirmation' => $request->input('confirm_password')]);
            }
            
            $request->validate([
                'full_name' => 'required|string|max:255',
                'phone_or_email' => [
                    'required', 
                    'string', 
                    'max:255',
                    new PhoneOrEmail, 
                    'unique:users,phone_or_email'
                ],
                'password' => 'required|string|min:8|confirmed',
                'avatar_url' => 'nullable|url|max:500',
                'role_id' => 'required|integer|exists:roles,id',
            ]);
            
            // Vérifier que le rôle existe
            $role = Role::findOrFail($request->role_id);
            
            // Créer l'utilisateur
            $user = User::create([
                'full_name' => $request->full_name,
                'phone_or_email' => $request->phone_or_email,
                'password' => Hash::make($request->password),
                'confirm_password' => Hash::make($request->input('confirm_password', $request->input('password_confirmation', $request->password))),
                'avatar_url' => $request->avatar_url,
                'remember_token' => Str::random(60),
                'email_verified_at' => now(),
            ]);
            
            // Assigner le rôle spécifié
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'school_id' => null, // À adapter selon votre logique métier
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            
            Auth::login($user);
            $token = $user->createToken('schoolpay-api-token')->plainTextToken;
            
            DB::commit();
            
            $response = $this->formatUserResponse($user, 'Inscription avec rôle réussie');
            $response['token'] = $token;
            $response['token_type'] = 'Bearer';
            $response['role_assigned'] = $role->name;

            return response()->json($response, 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Register with role failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'inscription avec rôle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request)
    {
        try {
            // Révoquer le token courant
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Déconnexion réussie',
            ]);
        } catch (\Exception $e) {
            Log::error('Logout failed:', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? 'unknown'
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la déconnexion',
            ], 500);
        }
    }

    /**
     * Récupérer l'utilisateur courant
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            
            // Format de réponse de base
            $response = $this->formatUserResponse($user, 'Utilisateur récupéré avec succès');
            
            // Ajouter les permissions SI le package Spatie est correctement configuré
            try {
                if (method_exists($user, 'getAllPermissions') && class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
                    $response['user']['permissions'] = $user->getAllPermissions()->pluck('name');
                } else {
                    $response['user']['permissions'] = [];
                }
            } catch (\Exception $permissionError) {
                // Si erreur avec les permissions, on continue sans
                Log::warning('Permissions non disponibles:', [
                    'error' => $permissionError->getMessage(),
                    'user_id' => $user->id
                ]);
                $response['user']['permissions'] = [];
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Me endpoint failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? 'unknown'
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération du profil',
                'debug' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Vérifier si un email/téléphone est disponible
     */
    public function checkAvailability(Request $request)
    {
        try {
            $request->validate([
                'phone_or_email' => ['required', new PhoneOrEmail],
            ]);

            $exists = User::where('phone_or_email', $request->phone_or_email)->exists();

            return response()->json([
                'status' => 'success',
                'available' => !$exists,
                'message' => $exists ? 'Cet identifiant est déjà utilisé' : 'Cet identifiant est disponible',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Check availability failed:', [
                'error' => $e->getMessage(),
                'phone_or_email' => $request->phone_or_email ?? 'unknown'
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la vérification de disponibilité',
            ], 500);
        }
    }


    /**
     * Réinitialiser son propre mot de passe (pour utilisateurs ayant oublié)
     * POST /api/v1/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'phone_or_email' => ['required', new PhoneOrEmail],
            ]);
            
            // Chercher l'utilisateur
            $user = User::where('phone_or_email', $request->phone_or_email)->first();
            
            // Pour des raisons de sécurité, ne pas révéler si l'utilisateur existe ou non
            if (!$user) {
                // Toujours retourner un succès pour éviter l'énumération d'utilisateurs
                return response()->json([
                    'status' => 'success',
                    'message' => 'Si votre email/téléphone existe dans notre système, vous recevrez un lien de réinitialisation.',
                    'note' => 'En production, un email/SMS serait envoyé.',
                ]);
            }
            
            // Générer un token de réinitialisation
            $token = Str::random(64);
            
            // Enregistrer le token dans la base de données
            DB::table('password_reset_tokens')->updateOrInsert(
                ['phone_or_email' => $user->phone_or_email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );
            
            // En production, vous enverriez un email/SMS ici
            // Mail::to($user->phone_or_email)->send(new PasswordResetMail($token));
            
            // Pour le mode développement, on retourne le token
            if (env('APP_DEBUG')) {
                $debugInfo = [
                    'reset_token' => $token,
                    'reset_link' => url("/api/v1/reset-password/{$token}"),
                    'test_note' => 'En mode développement, le token est affiché. En production, il serait envoyé par email/SMS.',
                ];
            }
            
            Log::info('Password reset requested', [
                'user_id' => $user->id,
                'phone_or_email' => $user->phone_or_email,
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Si votre email/téléphone existe dans notre système, vous recevrez un lien de réinitialisation.',
                'debug' => $debugInfo ?? null,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in forgot password:', [
                'error' => $e->getMessage(),
                'phone_or_email' => $request->phone_or_email ?? 'unknown',
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la demande de réinitialisation.',
            ], 500);
        }
    }
}