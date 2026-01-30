<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // PROBLÈME #5: Le middleware Cors doit être PREPEND (au début)
        // Pas APPEND (à la fin) car il doit s'exécuter avant les autres
        $middleware->prepend(\App\Http\Middleware\Cors::class);
        
        // PROBLÈME #6: EnsureFrontendRequestsAreStateful DOIT être avant Cors
        // L'ordre devrait être :
        // 1. Cors (pour les headers CORS)
        // 2. EnsureFrontendRequestsAreStateful (pour Sanctum)
        // 3. SubstituteBindings
        $middleware->api(prepend: [
            \App\Http\Middleware\Cors::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Enregistrez vos alias de middleware ici
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'check.school.access' => \App\Http\Middleware\CheckSchoolAccess::class,
            // Ajoutez d'autres middlewares si nécessaire
        ]);
        
        // Vous pouvez aussi ajouter des groupes de middleware
        $middleware->group('admin', [
            'auth:sanctum',
            'role:school_admin,super_admin',
        ]);
        
        $middleware->group('super_admin', [
            'auth:sanctum',
            'role:super_admin',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();