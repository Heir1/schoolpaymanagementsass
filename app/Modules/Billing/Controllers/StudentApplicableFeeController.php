<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Fee;
use App\Modules\Billing\Models\StudentFee;
use App\Modules\Billing\Models\GroupFee;
use App\Modules\Billing\Models\FeeType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicePayment;
use App\Modules\Academic\Models\Student;
use App\Modules\Schools\Models\StudentGroup;
use App\Modules\Billing\Models\FeeInstallment;
use App\Modules\Billing\Models\StudentFeeInstallment;
use App\Modules\Billing\Models\GroupFeeInstallment;
use App\Modules\Shared\Models\Pivots\ClassFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StudentApplicableFeeController extends Controller
{
    /**
     * GET: Récupérer tous les frais applicables d'un type spécifique pour un étudiant
     * GET /api/v1/admin/students/{studentId}/fee-types/{feeTypeId}/applicable-fees
     * 
     * Améliorations:
     * 1. Vérification des paiements via les invoices
     * 2. Calcul intelligent des tranches restantes à payer
     * 3. Ne montre que les tranches non payées ou partiellement payées
     * 4. Indique clairement le statut de paiement
     */
    public function getApplicableFeesByType(Request $request, $studentId, $feeTypeId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class', 'studentGroup'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            // Vérifier que le type de frais existe et appartient à la même école
            $feeType = FeeType::findOrFail($feeTypeId);
            if ($feeType->school_id !== $student->class->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le type de frais n\'appartient pas à la même école que l\'étudiant',
                ], 422);
            }
            
            // Déterminer la classification applicable selon la hiérarchie
            $classification = null;
            $applicableFees = collect();
            $source = null;
            
            // 1. Vérifier si l'étudiant appartient à un groupe
            if ($student->student_group_id) {
                $studentGroup = StudentGroup::find($student->student_group_id);
                
                if ($studentGroup) {
                    // Chercher les group_fees pour ce groupe et ce type de frais
                    $groupFees = GroupFee::where('group_id', $studentGroup->id)
                        ->where('fee_type_id', $feeTypeId)
                        ->with(['installments', 'feeType'])
                        ->get();
                    
                    if ($groupFees->isNotEmpty()) {
                        $classification = 'group_fees';
                        $applicableFees = $groupFees;
                        $source = [
                            'type' => 'student_group',
                            'id' => $studentGroup->id,
                            'name' => $studentGroup->name,
                        ];
                    }
                }
            }
            
            // 2. Si pas de group_fees, chercher les student_fees
            if (!$classification) {
                $studentFees = StudentFee::where('student_id', $studentId)
                    ->where('fee_type_id', $feeTypeId)
                    ->with(['installments', 'feeType'])
                    ->get();
                
                if ($studentFees->isNotEmpty()) {
                    $classification = 'student_fees';
                    $applicableFees = $studentFees;
                    $source = [
                        'type' => 'student',
                        'id' => $student->id,
                        'name' => $student->first_name.' '.$student->last_name,
                    ];
                }
            }
            
            // 3. Si ni group_fees ni student_fees, chercher les class_fees (fees généraux)
            if (!$classification) {
                // Chercher les frais généraux (fees) de ce type qui sont associés à la classe de l'étudiant
                $classFees = Fee::where('fee_type_id', $feeTypeId)
                    ->whereHas('classFees', function ($query) use ($student) {
                        $query->where('class_id', $student->class_id);
                    })
                    ->with(['installments', 'feeType', 'classFees.class'])
                    ->get();
                
                if ($classFees->isNotEmpty()) {
                    $classification = 'class_fees';
                    $applicableFees = $classFees;
                    $source = [
                        'type' => 'class',
                        'id' => $student->class_id,
                        'name' => $student->class->name,
                    ];
                }
            }
            
            // Si aucun frais n'est trouvé
            if (!$classification) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Aucun frais applicable trouvé pour cet étudiant et ce type de frais',
                    'data' => [
                        'student' => [
                            'id' => $student->id,
                            'code' => $student->student_code,
                            'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                            'class' => $student->class ? [
                                'id' => $student->class->id,
                                'name' => $student->class->name,
                            ] : null,
                            'student_group' => $student->studentGroup ? [
                                'id' => $student->studentGroup->id,
                                'name' => $student->studentGroup->name,
                            ] : null,
                        ],
                        'fee_type' => [
                            'id' => $feeType->id,
                            'name' => $feeType->name,
                            'payable_by' => $feeType->payable_by,
                        ],
                        'classification' => null,
                        'source' => null,
                        'fees' => [],
                    ],
                ]);
            }
            
            // Pour chaque frais, calculer les paiements et déterminer les tranches à afficher
            $transformedFees = $applicableFees->map(function ($fee) use ($classification, $student) {
                // Récupérer la facture correspondante
                $invoice = $this->getInvoiceForFee($student, $fee, $classification);
                
                // Calculer les paiements et déterminer les tranches à afficher
                $paymentInfo = $this->calculatePaymentInfo($invoice, $fee, $classification);
                
                $baseData = [
                    'id' => $fee->id,
                    'amount' => (float) $fee->amount,
                    'due_date' => $fee->due_date ? $fee->due_date->toDateString() : null,
                    'fee_type' => $fee->feeType ? [
                        'id' => $fee->feeType->id,
                        'name' => $fee->feeType->name,
                        'payable_by' => $fee->feeType->payable_by,
                    ] : null,
                    'installments' => $this->transformInstallmentsWithPayments(
                        $fee->installments, 
                        $classification, 
                        $paymentInfo['paid_per_installment']
                    ),
                    'payment_summary' => [
                        'total_amount' => (float) $fee->amount,
                        'amount_paid' => $paymentInfo['total_paid'],
                        'remaining_amount' => $paymentInfo['remaining'],
                        'payment_status' => $this->getPaymentStatus($paymentInfo['total_paid'], $fee->amount),
                        'invoice_id' => $invoice ? $invoice->id : null,
                    ],
                    'installments_summary' => $paymentInfo['installments_summary'],
                    'created_at' => $fee->created_at->toIso8601String(),
                    'updated_at' => $fee->updated_at->toIso8601String(),
                ];
                
                // Ajouter des informations spécifiques selon la classification
                switch ($classification) {
                    case 'class_fees':
                        $baseData['associated_classes'] = $fee->classFees->map(function ($classFee) {
                            return [
                                'id' => $classFee->class->id,
                                'name' => $classFee->class->name,
                            ];
                        });
                        break;
                    case 'group_fees':
                        $baseData['group_id'] = $fee->group_id;
                        break;
                    case 'student_fees':
                        $baseData['student_id'] = $fee->student_id;
                        break;
                }
                
                return $baseData;
            });
            
            // Calculer les totaux globaux
            $totalAmount = $transformedFees->sum('amount');
            $totalPaid = $transformedFees->sum(function ($fee) {
                return $fee['payment_summary']['amount_paid'];
            });
            $totalRemaining = $transformedFees->sum(function ($fee) {
                return $fee['payment_summary']['remaining_amount'];
            });
            
            // Compter les statuts
            $paymentStatuses = $transformedFees->pluck('payment_summary.payment_status')->countBy();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Frais applicables récupérés avec succès',
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'code' => $student->student_code,
                        'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                        'class' => $student->class ? [
                            'id' => $student->class->id,
                            'name' => $student->class->name,
                        ] : null,
                        'student_group' => $student->studentGroup ? [
                            'id' => $student->studentGroup->id,
                            'name' => $student->studentGroup->name,
                        ] : null,
                    ],
                    'fee_type' => [
                        'id' => $feeType->id,
                        'name' => $feeType->name,
                        'payable_by' => $feeType->payable_by,
                    ],
                    'classification' => $classification,
                    'source' => $source,
                    'fees' => $transformedFees,
                    'payment_overview' => [
                        'total_amount' => $totalAmount,
                        'total_paid' => $totalPaid,
                        'total_remaining' => $totalRemaining,
                        'payment_status_summary' => $paymentStatuses,
                    ],
                    'fees_count' => $transformedFees->count(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching applicable fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
                'fee_type_id' => $feeTypeId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des frais applicables',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Récupérer la facture correspondant à un frais
     */
    private function getInvoiceForFee($student, $fee, $classification)
    {
        $invoice = null;
        
        switch ($classification) {
            case 'class_fees':
                $invoice = Invoice::where('student_id', $student->id)
                    ->where('class_fee_id', $fee->id)
                    ->first();
                break;
            case 'group_fees':
                $invoice = Invoice::where('student_id', $student->id)
                    ->where('group_fee_id', $fee->id)
                    ->first();
                break;
            case 'student_fees':
                $invoice = Invoice::where('student_id', $student->id)
                    ->where('student_fee_id', $fee->id)
                    ->first();
                break;
        }
        
        return $invoice;
    }
    
    /**
     * Calculer les informations de paiement pour un frais
     */
    private function calculatePaymentInfo($invoice, $fee, $classification)
    {
        $totalPaid = 0;
        $paidPerInstallment = [];
        
        // Si une facture existe, récupérer les paiements
        if ($invoice) {
            $payments = InvoicePayment::where('invoice_id', $invoice->id)
                ->orderBy('paid_at', 'asc')
                ->get();
            
            $totalPaid = $payments->sum('amount_paid');
            
            // Répartir les paiements sur les tranches (par ordre d'échéance)
            // Principe: les paiements sont appliqués aux tranches dans l'ordre chronologique
            $remainingToAllocate = $totalPaid;
            $installments = $fee->installments->sortBy('due_date');
            
            foreach ($installments as $installment) {
                $installmentAmount = $installment->amount;
                
                if ($remainingToAllocate >= $installmentAmount) {
                    // La tranche est entièrement payée
                    $paidPerInstallment[$installment->installment_no] = $installmentAmount;
                    $remainingToAllocate -= $installmentAmount;
                } elseif ($remainingToAllocate > 0) {
                    // La tranche est partiellement payée
                    $paidPerInstallment[$installment->installment_no] = $remainingToAllocate;
                    $remainingToAllocate = 0;
                } else {
                    // Aucun paiement restant à allouer
                    $paidPerInstallment[$installment->installment_no] = 0;
                }
            }
        }
        
        // Calculer le résumé des tranches
        $installmentsSummary = $this->calculateInstallmentsSummary($fee->installments, $paidPerInstallment);
        
        return [
            'total_paid' => $totalPaid,
            'remaining' => $fee->amount - $totalPaid,
            'paid_per_installment' => $paidPerInstallment,
            'installments_summary' => $installmentsSummary,
        ];
    }
    
    /**
     * Calculer le résumé des tranches
     */
    private function calculateInstallmentsSummary($installments, $paidPerInstallment)
    {
        $totalInstallments = $installments->count();
        $paidInstallments = 0;
        $partiallyPaidInstallments = 0;
        $unpaidInstallments = 0;
        
        foreach ($installments as $installment) {
            $installmentNo = $installment->installment_no;
            $amount = $installment->amount;
            $paid = $paidPerInstallment[$installmentNo] ?? 0;
            
            if ($paid >= $amount) {
                $paidInstallments++;
            } elseif ($paid > 0) {
                $partiallyPaidInstallments++;
            } else {
                $unpaidInstallments++;
            }
        }
        
        return [
            'total_installments' => $totalInstallments,
            'paid_installments' => $paidInstallments,
            'partially_paid_installments' => $partiallyPaidInstallments,
            'unpaid_installments' => $unpaidInstallments,
        ];
    }
    
    /**
     * Transformer les tranches en incluant les informations de paiement
     * LOGIQUE INTELLIGENTE: Ne montrer que les tranches non complètement payées
     */
    private function transformInstallmentsWithPayments($installments, $classification, $paidPerInstallment)
    {
        if (!$installments) {
            return collect();
        }
        
        $transformed = $installments->sortBy('installment_no')->filter(function ($installment) use ($paidPerInstallment) {
            // Filtrer: ne garder que les tranches non complètement payées
            $installmentNo = $installment->installment_no;
            $amount = (float) $installment->amount;
            $paid = isset($paidPerInstallment[$installmentNo]) ? (float) $paidPerInstallment[$installmentNo] : 0;
            
            // Garder la tranche si elle n'est pas complètement payée
            return $paid < $amount;
        })->map(function ($installment) use ($classification, $paidPerInstallment) {
            $installmentNo = $installment->installment_no;
            $amount = (float) $installment->amount;
            $paid = isset($paidPerInstallment[$installmentNo]) ? (float) $paidPerInstallment[$installmentNo] : 0;
            $remaining = $amount - $paid;
            
            $baseData = [
                'id' => $installment->id,
                'installment_no' => $installmentNo,
                'original_amount' => $amount,
                'amount_paid' => $paid,
                'remaining_amount' => $remaining,
                'due_date' => $installment->due_date->toDateString(),
                'payment_status' => $this->getInstallmentPaymentStatus($paid, $amount),
                'is_overdue' => $this->isInstallmentOverdue($installment->due_date),
            ];
            
            // Ajouter des clés spécifiques selon la classification
            switch ($classification) {
                case 'class_fees':
                    $baseData['fee_id'] = $installment->fee_id;
                    break;
                case 'group_fees':
                    $baseData['group_fee_id'] = $installment->group_fee_id;
                    break;
                case 'student_fees':
                    $baseData['student_fee_id'] = $installment->student_fee_id;
                    break;
            }
            
            return $baseData;
        })->values();
        
        // Si toutes les tranches sont payées, retourner un tableau vide
        // Mais indiquer qu'elles sont toutes payées dans le résumé
        return $transformed;
    }
    
    /**
     * Vérifier si une tranche est en retard
     */
    private function isInstallmentOverdue($dueDate)
    {
        if (!$dueDate) {
            return false;
        }
        
        return Carbon::parse($dueDate)->isPast();
    }
    
    /**
     * Déterminer le statut de paiement d'un frais
     */
    private function getPaymentStatus($paid, $total)
    {
        if ($paid <= 0) {
            return 'unpaid';
        } elseif ($paid >= $total) {
            return 'paid';
        } else {
            return 'partially_paid';
        }
    }
    
    /**
     * Déterminer le statut de paiement d'une tranche
     */
    private function getInstallmentPaymentStatus($paid, $total)
    {
        if ($paid <= 0) {
            return 'unpaid';
        } elseif ($paid >= $total) {
            return 'paid';
        } else {
            return 'partially_paid';
        }
    }
    
    /**
     * GET: Récupérer TOUS les frais applicables pour un étudiant (tous types confondus)
     * GET /api/v1/admin/students/{studentId}/applicable-fees
     * 
     * Amélioré avec la gestion des paiements
     */
    public function getAllApplicableFees(Request $request, $studentId)
    {
        try {
            $currentUser = $request->user();
            
            // Vérifier que l'étudiant existe
            $student = Student::with(['class.school', 'studentGroup'])->findOrFail($studentId);
            
            // Vérifier les permissions
            if ($currentUser->isSchoolAdmin()) {
                $schoolId = $currentUser->getSchoolId();
                if ($student->class->school_id !== $schoolId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Vous n\'avez pas accès à cet étudiant',
                    ], 403);
                }
            }
            
            // Récupérer tous les types de frais de l'école
            $feeTypes = FeeType::where('school_id', $student->class->school_id)
                ->orderBy('name')
                ->get();
            
            $result = [];
            $totalOverallAmount = 0;
            $totalOverallPaid = 0;
            $totalOverallRemaining = 0;
            $statusSummary = [
                'paid' => 0,
                'partially_paid' => 0,
                'unpaid' => 0,
            ];
            
            foreach ($feeTypes as $feeType) {
                // Utiliser la même logique que getApplicableFeesByType
                $applicableFees = $this->getApplicableFeesData($student, $feeType);
                
                if ($applicableFees['fees']->isNotEmpty()) {
                    $typeTotal = $applicableFees['fees']->sum('amount');
                    $typePaid = $applicableFees['fees']->sum(function ($fee) {
                        return $fee['payment_summary']['amount_paid'];
                    });
                    $typeRemaining = $applicableFees['fees']->sum(function ($fee) {
                        return $fee['payment_summary']['remaining_amount'];
                    });
                    
                    // Compter les statuts pour ce type
                    $typeStatuses = $applicableFees['fees']->pluck('payment_summary.payment_status')->countBy();
                    
                    $result[] = [
                        'fee_type' => [
                            'id' => $feeType->id,
                            'name' => $feeType->name,
                            'payable_by' => $feeType->payable_by,
                        ],
                        'classification' => $applicableFees['classification'],
                        'source' => $applicableFees['source'],
                        'fees' => $applicableFees['fees'],
                        'payment_summary' => [
                            'total_amount' => $typeTotal,
                            'amount_paid' => $typePaid,
                            'remaining_amount' => $typeRemaining,
                            'payment_status_summary' => $typeStatuses,
                        ],
                        'fees_count' => $applicableFees['fees_count'],
                    ];
                    
                    $totalOverallAmount += $typeTotal;
                    $totalOverallPaid += $typePaid;
                    $totalOverallRemaining += $typeRemaining;
                    
                    // Mettre à jour le résumé global des statuts
                    foreach ($typeStatuses as $status => $count) {
                        $statusSummary[$status] = ($statusSummary[$status] ?? 0) + $count;
                    }
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Tous les frais applicables récupérés avec succès',
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'code' => $student->student_code,
                        'full_name' => $student->first_name.' '.$student->last_name.' '.$student->middle_name,
                        'class' => $student->class ? [
                            'id' => $student->class->id,
                            'name' => $student->class->name,
                            'school' => $student->class->school ? [
                                'id' => $student->class->school->id,
                                'name' => $student->class->school->name,
                            ] : null,
                        ] : null,
                        'student_group' => $student->studentGroup ? [
                            'id' => $student->studentGroup->id,
                            'name' => $student->studentGroup->name,
                        ] : null,
                    ],
                    'fee_types' => $result,
                    'payment_overview' => [
                        'total_amount' => $totalOverallAmount,
                        'total_paid' => $totalOverallPaid,
                        'total_remaining' => $totalOverallRemaining,
                        'payment_status_summary' => $statusSummary,
                    ],
                    'total_fee_types' => count($result),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching all applicable fees: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'student_id' => $studentId,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de tous les frais applicables',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    /**
     * Logique réutilisable pour obtenir les frais applicables avec paiements
     */
    private function getApplicableFeesData($student, $feeType)
    {
        $classification = null;
        $applicableFees = collect();
        $source = null;
        
        // 1. Vérifier si l'étudiant appartient à un groupe
        if ($student->student_group_id) {
            $studentGroup = StudentGroup::find($student->student_group_id);
            
            if ($studentGroup) {
                // Chercher les group_fees pour ce groupe et ce type de frais
                $groupFees = GroupFee::where('group_id', $studentGroup->id)
                    ->where('fee_type_id', $feeType->id)
                    ->with(['installments', 'feeType'])
                    ->get();
                
                if ($groupFees->isNotEmpty()) {
                    $classification = 'group_fees';
                    $applicableFees = $groupFees;
                    $source = [
                        'type' => 'student_group',
                        'id' => $studentGroup->id,
                        'name' => $studentGroup->name,
                    ];
                }
            }
        }
        
        // 2. Si pas de group_fees, chercher les student_fees
        if (!$classification) {
            $studentFees = StudentFee::where('student_id', $student->id)
                ->where('fee_type_id', $feeType->id)
                ->with(['installments', 'feeType'])
                ->get();
            
            if ($studentFees->isNotEmpty()) {
                $classification = 'student_fees';
                $applicableFees = $studentFees;
                $source = [
                    'type' => 'student',
                    'id' => $student->id,
                    'name' => $student->first_name.' '.$student->last_name,
                ];
            }
        }
        
        // 3. Si ni group_fees ni student_fees, chercher les class_fees (fees généraux)
        if (!$classification) {
            // Chercher les frais généraux (fees) de ce type qui sont associés à la classe de l'étudiant
            $classFees = Fee::where('fee_type_id', $feeType->id)
                ->whereHas('classFees', function ($query) use ($student) {
                    $query->where('class_id', $student->class_id);
                })
                ->with(['installments', 'feeType', 'classFees.class'])
                ->get();
            
            if ($classFees->isNotEmpty()) {
                $classification = 'class_fees';
                $applicableFees = $classFees;
                $source = [
                    'type' => 'class',
                    'id' => $student->class_id,
                    'name' => $student->class->name,
                ];
            }
        }
        
        // Transformer les frais avec les informations de paiement
        $transformedFees = $applicableFees->map(function ($fee) use ($classification, $student) {
            // Récupérer la facture correspondante
            $invoice = $this->getInvoiceForFee($student, $fee, $classification);
            
            // Calculer les paiements
            $paymentInfo = $this->calculatePaymentInfo($invoice, $fee, $classification);
            
            $baseData = [
                'id' => $fee->id,
                'amount' => (float) $fee->amount,
                'due_date' => $fee->due_date ? $fee->due_date->toDateString() : null,
                'installments' => $this->transformInstallmentsWithPayments(
                    $fee->installments, 
                    $classification, 
                    $paymentInfo['paid_per_installment']
                ),
                'payment_summary' => [
                    'total_amount' => (float) $fee->amount,
                    'amount_paid' => $paymentInfo['total_paid'],
                    'remaining_amount' => $paymentInfo['remaining'],
                    'payment_status' => $this->getPaymentStatus($paymentInfo['total_paid'], $fee->amount),
                    'invoice_id' => $invoice ? $invoice->id : null,
                ],
                'installments_summary' => $paymentInfo['installments_summary'],
                'created_at' => $fee->created_at->toIso8601String(),
                'updated_at' => $fee->updated_at->toIso8601String(),
            ];
            
            switch ($classification) {
                case 'class_fees':
                    $baseData['associated_classes'] = $fee->classFees->map(function ($classFee) {
                        return [
                            'id' => $classFee->class->id,
                            'name' => $classFee->class->name,
                        ];
                    });
                    break;
                case 'group_fees':
                    $baseData['group_id'] = $fee->group_id;
                    break;
                case 'student_fees':
                    $baseData['student_id'] = $fee->student_id;
                    break;
            }
            
            return $baseData;
        });
        
        return [
            'classification' => $classification,
            'source' => $source,
            'fees' => $transformedFees,
            'total_amount' => $transformedFees->sum('amount'),
            'fees_count' => $transformedFees->count(),
        ];
    }
}