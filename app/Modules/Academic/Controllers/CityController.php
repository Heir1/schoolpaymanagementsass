<?php

namespace App\Http\Controllers;

use App\Modules\Academic\Models\City;
use App\Modules\Academic\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CityController extends Controller
{
    /**
     * Récupérer toutes les villes
     * GET /api/v1/cities
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            
            $query = City::withTrashed()
                ->with(['province']);
            
            // Filtres
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhereHas('province', function ($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%");
                      });
                });
            }
            
            if ($request->has('province_id')) {
                $query->where('province_id', $request->province_id);
            }
            
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }
            
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'name');
            $sortDirection = $request->input('sort_dir', 'asc');
            $query->orderBy($sortField, $sortDirection);
            
            $cities = $query->paginate($perPage);
            
            // Formater la réponse
            $cities->getCollection()->transform(function ($city) {
                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'type' => $city->type,
                    'province' => $city->province ? [
                        'id' => $city->province->id,
                        'name' => $city->province->name,
                        'is_deleted' => !is_null($city->province->deleted_at),
                    ] : null,
                    'created_at' => $city->created_at,
                    'updated_at' => $city->updated_at,
                    'deleted_at' => $city->deleted_at,
                    'is_deleted' => !is_null($city->deleted_at),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $cities,
                'message' => 'Liste des villes récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('City index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des villes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Créer une nouvelle ville
     * POST /api/v1/cities
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $validator = Validator::make($request->all(), [
                'province_id' => 'required|exists:provinces,id',
                'name' => 'required|string|max:255',
                'type' => 'nullable|string|max:100',
            ], [
                'province_id.required' => 'La province est requise.',
                'province_id.exists' => 'La province sélectionnée n\'existe pas.',
                'name.required' => 'Le nom de la ville est requis.',
                'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
                'type.max' => 'Le type ne doit pas dépasser 100 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier si la ville existe déjà dans cette province
            $existingCity = City::where('province_id', $request->province_id)
                ->where('name', $request->name)
                ->withTrashed()
                ->first();
            
            if ($existingCity) {
                $message = 'Cette ville existe déjà dans cette province';
                if ($existingCity->deleted_at) {
                    $message .= ' (elle est actuellement supprimée)';
                }
                
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'data' => [
                        'existing_city' => [
                            'id' => $existingCity->id,
                            'name' => $existingCity->name,
                            'is_deleted' => !is_null($existingCity->deleted_at),
                        ]
                    ]
                ], 422);
            }
            
            $city = City::create([
                'province_id' => $request->province_id,
                'name' => $request->name,
                'type' => $request->type,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            
            DB::commit();
            
            $city->load('province');
            
            Log::info('Ville créée', [
                'user_id' => auth()->id(),
                'city_id' => $city->id,
                'city_name' => $city->name,
                'province_id' => $city->province_id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Ville créée avec succès',
                'data' => [
                    'city' => [
                        'id' => $city->id,
                        'name' => $city->name,
                        'type' => $city->type,
                        'province' => [
                            'id' => $city->province->id,
                            'name' => $city->province->name,
                        ],
                        'created_at' => $city->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating city:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la ville: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ... Autres méthodes CRUD pour les villes (show, update, destroy, restore)
}