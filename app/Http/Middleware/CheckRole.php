<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // Si l'utilisateur n'est pas connecté
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Non authentifié',
            ], 401);
        }

        // Si aucun rôle n'est spécifié, autoriser l'accès
        if (empty($roles)) {
            return $next($request);
        }

        // Si l'utilisateur est super_admin, il a tous les accès
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Pour les school_admin, nous devons vérifier leur école
        $schoolId = null;
        
        // Essayer de récupérer le school_id de différentes manières
        if (in_array('school_admin', $roles)) {
            // 1. Depuis l'URL (ex: /api/v1/admin/schools/{schoolId}/...)
            $schoolId = $request->route('schoolId') 
                ?? $request->route('school_id') 
                ?? $request->input('school_id');
            
            // 2. Depuis l'utilisateur lui-même
            if (!$schoolId && $user->isSchoolAdmin()) {
                $schoolId = $user->getSchoolId();
            }
            
            // 3. Pour les routes qui ne spécifient pas d'école (comme /api/v1/admin/students)
            // Nous autorisons l'accès si l'utilisateur est school_admin de n'importe quelle école
            if (!$schoolId && $user->hasAnyRole(['school_admin'])) {
                return $next($request);
            }
        }

        // Vérifier si l'utilisateur a l'un des rôles requis
        if ($user->hasAnyRole($roles, $schoolId)) {
            return $next($request);
        }

        // Si nous sommes ici, l'utilisateur n'a pas les permissions nécessaires
        $userRoles = $user->roles->pluck('name')->join(', ');
        
        return response()->json([
            'status' => 'error',
            'message' => 'Accès non autorisé. Rôle(s) requis: ' . implode(', ', $roles) . 
                        '. Vos rôles: ' . ($userRoles ?: 'Aucun') .
                        ($schoolId ? ' pour l\'école ID: ' . $schoolId : ''),
        ], 403);
    }
}