<?php

namespace App\Modules\Academic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\ClassModel;
use App\Modules\Schools\Models\School;
use App\Modules\Shared\Models\Pivots\StudentParent;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ParentChildController extends Controller
{

    /**
     * Vérifier si l'utilisateur est un parent
    */
    private function isParent(User $user)
    {
        // Vérifier via la relation userRoles
        return $user->userRoles()
            ->whereHas('role', function($query) {
                $query->where('name', 'parent');
            })
            ->exists();
    }

    /**
     * Rechercher les écoles (autocomplétion)
     * GET /api/v1/parent/schools/search
     */
    public function searchSchools(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$currentUser->isParent()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'search' => 'required|string|min:2',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le terme de recherche doit contenir au moins 2 caractères.',
                ], 422);
            }
            
            $search = $request->input('search');
            
            $schools = School::where(function($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                })
                ->whereNull('deleted_at')
                ->with(['type'])
                ->orderBy('name')
                ->limit(10)
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'schools' => $schools->map(function ($school) {
                        return [
                            'id' => $school->id,
                            'name' => $school->name,
                            'type' => $school->type ? $school->type->name : null,
                            'address' => $school->address,
                            'phone' => $school->phone,
                            'logo_url' => $school->logo_path ? asset('storage/' . $school->logo_path) : null,
                        ];
                    }),
                    'count' => $schools->count(),
                ],
                'message' => 'Écoles trouvées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error searching schools:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la recherche des écoles.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Récupérer les classes d'une école
     * GET /api/v1/parent/schools/{schoolId}/classes
     */
    public function getSchoolClasses(Request $request, $schoolId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$this->isParent($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $school = School::whereNull('deleted_at')
                ->findOrFail($schoolId);
            
            $currentDate = Carbon::now();
            
            $classes = ClassModel::where('school_id', $schoolId)
                ->whereNull('deleted_at')
                ->whereHas('schoolYear', function($query) use ($currentDate) {
                    // Filtrer par années scolaires actives ou non terminées
                    $query->where(function($q) use ($currentDate) {
                        $q->where('is_active', true)
                        ->orWhere('end_date', '>=', $currentDate);
                    });
                })
                ->with(['schoolYear'])
                ->orderBy('level')
                ->orderBy('name')
                ->get();
            
            // Si aucune classe avec année scolaire active, récupérer toutes les classes
            if ($classes->isEmpty()) {
                $classes = ClassModel::where('school_id', $schoolId)
                    ->whereNull('deleted_at')
                    ->with(['schoolYear'])
                    ->orderBy('level')
                    ->orderBy('name')
                    ->get();
            }
            
            // Grouper les classes par niveau
            $groupedClasses = $classes->groupBy('level');
            
            $formattedClasses = [];
            foreach ($groupedClasses as $level => $classGroup) {
                $formattedClasses[] = [
                    'level' => $level,
                    'classes' => $classGroup->map(function ($class) {
                        return [
                            'id' => $class->id,
                            'name' => $class->name,
                            'level' => $class->level,
                            'school_year' => $class->schoolYear ? [
                                'id' => $class->schoolYear->id,
                                'year_label' => $class->schoolYear->year_label,  // Changé ici
                                'start_date' => $class->schoolYear->start_date,
                                'end_date' => $class->schoolYear->end_date,
                            ] : null,
                        ];
                    })->values(),
                ];
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'school' => [
                        'id' => $school->id,
                        'name' => $school->name,
                    ],
                    'classes_by_level' => $formattedClasses,
                    'total_classes' => $classes->count(),
                ],
                'message' => 'Classes récupérées avec succès',
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'École non trouvée.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting school classes:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'school_id' => $schoolId,
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des classes.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Récupérer les étudiants d'une classe
     * GET /api/v1/parent/classes/{classId}/students
     */
    public function getClassStudents(Request $request, $classId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$currentUser->isParent()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $class = ClassModel::whereNull('deleted_at')
                ->with(['school'])
                ->findOrFail($classId);
            
            // Récupérer les étudiants de la classe
            $students = Student::where('class_id', $classId)
                ->whereNull('deleted_at')
                ->where('is_approved', true) // Seuls les étudiants approuvés
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();
            
            // Vérifier quels étudiants sont déjà liés à ce parent
            $parentId = $currentUser->parent ? $currentUser->parent->id : null;
            
            if ($parentId) {
                $linkedStudentIds = StudentParent::where('parent_id', $parentId)
                    ->whereNull('deleted_at')
                    ->pluck('student_id')
                    ->toArray();
            } else {
                $linkedStudentIds = [];
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'level' => $class->level,
                        'school' => $class->school ? [
                            'id' => $class->school->id,
                            'name' => $class->school->name,
                        ] : null,
                    ],
                    'students' => $students->map(function ($student) use ($linkedStudentIds) {
                        return [
                            'id' => $student->id,
                            'student_code' => $student->student_code,
                            'full_name' => trim($student->first_name . ' ' . ($student->middle_name ? $student->middle_name . ' ' : '') . $student->last_name),
                            'first_name' => $student->first_name,
                            'last_name' => $student->last_name,
                            'middle_name' => $student->middle_name,
                            'gender' => $student->gender,
                            'birth_date' => $student->birth_date,
                            'age' => $student->birth_date ? Carbon::parse($student->birth_date)->age : null,
                            'is_linked' => in_array($student->id, $linkedStudentIds),
                            'can_be_linked' => true, // À adapter selon vos règles métier
                        ];
                    }),
                    'total_students' => $students->count(),
                ],
                'message' => 'Étudiants récupérés avec succès',
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe non trouvée.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting class students:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class_id' => $classId,
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des étudiants.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Lier un enfant au parent
     * POST /api/v1/parent/children/link
    */
    public function linkChild(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$this->isParent($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'student_id' => 'required|integer|exists:students,id',
                'is_primary' => 'boolean',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $studentId = $request->input('student_id');
            $requestIsPrimary = $request->input('is_primary', false);
            
            // Chercher le parent directement
            $parent = \App\Modules\Users\Models\ParentModel::where('user_id', $currentUser->id)
                ->whereNull('deleted_at')
                ->first();
            
            if (!$parent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucun profil parent associé à votre compte.',
                ], 404);
            }
            
            $parentId = $parent->id;
            
            // Vérifier si l'étudiant existe et est approuvé
            $student = Student::where('id', $studentId)
                ->where('is_approved', true)
                ->whereNull('deleted_at')
                ->with(['class', 'school'])
                ->firstOrFail();
            
            // Vérifier si le lien existe déjà (même supprimé)
            $existingLink = DB::table('student_parent')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->first();
            
            $action = 'created';
            $isPrimary = $requestIsPrimary;
            
            if ($existingLink) {
                if ($existingLink->deleted_at === null) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cet enfant est déjà lié à votre compte.',
                        'data' => [
                            'existing_link' => $existingLink,
                        ],
                    ], 422);
                } else {
                    // Restaurer le lien supprimé
                    // Vérifier d'abord si l'étudiant a déjà un parent principal
                    $hasPrimaryParent = DB::table('student_parent')
                        ->where('student_id', $studentId)
                        ->where('parent_id', '!=', $parentId)
                        ->where('is_primary', true)
                        ->whereNull('deleted_at')
                        ->exists();
                    
                    // Si on restaure et qu'on demande à être principal OU qu'il n'y a pas de parent principal
                    $shouldBePrimary = $requestIsPrimary || !$hasPrimaryParent;
                    
                    DB::table('student_parent')
                        ->where('student_id', $studentId)
                        ->where('parent_id', $parentId)
                        ->update([
                            'deleted_at' => null,
                            'is_primary' => $shouldBePrimary,
                            'updated_at' => Carbon::now(),
                        ]);
                    
                    $action = 'restored';
                    $isPrimary = $shouldBePrimary;
                    
                    // Si on devient principal et qu'il y avait un autre parent principal, le déclasser
                    if ($shouldBePrimary && $hasPrimaryParent) {
                        DB::table('student_parent')
                            ->where('student_id', $studentId)
                            ->where('parent_id', '!=', $parentId)
                            ->where('is_primary', true)
                            ->whereNull('deleted_at')
                            ->update([
                                'is_primary' => false,
                                'updated_at' => Carbon::now(),
                            ]);
                    }
                }
            } else {
                // Vérifier si l'étudiant a déjà un parent principal
                $hasPrimaryParent = DB::table('student_parent')
                    ->where('student_id', $studentId)
                    ->where('is_primary', true)
                    ->whereNull('deleted_at')
                    ->exists();
                
                // Déterminer si ce parent doit être principal
                // Soit c'est demandé, soit c'est le premier parent
                $shouldBePrimary = $requestIsPrimary || !$hasPrimaryParent;
                
                // Si on devient principal et qu'il y a déjà un parent principal, le déclasser
                if ($shouldBePrimary && $hasPrimaryParent) {
                    DB::table('student_parent')
                        ->where('student_id', $studentId)
                        ->where('is_primary', true)
                        ->whereNull('deleted_at')
                        ->update([
                            'is_primary' => false,
                            'updated_at' => Carbon::now(),
                        ]);
                }
                
                // Créer le lien
                DB::table('student_parent')->insert([
                    'student_id' => $studentId,
                    'parent_id' => $parentId,
                    'is_primary' => $shouldBePrimary,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                
                $isPrimary = $shouldBePrimary;
            }
            
            DB::commit();
            
            // Récupérer le lien créé/mis à jour
            $link = DB::table('student_parent')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->first();
            
            Log::info('Parent linked to child', [
                'parent_id' => $parentId,
                'student_id' => $studentId,
                'action' => $action,
                'is_primary' => $isPrimary,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => $action === 'restored' 
                    ? 'Enfant restauré à votre compte avec succès' 
                    : 'Enfant lié à votre compte avec succès',
                'data' => [
                    'link' => [
                        'student_id' => $link->student_id,
                        'parent_id' => $link->parent_id,
                        'student' => $this->formatStudent($student),
                        'is_primary' => $link->is_primary,
                        'action' => $action,
                        'linked_at' => Carbon::now(),
                    ],
                ],
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Étudiant non trouvé ou non approuvé.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error linking child:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_id' => $request->input('student_id'),
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la liaison de l\'enfant.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Délier un enfant du parent
     * DELETE /api/v1/parent/children/{studentId}/unlink
     */
    public function unlinkChild(Request $request, $studentId)
    {
        DB::beginTransaction();
        
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$this->isParent($currentUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            // Chercher le parent directement
            $parent = \App\Modules\Users\Models\ParentModel::where('user_id', $currentUser->id)
                ->whereNull('deleted_at')
                ->first();
            
            if (!$parent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucun profil parent associé à votre compte.',
                ], 404);
            }
            
            $parentId = $parent->id;
            
            // Vérifier si le lien existe
            $linkExists = DB::table('student_parent')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->exists();
            
            if (!$linkExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lien parent-enfant non trouvé.',
                ], 404);
            }
            
            // Récupérer les informations du lien
            $linkInfo = DB::table('student_parent')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->first();
            
            $isPrimary = $linkInfo->is_primary ?? false;
            
            // Si c'est le parent principal et qu'il y a d'autres parents
            if ($isPrimary) {
                $otherParentsCount = DB::table('student_parent')
                    ->where('student_id', $studentId)
                    ->where('parent_id', '!=', $parentId)
                    ->whereNull('deleted_at')
                    ->count();
                
                if ($otherParentsCount > 0) {
                    // Trouver le parent le plus ancien comme nouveau principal
                    $newPrimary = DB::table('student_parent')
                        ->where('student_id', $studentId)
                        ->where('parent_id', '!=', $parentId)
                        ->whereNull('deleted_at')
                        ->orderBy('created_at', 'asc')
                        ->first();
                    
                    if ($newPrimary) {
                        DB::table('student_parent')
                            ->where('student_id', $studentId)
                            ->where('parent_id', $newPrimary->parent_id)
                            ->update([
                                'is_primary' => true,
                                'updated_at' => Carbon::now(),
                            ]);
                    }
                }
            }
            
            // Mettre à jour le deleted_at (soft delete)
            $affected = DB::table('student_parent')
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            
            if ($affected === 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le lien n\'a pas pu être supprimé.',
                ], 500);
            }
            
            DB::commit();
            
            Log::info('Parent unlinked from child', [
                'parent_id' => $parentId,
                'student_id' => $studentId,
                'was_primary' => $isPrimary,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Enfant délié de votre compte avec succès',
                'data' => [
                    'student_id' => $studentId,
                    'was_primary' => $isPrimary,
                    'unlinked_at' => Carbon::now(),
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error unlinking child:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_id' => $studentId,
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du déliage de l\'enfant.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Récupérer tous les enfants liés au parent
     * GET /api/v1/parent/children
     */
    public function getLinkedChildren(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$currentUser->isParent()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $parentId = $currentUser->parent->id;
            
            $links = StudentParent::where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->with(['student', 'student.class', 'student.school', 'student.class.schoolYear'])
                ->orderBy('is_primary', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
            
            $primaryChildren = $links->where('is_primary', true);
            $secondaryChildren = $links->where('is_primary', false);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'parent' => [
                        'id' => $currentUser->id,
                        'name' => $currentUser->full_name ?? $currentUser->name,
                        'email' => $currentUser->email,
                    ],
                    'children' => [
                        'all' => $links->map(function ($link) {
                            return [
                                'link_id' => $link->id,
                                'is_primary' => $link->is_primary,
                                'linked_since' => Carbon::parse($link->created_at)->diffForHumans(),
                                'student' => $this->formatStudent($link->student),
                            ];
                        }),
                        'primary' => $primaryChildren->map(function ($link) {
                            return $this->formatStudent($link->student);
                        })->values(),
                        'secondary' => $secondaryChildren->map(function ($link) {
                            return $this->formatStudent($link->student);
                        })->values(),
                    ],
                    'counts' => [
                        'total' => $links->count(),
                        'primary' => $primaryChildren->count(),
                        'secondary' => $secondaryChildren->count(),
                    ],
                ],
                'message' => 'Enfants récupérés avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting linked children:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des enfants.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Statistiques des enfants liés
     * GET /api/v1/parent/statistics
     */
    public function getStatistics(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'utilisateur est un parent
            if (!$currentUser->isParent()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Seuls les parents peuvent effectuer cette action.',
                ], 403);
            }
            
            $parentId = $currentUser->parent->id;
            
            // Statistiques de base
            $totalChildren = StudentParent::where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->count();
            
            $primaryChildren = StudentParent::where('parent_id', $parentId)
                ->where('is_primary', true)
                ->whereNull('deleted_at')
                ->count();
            
            // Statistiques par école
            $schoolStats = DB::table('student_parent')
                ->join('students', 'student_parent.student_id', '=', 'students.id')
                ->join('schools', 'students.school_id', '=', 'schools.id')
                ->where('student_parent.parent_id', $parentId)
                ->whereNull('student_parent.deleted_at')
                ->whereNull('students.deleted_at')
                ->whereNull('schools.deleted_at')
                ->select(
                    'schools.id',
                    'schools.name as school_name',
                    DB::raw('COUNT(DISTINCT students.id) as student_count'),
                    DB::raw('SUM(CASE WHEN student_parent.is_primary = 1 THEN 1 ELSE 0 END) as primary_count')
                )
                ->groupBy('schools.id', 'schools.name')
                ->get();
            
            // Statistiques par classe
            $classStats = DB::table('student_parent')
                ->join('students', 'student_parent.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('student_parent.parent_id', $parentId)
                ->whereNull('student_parent.deleted_at')
                ->whereNull('students.deleted_at')
                ->whereNull('classes.deleted_at')
                ->select(
                    'classes.id',
                    'classes.name as class_name',
                    'classes.level',
                    DB::raw('COUNT(DISTINCT students.id) as student_count')
                )
                ->groupBy('classes.id', 'classes.name', 'classes.level')
                ->get();
            
            // Statistiques par niveau
            $levelStats = DB::table('student_parent')
                ->join('students', 'student_parent.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->where('student_parent.parent_id', $parentId)
                ->whereNull('student_parent.deleted_at')
                ->whereNull('students.deleted_at')
                ->whereNull('classes.deleted_at')
                ->select(
                    'classes.level',
                    DB::raw('COUNT(DISTINCT students.id) as student_count'),
                    DB::raw('GROUP_CONCAT(DISTINCT classes.name SEPARATOR ", ") as class_names')
                )
                ->groupBy('classes.level')
                ->get();
            
            // Âge moyen des enfants
            $ageStats = DB::table('student_parent')
                ->join('students', 'student_parent.student_id', '=', 'students.id')
                ->where('student_parent.parent_id', $parentId)
                ->whereNull('student_parent.deleted_at')
                ->whereNull('students.deleted_at')
                ->whereNotNull('students.birth_date')
                ->select(
                    DB::raw('AVG(YEAR(CURDATE()) - YEAR(students.birth_date)) as average_age'),
                    DB::raw('MIN(YEAR(CURDATE()) - YEAR(students.birth_date)) as min_age'),
                    DB::raw('MAX(YEAR(CURDATE()) - YEAR(students.birth_date)) as max_age')
                )
                ->first();
            
            // Derniers enfants ajoutés
            $recentChildren = StudentParent::where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->with(['student', 'student.class'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($link) {
                    return [
                        'student_name' => trim($link->student->first_name . ' ' . $link->student->last_name),
                        'class_name' => $link->student->class ? $link->student->class->name : 'N/A',
                        'linked_at' => Carbon::parse($link->created_at)->format('d/m/Y'),
                        'days_ago' => Carbon::parse($link->created_at)->diffInDays(),
                    ];
                });
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_children' => $totalChildren,
                        'primary_children' => $primaryChildren,
                        'secondary_children' => $totalChildren - $primaryChildren,
                        'average_children_per_parent' => $totalChildren > 0 ? number_format($totalChildren, 1) : 0,
                    ],
                    'school_distribution' => $schoolStats->map(function ($stat) {
                        return [
                            'school_id' => $stat->id,
                            'school_name' => $stat->school_name,
                            'student_count' => $stat->student_count,
                            'primary_count' => $stat->primary_count,
                            'percentage' => $stat->student_count > 0 ? round(($stat->primary_count / $stat->student_count) * 100, 1) : 0,
                        ];
                    }),
                    'class_distribution' => $classStats->map(function ($stat) {
                        return [
                            'class_id' => $stat->id,
                            'class_name' => $stat->class_name,
                            'level' => $stat->level,
                            'student_count' => $stat->student_count,
                        ];
                    }),
                    'level_distribution' => $levelStats->map(function ($stat) {
                        return [
                            'level' => $stat->level,
                            'student_count' => $stat->student_count,
                            'classes' => $stat->class_names,
                        ];
                    }),
                    'age_statistics' => $ageStats ? [
                        'average_age' => round($ageStats->average_age, 1),
                        'min_age' => $ageStats->min_age,
                        'max_age' => $ageStats->max_age,
                        'age_range' => $ageStats->max_age - $ageStats->min_age,
                    ] : null,
                    'recent_activity' => [
                        'recently_added' => $recentChildren,
                        'last_update' => Carbon::now()->format('d/m/Y H:i'),
                    ],
                    'charts_data' => [
                        'school_pie_chart' => $schoolStats->map(function ($stat) {
                            return [
                                'name' => $stat->school_name,
                                'value' => $stat->student_count,
                            ];
                        }),
                        'level_bar_chart' => $levelStats->map(function ($stat) {
                            return [
                                'level' => $stat->level,
                                'count' => $stat->student_count,
                            ];
                        }),
                    ],
                ],
                'message' => 'Statistiques récupérées avec succès',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting parent statistics:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Vérifier si un enfant peut être lié (validation préalable)
     * GET /api/v1/parent/children/{studentId}/check
     */
    public function checkChildLink(Request $request, $studentId)
    {
        try {
                $currentUser = $request->user();
                
                // Vérifier que l'utilisateur est un parent
                if (!$this->isParent($currentUser)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Seuls les parents peuvent effectuer cette action.',
                    ], 403);
                }
                
                // VÉRIFIER SI L'UTILISATEUR A UN PARENT ASSOCIÉ
                if (!$currentUser->parent) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Aucun profil parent associé à votre compte. Veuillez contacter l\'administration.',
                    ], 404);
                }
                
                $student = Student::where('id', $studentId)
                    ->where('is_approved', true)
                    ->whereNull('deleted_at')
                    ->with(['class', 'school'])
                    ->firstOrFail();
                
                $parentId = $currentUser->parent->id; // Maintenant sécurisé
            
            // Vérifier le statut actuel du lien
            $existingLink = StudentParent::withTrashed()
                ->where('student_id', $studentId)
                ->where('parent_id', $parentId)
                ->first();
            
            $canLink = true;
            $status = 'available';
            $message = 'Cet enfant peut être lié à votre compte';
            
            if ($existingLink) {
                if ($existingLink->deleted_at === null) {
                    $canLink = false;
                    $status = 'already_linked';
                    $message = 'Cet enfant est déjà lié à votre compte';
                } else {
                    $status = 'previously_linked';
                    $message = 'Cet enfant était précédemment lié à votre compte et peut être restauré';
                }
            }
            
            // Vérifier si l'étudiant a déjà des parents liés
            $otherParents = StudentParent::where('student_id', $studentId)
                ->where('parent_id', '!=', $parentId)
                ->whereNull('deleted_at')
                ->count();
            
            $hasPrimaryParent = StudentParent::where('student_id', $studentId)
                ->where('is_primary', true)
                ->whereNull('deleted_at')
                ->exists();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'student' => $this->formatStudent($student),
                    'link_status' => [
                        'can_link' => $canLink,
                        'status' => $status,
                        'message' => $message,
                        'existing_link_id' => $existingLink ? $existingLink->id : null,
                        'is_currently_linked' => $existingLink && $existingLink->deleted_at === null,
                        'was_previously_linked' => $existingLink && $existingLink->deleted_at !== null,
                    ],
                    'parent_info' => [
                        'other_parents_count' => $otherParents,
                        'has_primary_parent' => $hasPrimaryParent,
                        'can_be_primary' => !$hasPrimaryParent,
                    ],
                ],
                'message' => 'Vérification terminée',
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Étudiant non trouvé ou non approuvé.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error checking child link:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_id' => $studentId,
                'user_id' => $request->user()->id ?? null,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la vérification.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Formater les informations d'un étudiant
     */
    private function formatStudent($student)
    {
        return [
            'id' => $student->id,
            'student_code' => $student->student_code,
            'full_name' => trim($student->first_name . ' ' . ($student->middle_name ? $student->middle_name . ' ' : '') . $student->last_name),
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'middle_name' => $student->middle_name,
            'gender' => $student->gender,
            'birth_date' => $student->birth_date,
            'age' => $student->birth_date ? Carbon::parse($student->birth_date)->age : null,
            'class' => $student->class ? [
                'id' => $student->class->id,
                'name' => $student->class->name,
                'level' => $student->class->level,
            ] : null,
            'school' => $student->school ? [
                'id' => $student->school->id,
                'name' => $student->school->name,
                'address' => $student->school->address,
            ] : null,
            'is_approved' => $student->is_approved,
            'created_at' => $student->created_at,
        ];
    }
}