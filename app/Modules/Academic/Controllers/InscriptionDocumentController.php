<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\InscriptionDocument;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InscriptionDocumentController extends Controller
{
    /**
     * Vérifier si l'utilisateur est super admin
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'superadmin');
            })
            ->exists();
    }

    /**
     * Vérifier les permissions pour les documents d'inscription
     * Seul le superadmin peut gérer les documents d'inscription globaux
     */
    private function checkDocumentPermissions(User $adminUser)
    {
        // Seul le superadmin peut gérer les documents d'inscription
        return $this->isSuperAdmin($adminUser);
    }

    /**
     * LISTER tous les documents d'inscription
     * GET /api/v1/admin/inscription-documents
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent gérer les documents d\'inscription.',
                ], 403);
            }
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // Construction de la requête
            $query = InscriptionDocument::with(['classRequiredDocuments.class', 'studentDocuments']);
            
            // Filtres optionnels
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
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
            
            $documents = $query->paginate($perPage);
            
            // Formater la réponse
            $documents->getCollection()->transform(function ($document) {
                return [
                    'id' => $document->id,
                    'name' => $document->name,
                    'description' => $document->description,
                    'full_name' => $document->full_name,
                    'required_by_classes_count' => $document->classRequiredDocuments->count(),
                    'mandatory_by_classes_count' => $document->classRequiredDocuments->where('is_mandatory', true)->count(),
                    'student_documents_count' => $document->studentDocuments->count(),
                    'created_at' => $document->created_at,
                    'updated_at' => $document->updated_at,
                    'deleted_at' => $document->deleted_at,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $documents,
                'message' => 'Liste des documents d\'inscription récupérée avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Inscription document index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des documents d\'inscription',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * VOIR un document d'inscription spécifique
     * GET /api/v1/admin/inscription-documents/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent voir les documents d\'inscription.',
                ], 403);
            }
            
            $document = InscriptionDocument::with([
                'classRequiredDocuments.class.school',
                'classRequiredDocuments.class.schoolYear',
                'studentDocuments'
            ])->findOrFail($id);
            
            // Formater la réponse
            $formattedDocument = [
                'id' => $document->id,
                'name' => $document->name,
                'description' => $document->description,
                'full_name' => $document->full_name,
                'required_by_classes' => $document->classRequiredDocuments->map(function ($classRequiredDoc) {
                    return [
                        'id' => $classRequiredDoc->id,
                        'class' => $classRequiredDoc->class ? [
                            'id' => $classRequiredDoc->class->id,
                            'name' => $classRequiredDoc->class->name,
                            'level' => $classRequiredDoc->class->level,
                            'school' => $classRequiredDoc->class->school ? [
                                'id' => $classRequiredDoc->class->school->id,
                                'name' => $classRequiredDoc->class->school->name,
                            ] : null,
                            'school_year' => $classRequiredDoc->class->schoolYear ? [
                                'id' => $classRequiredDoc->class->schoolYear->id,
                                'year_label' => $classRequiredDoc->class->schoolYear->year_label,
                            ] : null,
                        ] : null,
                        'is_mandatory' => $classRequiredDoc->is_mandatory,
                        'created_at' => $classRequiredDoc->created_at,
                    ];
                }),
                'student_documents_count' => $document->studentDocuments->count(),
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
                'deleted_at' => $document->deleted_at,
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedDocument,
                'message' => 'Document d\'inscription récupéré avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Inscription document show error:', [
                'error' => $e->getMessage(),
                'document_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération du document d\'inscription',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * CRÉER un nouveau document d'inscription
     * POST /api/v1/admin/inscription-documents
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent créer des documents d\'inscription.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:inscription_documents,name',
                'description' => 'nullable|string|max:1000',
            ], [
                'name.required' => 'Le nom du document est requis.',
                'name.unique' => 'Un document avec ce nom existe déjà.',
                'name.max' => 'Le nom du document ne doit pas dépasser 255 caractères.',
                'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Créer le document
            $document = InscriptionDocument::create([
                'name' => $request->name,
                'description' => $request->description,
            ]);
            
            DB::commit();
            
            // Log pour audit
            Log::info('Inscription document created', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->full_name,
                'document_id' => $document->id,
                'document_name' => $document->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document d\'inscription créé avec succès',
                'data' => [
                    'document' => [
                        'id' => $document->id,
                        'name' => $document->name,
                        'description' => $document->description,
                        'full_name' => $document->full_name,
                        'created_at' => $document->created_at,
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating inscription document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du document d\'inscription: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR un document d'inscription
     * PUT /api/v1/admin/inscription-documents/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent modifier les documents d\'inscription.',
                ], 403);
            }
            
            $document = InscriptionDocument::findOrFail($id);
            
            // Validation
            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('inscription_documents', 'name')->ignore($id),
                ],
                'description' => 'nullable|string|max:1000',
            ], [
                'name.required' => 'Le nom du document est requis.',
                'name.unique' => 'Un document avec ce nom existe déjà.',
                'name.max' => 'Le nom du document ne doit pas dépasser 255 caractères.',
                'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour le document
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }
            
            $document->update($updateData);
            
            DB::commit();
            
            Log::info('Inscription document updated', [
                'admin_id' => $currentUser->id,
                'document_id' => $document->id,
                'changes' => $updateData,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document d\'inscription mis à jour avec succès',
                'data' => [
                    'document' => [
                        'id' => $document->id,
                        'name' => $document->name,
                        'description' => $document->description,
                        'full_name' => $document->full_name,
                        'updated_at' => $document->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating inscription document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'document_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du document d\'inscription: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER un document d'inscription (soft delete)
     * DELETE /api/v1/admin/inscription-documents/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent supprimer des documents d\'inscription.',
                ], 403);
            }
            
            $document = InscriptionDocument::findOrFail($id);
            
            // Vérifier si le document est requis par des classes
            $isRequiredByClasses = $document->classRequiredDocuments()->exists();
            
            if ($isRequiredByClasses) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer ce document car il est requis par des classes.',
                ], 400);
            }
            
            // Vérifier si le document est associé à des étudiants
            $hasStudentDocuments = $document->studentDocuments()->exists();
            
            if ($hasStudentDocuments) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer ce document car il est associé à des étudiants.',
                ], 400);
            }
            
            // Soft delete
            $document->delete();
            
            DB::commit();
            
            Log::info('Inscription document deleted', [
                'admin_id' => $currentUser->id,
                'document_id' => $document->id,
                'document_name' => $document->name,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document d\'inscription supprimé avec succès',
                'data' => [
                    'document_id' => $document->id,
                    'name' => $document->name,
                    'deleted_at' => $document->deleted_at,
                    'can_be_restored' => true,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting inscription document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'document_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du document d\'inscription: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * RESTAURER un document d'inscription supprimé
     * POST /api/v1/admin/inscription-documents/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent restaurer des documents d\'inscription.',
                ], 403);
            }
            
            $document = InscriptionDocument::onlyTrashed()->findOrFail($id);
            $document->restore();
            
            DB::commit();
            
            Log::info('Inscription document restored', [
                'admin_id' => $currentUser->id,
                'document_id' => $document->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document d\'inscription restauré avec succès',
                'data' => [
                    'document_id' => $document->id,
                    'name' => $document->name,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring inscription document:', [
                'error' => $e->getMessage(),
                'document_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du document d\'inscription',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STATISTIQUES des documents d'inscription
     * GET /api/v1/admin/inscription-documents/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier les permissions
            if (!$this->checkDocumentPermissions($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les superadministrateurs peuvent voir les statistiques des documents d\'inscription.',
                ], 403);
            }
            
            $totalDocuments = InscriptionDocument::count();
            $activeDocuments = InscriptionDocument::whereNull('deleted_at')->count();
            $deletedDocuments = InscriptionDocument::onlyTrashed()->count();
            
            // Documents les plus requis
            $mostRequiredDocuments = InscriptionDocument::withCount('classRequiredDocuments')
                ->orderBy('class_required_documents_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($document) {
                    return [
                        'id' => $document->id,
                        'name' => $document->name,
                        'required_by_classes_count' => $document->class_required_documents_count,
                    ];
                });
            
            // Documents créés récemment (30 derniers jours)
            $recentDocuments = InscriptionDocument::where('created_at', '>=', now()->subDays(30))
                ->count();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_documents' => $totalDocuments,
                    'active_documents' => $activeDocuments,
                    'deleted_documents' => $deletedDocuments,
                    'most_required_documents' => $mostRequiredDocuments,
                    'recent_documents_last_30_days' => $recentDocuments,
                ],
                'message' => 'Statistiques récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting inscription document statistics:', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}