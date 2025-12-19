<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Modules\Users\Controllers\AdminUserController;
use App\Modules\Schools\Controllers\SchoolController;
use App\Modules\Schools\Controllers\SchoolYearController;
use App\Modules\Schools\Controllers\StudentGroupController;


/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // Route de status de la version 1
    Route::get('/status', function () {
        return response()->json([
            'status' => 'success',
            'api_version' => '1.0.0',
            'message' => 'API Version 1 is operational',
            'timestamp' => now()->toISOString(),
            'service' => 'SchoolPay Management SaaS',
            'environment' => config('app.env'),
            'octane' => app()->bound('octane') ? 'enabled' : 'disabled',
        ]);
    });
    
    // Routes d'authentification
    Route::prefix('auth')->group(function () {
        Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
        Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register']);
        Route::post('/register-parent', [\App\Http\Controllers\AuthController::class, 'registerParent']); // <-- NOUVELLE ROUTE
        Route::post('/check-availability', [\App\Http\Controllers\AuthController::class, 'checkAvailability']);
        
        // Routes protégées
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\AuthController::class, 'me']);
        });
    });


    // Routes pour la gestion complète des utilisateurs par les administrateurs
    Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
        // CRUD complet des utilisateurs
        Route::prefix('users')->group(function () {

            Route::put('/change-password', [AdminUserController::class, 'changePassword']);
            // Vérifier le statut du mot de passe
            Route::get('/check-password-status', [AdminUserController::class, 'checkPasswordStatus']);

            // CRUD de base
            Route::get('/', [AdminUserController::class, 'index']);
            Route::post('/', [AdminUserController::class, 'store']);
            Route::get('/{id}', [AdminUserController::class, 'show']);
            Route::put('/{id}', [AdminUserController::class, 'update']);
            Route::delete('/{id}', [AdminUserController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [AdminUserController::class, 'restore']);
            Route::post('/{id}/reset-password', [AdminUserController::class, 'resetPassword']);

            // Routes pour la gestion des avatars
            Route::delete('/{id}/avatar', [AdminUserController::class, 'removeAvatar']);
            Route::post('/{id}/avatar-from-url', [AdminUserController::class, 'uploadAvatarFromUrl']);

        });

        Route::prefix('schools')->group(function () {
            // CRUD de base
            Route::get('/', [SchoolController::class, 'index']);
            // Statistiques
            Route::get('/statistics', [SchoolController::class, 'statistics']);
            Route::post('/', [SchoolController::class, 'store']);
            Route::get('/{id}', [SchoolController::class, 'show']);
            Route::put('/{id}', [SchoolController::class, 'update']);
            Route::delete('/{id}', [SchoolController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [SchoolController::class, 'restore']);
            
        });
    
        // Routes pour les types d'écoles (lecture seule)
        Route::get('/school-types', [SchoolController::class, 'listSchoolTypes']);

        Route::prefix('school-years')->group(function () {
            // CRUD de base
            Route::get('/', [SchoolYearController::class, 'index']);
            Route::post('/', [SchoolYearController::class, 'store']);
            Route::get('/{id}', [SchoolYearController::class, 'show']);
            Route::put('/{id}', [SchoolYearController::class, 'update']);
            Route::delete('/{id}', [SchoolYearController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [SchoolYearController::class, 'restore']);
            Route::post('/{id}/toggle-active', [SchoolYearController::class, 'toggleActive']);
        });
    
        // Routes pour les groupes d'étudiants
        Route::prefix('student-groups')->group(function () {

            // Statistiques
            Route::get('/statistics', [StudentGroupController::class, 'statistics']);

            // CRUD de base
            Route::get('/', [StudentGroupController::class, 'index']);
            Route::post('/', [StudentGroupController::class, 'store']);
            Route::get('/{id}', [StudentGroupController::class, 'show']);
            Route::put('/{id}', [StudentGroupController::class, 'update']);
            Route::delete('/{id}', [StudentGroupController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [StudentGroupController::class, 'restore']);
            
        });
    
        // Routes pour les relations
        Route::prefix('schools/{schoolId}')->group(function () {
            Route::get('/school-years', [SchoolYearController::class, 'listBySchool']);
            Route::get('/active-school-year', [SchoolYearController::class, 'getActiveSchoolYear']);
            Route::get('/student-groups', [StudentGroupController::class, 'listBySchool']);
        });

    });

    // Route publique pour générer un nouveau mot de passe initial
    Route::post('/generate-initial-password', [AdminUserController::class, 'generateNewInitialPassword']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    
    // Routes publiques (sans authentification)
    Route::prefix('public')->group(function () {
        Route::get('/test', function () {
            return response()->json([
                'message' => 'API publique accessible',
                'endpoints' => [
                    'auth' => [
                        'login' => 'POST /api/v1/auth/login',
                        'register' => 'POST /api/v1/auth/register',
                        'check_availability' => 'POST /api/v1/auth/check-availability',
                    ],
                ],
            ]);
        });
    });
    
    // Routes protégées (avec authentification)
    Route::middleware(['auth:sanctum'])->group(function () {
        
        // Test d'authentification
        Route::get('/test-auth', function (Request $request) {
            return response()->json([
                'message' => 'Authentifié avec succès',
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->full_name,
                    'identifier' => $request->user()->phone_or_email,
                ],
                'timestamp' => now()->toISOString(),
            ]);
        });
        
        // Routes pour les écoles
        Route::prefix('schools')->group(function () {
            Route::get('/', function () {
                return response()->json([
                    'data' => [
                        ['id' => 1, 'name' => 'École Primaire de Paris', 'status' => 'active'],
                        ['id' => 2, 'name' => 'Lycée International', 'status' => 'active'],
                    ],
                    'message' => 'Écoles récupérées avec succès',
                ]);
            });
        });
        
        // Routes pour les utilisateurs
        Route::prefix('users')->group(function () {
            Route::get('/profile', function (Request $request) {
                $user = $request->user();
                
                return response()->json([
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'avatar_url' => $user->avatar_url,
                        'identifier_type' => $user->isEmail() ? 'email' : 'phone',
                        'created_at' => $user->created_at->format('d/m/Y H:i'),
                    ],
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'message' => 'Profil utilisateur récupéré avec succès',
                ]);
            });
        });
    });
});