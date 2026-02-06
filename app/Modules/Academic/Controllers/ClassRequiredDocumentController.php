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
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;




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
     * Formater les données du document requis avec is_deleted
     */
    private function formatRequiredDocument($requiredDoc)
    {
        return [
            'id' => $requiredDoc->id,
            'document' => $requiredDoc->document ? [
                'id' => $requiredDoc->document->id,
                'name' => $requiredDoc->document->name,
                'description' => $requiredDoc->document->description,
                'full_name' => $requiredDoc->document->full_name,
            ] : null,
            'is_mandatory' => $requiredDoc->is_mandatory,
            'deleted_at' => $requiredDoc->deleted_at,
            'is_deleted' => !is_null($requiredDoc->deleted_at), // Calculé à partir de deleted_at
            'created_at' => $requiredDoc->created_at,
        ];
    }

    /**
     * LISTER TOUS les documents requis d'une classe (y compris supprimés)
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
            
            // Récupérer TOUS les documents (même supprimés)
            $requiredDocuments = ClassRequiredDocument::withTrashed()  // <-- Ajoutez withTrashed()
                ->where('class_id', $classId)
                ->with(['document'])
                ->orderBy('deleted_at', 'asc')  // <-- Trier par statut de suppression
                ->orderBy('is_mandatory', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Statistiques incluant les documents supprimés
            $totalCount = $requiredDocuments->count();
            $activeDocuments = $requiredDocuments->whereNull('deleted_at');
            $deletedDocuments = $requiredDocuments->whereNotNull('deleted_at');
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                    ],
                    'required_documents' => $requiredDocuments->map(function ($requiredDoc) {
                        return $this->formatRequiredDocument($requiredDoc);
                    }),
                    'counts' => [
                        'total' => $totalCount,
                        'active' => $activeDocuments->count(),
                        'deleted' => $deletedDocuments->count(),
                        'mandatory_active' => $activeDocuments->where('is_mandatory', true)->count(),
                        'optional_active' => $activeDocuments->where('is_mandatory', false)->count(),
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
     * LISTER les documents requis supprimés d'une classe
     * GET /api/v1/admin/classes/{classId}/required-documents/deleted
     */
    public function indexDeleted(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour voir les documents supprimés de cette classe.',
                ], 403);
            }
            
            // Récupérer uniquement les documents supprimés (deleted_at NOT NULL)
            $deletedDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->whereNotNull('deleted_at')
                ->with(['document'])
                ->orderBy('deleted_at', 'desc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                    ],
                    'deleted_documents' => $deletedDocuments->map(function ($requiredDoc) {
                        return $this->formatRequiredDocument($requiredDoc);
                    }),
                    'counts' => [
                        'total_deleted' => $deletedDocuments->count(),
                        'mandatory_deleted' => $deletedDocuments->where('is_mandatory', true)->count(),
                        'optional_deleted' => $deletedDocuments->where('is_mandatory', false)->count(),
                    ],
                ],
                'message' => 'Documents supprimés récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Class required document deleted index error:', [
                'error' => $e->getMessage(),
                'class_id' => $classId
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des documents supprimés',
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
            
            $documentId = $request->document_id;
            $isMandatory = $request->input('is_mandatory', false);
            
            // Rechercher TOUS les documents (même supprimés) avec cette combinaison class_id/document_id
            $existingRequiredDocument = ClassRequiredDocument::withTrashed()
                ->where('class_id', $classId)
                ->where('document_id', $documentId)
                ->first();
            
            $action = 'created';
            $requiredDocument = null;
            
            if ($existingRequiredDocument) {
                if ($existingRequiredDocument->deleted_at !== null) {
                    // CAS 1: Document existe mais est supprimé => RESTAURATION
                    $existingRequiredDocument->restore();
                    $existingRequiredDocument->update([
                        'is_mandatory' => $isMandatory,
                    ]);
                    $requiredDocument = $existingRequiredDocument;
                    $action = 'restored';
                    
                    Log::info('Class required document restored during store', [
                        'admin_id' => $currentUser->id,
                        'class_id' => $classId,
                        'document_id' => $documentId,
                        'previous_deleted_at' => $existingRequiredDocument->deleted_at,
                    ]);
                } else {
                    // CAS 2: Document existe et est actif => ERREUR
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ce document est déjà requis pour cette classe.',
                        'details' => [
                            'document_id' => $documentId,
                            'existing_document_id' => $existingRequiredDocument->id,
                            'is_mandatory_current' => $existingRequiredDocument->is_mandatory,
                        ],
                    ], 422);
                }
            } else {
                // CAS 3: Document n'existe pas => CRÉATION
                // Vérifier d'abord s'il existe une contrainte d'unicité violée même après restauration
                // Cette vérification est nécessaire car même avec withTrashed(), un deleted_at non null
                // peut toujours violer la contrainte d'unicité si elle n'inclut pas deleted_at
                try {
                    $requiredDocument = ClassRequiredDocument::create([
                        'class_id' => $classId,
                        'document_id' => $documentId,
                        'is_mandatory' => $isMandatory,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Si erreur de contrainte d'unicité, tenter une restauration
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false && 
                        strpos($e->getMessage(), 'class_required_documents_class_id_document_id_unique') !== false) {
                        
                        // Rechercher l'enregistrement supprimé qui cause la violation
                        $deletedRecord = ClassRequiredDocument::withTrashed()
                            ->where('class_id', $classId)
                            ->where('document_id', $documentId)
                            ->whereNotNull('deleted_at')
                            ->first();
                        
                        if ($deletedRecord) {
                            $deletedRecord->restore();
                            $deletedRecord->update([
                                'is_mandatory' => $isMandatory,
                            ]);
                            $requiredDocument = $deletedRecord;
                            $action = 'restored_from_duplicate';
                            
                            Log::info('Document restored from duplicate constraint violation', [
                                'admin_id' => $currentUser->id,
                                'class_id' => $classId,
                                'document_id' => $documentId,
                                'duplicate_id' => $deletedRecord->id,
                            ]);
                        } else {
                            throw $e; // Relancer l'exception si on ne trouve pas l'enregistrement supprimé
                        }
                    } else {
                        throw $e; // Relancer l'exception si c'est une autre erreur
                    }
                }
            }
            
            // Charger les relations
            $requiredDocument->load(['document', 'class']);
            
            DB::commit();
            
            $message = match($action) {
                'restored' => 'Document requis restauré avec succès',
                'restored_from_duplicate' => 'Document requis restauré (existait déjà en version supprimée)',
                default => 'Document requis ajouté avec succès'
            };
            
            Log::info('Class required document processed', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'document_id' => $documentId,
                'action' => $action,
                'is_mandatory' => $isMandatory,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'required_document' => $this->formatRequiredDocument($requiredDocument),
                    'action_performed' => $action,
                    'was_restored' => in_array($action, ['restored', 'restored_from_duplicate']),
                ],
            ], 201);
            
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            Log::error('Database error adding class required document:', [
                'error' => $e->getMessage(),
                'class_id' => $classId,
                'document_id' => $request->input('document_id'),
                'sql_state' => $e->errorInfo[0] ?? null,
                'sql_error' => $e->errorInfo[2] ?? null,
            ]);
            
            // Message d'erreur spécifique pour violation de contrainte d'unicité
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce document existe déjà pour cette classe (même en version supprimée).',
                    'details' => 'Le système a tenté de restaurer le document supprimé mais a échoué.',
                    'error' => env('APP_DEBUG') ? $e->getMessage() : null,
                ], 422);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de base de données lors de l\'ajout du document requis.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
            
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
                ->whereNull('deleted_at')
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
                        'deleted_at' => $requiredDocument->deleted_at,
                        'is_deleted' => !is_null($requiredDocument->deleted_at),
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
            
            // Récupérer les IDs des documents dans la requête
            $requestDocumentIds = collect($request->documents)->pluck('document_id')->toArray();
            
            // Récupérer tous les documents actuellement actifs pour cette classe
            $currentDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->whereNull('deleted_at')
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
                $existingDocument = ClassRequiredDocument::where('class_id', $classId)
                    ->where('document_id', $documentId)
                    ->first();
                
                if ($existingDocument) {
                    // Si l'enregistrement existe
                    if ($existingDocument->deleted_at !== null) {
                        // Restaurer l'enregistrement supprimé
                        $existingDocument->update([
                            'deleted_at' => null,
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
                    // Ce document est actuellement actif mais n'est pas dans la requête => le marquer comme supprimé
                    $currentDocument->update([
                        'deleted_at' => Carbon::now(),
                    ]);
                    $deletedDocuments[] = [
                        'id' => $currentDocument->id,
                        'document_id' => $currentDocument->document_id,
                        'document_name' => $currentDocument->document->name ?? 'Inconnu',
                        'is_deleted' => true,
                    ];
                }
            }
            
            // Récupérer les documents requis actuels après les modifications
            $currentRequiredDocuments = ClassRequiredDocument::where('class_id', $classId)
                ->whereNull('deleted_at')
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
                            'is_deleted' => !is_null($requiredDoc->deleted_at),
                        ];
                    }),
                    'updated_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'is_deleted' => !is_null($doc->deleted_at),
                            'action' => 'updated',
                        ];
                    }, $updatedDocuments),
                    'added_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'is_deleted' => !is_null($doc->deleted_at),
                            'action' => 'created',
                        ];
                    }, $addedDocuments),
                    'restored_documents' => array_map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_id' => $doc->document_id,
                            'is_mandatory' => $doc->is_mandatory,
                            'is_deleted' => !is_null($doc->deleted_at),
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
     * SUPPRIMER un document requis (soft delete)
     * DELETE /api/v1/admin/classes/{classId}/required-documents/{id}
     */
    public function destroy(Request $request, $classId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // MODIFIER : Chercher le document même s'il est supprimé
            $requiredDocument = ClassRequiredDocument::withTrashed()
                ->where('class_id', $classId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer les documents requis de cette classe.',
                ], 403);
            }
            
            // Vérifier si le document est déjà supprimé
            if ($requiredDocument->deleted_at !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce document requis est déjà supprimé.',
                ], 422);
            }
            
            // Soft delete le document requis avec la méthode delete() d'Eloquent
            $requiredDocument->delete();
            
            DB::commit();
            
            Log::info('Class required document soft deleted', [
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
                        'id' => $requiredDocument->id,
                        'document_id' => $requiredDocument->document_id,
                        'document_name' => $requiredDocument->document->name ?? 'Inconnu',
                        'deleted_at' => $requiredDocument->deleted_at,
                        'is_deleted' => true,
                    ],
                ],
            ]);
            
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            Log::warning('Attempt to delete non-existent class required document', [
                'admin_id' => $request->user()->id ?? null,
                'class_id' => $classId,
                'required_document_id' => $id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Le document requis demandé n\'existe pas ou n\'appartient pas à cette classe.',
            ], 404);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error soft deleting class required document:', [
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
     * RESTAURER un document requis supprimé
     * POST /api/v1/admin/classes/{classId}/required-documents/{id}/restore
     */
    public function restore(Request $request, $classId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // CORRECTION : Utiliser withTrashed() pour inclure les documents supprimés
            $requiredDocument = ClassRequiredDocument::withTrashed()
                ->where('class_id', $classId)
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour restaurer les documents requis de cette classe.',
                ], 403);
            }
            
            // Vérifier si le document est déjà actif
            if ($requiredDocument->deleted_at === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce document requis n\'est pas supprimé.',
                ], 422);
            }
            
            // Restaurer le document requis avec la méthode restore() d'Eloquent
            $requiredDocument->restore();
            
            // Recharger le modèle pour avoir les données fraîches
            $requiredDocument->refresh();
            
            // Charger les relations
            $requiredDocument->load(['document', 'class']);
            
            DB::commit();
            
            Log::info('Class required document restored', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'required_document_id' => $id,
                'document_name' => $requiredDocument->document->name ?? 'Inconnu',
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document requis restauré avec succès',
                'data' => [
                    'restored_document' => [
                        'id' => $requiredDocument->id,
                        'document' => $requiredDocument->document ? [
                            'id' => $requiredDocument->document->id,
                            'name' => $requiredDocument->document->name,
                            'description' => $requiredDocument->document->description,
                        ] : null,
                        'class_id' => $requiredDocument->class_id,
                        'is_mandatory' => $requiredDocument->is_mandatory,
                        'deleted_at' => null,
                        'is_deleted' => false,
                        'restored_at' => Carbon::now(),
                    ],
                ],
            ]);
            
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            Log::warning('Attempt to restore non-existent class required document', [
                'admin_id' => $request->user()->id ?? null,
                'class_id' => $classId,
                'required_document_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Le document requis demandé n\'existe pas ou n\'appartient pas à cette classe.',
            ], 404);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring class required document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'required_document_id' => $id,
                'admin_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration du document requis: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * SUPPRIMER DÉFINITIVEMENT un document requis (force delete)
     * DELETE /api/v1/admin/classes/{classId}/required-documents/{id}/force
     */
    public function forceDestroy(Request $request, $classId, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            $requiredDocument = ClassRequiredDocument::where('class_id', $classId)
                ->whereNotNull('deleted_at')
                ->findOrFail($id);
            
            // Vérifier les permissions
            if (!$this->checkClassRequiredDocumentPermissions($currentUser, $class)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous n\'avez pas les permissions pour supprimer définitivement les documents requis de cette classe.',
                ], 403);
            }
            
            // Sauvegarder les informations avant suppression
            $documentInfo = [
                'document_id' => $requiredDocument->document_id,
                'document_name' => $requiredDocument->document->name ?? 'Inconnu',
                'is_mandatory' => $requiredDocument->is_mandatory,
                'deleted_at' => $requiredDocument->deleted_at,
                'is_deleted' => true,
            ];
            
            // Supprimer définitivement le document requis
            $requiredDocument->forceDelete();
            
            DB::commit();
            
            Log::info('Class required document force deleted', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'required_document_id' => $id,
                'document_name' => $documentInfo['document_name'],
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Document requis supprimé définitivement avec succès',
                'data' => [
                    'force_deleted_document' => $documentInfo,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error force deleting class required document:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'required_document_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression définitive du document requis: ' . $e->getMessage(),
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
            $failedDocuments = [];
            
            foreach ($request->documents as $index => $documentData) {
                try {
                    $documentId = $documentData['document_id'];
                    $isMandatory = $documentData['is_mandatory'] ?? false;
                    
                    // Rechercher TOUS les documents (même supprimés)
                    $existingRequiredDocument = ClassRequiredDocument::withTrashed()
                        ->where('class_id', $classId)
                        ->where('document_id', $documentId)
                        ->first();
                    
                    if ($existingRequiredDocument) {
                        if ($existingRequiredDocument->deleted_at !== null) {
                            // CAS 1: Document supprimé => RESTAURATION
                            $existingRequiredDocument->restore();
                            $existingRequiredDocument->update([
                                'is_mandatory' => $isMandatory,
                            ]);
                            
                            $restoredDocuments[] = [
                                'document' => $existingRequiredDocument,
                                'index' => $index,
                                'action' => 'restored',
                            ];
                        } else {
                            // CAS 2: Document déjà actif => SKIP
                            $skippedDocuments[] = [
                                'index' => $index,
                                'document_id' => $documentId,
                                'existing_id' => $existingRequiredDocument->id,
                                'reason' => 'Document déjà requis pour cette classe',
                                'action' => 'skipped',
                            ];
                            continue;
                        }
                    } else {
                        // CAS 3: Nouveau document => CRÉATION (avec gestion d'erreur)
                        try {
                            $requiredDocument = ClassRequiredDocument::create([
                                'class_id' => $classId,
                                'document_id' => $documentId,
                                'is_mandatory' => $isMandatory,
                            ]);
                            
                            $addedDocuments[] = [
                                'document' => $requiredDocument,
                                'index' => $index,
                                'action' => 'created',
                            ];
                        } catch (\Illuminate\Database\QueryException $e) {
                            // Si violation de contrainte d'unicité, tenter une restauration
                            if (strpos($e->getMessage(), 'Duplicate entry') !== false && 
                                strpos($e->getMessage(), 'class_required_documents_class_id_document_id_unique') !== false) {
                                
                                $deletedRecord = ClassRequiredDocument::withTrashed()
                                    ->where('class_id', $classId)
                                    ->where('document_id', $documentId)
                                    ->whereNotNull('deleted_at')
                                    ->first();
                                
                                if ($deletedRecord) {
                                    $deletedRecord->restore();
                                    $deletedRecord->update([
                                        'is_mandatory' => $isMandatory,
                                    ]);
                                    
                                    $restoredDocuments[] = [
                                        'document' => $deletedRecord,
                                        'index' => $index,
                                        'action' => 'restored_from_duplicate',
                                    ];
                                } else {
                                    $failedDocuments[] = [
                                        'index' => $index,
                                        'document_id' => $documentId,
                                        'error' => 'Violation de contrainte d\'unicité sans enregistrement supprimé trouvé',
                                        'action' => 'failed',
                                    ];
                                }
                            } else {
                                $failedDocuments[] = [
                                    'index' => $index,
                                    'document_id' => $documentId,
                                    'error' => $e->getMessage(),
                                    'action' => 'failed',
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $failedDocuments[] = [
                        'index' => $index,
                        'document_id' => $documentData['document_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'action' => 'failed',
                    ];
                }
            }
            
            // Si tous les documents ont échoué, rollback
            if (count($failedDocuments) === count($request->documents)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tous les documents ont échoué lors de l\'ajout.',
                    'data' => [
                        'failed_documents' => $failedDocuments,
                    ],
                ], 422);
            }
            
            DB::commit();
            
            Log::info('Bulk class required documents processed', [
                'admin_id' => $currentUser->id,
                'class_id' => $classId,
                'added_count' => count($addedDocuments),
                'restored_count' => count($restoredDocuments),
                'skipped_count' => count($skippedDocuments),
                'failed_count' => count($failedDocuments),
            ]);
            
            // Fonction pour formater un document dans le résultat
            $formatDocumentResult = function ($docInfo) {
                $doc = $docInfo['document'];
                return [
                    'id' => $doc->id,
                    'document_id' => $doc->document_id,
                    'is_mandatory' => $doc->is_mandatory,
                    'deleted_at' => $doc->deleted_at,
                    'is_deleted' => $doc->deleted_at !== null,
                    'action' => $docInfo['action'],
                    'original_index' => $docInfo['index'],
                ];
            };
            
            return response()->json([
                'status' => count($failedDocuments) > 0 ? 'partial_success' : 'success',
                'message' => count($failedDocuments) > 0 
                    ? 'Documents partiellement ajoutés/restaurés' 
                    : 'Documents requis ajoutés/restaurés avec succès',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                    ],
                    'summary' => [
                        'total_processed' => count($request->documents),
                        'added_count' => count($addedDocuments),
                        'restored_count' => count($restoredDocuments),
                        'skipped_count' => count($skippedDocuments),
                        'failed_count' => count($failedDocuments),
                        'success_count' => count($addedDocuments) + count($restoredDocuments) + count($skippedDocuments),
                    ],
                    'results' => [
                        'added_documents' => array_map($formatDocumentResult, $addedDocuments),
                        'restored_documents' => array_map($formatDocumentResult, $restoredDocuments),
                        'skipped_documents' => $skippedDocuments,
                        'failed_documents' => $failedDocuments,
                    ],
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
            
            // Récupérer les documents déjà requis par cette classe (non supprimés)
            $alreadyRequiredDocumentIds = ClassRequiredDocument::where('class_id', $classId)
                ->whereNull('deleted_at')
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