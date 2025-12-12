<?php

namespace App\Http\Controllers;

use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Rules\PhoneOrEmail;

class AuthController extends Controller
{
    /**
     * Connexion avec email ou téléphone
     */
    public function login(Request $request)
    {
        $request->validate([
            'phone_or_email' => ['required', new PhoneOrEmail],
            'password' => 'required|string',
        ]);

        // Essayer de s'authentifier
        $credentials = $request->only('phone_or_email', 'password');
        
        // Pour Laravel, nous devons préciser que phone_or_email est le champ d'identification
        if (Auth::attempt(['phone_or_email' => $credentials['phone_or_email'], 'password' => $credentials['password']])) {
            $user = Auth::user();
            
            // Révoquer les anciens tokens (optionnel)
            $user->tokens()->delete();
            
            // Créer un nouveau token
            $token = $user->createToken('schoolpay-api-token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'phone_or_email' => $user->phone_or_email,
                    'avatar_url' => $user->avatar_url,
                    'is_email' => $user->isEmail(),
                    'is_phone' => $user->isPhone(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => config('sanctum.expiration', null),
                'message' => 'Connexion réussie',
            ]);
        }

        throw ValidationException::withMessages([
            'phone_or_email' => ['Les identifiants fournis sont incorrects.'],
        ]);
    }

    /**
     * Inscription avec email ou téléphone
     */
    public function register(Request $request)
    {
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

            // DEBUG: Vérifier ce qui est envoyé
            \Log::info('Register data:', [
                'confirm_password_present' => $request->has('confirm_password'),
                'password_confirmation_present' => $request->has('password_confirmation'),
                'all_data' => $request->all()
            ]);

            // Préparer les données d'insertion avec TOUS les champs requis
            $userData = [
                'full_name' => $request->full_name,
                'phone_or_email' => $request->phone_or_email,
                'password' => Hash::make($request->password),
                'confirm_password' => Hash::make($request->input('confirm_password', $request->input('password_confirmation', $request->password))),
                'avatar_url' => $request->avatar_url,
                'remember_token' => \Illuminate\Support\Str::random(60), // ← AJOUTÉ
                'email_verified_at' => now(), // ← AJOUTÉ (vérification immédiate pour le moment)
            ];

            // DEBUG: Vérifier les données d'insertion
            \Log::info('User data to insert:', $userData);

            // Créer l'utilisateur
            $user = User::create($userData);

            // Connexion automatique après inscription
            Auth::login($user);
            
            try {
                // Créer un token d'accès
                $token = $user->createToken('schoolpay-api-token')->plainTextToken;
                
                return response()->json([
                    'status' => 'success',
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'avatar_url' => $user->avatar_url,
                        'created_at' => $user->created_at->toISOString(),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'message' => 'Inscription réussie',
                ], 201);
                
            } catch (\Exception $tokenError) {
                // Si la création du token échoue, on retourne quand même l'utilisateur
                \Log::error('Token creation failed:', [
                    'error' => $tokenError->getMessage(),
                    'user_id' => $user->id
                ]);
                
                return response()->json([
                    'status' => 'warning',
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'avatar_url' => $user->avatar_url,
                        'created_at' => $user->created_at->toISOString(),
                    ],
                    'message' => 'Utilisateur créé mais problème avec le token.',
                    'solution' => 'Vérifiez la table personal_access_tokens: tokenable_id doit être VARCHAR(36)',
                ], 201);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Relancer l'exception de validation pour le traitement standard
            throw $e;
            
        } catch (\Exception $e) {
            // Log de l'erreur
            \Log::error('Registration failed:', [
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
     * Déconnexion
     */
    public function logout(Request $request)
    {
        // Révoquer le token courant
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * Récupérer l'utilisateur courant
     */
    public function me(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'phone_or_email' => $user->phone_or_email,
                'avatar_url' => $user->avatar_url,
                'is_email' => $user->isEmail(),
                'is_phone' => $user->isPhone(),
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'message' => 'Utilisateur récupéré avec succès',
        ]);
    }

    /**
     * Vérifier si un email/téléphone est disponible
     */
    public function checkAvailability(Request $request)
    {
        $request->validate([
            'phone_or_email' => ['required', new PhoneOrEmail],
        ]);

        $exists = User::where('phone_or_email', $request->phone_or_email)->exists();

        return response()->json([
            'status' => 'success',
            'available' => !$exists,
            'message' => $exists ? 'Cet identifiant est déjà utilisé' : 'Cet identifiant est disponible',
        ]);
    }
}