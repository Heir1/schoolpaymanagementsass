<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Cors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // PROBLÈME #1: La liste d'origines manque 127.0.0.1:3000
        // Next.js envoie parfois 127.0.0.1:3000 au lieu de localhost:3000
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            // Ajoutez d'autres domaines si nécessaire
        ];
        
        $origin = $request->headers->get('Origin');
        
        // Log pour débogage
        Log::info('CORS Request:', [
            'origin' => $origin,
            'path' => $request->path(),
            'method' => $request->method(),
            'allowed_origins' => $allowedOrigins,
        ]);
        
        // PROBLÈME #2: Gestion incorrecte des requêtes OPTIONS
        // Il faut gérer OPTIONS AVANT de vérifier l'origine
        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflightRequest($origin, $allowedOrigins);
        }
        
        // Si l'origine est dans la liste des autorisées
        if (in_array($origin, $allowedOrigins)) {
            // PROBLÈME #3: Les headers doivent être ajoutés APRÈS la réponse
            $response = $next($request);
            
            // Ajouter les headers CORS
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-XSRF-TOKEN, Accept, Origin');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Expose-Headers', 'Authorization, X-CSRF-Token');
            $response->headers->set('Access-Control-Max-Age', '86400');
            
            return $response;
        }
        
        // PROBLÈME #4: Retourner une réponse sans headers CORS pour les origines non autorisées
        // Ceci causera une erreur CORS dans le navigateur
        // Mieux vaut retourner une erreur 403 ou autoriser avec restrictions
        return $next($request);
    }
    
    /**
     * Gère les requêtes OPTIONS (preflight)
     */
    private function handlePreflightRequest(?string $origin, array $allowedOrigins)
    {
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-XSRF-TOKEN, Accept, Origin',
            'Access-Control-Max-Age' => '86400',
        ];
        
        // Si l'origine est autorisée, l'ajouter aux headers
        if ($origin && in_array($origin, $allowedOrigins)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        
        Log::info('CORS Preflight Response:', $headers);
        
        return response()->json([], 200, $headers);
    }
}