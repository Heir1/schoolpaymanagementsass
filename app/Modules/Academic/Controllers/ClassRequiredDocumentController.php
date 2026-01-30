<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\ClassModel;
use App\Modules\Academic\Models\ClassRequiredDocument;
use App\Modules\Academic\Models\InscriptionDocument;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClassRequiredDocumentController extends Controller
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
     * Vérifier les permissions pour les documents requis
     */
    private function checkClassRequiredDocumentPermissions(User $adminUser, ClassModel $class)
    {
        // Super admin peut tout faire
        if ($this->isSuperAdmin($adminUser)) {
            return true;
        }

        // School admin ne peut gérer que les classes de son école
        $adminSchoolId = $adminUser->userRoles()
            ->whereHas('role', function ($query) {
                $query->where('name', 'school_admin');
            })
            ->value('school_id');

        if (!$adminSchoolId) {
            return false;
        }

        return $class->school_id == $adminSchoolId;
    }

    /**
     * LISTER les documents requis d'une classe
     * GET /api/v1/admin/classes/{classId}/required-documents
     */
    public function index(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les documents requis de cette classe.',
                ], 403);
            }
            
            $requiredDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->with(['document'])
                ->orderBy('is_mandatory', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                    ],
                    'required_documents' => $requiredDocuments->map(function ($requiredDoc) {
                        return [
                            'id' => $requiredDoc->id,
                            'document' => $requiredDoc->document ? [
                                'id' => $requiredDoc->document->id,
                                'name' => $requiredDoc->document->name,
                                'description' => $requiredDoc->document->description,
                                'full_name' => $requiredDoc->document->full_name,
                            ] : null,
                            'is_mandatory' => $requiredDoc->is_mandatory,
                            'created_at' => $requiredDoc->created_at,
                        ];
                    }),
                    'counts' => [
                        'total' => $requiredDocuments->count(),
                        'mandatory' => $requiredDocuments->where('is_mandatory', true)->count(),
                        'optional' => $requiredDocuments->where('is_mandatory', false)->count(),
                    ],
                ],
                'message' => 'Documents requis récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Class required document index error:', [
                'error' => $e->getMessage(),
                'class_id' => $classId
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des documents requis',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * AJOUTER un document requis à une classe
     * POST /api/v1/admin/classes/{classId}/required-documents
     */
    public function store(Request $request, $classId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour ajouter des documents requis à cette classe.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'document_id' => 'required|integer|exists:inscription_documents,id',
                'is_mandatory' => 'boolean',
            ], [
                'document_id.required' => 'Le document est requis.',
                'document_id.exists' => 'Le document sélectionné n\'existe pas.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier si le document est déjà requis pour cette classe (y compris supprimé)
            $existingRequiredDocument = ClassRequiredDocument::withTrashed()
                ->where('class_id', $classId)
                ->where('document_id', $request->document_id)
                ->first();
            
            if ($existingRequiredDocument) {
                if ($existingRequiredDocument->trashed()) {
                    // Restaurer l'enregistrement supprimé
                    $existingRequiredDocument->restore();
                    $existingRequiredDocument->update([
                        'is_mandatory' => $request->input('is_mandatory', false),
                    ]);
                    
                    $requiredDocument = $existingRequiredDocument;
                    
                    Log::info('Class required document restored', [
                        'admin_id' => $currentUser->id,
                        'class_id' => $classId,
                        'document_id' => $request->document_id,
                        'action' => 'restored',
                    ]);
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ce document est déjà requis pour cette classe.',
                    ], 422);
                }
            } else {
                // Créer le document requis
                $requiredDocument = ClassRequiredDocument::create([
                    'class_id' => $classId,
                    'document_id' => $request->document_id,
                    'is_mandatory' => $request->input('is_mandatory', false),
                ]);
            }
            
            // Charger les relations
            $requiredDocument->load(['document', 'class']);
            
            DB::commit();
            
            Log::info('Class required document added/restored', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'document_id' => $request->document_id,
                'is_mandatory' => $requiredDocument->is_mandatory,
                'action' => isset($existingRequiredDocument) && $existingRequiredDocument->trashed() ? 'restored' : 'created',
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => isset($existingRequiredDocument) && $existingRequiredDocument->trashed() 
                    ? 'Document requis restauré avec succès' 
                    : 'Document requis ajouté avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'required_document' => [
                        'id' => $requiredDocument->id,
                        'document' => $requiredDocument->document ? [
                            'id' => $requiredDocument->document->id,
                            'name' => $requiredDocument->document->name,
                            'description' => $requiredDocument->document->description,
                        ] : null,
                        'is_mandatory' => $requiredDocument->is_mandatory,
                        'created_at' => $requiredDocument->created_at,
                        'updated_at' => $requiredDocument->updated_at,
                        'was_restored' => isset($existingRequiredDocument) && $existingRequiredDocument->trashed(),
                    ],
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding class required document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout du document requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * METTRE À JOUR un document requis
     * PUT /api/v1/admin/classes/{classId}/required-documents/{id}
     */
    public function update(Request $request, $classId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            $requiredDocument = ClassRequiredDocument::where('class_id', $classId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier les documents requis de cette classe.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'is_mandatory' => 'boolean',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Mettre à jour le document requis
            $requiredDocument->update([
                'is_mandatory' => $request->input('is_mandatory', $requiredDocument->is_mandatory),
            ]);
            
            // Charger les relations
            $requiredDocument->load(['document', 'class']);
            
            DB::commit();
            
            Log::info('Class required document updated', [
                'admin_id' => $currentUser->id,
                'required_document_id' => $id,
                'is_mandatory' => $requiredDocument->is_mandatory,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document requis mis à jour avec succès',
                'data' => [
                    'required_document' => [
                        'id' => $requiredDocument->id,
                        'document' => $requiredDocument->document ? [
                            'id' => $requiredDocument->document->id,
                            'name' => $requiredDocument->document->name,
                            'description' => $requiredDocument->document->description,
                        ] : null,
                        'is_mandatory' => $requiredDocument->is_mandatory,
                        'updated_at' => $requiredDocument->updated_at,
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating class required document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'required_document_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour du document requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * METTRE À JOUR plusieurs documents requis en masse (LOGIQUE DE REMPLACEMENT)
     * PUT /api/v1/admin/classes/{classId}/required-documents/bulk
     */
    public function bulkUpdate(Request $request, $classId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier les documents requis de cette classe.',
                ], 403);
            }
            
            // Validation - maintenant on accepte juste une liste de documents sans action
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array',
                'documents.*.document_id' => 'required|integer|exists:inscription_documents,id',
                'documents.*.is_mandatory' => 'boolean',
            ], [
                'documents.required' => 'La liste des documents est requise.',
                'documents.array' => 'La liste des documents doit être un tableau.',
                'documents.*.document_id.required' => 'L\'ID du document est requis.',
                'documents.*.document_id.exists' => 'Le document sélectionné n\'existe pas.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Récupérer les IDs des documents dans la requête
            $requestDocumentIds = collect($request->documents)->pluck('document_id')->toArray();
            
            // Récupérer tous les documents actuellement actifs pour cette classe
            $currentDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->with('document')
                ->get();
            
            $updatedDocuments = [];
            $addedDocuments = [];
            $restoredDocuments = [];
            $deletedDocuments = [];
            
            // 1. TRAITER LES DOCUMENTS DE LA REQUÊTE (ceux qu'on veut garder/ajouter)
            foreach ($request->documents as $documentData) {
                $documentId = $documentData['document_id'];
                $isMandatory = $documentData['is_mandatory'] ?? false;
                
                // Chercher si ce document existe déjà (actif ou supprimé)
                $existingDocument = ClassRequiredDocument::withTrashed()
                    ->where('class_id', $classId)
                    ->where('document_id', $documentId)
                    ->first();
                
                if ($existingDocument) {
                    // Si l'enregistrement existe
                    if ($existingDocument->trashed()) {
                        // Restaurer l'enregistrement supprimé
                        $existingDocument->restore();
                        $existingDocument->update([
                            'is_mandatory' => $isMandatory
                        ]);
                        $restoredDocuments[] = $existingDocument;
                    } else {
                        // Mettre à jour l'enregistrement existant
                        $existingDocument->update([
                            'is_mandatory' => $isMandatory
                        ]);
                        $updatedDocuments[] = $existingDocument;
                    }
                } else {
                    // Créer un nouvel enregistrement
                    $newDocument = ClassRequiredDocument::create([
                        'class_id' => $classId,
                        'document_id' => $documentId,
                        'is_mandatory' => $isMandatory,
                    ]);
                    $addedDocuments[] = $newDocument;
                }
            }
            
            // 2. SUPPRIMER LES DOCUMENTS ACTUELS QUI NE SONT PAS DANS LA REQUÊTE
            foreach ($currentDocuments as $currentDocument) {
                if (!in_array($currentDocument->document_id, $requestDocumentIds)) {
                    // Ce document est actuellement actif mais n'est pas dans la requête => le supprimer
                    $currentDocument->delete();
                    $deletedDocuments[] = [
                        'id' => $currentDocument->id,
                        'document_id' => $currentDocument->document_id,
                        'document_name' => $currentDocument->document->name ?? 'Inconnu',
                    ];
                }
            }
            
            // Récupérer les documents requis actuels après les modifications
            $currentRequiredDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->with('document')
                ->get();
            
            DB::commit();
            
            Log::info('Bulk class required documents updated (replace logic)', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'updated_count' => count($updatedDocuments),
                'added_count' => count($addedDocuments),
                'restored_count' => count($restoredDocuments),
                'deleted_count' => count($deletedDocuments),
                'request_document_ids' => $requestDocumentIds,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Documents requis mis à jour avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'summary' => [
                        'updated_count' => count($updatedDocuments),
                        'added_count' => count($addedDocuments),
                        'restored_count' => count($restoredDocuments),
                        'deleted_count' => count($deletedDocuments),
                        'total_requested' => count($request->documents),
                        'total_current' => $currentRequiredDocuments->count(),
                    ],
                    'current_required_documents' => $currentRequiredDocuments->map(function ($requiredDoc) {
                        return [
                            'id' => $requiredDoc->id,
                            'document' => $requiredDoc->document ? [
                                'id' => $requiredDoc->document->id,
                                'name' => $requiredDoc->document->name,
                                'description' => $requiredDoc->document->description,
                            ] : null,
                            'is_mandatory' => $requiredDoc->is_mandatory,
                        ];
                    }),
                    'updated_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'action' => 'updated',
                        ];
                    }, $updatedDocuments),
                    'added_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'action' => 'created',
                        ];
                    }, $addedDocuments),
                    'restored_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'action' => 'restored',
                        ];
                    }, $restoredDocuments),
                    'deleted_documents' => $deletedDocuments,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk updating class required documents (replace logic):', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour des documents requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * REMPLACER complètement les documents requis d'une classe
     * PUT /api/v1/admin/classes/{classId}/required-documents/replace
     */
    public function bulkReplace(Request $request, $classId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour modifier les documents requis de cette classe.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array',
                'documents.*.document_id' => 'required|integer|exists:inscription_documents,id',
                'documents.*.is_mandatory' => 'boolean',
            ], [
                'documents.required' => 'La liste des documents est requise.',
                'documents.array' => 'La liste des documents doit être un tableau.',
                'documents.*.document_id.required' => 'L\'ID du document est requis.',
                'documents.*.document_id.exists' => 'Le document sélectionné n\'existe pas.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Supprimer tous les documents requis existants pour cette classe
            $deletedCount = ClassRequiredDocument::where('class_id', $classId)->delete();
            
            // Créer les nouveaux documents requis
            $createdDocuments = [];
            $duplicateDocuments = [];
            
            // Pour éviter les doublons dans la même requête
            $processedDocumentIds = [];
            
            foreach ($request->documents as $documentData) {
                $documentId = $documentData['document_id'];
                
                // Vérifier les doublons dans la même requête
                if (in_array($documentId, $processedDocumentIds)) {
                    $duplicateDocuments[] = [
                        'document_id' => $documentId,
                        'reason' => 'Document en double dans la requête',
                    ];
                    continue;
                }
                
                // Créer le document requis
                $requiredDocument = ClassRequiredDocument::create([
                    'class_id' => $classId,
                    'document_id' => $documentId,
                    'is_mandatory' => $documentData['is_mandatory'] ?? false,
                ]);
                
                $createdDocuments[] = $requiredDocument;
                $processedDocumentIds[] = $documentId;
            }
            
            // Charger les relations pour la réponse
            foreach ($createdDocuments as $doc) {
                $doc->load('document');
            }
            
            DB::commit();
            
            Log::info('Class required documents replaced', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'deleted_count' => $deletedCount,
                'created_count' => count($createdDocuments),
                'duplicate_count' => count($duplicateDocuments),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Documents requis remplacés avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'summary' => [
                        'previous_documents_deleted' => $deletedCount,
                        'new_documents_created' => count($createdDocuments),
                        'duplicates_skipped' => count($duplicateDocuments),
                    ],
                    'new_required_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document' => $doc->document ? [
                                'id' => $doc->document->id,
                                'name' => $doc->document->name,
                                'description' => $doc->document->description,
                            ] : null,
                            'is_mandatory' => $doc->is_mandatory,
                        ];
                    }, $createdDocuments),
                    'duplicates_skipped' => $duplicateDocuments,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error replacing class required documents:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du remplacement des documents requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * SUPPRIMER un document requis
     * DELETE /api/v1/admin/classes/{classId}/required-documents/{id}
     */
    public function destroy(Request $request, $classId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            $requiredDocument = ClassRequiredDocument::where('class_id', $classId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer les documents requis de cette classe.',
                ], 403);
            }
            
            // Supprimer le document requis
            $requiredDocument->delete();
            
            DB::commit();
            
            Log::info('Class required document deleted', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'required_document_id' => $id,
                'document_name' => $requiredDocument->document->name ?? 'Inconnu',
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document requis supprimé avec succès',
                'data' => [
                    'deleted_document' => [
                        'document_id' => $requiredDocument->document_id,
                        'document_name' => $requiredDocument->document->name ?? 'Inconnu',
                        'deleted_at' => now(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting class required document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'required_document_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du document requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * AJOUTER PLUSIEURS documents requis à une classe
     * POST /api/v1/admin/classes/{classId}/required-documents/bulk
     */
    public function bulkStore(Request $request, $classId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour ajouter des documents requis à cette classe.',
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array|min:1',
                'documents.*.document_id' => 'required|integer|exists:inscription_documents,id',
                'documents.*.is_mandatory' => 'boolean',
            ], [
                'documents.required' => 'La liste des documents est requise.',
                'documents.array' => 'La liste des documents doit être un tableau.',
                'documents.min' => 'Au moins un document doit être fourni.',
                'documents.*.document_id.required' => 'L\'ID du document est requis.',
                'documents.*.document_id.exists' => 'Le document sélectionné n\'existe pas.',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $addedDocuments = [];
            $restoredDocuments = [];
            $skippedDocuments = [];
            
            foreach ($request->documents as $documentData) {
                // Vérifier si le document est déjà requis pour cette classe (y compris supprimé)
                $existingRequiredDocument = ClassRequiredDocument::withTrashed()
                    ->where('class_id', $classId)
                    ->where('document_id', $documentData['document_id'])
                    ->first();
                
                if ($existingRequiredDocument) {
                    if ($existingRequiredDocument->trashed()) {
                        // Restaurer l'enregistrement supprimé
                        $existingRequiredDocument->restore();
                        $existingRequiredDocument->update([
                            'is_mandatory' => $documentData['is_mandatory'] ?? false,
                        ]);
                        
                        $restoredDocuments[] = $existingRequiredDocument;
                    } else {
                        $skippedDocuments[] = [
                            'document_id' => $documentData['document_id'],
                            'reason' => 'Document déjà requis pour cette classe',
                            'action' => 'skipped',
                        ];
                        continue;
                    }
                } else {
                    // Créer le document requis
                    $requiredDocument = ClassRequiredDocument::create([
                        'class_id' => $classId,
                        'document_id' => $documentData['document_id'],
                        'is_mandatory' => $documentData['is_mandatory'] ?? false,
                    ]);
                    
                    $addedDocuments[] = $requiredDocument;
                }
            }
            
            DB::commit();
            
            Log::info('Bulk class required documents added/restored', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'added_count' => count($addedDocuments),
                'restored_count' => count($restoredDocuments),
                'skipped_count' => count($skippedDocuments),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Documents requis ajoutés/restaurés avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'summary' => [
                        'added_count' => count($addedDocuments),
                        'restored_count' => count($restoredDocuments),
                        'skipped_count' => count($skippedDocuments),
                        'total_processed' => count($request->documents),
                    ],
                    'added_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'action' => 'created',
                        ];
                    }, $addedDocuments),
                    'restored_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'action' => 'restored',
                        ];
                    }, $restoredDocuments),
                    'skipped_documents' => $skippedDocuments,
                ],
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk adding class required documents:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout des documents requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * LISTER les documents disponibles pour une classe
     * GET /api/v1/admin/classes/{classId}/available-documents
     */
    public function listAvailableDocuments(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les documents disponibles pour cette classe.',
                ], 403);
            }
            
            // Récupérer les documents déjà requis par cette classe
            $alreadyRequiredDocumentIds = ClassRequiredDocument::where('class_id', $classId)
                ->pluck('document_id')
                ->toArray();
            
            // Récupérer tous les documents disponibles (non encore requis)
            $availableDocuments = InscriptionDocument::whereNotIn('id', $alreadyRequiredDocumentIds)
                ->whereNull('deleted_at')
                ->orderBy('name', 'asc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                    ],
                    'available_documents' => $availableDocuments->map(function ($document) {
                        return [
                            'id' => $document->id,
                            'name' => $document->name,
                            'description' => $document->description,
                            'full_name' => $document->full_name,
                        ];
                    }),
                    'count' => $availableDocuments->count(),
                ],
                'message' => 'Documents disponibles récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing available documents for class:', [
                'error' => $e->getMessage(),
                'class_id' => $classId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des documents disponibles',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}