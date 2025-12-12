<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

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
        Route::post('/check-availability', [\App\Http\Controllers\AuthController::class, 'checkAvailability']);
        
        // Routes protégées
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\AuthController::class, 'me']);
        });
    });
    
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