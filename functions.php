<?php
/**
 * Lending Tracking System - Core Functions
 * 13 Simple & Clean Database Functions (Full CRUD + Financial Logic)
 */

require_once __DIR__ . '/config.php';

// -------------------------------------------------------------
// 1. CREATE BORROWER (C in CRUD)
// -------------------------------------------------------------
function registerBorrower($pdo, $data) {
    $code = 'BOR-' . rand(1000, 9999);
    $sql = "INSERT INTO borrowers (borrower_code, first_name, last_name, email, phone, address, monthly_income, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $code,
        trim($data['first_name']),
        trim($data['last_name']),
        trim($data['email']),
        trim($data['phone']),
        trim($data['address']),
        floatval($data['monthly_income'])
    ]);

    $id = $pdo->lastInsertId();
    logAudit($pdo, 'BORROWER_CREATED', 'borrower', $id, "Added borrower: " . $data['first_name'] . ' ' . $data['last_name']);
    return $id;
}

// -------------------------------------------------------------
// 2. READ ALL BORROWERS (R in CRUD)
// -------------------------------------------------------------
function getAllBorrowers($pdo, $search = '', $status = '') {
    $sql = "SELECT b.*, 
                   COUNT(l.id) AS total_loans,
                   COALESCE(SUM(CASE WHEN l.status IN ('disbursed', 'pending') THEN l.remaining_balance ELSE 0 END), 0) AS total_outstanding
            FROM borrowers b
            LEFT JOIN loans l ON b.id = l.borrower_id
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (b.borrower_code LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ? OR b.phone LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term, $term]);
    }
    if (!empty($status)) {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }

    $sql .= " GROUP BY b.id ORDER BY b.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// -------------------------------------------------------------
// 3. READ SINGLE BORROWER (R in CRUD)
// -------------------------------------------------------------
function getBorrowerById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// -------------------------------------------------------------
// 4. UPDATE BORROWER (U in CRUD)
// -------------------------------------------------------------
function updateBorrower($pdo, $id, $data) {
    $sql = "UPDATE borrowers 
            SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, monthly_income = ?, status = ?
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        trim($data['first_name']),
        trim($data['last_name']),
        trim($data['email']),
        trim($data['phone']),
        trim($data['address']),
        floatval($data['monthly_income']),
        $data['status'] ?? 'active',
        $id
    ]);
    if ($ok) logAudit($pdo, 'BORROWER_UPDATED', 'borrower', $id, "Updated borrower ID $id");
    return $ok;
}

// -------------------------------------------------------------
// 5. DELETE BORROWER (D in CRUD)
// -------------------------------------------------------------
function deleteBorrower($pdo, $id) {
    // Check if borrower has active loans
    $check = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE borrower_id = ? AND status IN ('pending', 'approved', 'disbursed')");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        throw new Exception("Cannot delete borrower with active or pending loans.");
    }

    // Check if historical completed loans exist
    $history = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE borrower_id = ?");
    $history->execute([$id]);
    if ($history->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE borrowers SET status = 'inactive' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    $stmt = $pdo->prepare("DELETE FROM borrowers WHERE id = ?");
    return $stmt->execute([$id]);
}

// -------------------------------------------------------------
// -------------------------------------------------------------
// 6. READ LOAN PLANS (R in CRUD)
// -------------------------------------------------------------
function getLoanPlans($pdo, $activeOnly = false) {
    if ($activeOnly) {
        return $pdo->query("SELECT * FROM loan_plans WHERE status = 'active' ORDER BY tenure_months ASC")->fetchAll();
    }
    return $pdo->query("SELECT p.*, 
                               COUNT(l.id) AS total_loans,
                               COUNT(CASE WHEN l.status = 'disbursed' THEN 1 END) AS active_loans,
                               COALESCE(SUM(CASE WHEN l.status = 'disbursed' THEN l.remaining_balance ELSE 0 END), 0) AS active_exposure
                        FROM loan_plans p 
                        LEFT JOIN loans l ON p.id = l.plan_id 
                        GROUP BY p.id 
                        ORDER BY p.id ASC")->fetchAll();
}

// -------------------------------------------------------------
// 7. CREATE LOAN APPLICATION (C in CRUD + Math)
// -------------------------------------------------------------
function applyLoan($pdo, $data) {
    $borrowerId = (int)$data['borrower_id'];
    $planId     = (int)$data['plan_id'];
    $principal  = floatval($data['principal_amount']);
    $purpose    = trim($data['purpose'] ?? 'Personal');
    $collateral = !empty($data['collateral_info']) ? trim($data['collateral_info']) : 'None / Unsecured';
    $guarantorName  = !empty($data['guarantor_name']) ? trim($data['guarantor_name']) : 'None';
    $guarantorPhone = !empty($data['guarantor_phone']) ? trim($data['guarantor_phone']) : 'None';

    // Get plan details for calculation
    $planStmt = $pdo->prepare("SELECT * FROM loan_plans WHERE id = ?");
    $planStmt->execute([$planId]);
    $plan = $planStmt->fetch();

    if (!$plan) throw new Exception("Selected loan plan not found.");

    // Simple Interest Formula: Principal * (Rate / 100)
    $interestAmount = round($principal * ($plan['interest_rate'] / 100), 2);
    $totalPayable   = $principal + $interestAmount;
    $monthlyInstall = round($totalPayable / $plan['tenure_months'], 2);
    $dueDate        = date('Y-m-d', strtotime("+{$plan['tenure_months']} months"));
    $loanCode       = 'LN-' . date('Y') . '-' . rand(1000, 9999);

    $sql = "INSERT INTO loans (loan_code, borrower_id, plan_id, principal_amount, interest_amount, total_payable, total_paid, remaining_balance, monthly_installment, due_date, status, purpose, collateral_info, guarantor_name, guarantor_phone)
            VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, 'pending', ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $loanCode, 
        $borrowerId, 
        $planId, 
        $principal, 
        $interestAmount, 
        $totalPayable, 
        $totalPayable, 
        $monthlyInstall, 
        $dueDate, 
        $purpose,
        $collateral,
        $guarantorName,
        $guarantorPhone
    ]);

    $loanId = $pdo->lastInsertId();
    logAudit($pdo, 'LOAN_APPLIED', 'loan', $loanId, "Loan application $loanCode submitted for " . formatCurrency($principal));
    return $loanId;
}

// -------------------------------------------------------------
// 8. UPDATE LOAN STATUS (Approve, Reject, Disburse)
// -------------------------------------------------------------
function updateLoanStatus($pdo, $loanId, $action, $remarks = '') {
    $statusMap = ['approve' => 'approved', 'reject' => 'rejected', 'disburse' => 'disbursed'];
    if (!isset($statusMap[$action])) throw new Exception("Invalid action.");

    $newStatus = $statusMap[$action];
    $releaseDate = ($action === 'disburse') ? date('Y-m-d') : null;

    $stmt = $pdo->prepare("UPDATE loans SET status = ?, release_date = COALESCE(?, release_date), remarks = ? WHERE id = ?");
    $ok = $stmt->execute([$newStatus, $releaseDate, $remarks, $loanId]);
    if ($ok) logAudit($pdo, 'STATUS_CHANGE', 'loan', $loanId, "Loan marked as $newStatus");
    return $ok;
}

// -------------------------------------------------------------
// 9. RECORD REPAYMENT & AUTO-REDUCE BALANCE (C & U in CRUD)
// -------------------------------------------------------------
function recordRepayment($pdo, $loanId, $amount, $method = 'cash', $notes = '', $penalty = 0.00) {
    if ($amount <= 0) throw new Exception("Payment amount must be greater than zero.");

    // Fetch current loan balance
    $loan = getLoanDetails($pdo, $loanId);
    if (!$loan) throw new Exception("Loan account not found.");
    if ($loan['remaining_balance'] <= 0) throw new Exception("This loan is already fully settled.");

    $newTotalPaid = $loan['total_paid'] + $amount;
    $newBalance   = max(0, round($loan['remaining_balance'] - $amount, 2));
    $newStatus    = ($newBalance <= 0) ? 'completed' : 'disbursed';
    $txnRef       = 'TXN-' . date('Ymd') . '-' . rand(1000, 9999);

    // 1. Insert Repayment Record
    $repStmt = $pdo->prepare("INSERT INTO repayments (transaction_reference, loan_id, amount_paid, penalty_paid, payment_date, payment_method, received_by, receipt_notes)
                              VALUES (?, ?, ?, ?, CURRENT_DATE, ?, 'Admin', ?)");
    $repStmt->execute([$txnRef, $loanId, $amount, $penalty, $method, $notes]);
    $repaymentId = $pdo->lastInsertId();

    // 2. Update Loan Balance & Status
    $updStmt = $pdo->prepare("UPDATE loans SET total_paid = ?, remaining_balance = ?, status = ? WHERE id = ?");
    $updStmt->execute([$newTotalPaid, $newBalance, $newStatus, $loanId]);

    logAudit($pdo, 'PAYMENT_RECORDED', 'repayment', $repaymentId, "Payment of " . formatCurrency($amount) . " for loan " . $loan['loan_code']);

    return [
        'transaction_reference' => $txnRef,
        'new_balance'           => $newBalance,
        'is_completed'          => ($newStatus === 'completed')
    ];
}

// -------------------------------------------------------------
// 10. READ LOAN DETAILS & REPAYMENT LEDGER
// -------------------------------------------------------------
function getLoanDetails($pdo, $loanId) {
    $sql = "SELECT l.*, b.borrower_code, b.first_name, b.last_name, b.email, b.phone, b.address, b.monthly_income,
                   p.plan_name, p.interest_rate, p.tenure_months, p.penalty_rate
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            JOIN loan_plans p ON l.plan_id = p.id
            WHERE l.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch();

    if (!$loan) return null;

    // Repayments history
    $repStmt = $pdo->prepare("SELECT * FROM repayments WHERE loan_id = ? ORDER BY payment_date DESC, id DESC");
    $repStmt->execute([$loanId]);
    $loan['repayments'] = $repStmt->fetchAll();

    // Progress percentage
    $loan['progress_pct'] = ($loan['total_payable'] > 0) 
        ? min(100, round(($loan['total_paid'] / $loan['total_payable']) * 100, 1)) 
        : 0;

    return $loan;
}

// -------------------------------------------------------------
// 11. CALCULATE OVERDUE LATE PENALTY (Business Logic)
// -------------------------------------------------------------
function calculateOverduePenalty($pdo, $loanId) {
    $loan = getLoanDetails($pdo, $loanId);
    if (!$loan || $loan['status'] !== 'disbursed' || $loan['remaining_balance'] <= 0) {
        return ['is_overdue' => false, 'days_overdue' => 0, 'penalty_amount' => 0.00, 'penalty_rate_pct' => 0];
    }

    $daysOverdue = (strtotime(date('Y-m-d')) - strtotime($loan['due_date'])) / (60 * 60 * 24);
    if ($daysOverdue > 0) {
        $rate = floatval($loan['penalty_rate']);
        $penalty = round(($loan['remaining_balance'] * ($rate / 100)), 2);
        return [
            'is_overdue'       => true,
            'days_overdue'     => (int)$daysOverdue,
            'penalty_amount'   => $penalty,
            'penalty_rate_pct' => $rate
        ];
    }

    return ['is_overdue' => false, 'days_overdue' => 0, 'penalty_amount' => 0.00, 'penalty_rate_pct' => floatval($loan['penalty_rate'])];
}

// -------------------------------------------------------------
// 12. DASHBOARD TOTALS & METRICS
// -------------------------------------------------------------
function getDashboardMetrics($pdo) {
    $stats = $pdo->query("SELECT 
        COALESCE(SUM(CASE WHEN status IN ('disbursed', 'completed') THEN principal_amount ELSE 0 END), 0) AS disbursed,
        COALESCE(SUM(CASE WHEN status = 'disbursed' THEN remaining_balance ELSE 0 END), 0) AS outstanding,
        COUNT(CASE WHEN status = 'disbursed' THEN 1 END) AS active_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_count,
        COUNT(CASE WHEN status = 'disbursed' AND due_date < CURRENT_DATE THEN 1 END) AS overdue_count
        FROM loans")->fetch();

    $collected = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM repayments")->fetchColumn();
    $borrowers = $pdo->query("SELECT COUNT(*) FROM borrowers WHERE status = 'active'")->fetchColumn();

    return [
        'total_disbursed'   => (float)$stats['disbursed'],
        'total_collected'   => (float)$collected,
        'total_outstanding' => (float)$stats['outstanding'],
        'active_borrowers'  => (int)$borrowers,
        'active_loans'      => (int)$stats['active_count'],
        'pending_loans'     => (int)$stats['pending_count'],
        'overdue_loans'     => (int)$stats['overdue_count'],
        'completed_loans'   => (int)$stats['completed_count']
    ];
}

// -------------------------------------------------------------
// 13. AUDIT LOG HELPER
// -------------------------------------------------------------
function logAudit($pdo, $action, $entityType, $entityId, $desc) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (action_type, entity_type, entity_id, description, performed_by, ip_address) 
                               VALUES (?, ?, ?, ?, 'Admin', ?)");
        $stmt->execute([$action, $entityType, $entityId, $desc, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
    } catch (Exception $e) {
        // Silently continue if audit logging fails
    }
}

// -------------------------------------------------------------
// 14. GET COMPREHENSIVE BORROWER PROFILE & 360 DOSSIER
// -------------------------------------------------------------
function getBorrowerProfile($pdo, $borrowerId) {
    $borrower = getBorrowerById($pdo, $borrowerId);
    if (!$borrower) return null;

    // Fetch all loans for this borrower
    $stmt = $pdo->prepare("SELECT l.*, p.plan_name, p.interest_rate, p.tenure_months, p.penalty_rate 
                           FROM loans l 
                           JOIN loan_plans p ON l.plan_id = p.id 
                           WHERE l.borrower_id = ? 
                           ORDER BY l.id DESC");
    $stmt->execute([$borrowerId]);
    $loans = $stmt->fetchAll();

    // Fetch all repayments for this borrower's loans
    $repStmt = $pdo->prepare("SELECT r.*, l.loan_code 
                              FROM repayments r 
                              JOIN loans l ON r.loan_id = l.id 
                              WHERE l.borrower_id = ? 
                              ORDER BY r.payment_date DESC, r.id DESC");
    $repStmt->execute([$borrowerId]);
    $repayments = $repStmt->fetchAll();

    // Aggregate statistics
    $totalBorrowed   = 0.00;
    $totalRepaid     = 0.00;
    $currentDebt     = 0.00;
    $activeLoans     = 0;
    $completedLoans  = 0;
    $hasOverdue      = false;
    $today           = date('Y-m-d');

    foreach ($loans as $loan) {
        $totalBorrowed += (float)$loan['principal_amount'];
        $totalRepaid   += (float)$loan['total_paid'];
        if ($loan['status'] === 'disbursed') {
            $activeLoans++;
            $currentDebt += (float)$loan['remaining_balance'];
            if (strtotime($loan['due_date']) < strtotime($today) && $loan['remaining_balance'] > 0) {
                $hasOverdue = true;
            }
        } elseif ($loan['status'] === 'completed') {
            $completedLoans++;
        }
    }

    // Determine credit standing
    if (count($loans) === 0) {
        $rating = 'New Client (No Loan History)';
        $ratingClass = 'secondary';
    } elseif ($hasOverdue) {
        $rating = 'High Risk (Has Overdue Loan)';
        $ratingClass = 'danger';
    } elseif ($activeLoans === 0 && $completedLoans > 0) {
        $rating = 'Excellent (Settled All Previous Loans)';
        $ratingClass = 'success';
    } elseif ($activeLoans > 0 && !$hasOverdue) {
        $rating = 'Good Standing (Current on Payments)';
        $ratingClass = 'primary';
    } else {
        $rating = 'Regular Account';
        $ratingClass = 'info';
    }

    return [
        'borrower'        => $borrower,
        'loans'           => $loans,
        'repayments'      => $repayments,
        'total_loans'     => count($loans),
        'active_loans'    => $activeLoans,
        'completed_loans' => $completedLoans,
        'total_borrowed'  => $totalBorrowed,
        'total_repaid'    => $totalRepaid,
        'current_debt'    => $currentDebt,
        'has_overdue'     => $hasOverdue,
        'credit_rating'   => $rating,
        'rating_class'    => $ratingClass
    ];
}

// -------------------------------------------------------------
// 15. GENERATE MONTHLY AMORTIZATION SCHEDULE
// -------------------------------------------------------------
function generateAmortizationSchedule($loan) {
    $tenure = (int)$loan['tenure_months'];
    if ($tenure <= 0) $tenure = 1;

    $startDate = !empty($loan['release_date']) ? $loan['release_date'] : $loan['created_at'];
    $installment = (float)$loan['monthly_installment'];
    $principalShare = round($loan['principal_amount'] / $tenure, 2);
    $interestShare  = round($loan['interest_amount'] / $tenure, 2);
    
    $schedule = [];
    $runningBalance = (float)$loan['total_payable'];
    $totalPaid = (float)$loan['total_paid'];
    $today = date('Y-m-d');

    for ($i = 1; $i <= $tenure; $i++) {
        $dueDate = date('Y-m-d', strtotime("+{$i} month", strtotime($startDate)));
        $expectedCumulative = round($i * $installment, 2);
        $prevCumulative = round(($i - 1) * $installment, 2);

        $runningBalance = max(0, round($runningBalance - $installment, 2));

        if ($totalPaid >= $expectedCumulative) {
            $status = 'PAID';
            $badgeClass = 'bg-success';
        } elseif ($totalPaid > $prevCumulative) {
            $status = 'PARTIAL';
            $badgeClass = 'bg-warning text-dark';
        } elseif (strtotime($dueDate) < strtotime($today) && $loan['status'] === 'disbursed') {
            $status = 'OVERDUE';
            $badgeClass = 'bg-danger';
        } else {
            $status = 'SCHEDULED';
            $badgeClass = 'bg-secondary';
        }

        $schedule[] = [
            'installment_no'  => $i,
            'due_date'        => $dueDate,
            'amount_due'      => $installment,
            'principal_part'  => $principalShare,
            'interest_part'   => $interestShare,
            'ending_balance'  => $runningBalance,
            'status'          => $status,
            'badge_class'     => $badgeClass
        ];
    }

    return $schedule;
}

// -------------------------------------------------------------
// 16. READ AUDIT LOGS WITH FILTERS (R in CRUD)
// -------------------------------------------------------------
function getAuditLogs($pdo, $limit = 100, $actionType = '', $search = '') {
    $sql = "SELECT * FROM audit_logs WHERE 1=1";
    $params = [];

    if (!empty($actionType)) {
        $sql .= " AND action_type = ?";
        $params[] = $actionType;
    }

    if (!empty($search)) {
        $sql .= " AND (description LIKE ? OR performed_by LIKE ? OR ip_address LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }

    $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

