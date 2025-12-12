<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::middleware(['throttle:60,1'])->group(function () {
    // Route pour vérifier la santé de l'API
    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'service' => 'School Pay Management API',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
            'environment' => config('app.env'),
        ]);
    });
    
    // Route de ping
    Route::get('/ping', function (Request $request) {
        $start = microtime(true);
        
        return response()->json([
            'pong' => now()->toISOString(),
            'client_ip' => $request->ip(),
            'response_time_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);
    });
    
    // Inclure les fichiers de routes par version
    require __DIR__ . '/api/v1.php';
    require __DIR__ . '/api/v2.php';
});

// Route catch-all pour les endpoints inexistants
Route::fallback(function () {
    return response()->json([
        'error' => 'Endpoint not found',
        'message' => 'The requested API endpoint does not exist.',
        'available_versions' => ['v1', 'v2'],
        'timestamp' => now()->toISOString(),
    ], 404);
});