<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Modules\Users\Controllers\AdminUserController;
use App\Modules\Schools\Controllers\SchoolController;
use App\Modules\Schools\Controllers\SchoolYearController;
use App\Modules\Schools\Controllers\StudentGroupController;
use App\Modules\Academic\Controllers\ClassController;
use App\Modules\Academic\Controllers\InscriptionDocumentController;
use App\Modules\Academic\Controllers\ClassRequiredDocumentController;
use App\Modules\Academic\Controllers\StudentController;
use App\Modules\Billing\Controllers\FeeTypeController;
use App\Modules\Billing\Controllers\FeeController;
use App\Modules\Billing\Controllers\StudentFeeController;
use App\Modules\Billing\Controllers\GroupFeeController;
use App\Modules\Billing\Controllers\StudentApplicableFeeController;
use App\Modules\Billing\Controllers\PaymentMethodController;


/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // Route de status de la version 1
    Route::get('/status', function () {
        return response()->json([
            'status' => 'success',
            'api_version' => '1.0.0',
            'message' => 'API Version 1 is operational',
            'timestamp' => now()->toISOString(),
            'service' => 'SchoolPay Management SaaS',
            'environment' => config('app.env'),
            'octane' => app()->bound('octane') ? 'enabled' : 'disabled',
        ]);
    });
    
    // Routes d'authentification
    Route::prefix('auth')->group(function () {
        Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
        Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register']);
        Route::post('/register-parent', [\App\Http\Controllers\AuthController::class, 'registerParent']); // <-- NOUVELLE ROUTE
        Route::post('/check-availability', [\App\Http\Controllers\AuthController::class, 'checkAvailability']);
        
        // Routes protégées
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\AuthController::class, 'me']);
        });
    });


    // Routes pour la gestion complète des utilisateurs par les administrateurs
    Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
        // CRUD complet des utilisateurs
        Route::prefix('users')->group(function () {

            Route::get('/statistics', [AdminUserController::class, 'statistics']);

            Route::put('/change-password', [AdminUserController::class, 'changePassword']);
            // Vérifier le statut du mot de passe
            Route::get('/check-password-status', [AdminUserController::class, 'checkPasswordStatus']);

            // CRUD de base
            Route::get('/', [AdminUserController::class, 'index']);
            Route::post('/', [AdminUserController::class, 'store']);
            Route::get('/{id}', [AdminUserController::class, 'show']);
            Route::put('/{id}', [AdminUserController::class, 'update']);
            Route::delete('/{id}', [AdminUserController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [AdminUserController::class, 'restore']);
            Route::post('/{id}/reset-password', [AdminUserController::class, 'resetPassword']);

            // Routes pour la gestion des avatars
            Route::delete('/{id}/avatar', [AdminUserController::class, 'removeAvatar']);
            Route::post('/{id}/upload-avatar', [AdminUserController::class, 'uploadAvatar']);

        });

        Route::prefix('schools')->group(function () {
            // CRUD de base
            Route::get('/', [SchoolController::class, 'index']);
            // Statistiques
            Route::get('/statistics', [SchoolController::class, 'statistics']);
            Route::post('/', [SchoolController::class, 'store']);
            Route::get('/{id}', [SchoolController::class, 'show']);
            Route::put('/{id}', [SchoolController::class, 'update']);
            Route::delete('/{id}', [SchoolController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [SchoolController::class, 'restore']);


            // Routes pour les logos d'écoles
            Route::post('/{id}/logo', [SchoolController::class, 'uploadLogo']);
            Route::delete('/{id}/logo', [SchoolController::class, 'deleteLogo']);

            
        });
    
        // Routes pour les types d'écoles (lecture seule)
        Route::get('/school-types', [SchoolController::class, 'listSchoolTypes']);

        Route::prefix('school-years')->group(function () {
            // CRUD de base
            Route::get('/', [SchoolYearController::class, 'index']);
            Route::post('/', [SchoolYearController::class, 'store']);
            Route::get('/{id}', [SchoolYearController::class, 'show']);
            Route::put('/{id}', [SchoolYearController::class, 'update']);
            Route::delete('/{id}', [SchoolYearController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [SchoolYearController::class, 'restore']);
            Route::post('/{id}/toggle-active', [SchoolYearController::class, 'toggleActive']);
        });
    
        // Routes pour les groupes d'étudiants
        Route::prefix('student-groups')->group(function () {

            // Statistiques
            Route::get('/statistics', [StudentGroupController::class, 'statistics']);

            // CRUD de base
            Route::get('/', [StudentGroupController::class, 'index']);
            Route::post('/', [StudentGroupController::class, 'store']);
            Route::get('/{id}', [StudentGroupController::class, 'show']);
            Route::put('/{id}', [StudentGroupController::class, 'update']);
            Route::delete('/{id}', [StudentGroupController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [StudentGroupController::class, 'restore']);
            
        });
    
        // Routes pour les relations
        Route::prefix('schools/{schoolId}')->group(function () {
            Route::get('/school-years', [SchoolYearController::class, 'listBySchool']);
            Route::get('/active-school-year', [SchoolYearController::class, 'getActiveSchoolYear']);
            Route::get('/student-groups', [StudentGroupController::class, 'listBySchool']);
        });


        // Routes pour les classes
        Route::prefix('classes')->group(function () {
            
            // Statistiques
            Route::get('/statistics', [ClassController::class, 'statistics']);
            Route::get('/options', [ClassController::class, 'getOptions']);

            // CRUD de base
            Route::get('/', [ClassController::class, 'index']);
            Route::post('/', [ClassController::class, 'store']);
            Route::get('/{id}', [ClassController::class, 'show']);
            Route::put('/{id}', [ClassController::class, 'update']);
            Route::delete('/{id}', [ClassController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [ClassController::class, 'restore']);
        });
    
        // Routes pour les documents d'inscription
        Route::prefix('inscription-documents')->group(function () {

            // Statistiques
            Route::get('/statistics', [InscriptionDocumentController::class, 'statistics']);

            // CRUD de base
            Route::get('/', [InscriptionDocumentController::class, 'index']);
            Route::post('/', [InscriptionDocumentController::class, 'store']);
            Route::get('/{id}', [InscriptionDocumentController::class, 'show']);
            Route::put('/{id}', [InscriptionDocumentController::class, 'update']);
            Route::delete('/{id}', [InscriptionDocumentController::class, 'destroy']);
            
            // Actions supplémentaires
            Route::post('/{id}/restore', [InscriptionDocumentController::class, 'restore']);
        });
        
        // Routes pour les documents requis par classe
        Route::prefix('classes/{classId}/required-documents')->group(function () {
            Route::get('/', [ClassRequiredDocumentController::class, 'index']);
            Route::post('/', [ClassRequiredDocumentController::class, 'store']);
            Route::post('/bulk', [ClassRequiredDocumentController::class, 'bulkStore']);
            Route::put('/bulk', [ClassRequiredDocumentController::class, 'bulkUpdate']); // Nouvelle route
            Route::put('/replace', [ClassRequiredDocumentController::class, 'bulkReplace']); // Nouvelle route
            Route::put('/{id}', [ClassRequiredDocumentController::class, 'update']);
            Route::delete('/{id}', [ClassRequiredDocumentController::class, 'destroy']);
            Route::get('/available-documents', [ClassRequiredDocumentController::class, 'listAvailableDocuments']);
        });
        
        // Routes pour les relations
        Route::prefix('schools/{schoolId}')->group(function () {
            Route::get('/classes', [ClassController::class, 'listBySchool']);
        });
        
        Route::prefix('school-years/{schoolYearId}')->group(function () {
            Route::get('/classes', [ClassController::class, 'listBySchoolYear']);
        });

        // Routes pour les étudiants
        Route::prefix('students')->middleware(['auth:sanctum', 'role:school_admin,super_admin,parent'])->group(function () {
            Route::get('/', [StudentController::class, 'index']);
            Route::post('/', [StudentController::class, 'store']);
            Route::get('/generate-code', [StudentController::class, 'generateStudentCode']);

            // Export
            Route::get('/export', [StudentController::class, 'export']);
            Route::get('/import-template', [StudentController::class, 'downloadImportTemplate']);
            
            // Import
            Route::post('/import', [StudentController::class, 'import']);
            Route::post('/import-replace', [StudentController::class, 'importReplace']);
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [StudentController::class, 'show']);
                Route::put('/', [StudentController::class, 'update']);
                Route::delete('/', [StudentController::class, 'destroy']);
                Route::post('/restore', [StudentController::class, 'restore']);
                Route::post('/approve', [StudentController::class, 'approve']);
                Route::post('/disapprove', [StudentController::class, 'disapprove']);

                // Récupérer tous les frais applicables pour un étudiant
                Route::get('/applicable-fees', [StudentApplicableFeeController::class, 'getAllApplicableFees']);
                
                // Récupérer les frais applicables d'un type spécifique pour un étudiant
                Route::get('/fee-types/{feeTypeId}/applicable-fees', [StudentApplicableFeeController::class, 'getApplicableFeesByType']);

            });

        });

        // Méthodes de paiement
        Route::prefix('/payment-methods')->group(function () {
            Route::get('/', [PaymentMethodController::class, 'index']);
            Route::post('/', [PaymentMethodController::class, 'store']);
            Route::get('/{id}', [PaymentMethodController::class, 'show']);
            Route::put('/{id}', [PaymentMethodController::class, 'update']);
            Route::delete('/{id}', [PaymentMethodController::class, 'destroy']);
            Route::post('/{id}/restore', [PaymentMethodController::class, 'restore']);
        });

        // Routes pour les étudiants par classe
        Route::prefix('classes/{classId}/students')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [StudentController::class, 'getByClass']);
        });

        // Routes pour les types de frais
        Route::prefix('fee-types')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeTypeController::class, 'index']);
            Route::post('/', [FeeTypeController::class, 'store']);
            Route::get('/export', [FeeTypeController::class, 'export']);
            Route::get('/import-template', [FeeTypeController::class, 'downloadImportTemplate']);
            Route::post('/import', [FeeTypeController::class, 'import']);
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [FeeTypeController::class, 'show']);
                Route::put('/', [FeeTypeController::class, 'update']);
                Route::delete('/', [FeeTypeController::class, 'destroy']);
                Route::post('/restore', [FeeTypeController::class, 'restore']);
            });
        });

        // Routes pour les types de frais par école
        Route::prefix('schools/{schoolId}/fee-types')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeTypeController::class, 'getBySchool']);
        });

        // Routes pour les frais (fees)
        Route::prefix('fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeController::class, 'index']);
            Route::post('/', [FeeController::class, 'store']);
            Route::get('/export', [FeeController::class, 'export']);
            Route::get('/import-template', [FeeController::class, 'downloadImportTemplate']);
            Route::post('/import', [FeeController::class, 'import']);
            
            // Statistiques
            Route::get('/statistics', [FeeController::class, 'statistics']);
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [FeeController::class, 'show']);
                Route::put('/', [FeeController::class, 'update']);
                Route::delete('/', [FeeController::class, 'destroy']);
                Route::post('/restore', [FeeController::class, 'restore']);
                
                // Gestion des échéanciers (installments)
                Route::prefix('installments')->group(function () {
                    Route::get('/', [FeeController::class, 'listInstallments']);
                    Route::post('/', [FeeController::class, 'addInstallment']);
                    Route::put('/{installmentId}', [FeeController::class, 'updateInstallment']);
                    Route::delete('/{installmentId}', [FeeController::class, 'removeInstallment']);
                });
                
                // Association aux classes
                Route::prefix('classes')->group(function () {
                    Route::get('/', [FeeController::class, 'listAssociatedClasses']);
                    Route::post('/associate', [FeeController::class, 'associateToClasses']);
                    Route::delete('/dissociate', [FeeController::class, 'dissociateFromClasses']);
                });
            });
        });
        
        // Routes pour les frais par type de frais
        Route::prefix('fee-types/{feeTypeId}/fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeController::class, 'getByFeeType']);
        });
        
        // Routes pour les frais par école
        Route::prefix('schools/{schoolId}/fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeController::class, 'getBySchool']);
        });
        
        // Routes pour les frais par classe
        Route::prefix('classes/{classId}/fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [FeeController::class, 'getByClass']);
            Route::post('/assign', [FeeController::class, 'assignFeesToClass']);
        });


        // Routes pour les frais par étudiant
        Route::prefix('students/{studentId}/fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [StudentFeeController::class, 'index']);
            Route::post('/', [StudentFeeController::class, 'store']);
            Route::get('/export', [StudentFeeController::class, 'export']);
            Route::get('/import-template', [StudentFeeController::class, 'downloadImportTemplate']);
            Route::post('/import', [StudentFeeController::class, 'import']);
            
            // Statistiques
            Route::get('/statistics', [StudentFeeController::class, 'statistics']);
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [StudentFeeController::class, 'show']);
                Route::put('/', [StudentFeeController::class, 'update']);
                Route::delete('/', [StudentFeeController::class, 'destroy']);
                Route::post('/restore', [StudentFeeController::class, 'restore']);
                
                // Gestion des tranches pour un frais étudiant spécifique
                Route::prefix('installments')->group(function () {
                    Route::get('/', [StudentFeeController::class, 'listInstallments']);
                    Route::post('/', [StudentFeeController::class, 'addInstallment']);
                    Route::put('/{installmentId}', [StudentFeeController::class, 'updateInstallment']);
                    Route::delete('/{installmentId}', [StudentFeeController::class, 'removeInstallment']);
                });
            });
        });


        // Routes pour les étudiants avec des frais spécifiques
        Route::prefix('students-with-specific-fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [StudentFeeController::class, 'listStudentsWithSpecificFees']);
            
            // Pour super_admin, possibilité de filtrer par école
            Route::get('/schools/{schoolId}', [StudentFeeController::class, 'listStudentsWithSpecificFeesBySchool']);
        });

        Route::get('/schools/{schoolId}/specific-fees-statistics', [StudentFeeController::class, 'specificFeesStatistics']);

        // Routes pour les frais de groupe d'étudiants
        Route::prefix('student-groups/{groupId}/fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [GroupFeeController::class, 'index']);
            Route::post('/', [GroupFeeController::class, 'store']);
            Route::get('/export', [GroupFeeController::class, 'export']);
            Route::get('/import-template', [GroupFeeController::class, 'downloadImportTemplate']);
            Route::post('/import', [GroupFeeController::class, 'import']);
            
            // Statistiques
            Route::get('/statistics', [GroupFeeController::class, 'statistics']);
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [GroupFeeController::class, 'show']);
                Route::put('/', [GroupFeeController::class, 'update']);
                Route::delete('/', [GroupFeeController::class, 'destroy']);
                Route::post('/restore', [GroupFeeController::class, 'restore']);
                
                // Gestion des tranches pour un frais de groupe spécifique
                Route::prefix('installments')->group(function () {
                    Route::get('/', [GroupFeeController::class, 'listInstallments']);
                    Route::post('/', [GroupFeeController::class, 'addInstallment']);
                    Route::put('/{installmentId}', [GroupFeeController::class, 'updateInstallment']);
                    Route::delete('/{installmentId}', [GroupFeeController::class, 'removeInstallment']);
                });
            });
        });

        // Routes pour les frais de groupe par école
        Route::prefix('schools/{schoolId}/group-fees')->middleware(['auth:sanctum', 'role:school_admin,super_admin'])->group(function () {
            Route::get('/', [GroupFeeController::class, 'getBySchool']);
        });
        

    });


    // Route publique pour générer un nouveau mot de passe initial
    Route::post('/generate-initial-password', [AdminUserController::class, 'generateNewInitialPassword']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    
    // Routes publiques (sans authentification)
    Route::prefix('public')->group(function () {
        Route::get('/test', function () {
            return response()->json([
                'message' => 'API publique accessible',
                'endpoints' => [
                    'auth' => [
                        'login' => 'POST /api/v1/auth/login',
                        'register' => 'POST /api/v1/auth/register',
                        'check_availability' => 'POST /api/v1/auth/check-availability',
                    ],
                ],
            ]);
        });
    });
    
    // Routes protégées (avec authentification)
    Route::middleware(['auth:sanctum'])->group(function () {
        
        // Test d'authentification
        Route::get('/test-auth', function (Request $request) {
            return response()->json([
                'message' => 'Authentifié avec succès',
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->full_name,
                    'identifier' => $request->user()->phone_or_email,
                ],
                'timestamp' => now()->toISOString(),
            ]);
        });
        
        // Routes pour les écoles
        Route::prefix('schools')->group(function () {
            Route::get('/', function () {
                return response()->json([
                    'data' => [
                        ['id' => 1, 'name' => 'École Primaire de Paris', 'status' => 'active'],
                        ['id' => 2, 'name' => 'Lycée International', 'status' => 'active'],
                    ],
                    'message' => 'Écoles récupérées avec succès',
                ]);
            });
        });
        
        // Routes pour les utilisateurs
        Route::prefix('users')->group(function () {
            Route::get('/profile', function (Request $request) {
                $user = $request->user();
                
                return response()->json([
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'phone_or_email' => $user->phone_or_email,
                        'avatar_url' => $user->avatar_url,
                        'identifier_type' => $user->isEmail() ? 'email' : 'phone',
                        'created_at' => $user->created_at->format('d/m/Y H:i'),
                    ],
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'message' => 'Profil utilisateur récupéré avec succès',
                ]);
            });
        });
    });
});