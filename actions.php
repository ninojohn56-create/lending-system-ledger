<?php
/**
 * Action Controller & Request Router
 * Handles form submissions with CSRF checks, validation, and error redirection.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// 1. Verify CSRF Token
$token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($token)) {
    setFlash('danger', 'Security validation failed (Invalid CSRF Token). Please reload and try again.');
    $referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header("Location: {$referrer}");
    exit;
}

$action = $_POST['action'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';

try {
    $pdo = getDbConnection();

    switch ($action) {
        // -------------------------------------------------------------
        // BORROWER ACTIONS
        // -------------------------------------------------------------
        case 'add_borrower':
            $newId = registerBorrower($pdo, $_POST);
            setFlash('success', 'Borrower successfully registered!');
            header("Location: borrowers.php");
            exit;

        case 'edit_borrower':
            $borrowerId = (int)($_POST['id'] ?? 0);
            updateBorrower($pdo, $borrowerId, $_POST);
            setFlash('success', 'Borrower information updated successfully.');
            header("Location: borrowers.php");
            exit;

        case 'delete_borrower':
            $borrowerId = (int)($_POST['id'] ?? 0);
            deleteBorrower($pdo, $borrowerId);
            setFlash('success', 'Borrower record processed successfully.');
            header("Location: borrowers.php");
            exit;

        // -------------------------------------------------------------
        // LOAN ACTIONS
        // -------------------------------------------------------------
        case 'apply_loan':
            $newLoanId = applyLoan($pdo, $_POST);
            setFlash('success', 'Loan application submitted successfully.');
            header("Location: loan_view.php?id={$newLoanId}");
            exit;

        case 'update_loan_status':
            $loanId = (int)($_POST['loan_id'] ?? 0);
            $statusAction = $_POST['status_action'] ?? '';
            $remarks = $_POST['remarks'] ?? '';
            updateLoanStatus($pdo, $loanId, $statusAction, $remarks);
            setFlash('success', "Loan marked as {$statusAction} successfully.");
            header("Location: loan_view.php?id={$loanId}");
            exit;

        // -------------------------------------------------------------
        // PAYMENT ACTIONS
        // -------------------------------------------------------------
        case 'record_payment':
            $loanId      = (int)($_POST['loan_id'] ?? 0);
            $amount      = floatval($_POST['amount'] ?? 0);
            $method      = $_POST['payment_method'] ?? 'cash';
            $notes       = $_POST['notes'] ?? '';
            $penaltyPaid = floatval($_POST['penalty_paid'] ?? 0);

            $result = recordRepayment($pdo, $loanId, $amount, $method, $notes, $penaltyPaid);
            $msg = "Payment of " . formatCurrency($amount) . " successfully posted! Ref: " . $result['transaction_reference'];
            if ($result['is_completed']) {
                $msg .= " — Loan is now FULLY PAID!";
            }
            setFlash('success', $msg);
            header("Location: loan_view.php?id={$loanId}");
            exit;

        // -------------------------------------------------------------
        // LOAN PLAN ACTIONS
        // -------------------------------------------------------------
        case 'add_plan':
            $stmt = $pdo->prepare("INSERT INTO loan_plans (plan_name, interest_rate, tenure_months, penalty_rate, description, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                trim($_POST['plan_name']),
                floatval($_POST['interest_rate']),
                (int)$_POST['tenure_months'],
                floatval($_POST['penalty_rate'] ?? 2.0),
                trim($_POST['description'] ?? '')
            ]);
            setFlash('success', 'New loan plan created successfully!');
            header("Location: plans.php");
            exit;

        case 'toggle_plan':
            $planId = (int)($_POST['plan_id'] ?? 0);
            $newStatus = ($_POST['current_status'] === 'active') ? 'inactive' : 'active';
            $stmt = $pdo->prepare("UPDATE loan_plans SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $planId]);
            setFlash('success', "Loan plan status updated to {$newStatus}.");
            header("Location: plans.php");
            exit;

        default:
            throw new Exception("Unknown action request received.");
    }

} catch (InvalidArgumentException $e) {
    setFlash('warning', 'Validation Warning: ' . $e->getMessage());
    header("Location: {$referrer}");
    exit;
} catch (Exception $e) {
    setFlash('danger', 'Error: ' . $e->getMessage());
    header("Location: {$referrer}");
    exit;
}
