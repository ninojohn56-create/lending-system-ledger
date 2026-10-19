<?php
/**
 * Loan Details, Status Workflow & Payment Ledger View
 * File: loans/view.php
 *
 * Implements:
 * - System Function #10: View loan details & payment history
 * - System Function #12: Verify & display remaining loan balance
 * - System Function #13: Track and manage loan statuses (approve, reject, cancel)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'Invalid loan ID specified.');
    header('Location: ' . baseUrl('loans/index.php'));
    exit;
}

// Handle POST status transition actions (Approve pending loan, Reject, or Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Security token mismatch. Please try again.');
        header('Location: ' . baseUrl('loans/view.php?id=' . $id));
        exit;
    }

    $statusAction = (string)$_POST['status_action'];

    $pdo->beginTransaction();
    try {
        // Lock the loan row and check existing payments
        $lockStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
        $lockStmt->execute([':id' => $id]);
        $currentLoan = $lockStmt->fetch();

        if (!$currentLoan) {
            $pdo->rollBack();
            setFlash('danger', 'Loan record not found.');
            header('Location: ' . baseUrl('loans/index.php'));
            exit;
        }

        $paidStmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE loan_id = :id');
        $paidStmt->execute([':id' => $id]);
        $paidInfo = $paidStmt->fetch();
        $paymentCount = (int)$paidInfo['cnt'];

        if ($statusAction === 'approve' && $currentLoan['status'] === 'pending') {
            $newStatus = ($currentLoan['due_date'] < date('Y-m-d')) ? 'overdue' : 'active';
            $upd = $pdo->prepare('UPDATE loans SET status = :status, balance = total_payable WHERE id = :id');
            $upd->execute([':status' => $newStatus, ':id' => $id]);
            $pdo->commit();
            setFlash('success', 'Loan ' . $currentLoan['loan_no'] . ' has been approved and marked as ' . strtoupper($newStatus) . '.');
        } elseif (in_array($statusAction, ['reject', 'cancel'], true)) {
            if ($paymentCount > 0) {
                $pdo->rollBack();
                setFlash('danger', 'Cannot reject or cancel a loan that already has recorded payments.');
            } else {
                $targetStatus = $statusAction === 'reject' ? 'rejected' : 'cancelled';
                $upd = $pdo->prepare('UPDATE loans SET status = :status, balance = 0.00 WHERE id = :id');
                $upd->execute([':status' => $targetStatus, ':id' => $id]);
                $pdo->commit();
                setFlash('info', 'Loan ' . $currentLoan['loan_no'] . ' has been marked as ' . ucfirst($targetStatus) . ' (Balance set to ₱0.00).');
            }
        } else {
            $pdo->rollBack();
            setFlash('warning', 'Requested status transition is not applicable for this loan.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Loan status update error: ' . $e->getMessage());
        setFlash('danger', 'An error occurred while updating the loan status.');
    }

    header('Location: ' . baseUrl('loans/view.php?id=' . $id));
    exit;
}

// Fetch loan record joined with borrower details
$stmt = $pdo->prepare(
    'SELECT
        l.*,
        b.borrower_no,
        b.full_name AS borrower_name,
        b.phone AS borrower_phone,
        b.address AS borrower_address
     FROM loans l
     INNER JOIN borrowers b ON b.id = l.borrower_id
     WHERE l.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$loan = $stmt->fetch();

if (!$loan) {
    setFlash('danger', 'Loan record not found.');
    header('Location: ' . baseUrl('loans/index.php'));
    exit;
}

// Fetch all payment transactions for this loan (audit source of truth)
$paymentsStmt = $pdo->prepare(
    'SELECT
        p.*,
        u.username AS staff_username,
        u.full_name AS staff_full_name
     FROM payments p
     INNER JOIN users u ON u.id = p.recorded_by
     WHERE p.loan_id = :loan_id
     ORDER BY p.payment_date ASC, p.id ASC'
);
$paymentsStmt->execute([':loan_id' => $id]);
$payments = $paymentsStmt->fetchAll();

// Compute audited payment sum and verify consistency with stored balance
$totalPaid = 0.0;
foreach ($payments as $p) {
    $totalPaid += (float)$p['amount'];
}
$totalPaid = round($totalPaid, 2);

$expectedBalance = in_array($loan['status'], ['rejected', 'cancelled'], true)
    ? 0.00
    : max(0.00, round((float)$loan['total_payable'] - $totalPaid, 2));

$balanceIsConsistent = abs((float)$loan['balance'] - $expectedBalance) < 0.01;
$repaymentProgress   = (float)$loan['total_payable'] > 0
    ? min(100, round(($totalPaid / (float)$loan['total_payable']) * 100, 1))
    : 0.0;

$canRecordPayment = in_array($loan['status'], ['active', 'partially_paid', 'overdue'], true) && (float)$loan['balance'] > 0;

$pageTitle  = 'Loan ' . $loan['loan_no'];
$activeMenu = 'loans';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2 no-print">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('dashboard.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('loans/index.php')) ?>">Loans</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($loan['loan_no']) ?></li>
    </ol>
</nav>

<!-- Header & Action Buttons -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h3 class="mb-0"><?= e($loan['loan_no']) ?></h3>
            <?= loanStatusBadge($loan['status']) ?>
        </div>
        <p class="text-secondary mb-0">
            Borrower:
            <a href="<?= e(baseUrl('borrowers/index.php?view_id=' . (int)$loan['borrower_id'])) ?>" class="fw-semibold text-decoration-none">
                <?= e($loan['borrower_name']) ?> (<?= e($loan['borrower_no']) ?>)
            </a>
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2 no-print">
        <?php if ($canRecordPayment): ?>
            <a href="<?= e(baseUrl('payments/add.php?loan_id=' . (int)$loan['id'])) ?>" class="btn btn-success">
                <i class="bi bi-cash-coin me-1"></i>Record Payment
            </a>
        <?php endif; ?>

        <button type="button" onclick="window.print()" class="btn btn-outline-primary">
            <i class="bi bi-printer me-1"></i>Print Statement
        </button>

        <a href="<?= e(baseUrl('loans/index.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Financial Breakdown Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="stat-label">Principal Amount</div>
            <p class="stat-value"><?= e(formatPeso($loan['principal'])) ?></p>
            <div class="text-secondary small mt-2">
                Rate: <?= e($loan['interest_rate']) ?>%/mo &times; <?= (int)$loan['term_months'] ?> mos (Flat)
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="stat-label">Total Payable (+ Interest)</div>
            <p class="stat-value"><?= e(formatPeso($loan['total_payable'])) ?></p>
            <div class="text-secondary small mt-2">
                Total Interest: <?= e(formatPeso($loan['total_interest'])) ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="stat-label">Total Paid (Audited)</div>
            <p class="stat-value text-success"><?= e(formatPeso($totalPaid)) ?></p>
            <div class="text-secondary small mt-2">
                <?= count($payments) ?> payment transaction(s)
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="stat-label">Remaining Balance</div>
            <p class="stat-value <?= (float)$loan['balance'] > 0 ? 'text-primary' : 'text-success' ?>">
                <?= e(formatPeso($loan['balance'])) ?>
            </p>
            <div class="text-secondary small mt-2">
                Monthly Due: <?= e(formatPeso($loan['monthly_due'])) ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Left Column: Loan Contract & Borrower Details -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text text-primary me-2"></i>Loan Agreement Specification</span>
                <?php if ($balanceIsConsistent): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="bi bi-shield-check me-1"></i>Ledger Verified
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small text-uppercase fw-semibold">Borrower Name &amp; ID</div>
                        <div class="fw-medium"><?= e($loan['borrower_name']) ?> (<?= e($loan['borrower_no']) ?>)</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small text-uppercase fw-semibold">Contact Phone</div>
                        <div class="fw-medium"><?= e($loan['borrower_phone']) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small text-uppercase fw-semibold">Borrower Address</div>
                        <div class="fw-medium"><?= e($loan['borrower_address']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small text-uppercase fw-semibold">Release Date</div>
                        <div class="fw-medium"><?= e(date('F j, Y', strtotime($loan['release_date']))) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small text-uppercase fw-semibold">Maturity Due Date</div>
                        <div class="fw-medium <?= $loan['status'] === 'overdue' ? 'text-danger fw-bold' : '' ?>">
                            <?= e(date('F j, Y', strtotime($loan['due_date']))) ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small text-uppercase fw-semibold">Interest Convention</div>
                        <div class="fw-medium text-capitalize"><?= e($loan['interest_method']) ?> (<?= e($loan['interest_rate']) ?>% / mo)</div>
                    </div>
                    <?php if (!empty($loan['remarks'])): ?>
                        <div class="col-12">
                            <div class="text-muted small text-uppercase fw-semibold">Remarks / Purpose</div>
                            <div class="fw-medium"><?= e($loan['remarks']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Repayment Progress Bar -->
                <?php if (!in_array($loan['status'], ['rejected', 'cancelled'], true)): ?>
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold">Repayment Progress</span>
                            <span class="fw-bold text-primary"><?= e((string)$repaymentProgress) ?>% Paid</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" role="progressbar"
                                 style="width: <?= e((string)$repaymentProgress) ?>%"
                                 aria-valuenow="<?= e((string)$repaymentProgress) ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Formula Verification & Status Controls -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-calculator text-primary me-2"></i>Accounting &amp; Status Controls
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="bg-light rounded-3 p-3 border small mb-3">
                        <div class="fw-bold text-dark mb-2">Flat Interest Calculation Audit:</div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Principal:</span>
                            <span><?= e(formatPeso($loan['principal'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Interest (<?= e(formatPeso($loan['principal'])) ?> &times; <?= e($loan['interest_rate']) ?>% &times; <?= (int)$loan['term_months'] ?> mos):</span>
                            <span>+ <?= e(formatPeso($loan['total_interest'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-semibold border-top pt-1 mb-1">
                            <span>Total Scheduled Payable:</span>
                            <span><?= e(formatPeso($loan['total_payable'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-success mb-1">
                            <span>Less: Cumulative Payments:</span>
                            <span>- <?= e(formatPeso($totalPaid)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold border-top pt-1 text-primary">
                            <span>Audited Remaining Balance:</span>
                            <span><?= e(formatPeso($loan['balance'])) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Administrative Status Transitions (Only when no payments have been made yet) -->
                <div class="no-print">
                    <?php if ($loan['status'] === 'pending'): ?>
                        <div class="border rounded-3 p-3 bg-warning bg-opacity-10">
                            <div class="fw-semibold small mb-2">Pending Loan Decision:</div>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="<?= e(baseUrl('loans/view.php?id=' . $id)) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="status_action" value="approve">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-lg me-1"></i>Approve &amp; Activate
                                    </button>
                                </form>

                                <form method="POST" action="<?= e(baseUrl('loans/view.php?id=' . $id)) ?>"
                                      onsubmit="return confirm('Mark this pending loan application as Rejected?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="status_action" value="reject">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-circle me-1"></i>Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php elseif (in_array($loan['status'], ['active', 'overdue'], true) && count($payments) === 0): ?>
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small">No payments recorded yet. Need to void this loan?</span>
                                <form method="POST" action="<?= e(baseUrl('loans/view.php?id=' . $id)) ?>"
                                      onsubmit="return confirm('Cancel this loan agreement? Its balance will be set to ₱0.00.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="status_action" value="cancel">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        Cancel Loan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small">
                            <i class="bi bi-lock-fill me-1"></i>
                            Loan status is automatically controlled by repayment transactions and maturity dates.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment History Ledger for this Loan -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt-cutoff text-success me-2"></i>Payment History Ledger (<?= count($payments) ?>)</span>
        <?php if ($canRecordPayment): ?>
            <a href="<?= e(baseUrl('payments/add.php?loan_id=' . (int)$loan['id'])) ?>" class="btn btn-sm btn-success no-print">
                <i class="bi bi-plus-lg me-1"></i>Add Payment
            </a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Receipt / Reference No.</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end">Running Balance</th>
                    <th>Recorded By</th>
                    <th>Remarks</th>
                    <th class="text-end no-print">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No payments have been recorded against this loan yet.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $runningBalance = (float)$loan['total_payable'];
                    foreach ($payments as $index => $pmt):
                        $runningBalance = max(0.00, round($runningBalance - (float)$pmt['amount'], 2));
                    ?>
                        <tr>
                            <td class="text-muted"><?= $index + 1 ?></td>
                            <td class="fw-semibold"><?= e($pmt['reference_no']) ?></td>
                            <td><?= e(date('M d, Y', strtotime($pmt['payment_date']))) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($pmt['payment_method']) ?></span></td>
                            <td class="text-end fw-bold text-success"><?= e(formatPeso($pmt['amount'])) ?></td>
                            <td class="text-end fw-semibold"><?= e(formatPeso($runningBalance)) ?></td>
                            <td class="small"><?= e($pmt['staff_full_name'] ?: $pmt['staff_username']) ?></td>
                            <td class="text-secondary small"><?= e($pmt['remarks'] ?? '—') ?></td>
                            <td class="text-end no-print">
                                <a href="<?= e(baseUrl('payments/index.php?receipt_id=' . (int)$pmt['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-printer"></i> Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
