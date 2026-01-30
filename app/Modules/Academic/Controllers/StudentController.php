<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\ClassModel;
use App\Modules\Schools\Models\School;
use App\Modules\Schools\Models\StudentGroup;
use App\Modules\Academic\Models\Province;
use App\Modules\Academic\Models\City;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Http\UploadedFile;


class StudentController extends Controller
{
    /**
     * GET: Liste tous les étudiants avec pagination et filtres
     * GET /api/v1/admin/students
     */
    public function index(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Construire la requête
            $query = Student::with([
                'school',
                'class',
                'province',
                'city',
                'studentGroup',
                'createdBy',
                'updatedBy'
            ]);
            
            // Si c'est un school_admin, on filtre par son école
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                if ($schoolId) {
                    $query->where('school_id', $schoolId);
                } else {
                    // Si un school_admin n'a pas de school_id, on ne retourne rien
                    $query->whereRaw('1 = 0');
                }
            } elseif (!$currentUser->isSuperAdmin()) {
                // Si l'utilisateur n'est ni school_admin ni super_admin, accès refusé
                return response()->json([
                    'status' => 'error',
                    'message' => 'Accès non autorisé. Rôle requis: school_admin ou super_admin'
                ], 403);
            }
            
            // Pour super_admin, on peut filtrer par école si demandé
            if ($currentUser->isSuperAdmin() && $request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }
            
            // Filtres
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }
            
            if ($request->has('student_group_id')) {
                $query->where('student_group_id', $request->student_group_id);
            }
            
            if ($request->has('is_approved')) {
                $query->where('is_approved', $request->is_approved);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('middle_name', 'like', "%{$search}%")
                      ->orWhere('student_code', 'like', "%{$search}%");
                });
            }
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $students = $query->paginate($perPage);
            
            // Transformer les données pour la réponse
            $transformedStudents = $students->getCollection()->map(function ($student) {
                return [
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'middle_name' => $student->middle_name,
                    'gender' => $student->gender,
                    'birth_date' => $student->birth_date ? $student->birth_date->format('Y-m-d') : null,
                    'school' => $student->school ? [
                        'id' => $student->school->id,
                        'name' => $student->school->name,
                    ] : null,
                    'class' => $student->class ? [
                        'id' => $student->class->id,
                        'name' => $student->class->name,
                    ] : null,
                    'province' => $student->province ? $student->province->name : null,
                    'city' => $student->city ? $student->city->name : null,
                    'street' => $student->street,
                    'student_group' => $student->studentGroup ? [
                        'id' => $student->studentGroup->id,
                        'name' => $student->studentGroup->name,
                    ] : null,
                    'is_approved' => (bool)$student->is_approved,
                    'created_by' => $student->createdBy ? $student->createdBy->full_name : null,
                    'updated_by' => $student->updatedBy ? $student->updatedBy->full_name : null,
                    'created_at' => $student->created_at->toIso8601String(),
                    'updated_at' => $student->updated_at->toIso8601String(),
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'message' => 'Liste des étudiants récupérée avec succès',
                'data' => [
                    'students' => $transformedStudents,
                    'pagination' => [
                        'total' => $students->total(),
                        'per_page' => $students->perPage(),
                        'current_page' => $students->currentPage(),
                        'last_page' => $students->lastPage(),
                        'from' => $students->firstItem(),
                        'to' => $students->lastItem(),
                    ],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching students list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la liste des étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Créer un nouvel étudiant
     * POST /api/v1/admin/students
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Validation
            $validator = Validator::make($request->all(), [
                'class_id' => 'required|exists:classes,id',
                'student_code' => 'required|string|max:50|unique:students,student_code',
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'gender' => 'required|in:male,female,other',
                'birth_date' => 'required|date',
                'province_id' => 'nullable|exists:provinces,id',
                'city_id' => 'nullable|exists:cities,id',
                'street' => 'nullable|string|max:255',
                'student_group_id' => 'nullable|exists:student_groups,id',
                'is_approved' => 'boolean',
            ], [
                'class_id.required' => 'La classe est requise',
                'class_id.exists' => 'La classe sélectionnée n\'existe pas',
                'student_code.required' => 'Le code étudiant est requis',
                'student_code.unique' => 'Ce code étudiant existe déjà',
                'first_name.required' => 'Le prénom est requis',
                'last_name.required' => 'Le nom est requis',
                'gender.required' => 'Le genre est requis',
                'birth_date.required' => 'La date de naissance est requise',
                'province_id.exists' => 'La province sélectionnée n\'existe pas',
                'city_id.exists' => 'La ville sélectionnée n\'existe pas',
                'student_group_id.exists' => 'Le groupe d\'étudiants sélectionné n\'existe pas',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Récupérer la classe
            $class = ClassModel::find($request->class_id);
            if (!$class) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Classe non trouvée',
                ], 404);
            }
            
            // Déterminer le school_id de l'étudiant
            $schoolId = null;
            
            // Si l'utilisateur est school_admin, on utilise son school_id
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                
                // Vérifier que la classe appartient à la même école que l'admin
                if ($class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous ne pouvez pas ajouter un étudiant dans cette classe',
                    ], 403);
                }
            } else {
                // Pour super_admin, on utilise le school_id de la classe
                $schoolId = $class->school_id;
            }
            
            // Créer l'étudiant
            $student = Student::create([
                'school_id' => $schoolId,
                'class_id' => $request->class_id,
                'student_code' => $request->student_code,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'middle_name' => $request->middle_name,
                'gender' => $request->gender,
                'birth_date' => $request->birth_date,
                'province_id' => $request->province_id,
                'city_id' => $request->city_id,
                'street' => $request->street,
                'student_group_id' => $request->student_group_id,
                'is_approved' => $request->boolean('is_approved', true),
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
            
            DB::commit();
            
            // Charger les relations pour la réponse
            $student->load(['school', 'class', 'province', 'city', 'studentGroup', 'createdBy', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant créé avec succès',
                'data' => $student,
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Afficher un étudiant spécifique
     * GET /api/v1/admin/students/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            $student = Student::with([
                'school',
                'class',
                'province',
                'city',
                'studentGroup',
                'createdBy',
                'updatedBy',
                'documents.document',
                'studentFees',
                'inscriptionPayments'
            ])->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            // Récupérer les documents manquants si nécessaire
            $missingDocuments = $student->getMissingRequiredDocuments();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant récupéré avec succès',
                'data' => [
                    'student' => $student,
                    'missing_required_documents' => $missingDocuments,
                    'has_missing_documents' => $missingDocuments->isNotEmpty(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Étudiant non trouvé'
            ], 404);
        }
    }

    /**
     * PUT: Mettre à jour un étudiant
     * PUT /api/v1/admin/students/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $student = Student::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour modifier cet étudiant',
                    ], 403);
                }
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'class_id' => 'sometimes|exists:classes,id',
                'student_code' => 'sometimes|string|max:50|unique:students,student_code,' . $id,
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'gender' => 'sometimes|in:male,female,other',
                'birth_date' => 'sometimes|date',
                'province_id' => 'nullable|exists:provinces,id',
                'city_id' => 'nullable|exists:cities,id',
                'street' => 'nullable|string|max:255',
                'student_group_id' => 'nullable|exists:student_groups,id',
                'is_approved' => 'boolean',
            ], [
                'class_id.exists' => 'La classe sélectionnée n\'existe pas',
                'student_code.unique' => 'Ce code étudiant existe déjà',
                'gender.in' => 'Le genre doit être male, female ou other',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Vérifier que la nouvelle classe appartient à la même école (si modification de la classe)
            if ($request->has('class_id')) {
                $newClass = ClassModel::find($request->class_id);
                if (!$newClass) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Classe non trouvée',
                    ], 404);
                }
                
                // Si l'utilisateur est school_admin, vérifier que la nouvelle classe appartient à son école
                if ($currentUser->isSchoolAdmin()) {
                    $schoolId = $currentUser->getSchoolId();
                    if ($newClass->school_id !== $schoolId) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Vous ne pouvez pas déplacer l\'étudiant dans cette classe',
                        ], 403);
                    }
                }
            }
            
            // Mettre à jour l'étudiant
            $student->update(array_merge(
                $request->except(['school_id']),
                ['updated_by' => $currentUser->id]
            ));
            
            DB::commit();
            
            // Recharger les relations
            $student->load(['school', 'class', 'province', 'city', 'studentGroup', 'updatedBy']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant mis à jour avec succès',
                'data' => $student,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE: Supprimer un étudiant (soft delete)
     * DELETE /api/v1/admin/students/{id}
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $student = Student::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour supprimer cet étudiant',
                    ], 403);
                }
            }
            
            // Vérifier si l'étudiant a des paiements ou frais associés
            if ($student->inscriptionPayments()->exists() || $student->studentFees()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cet étudiant car il a des paiements ou frais associés',
                ], 422);
            }
            
            $student->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant supprimé avec succès',
                'data' => [
                    'student_id' => $id,
                    'deleted_at' => now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Restaurer un étudiant supprimé
     * POST /api/v1/admin/students/{id}/restore
     */
    public function restore(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            $student = Student::withTrashed()->findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour restaurer cet étudiant',
                    ], 403);
                }
            }
            
            if (!$student->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cet étudiant n\'est pas supprimé',
                ], 422);
            }
            
            $student->restore();
            $student->update(['updated_by' => $currentUser->id]);
            
            DB::commit();
            
            $student->load(['school', 'class']);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant restauré avec succès',
                'data' => $student,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la restauration de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Récupérer les étudiants par classe
     * GET /api/v1/admin/classes/{classId}/students
     */
    public function getByClass(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            $class = ClassModel::findOrFail($classId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette classe',
                    ], 403);
                }
            }
            
            $students = Student::where('class_id', $classId)
                ->with(['province', 'city', 'studentGroup', 'documents'])
                ->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiants de la classe récupérés avec succès',
                'data' => [
                    'class' => $class,
                    'students' => $students,
                    'count' => $students->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching class students: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'class_id' => $classId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des étudiants de la classe'
            ], 500);
        }
    }

    /**
     * GET: Générer un code étudiant unique
     * GET /api/v1/admin/students/generate-code
     */
    public function generateStudentCode(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Déterminer le code d'école
            $schoolCode = 'SCHOOL';
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($schoolId) {
                    $school = School::find($schoolId);
                    if ($school) {
                        $schoolCode = Str::upper(Str::substr($school->name, 0, 4));
                    }
                }
            }
            
            do {
                $year = date('Y');
                $month = date('m');
                $random = Str::upper(Str::random(4));
                
                $studentCode = "{$schoolCode}-{$year}{$month}-{$random}";
            } while (Student::where('student_code', $studentCode)->exists());
            
            return response()->json([
                'status' => 'success',
                'message' => 'Code étudiant généré avec succès',
                'data' => [
                    'student_code' => $studentCode,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating student code: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la génération du code étudiant'
            ], 500);
        }
    }

    /**
     * POST: Approuver un étudiant
     * POST /api/v1/admin/students/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $student = Student::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour approuver cet étudiant',
                    ], 403);
                }
            }
            
            $student->update([
                'is_approved' => true,
                'updated_by' => $currentUser->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant approuvé avec succès',
                'data' => $student,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error approving student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'approbation de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Désapprouver un étudiant
     * POST /api/v1/admin/students/{id}/disapprove
     */
    public function disapprove(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $student = Student::findOrFail($id);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas les permissions pour désapprouver cet étudiant',
                    ], 403);
                }
            }
            
            $student->update([
                'is_approved' => false,
                'updated_by' => $currentUser->id,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Étudiant désapprouvé avec succès',
                'data' => $student,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error disapproving student: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la désapprobation de l\'étudiant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET: Exporter les étudiants avec FastExcel
     * GET /api/v1/admin/students/export
     */
    public function export(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            $query = Student::with(['school', 'class', 'studentGroup']);
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($schoolId) {
                    $query->where('school_id', $schoolId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
            
            $students = $query->get();
            
            $exportData = $students->map(function ($student) {
                return [
                    'ID' => $student->id,
                    'Code étudiant' => $student->student_code,
                    'Nom' => $student->last_name,
                    'Prénom' => $student->first_name,
                    'Nom du milieu' => $student->middle_name ?? '',
                    'Genre' => $student->gender,
                    'Date de naissance' => $student->birth_date ? $student->birth_date->format('Y-m-d') : '',
                    'École' => $student->school->name ?? '',
                    'Classe' => $student->class->name ?? '',
                    'Groupe' => $student->studentGroup->name ?? '',
                    'Province' => $student->province->name ?? '',
                    'Ville' => $student->city->name ?? '',
                    'Rue' => $student->street ?? '',
                    'Approuvé' => $student->is_approved ? 'Oui' : 'Non',
                    'Date de création' => $student->created_at->format('Y-m-d H:i:s'),
                    'Date de mise à jour' => $student->updated_at->format('Y-m-d H:i:s'),
                ];
            });
            
            $fileName = 'etudiants_' . date('Y-m-d_His') . '.xlsx';
            
            return (new FastExcel($exportData))->download($fileName);
            
        } catch (\Exception $e) {
            Log::error('Error exporting students with FastExcel: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'exportation des étudiants'
            ], 500);
        }
    }

    /**
     * GET: Télécharger le template d'importation
     * GET /api/v1/admin/students/import-template
     */
    public function downloadImportTemplate(Request $request)
    {
        try {
            $templateData = collect([
                [
                    'Code étudiant' => 'ETU-2024-001',
                    'Nom' => 'Doe',
                    'Prénom' => 'John',
                    'Nom du milieu' => 'Michael',
                    'Genre' => 'male',
                    'Date de naissance' => '2010-05-15',
                    'Code de la classe' => 'PRIM1-2024',
                    'Code du groupe' => 'GROUPE-A',
                    'Province' => 'Kinshasa',
                    'Ville' => 'Gombe',
                    'Rue' => '123 Avenue Principale',
                    'Approuvé' => 'oui',
                ]
            ]);
            
            $fileName = 'template_import_etudiants_' . date('Y-m-d') . '.xlsx';
            
            return (new FastExcel($templateData))->download($fileName, function ($row) {
                return [
                    'Code étudiant' => $row['Code étudiant'] ?? '',
                    'Nom' => $row['Nom'] ?? '',
                    'Prénom' => $row['Prénom'] ?? '',
                    'Nom du milieu' => $row['Nom du milieu'] ?? '',
                    'Genre' => $row['Genre'] ?? '',
                    'Date de naissance' => $row['Date de naissance'] ?? '',
                    'Code de la classe' => $row['Code de la classe'] ?? '',
                    'Code du groupe' => $row['Code du groupe'] ?? '',
                    'Province' => $row['Province'] ?? '',
                    'Ville' => $row['Ville'] ?? '',
                    'Rue' => $row['Rue'] ?? '',
                    'Approuvé' => $row['Approuvé'] ?? '',
                ];
            });
            
        } catch (\Exception $e) {
            Log::error('Error downloading import template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du téléchargement du template'
            ], 500);
        }
    }

    /**
     * POST: Importer des étudiants depuis un fichier Excel
     * POST /api/v1/admin/students/import
     */
    public function import(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
                'class_id' => 'nullable|exists:classes,id',
            ], [
                'file.required' => 'Le fichier est requis',
                'file.mimes' => 'Le fichier doit être au format Excel (xlsx, xls) ou CSV',
                'file.max' => 'Le fichier ne doit pas dépasser 10MB',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $file = $request->file('file');
            $classId = $request->class_id;
            
            // Vérifier que la classe appartient à la même école (si school_admin)
            if ($classId && $currentUser->isSchoolAdmin()) {
                $class = ClassModel::find($classId);
                $schoolId = $currentUser->getSchoolId();
                if (!$class || $class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous ne pouvez pas importer des étudiants dans cette classe',
                    ], 403);
                }
            }
            
            $rows = (new FastExcel)->import($file);
            
            $importedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $errors = [];
            
            foreach ($rows as $index => $row) {
                try {
                    $rowNumber = $index + 2;
                    
                    if (empty($row['Code étudiant']) || empty($row['Nom']) || empty($row['Prénom'])) {
                        $errors[] = "Ligne {$rowNumber}: Code étudiant, Nom et Prénom sont requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    $studentClass = null;
                    if ($classId) {
                        $studentClass = ClassModel::find($classId);
                    } elseif (!empty($row['Code de la classe'])) {
                        $studentClass = ClassModel::where('code', $row['Code de la classe'])->first();
                        
                        if (!$studentClass) {
                            $errors[] = "Ligne {$rowNumber}: Classe avec code '{$row['Code de la classe']}' non trouvée";
                            $skippedCount++;
                            continue;
                        }
                        
                        if ($currentUser->isSchoolAdmin()) {
                            $schoolId = $currentUser->getSchoolId();
                            if ($studentClass->school_id !== $schoolId) {
                                $errors[] = "Ligne {$rowNumber}: Vous n'avez pas accès à la classe '{$row['Code de la classe']}'";
                                $skippedCount++;
                                continue;
                            }
                        }
                    } else {
                        $errors[] = "Ligne {$rowNumber}: Code de la classe est requis";
                        $skippedCount++;
                        continue;
                    }
                    
                    $studentGroupId = null;
                    if (!empty($row['Code du groupe'])) {
                        $studentGroup = StudentGroup::where('code', $row['Code du groupe'])->first();
                        if ($studentGroup) {
                            $studentGroupId = $studentGroup->id;
                        }
                    }
                    
                    $provinceId = null;
                    $cityId = null;
                    
                    if (!empty($row['Province'])) {
                        $province = Province::where('name', $row['Province'])->first();
                        if ($province) {
                            $provinceId = $province->id;
                            
                            if (!empty($row['Ville'])) {
                                $city = City::where('name', $row['Ville'])->where('province_id', $provinceId)->first();
                                if ($city) {
                                    $cityId = $city->id;
                                }
                            }
                        }
                    }
                    
                    $existingStudent = Student::where('student_code', $row['Code étudiant'])->first();
                    
                    $schoolIdForStudent = null;
                    if ($currentUser->isSchoolAdmin()) {
                        $schoolIdForStudent = $currentUser->getSchoolId();
                    } else {
                        $schoolIdForStudent = $studentClass->school_id;
                    }
                    
                    $studentData = [
                        'school_id' => $schoolIdForStudent,
                        'class_id' => $studentClass->id,
                        'student_code' => $row['Code étudiant'],
                        'first_name' => $row['Prénom'],
                        'last_name' => $row['Nom'],
                        'middle_name' => $row['Nom du milieu'] ?? null,
                        'gender' => in_array(strtolower($row['Genre'] ?? ''), ['male', 'female', 'other']) 
                            ? strtolower($row['Genre']) 
                            : 'other',
                        'birth_date' => !empty($row['Date de naissance']) ? $row['Date de naissance'] : null,
                        'province_id' => $provinceId,
                        'city_id' => $cityId,
                        'street' => $row['Rue'] ?? null,
                        'student_group_id' => $studentGroupId,
                        'is_approved' => in_array(strtolower($row['Approuvé'] ?? ''), ['oui', 'yes', 'true', '1']) ? true : false,
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ];
                    
                    if ($existingStudent) {
                        $existingStudent->update($studentData);
                        $updatedCount++;
                    } else {
                        Student::create($studentData);
                        $importedCount++;
                    }
                    
                } catch (\Exception $e) {
                    $errors[] = "Ligne {$rowNumber}: " . $e->getMessage();
                    $skippedCount++;
                    continue;
                }
            }
            
            DB::commit();
            
            $response = [
                'status' => 'success',
                'message' => 'Importation terminée avec succès',
                'data' => [
                    'imported_count' => $importedCount,
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'total_processed' => $importedCount + $updatedCount + $skippedCount,
                ],
            ];
            
            if (!empty($errors)) {
                $response['warnings'] = [
                    'error_count' => count($errors),
                    'errors' => $errors,
                ];
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing students: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'importation des étudiants: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST: Importer et remplacer tous les étudiants
     * POST /api/v1/admin/students/import-replace
     */
    public function importReplace(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
                'class_id' => 'required|exists:classes,id',
                'confirm' => 'required|boolean',
            ], [
                'file.required' => 'Le fichier est requis',
                'class_id.required' => 'La classe est requise',
                'confirm.required' => 'Vous devez confirmer le remplacement',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            if (!$request->boolean('confirm')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous devez confirmer le remplacement des étudiants',
                ], 422);
            }
            
            $classId = $request->class_id;
            $class = ClassModel::find($classId);
            
            if (!$class) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Classe non trouvée',
                ], 404);
            }
            
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cette classe',
                    ], 403);
                }
            }
            
            $deletedCount = Student::where('class_id', $classId)->delete();
            
            $file = $request->file('file');
            $rows = (new FastExcel)->import($file);
            
            $importedCount = 0;
            $errors = [];
            
            $schoolIdForStudents = null;
            if ($currentUser->isSchoolAdmin()) {
                $schoolIdForStudents = $currentUser->getSchoolId();
            } else {
                $schoolIdForStudents = $class->school_id;
            }
            
            foreach ($rows as $index => $row) {
                try {
                    $rowNumber = $index + 2;
                    
                    if (empty($row['Code étudiant']) || empty($row['Nom']) || empty($row['Prénom'])) {
                        $errors[] = "Ligne {$rowNumber}: Code étudiant, Nom et Prénom sont requis";
                        continue;
                    }
                    
                    Student::create([
                        'school_id' => $schoolIdForStudents,
                        'class_id' => $classId,
                        'student_code' => $row['Code étudiant'],
                        'first_name' => $row['Prénom'],
                        'last_name' => $row['Nom'],
                        'middle_name' => $row['Nom du milieu'] ?? null,
                        'gender' => in_array(strtolower($row['Genre'] ?? ''), ['male', 'female', 'other']) 
                            ? strtolower($row['Genre']) 
                            : 'other',
                        'birth_date' => !empty($row['Date de naissance']) ? $row['Date de naissance'] : null,
                        'province_id' => null,
                        'city_id' => null,
                        'street' => $row['Rue'] ?? null,
                        'student_group_id' => null,
                        'is_approved' => true,
                        'created_by' => $currentUser->id,
                        'updated_by' => $currentUser->id,
                    ]);
                    
                    $importedCount++;
                    
                } catch (\Exception $e) {
                    $errors[] = "Ligne {$rowNumber}: " . $e->getMessage();
                    continue;
                }
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Remplacement des étudiants terminé',
                'data' => [
                    'deleted_count' => $deletedCount,
                    'imported_count' => $importedCount,
                    'error_count' => count($errors),
                    'errors' => $errors,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in import replace: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'class_id' => $request->class_id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du remplacement des étudiants',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }


}