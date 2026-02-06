<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Province;
use App\Modules\Academic\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProvinceController extends Controller
{
    /**
     * Récupérer toutes les provinces
     * GET /api/v1/admin/provinces
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            
            // Construire la requête avec withTrashed pour inclure les supprimées
            $query = Province::withTrashed();
            
            // Filtres
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%");
            }
            
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'deleted') {
                    $query->onlyTrashed();
                }
                // Si 'all' ou aucune valeur, on garde withTrashed()
            }
            
            // Tri
            $sortField = $request->input('sort_by', 'name');
            $sortDirection = $request->input('sort_dir', 'asc');
            $query->orderBy($sortField, $sortDirection);
            
            $provinces = $query->paginate($perPage);
            
            // Formater la réponse
            $provinces->getCollection()->transform(function ($province) {
                return [
                    'id' => $province->id,
                    'name' => $province->name,
                    'created_by' => $province->createdBy ? [
                        'id' => $province->createdBy->id,
                        'name' => $province->createdBy->full_name,
                    ] : null,
                    'updated_by' => $province->updatedBy ? [
                        'id' => $province->updatedBy->id,
                        'name' => $province->updatedBy->full_name,
                    ] : null,
                    'created_at' => $province->created_at,
                    'updated_at' => $province->updated_at,
                    'deleted_at' => $province->deleted_at,
                    'is_deleted' => !is_null($province->deleted_at),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $provinces,
                'message' => 'Liste des provinces récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Province index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des provinces',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Créer une nouvelle province
     * POST /api/v1/admin/provinces
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:provinces,name',
            ], [
                'name.required' => 'Le nom de la province est requis.',
                'name.unique' => 'Cette province existe déjà.',
                'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $province = Province::create([
                'name' => $request->name,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            
            DB::commit();
            
            Log::info('Province créée', [
                'user_id' => auth()->id(),
                'province_id' => $province->id,
                'province_name' => $province->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Province créée avec succès',
                'data' => [
                    'province' => [
                        'id' => $province->id,
                        'name' => $province->name,
                        'created_at' => $province->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating province:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la province: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Récupérer une province spécifique avec ses villes
     * GET /api/v1/admin/provinces/{id}
     */
    public function show($id)
    {
        try {
            $province = Province::withTrashed()
                ->with(['cities' => function($query) {
                    $query->withTrashed()->orderBy('name');
                }])
                ->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $province->id,
                    'name' => $province->name,
                    'cities_count' => $province->cities->count(),
                    'cities' => $province->cities->map(function ($city) {
                        return [
                            'id' => $city->id,
                            'name' => $city->name,
                            'type' => $city->type,
                            'created_at' => $city->created_at,
                            'updated_at' => $city->updated_at,
                            'deleted_at' => $city->deleted_at,
                            'is_deleted' => !is_null($city->deleted_at),
                        ];
                    }),
                    'created_by' => $province->createdBy ? [
                        'id' => $province->createdBy->id,
                        'name' => $province->createdBy->full_name,
                    ] : null,
                    'updated_by' => $province->updatedBy ? [
                        'id' => $province->updatedBy->id,
                        'name' => $province->updatedBy->full_name,
                    ] : null,
                    'created_at' => $province->created_at,
                    'updated_at' => $province->updated_at,
                    'deleted_at' => $province->deleted_at,
                    'is_deleted' => !is_null($province->deleted_at),
                ],
                'message' => 'Province récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Province show error:', [
                'error' => $e->getMessage(),
                'province_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Province non trouvée',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 404);
        }
    }

    /**
     * Mettre à jour une province
     * PUT /api/v1/admin/provinces/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $province = Province::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('provinces', 'name')->ignore($province->id),
                ],
            ], [
                'name.required' => 'Le nom de la province est requis.',
                'name.unique' => 'Cette province existe déjà.',
                'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $province->update([
                'name' => $request->name,
                'updated_by' => auth()->id(),
            ]);
            
            DB::commit();
            
            Log::info('Province mise à jour', [
                'user_id' => auth()->id(),
                'province_id' => $province->id,
                'new_name' => $province->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Province mise à jour avec succès',
                'data' => [
                    'province' => [
                        'id' => $province->id,
                        'name' => $province->name,
                        'updated_at' => $province->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating province:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'province_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la province: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Supprimer (soft delete) une province
     * DELETE /api/v1/admin/provinces/{id}
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        
        try {
            $province = Province::findOrFail($id);
            
            // Vérifier si la province a des villes associées
            $hasCities = $province->cities()->exists();
            
            if ($hasCities) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette province car elle contient des villes. Supprimez d\'abord les villes.',
                ], 400);
            }
            
            $province->delete();
            
            DB::commit();
            
            Log::info('Province supprimée', [
                'user_id' => auth()->id(),
                'province_id' => $province->id,
                'province_name' => $province->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Province supprimée avec succès',
                'data' => [
                    'province_id' => $province->id,
                    'name' => $province->name,
                    'deleted_at' => $province->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting province:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'province_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de la province: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Restaurer une province supprimée
     * POST /api/v1/admin/provinces/{id}/restore
     */
    public function restore($id)
    {
        DB::beginTransaction();
        
        try {
            $province = Province::onlyTrashed()->findOrFail($id);
            $province->restore();
            
            DB::commit();
            
            Log::info('Province restaurée', [
                'user_id' => auth()->id(),
                'province_id' => $province->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Province restaurée avec succès',
                'data' => [
                    'province_id' => $province->id,
                    'name' => $province->name,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring province:', [
                'error' => $e->getMessage(),
                'province_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de la province',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Récupérer toutes les villes d'une province
     * GET /api/v1/admin/provinces/{id}/cities
     */
    public function getProvinceCities(Request $request, $id)
    {
        try {
            $province = Province::withTrashed()->findOrFail($id);
            
            $perPage = $request->input('per_page', 15);
            
            $query = City::where('province_id', $id)
                ->withTrashed()
                ->orderBy('name', 'asc');
            
            // Filtres
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%");
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
            
            $cities = $query->paginate($perPage);
            
            // Formater la réponse
            $cities->getCollection()->transform(function ($city) {
                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'type' => $city->type,
                    'province_id' => $city->province_id,
                    'created_at' => $city->created_at,
                    'updated_at' => $city->updated_at,
                    'deleted_at' => $city->deleted_at,
                    'is_deleted' => !is_null($city->deleted_at),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'province' => [
                        'id' => $province->id,
                        'name' => $province->name,
                    ],
                    'cities' => $cities,
                ],
                'message' => 'Villes de la province récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting province cities:', [
                'error' => $e->getMessage(),
                'province_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des villes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Statistiques des provinces
     * GET /api/v1/admin/provinces/statistics
     */
    public function statistics()
    {
        try {
            $totalProvinces = Province::withTrashed()->count();
            $activeProvinces = Province::whereNull('deleted_at')->count();
            $deletedProvinces = Province::onlyTrashed()->count();
            
            // Provinces avec le plus de villes
            $topProvinces = Province::withCount(['cities' => function ($query) {
                $query->whereNull('deleted_at');
            }])
            ->whereNull('deleted_at')
            ->orderBy('cities_count', 'desc')
            ->take(5)
            ->get()
            ->map(function ($province) {
                return [
                    'id' => $province->id,
                    'name' => $province->name,
                    'cities_count' => $province->cities_count,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_provinces' => $totalProvinces,
                    'active_provinces' => $activeProvinces,
                    'deleted_provinces' => $deletedProvinces,
                    'top_provinces_by_cities' => $topProvinces,
                ],
                'message' => 'Statistiques des provinces récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting province statistics:', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Rechercher des provinces
     * GET /api/v1/admin/provinces/search
     */
    public function search(Request $request)
    {
        try {
            $search = $request->input('q');
            
            if (!$search || strlen($search) < 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le terme de recherche doit contenir au moins 2 caractères.',
                ], 400);
            }
            
            $provinces = Province::withTrashed()
                ->where('name', 'like', "%{$search}%")
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(function ($province) {
                    return [
                        'id' => $province->id,
                        'name' => $province->name,
                        'is_deleted' => !is_null($province->deleted_at),
                    ];
                });
            
            return response()->json([
                'status' => 'success',
                'data' => $provinces,
                'message' => 'Résultats de recherche récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error searching provinces:', [
                'error' => $e->getMessage(),
                'search_term' => $request->input('q')
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la recherche',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Créer une ville dans une province
     * POST /api/v1/admin/provinces/{provinceId}/cities
     */
    public function storeCity(Request $request, $provinceId)
    {
        DB::beginTransaction();
        
        try {
            $province = Province::findOrFail($provinceId);
            
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'nullable|string|max:100',
            ], [
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
            $existingCity = City::where('province_id', $provinceId)
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
                'province_id' => $provinceId,
                'name' => $request->name,
                'type' => $request->type,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Ville créée avec succès',
                'data' => [
                    'city' => [
                        'id' => $city->id,
                        'name' => $city->name,
                        'type' => $city->type,
                        'province_id' => $city->province_id,
                        'created_at' => $city->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating city in province:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'province_id' => $provinceId,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la ville: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}