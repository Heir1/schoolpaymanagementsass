<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PaymentMethodController extends Controller
{
    /**
     * GET: Liste toutes les méthodes de paiement
     * GET /api/v1/admin/payment-methods
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Construire la requête
            $query = PaymentMethod::query();
            
            // Filtres
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            // Trier par défaut par code
            $query->orderBy('code', 'asc');
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $paymentMethods = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedMethods = $paymentMethods->getCollection()->map(function ($method) {
                return [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'description' => $method->description,
                    'created_at' => $method->created_at->toIso8601String(),
                    'updated_at' => $method->updated_at->toIso8601String(),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des méthodes de paiement récupérée avec succès',
                'data' => [
                    'payment_methods' => $transformedMethods,
                    'pagination' => [
                        'total' => $paymentMethods->total(),
                        'per_page' => $paymentMethods->perPage(),
                        'current_page' => $paymentMethods->currentPage(),
                        'last_page' => $paymentMethods->lastPage(),
                        'from' => $paymentMethods->firstItem(),
                        'to' => $paymentMethods->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching payment methods: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des méthodes de paiement',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer une nouvelle méthode de paiement
     * POST /api/v1/admin/payment-methods
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|max:50|unique:payment_methods,code',
                'name' => 'required|string|max:100',
                'description' => 'nullable|string',
            ], [
                'code.required' => 'Le code est requis',
                'code.unique' => 'Ce code existe déjà',
                'name.required' => 'Le nom est requis',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Créer la méthode de paiement
            $paymentMethod = PaymentMethod::create([
                'code' => $request->code,
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Méthode de paiement créée avec succès',
                'data' => [
                    'id' => $paymentMethod->id,
                    'code' => $paymentMethod->code,
                    'name' => $paymentMethod->name,
                    'description' => $paymentMethod->description,
                    'created_at' => $paymentMethod->created_at->toIso8601String(),
                    'updated_at' => $paymentMethod->updated_at->toIso8601String(),
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating payment method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la méthode de paiement',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher une méthode de paiement spécifique
     * GET /api/v1/admin/payment-methods/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $paymentMethod = PaymentMethod::findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Méthode de paiement récupérée avec succès',
                'data' => [
                    'id' => $paymentMethod->id,
                    'code' => $paymentMethod->code,
                    'name' => $paymentMethod->name,
                    'description' => $paymentMethod->description,
                    'created_at' => $paymentMethod->created_at->toIso8601String(),
                    'updated_at' => $paymentMethod->updated_at->toIso8601String(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching payment method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'payment_method_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Méthode de paiement non trouvée'
            ], 404);
        }
    }

    /**
     * PUT: Mettre à jour une méthode de paiement
     * PUT /api/v1/admin/payment-methods/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $paymentMethod = PaymentMethod::findOrFail($id);
            
            // Validation
            $validator = Validator::make($request->all(), [
                'code' => 'sometimes|required|string|max:50|unique:payment_methods,code,' . $id,
                'name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
            ], [
                'code.unique' => 'Ce code existe déjà',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour
            $updateData = [];
            if ($request->has('code')) $updateData['code'] = $request->code;
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('description')) $updateData['description'] = $request->description;
            
            if (!empty($updateData)) {
                $updateData['updated_by'] = $currentUser->id;
                $paymentMethod->update($updateData);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Méthode de paiement mise à jour avec succès',
                'data' => [
                    'id' => $paymentMethod->id,
                    'code' => $paymentMethod->code,
                    'name' => $paymentMethod->name,
                    'description' => $paymentMethod->description,
                    'created_at' => $paymentMethod->created_at->toIso8601String(),
                    'updated_at' => $paymentMethod->updated_at->toIso8601String(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating payment method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'payment_method_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la méthode de paiement',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer une méthode de paiement (soft delete)
     * DELETE /api/v1/admin/payment-methods/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $paymentMethod = PaymentMethod::findOrFail($id);
            
            // Vérifier si la méthode de paiement est utilisée dans des paiements
            // Vous devrez peut-être adapter cette vérification selon vos relations
            // if ($paymentMethod->invoicePayments()->exists()) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Impossible de supprimer cette méthode de paiement car elle est utilisée dans des paiements',
            //     ], 422);
            // }
            
            $paymentMethod->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Méthode de paiement supprimée avec succès',
                'data' => [
                    'id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting payment method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'payment_method_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de la méthode de paiement',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer une méthode de paiement supprimée
     * POST /api/v1/admin/payment-methods/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $paymentMethod = PaymentMethod::withTrashed()->findOrFail($id);
            
            if (!$paymentMethod->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette méthode de paiement n\'est pas supprimée',
                ], 422);
            }
            
            $paymentMethod->restore();
            $paymentMethod->update([
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Méthode de paiement restaurée avec succès',
                'data' => [
                    'id' => $paymentMethod->id,
                    'code' => $paymentMethod->code,
                    'name' => $paymentMethod->name,
                    'restored_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring payment method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'payment_method_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de la méthode de paiement',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}