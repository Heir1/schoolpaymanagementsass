<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 2
|--------------------------------------------------------------------------
*/

Route::prefix('v2')->group(function () {
    
    Route::get('/status', function () {
        return response()->json([
            'status' => 'success',
            'api_version' => '2.0.0',
            'message' => 'API Version 2 is under development',
            'expected_release' => '2024-12-01',
            'current_timestamp' => now()->toISOString(),
        ]);
    });
    
    // Placeholder pour les futures routes v2
    Route::get('/features', function () {
        return response()->json([
            'planned_features' => [
                'GraphQL API',
                'WebSocket support',
                'Real-time notifications',
                'Advanced analytics',
                'Multi-tenant enhancements',
            ],
            'message' => 'Version 2 features preview',
        ]);
    });
});