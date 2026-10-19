<?php
/**
 * Payments Ledger, Search, Date Filter & Printable Official Receipt
 * File: payments/index.php
 *
 * Implements:
 * - Payment history directory & search
 * - Printable Official Payment Receipt (?receipt_id=X)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

$search    = trim((string)($_GET['q'] ?? ''));
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));
$receiptId = isset($_GET['receipt_id']) ? (int)$_GET['receipt_id'] : 0;

$whereClauses = [];
$params       = [];

if ($search !== '') {
    $whereClauses[] = '(p.reference_no LIKE :q1 OR l.loan_no LIKE :q2 OR b.full_name LIKE :q3 OR b.borrower_no LIKE :q4)';
    $likeTerm       = '%' . $search . '%';
    $params[':q1']  = $likeTerm;
    $params[':q2']  = $likeTerm;
    $params[':q3']  = $likeTerm;
    $params[':q4']  = $likeTerm;
}

if ($dateFrom !== '' && strtotime($dateFrom) !== false) {
    $whereClauses[]       = 'p.payment_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '' && strtotime($dateTo) !== false) {
    $whereClauses[]     = 'p.payment_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "SELECT
            p.*,
            l.loan_no,
            l.total_payable,
            l.balance AS current_loan_balance,
            l.status AS loan_status,
            b.id AS borrower_id,
            b.borrower_no,
            b.full_name AS borrower_name,
            u.username AS staff_username,
            u.full_name AS staff_full_name
        FROM payments p
        INNER JOIN loans l ON l.id = p.loan_id
        INNER JOIN borrowers b ON b.id = l.borrower_id
        INNER JOIN users u ON u.id = p.recorded_by
        {$whereSql}
        ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$filteredTotal = 0.0;
foreach ($payments as $row) {
    $filteredTotal += (float)$row['amount'];
}

// Optional: Load selected receipt for printable view
$selectedReceipt = null;
if ($receiptId > 0) {
    $rStmt = $pdo->prepare(
        'SELECT
            p.*,
            l.loan_no,
            l.principal,
            l.total_payable,
            l.balance AS loan_current_balance,
            l.status AS loan_status,
            b.borrower_no,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            b.address AS borrower_address,
            u.username AS staff_username,
            u.full_name AS staff_full_name,
            COALESCE((
                SELECT SUM(p2.amount)
                FROM payments p2
                WHERE p2.loan_id = p.loan_id
                  AND (p2.payment_date < p.payment_date OR (p2.payment_date = p.payment_date AND p2.id <= p.id))
            ), 0) AS cumulative_paid_at_receipt
         FROM payments p
         INNER JOIN loans l ON l.id = p.loan_id
         INNER JOIN borrowers b ON b.id = l.borrower_id
         INNER JOIN users u ON u.id = p.recorded_by
         WHERE p.id = :id
         LIMIT 1'
    );
    $rStmt->execute([':id' => $receiptId]);
    $selectedReceipt = $rStmt->fetch();
}

$pageTitle  = 'Payments & Receipts';
$activeMenu = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
    <div>
        <h3 class="mb-1">Repayment Transactions</h3>
        <p class="text-secondary mb-0">Immutable audit log of all borrower loan payments and official receipts.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(baseUrl('payments/add.php')) ?>" class="btn btn-success">
            <i class="bi bi-cash-stack me-1"></i>Record Loan Payment
        </a>
    </div>
</div>

<?php if ($selectedReceipt): ?>
    <?php
    $balanceAfterReceipt = max(
        0.00,
        round((float)$selectedReceipt['total_payable'] - (float)$selectedReceipt['cumulative_paid_at_receipt'], 2)
    );
    $balanceBeforeReceipt = round($balanceAfterReceipt + (float)$selectedReceipt['amount'], 2);
    ?>
    <!-- Printable Official Payment Receipt Card -->
    <div class="card border-success shadow-sm mb-4">
        <div class="card-header bg-success bg-opacity-10 d-flex flex-wrap justify-content-between align-items-center gap-2 no-print">
            <div>
                <i class="bi bi-receipt-cutoff text-success me-1"></i>
                <span class="fw-bold text-dark">Official Payment Receipt: <?= e($selectedReceipt['reference_no']) ?></span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" onclick="window.print()" class="btn btn-sm btn-success">
                    <i class="bi bi-printer me-1"></i>Print Receipt
                </button>
                <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$selectedReceipt['loan_id'])) ?>" class="btn btn-sm btn-outline-primary">
                    View Loan <?= e($selectedReceipt['loan_no']) ?>
                </a>
                <a href="<?= e(baseUrl('payments/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    Close Receipt
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start border-bottom pb-3 mb-3">
                <div>
                    <h4 class="fw-bold mb-1"><?= e(APP_NAME) ?></h4>
                    <div class="text-secondary small">Official Loan Repayment Acknowledgment Receipt</div>
                </div>
                <div class="text-sm-end mt-2 mt-sm-0">
                    <div class="badge bg-success fs-6 mb-1"><?= e($selectedReceipt['reference_no']) ?></div>
                    <div class="small text-secondary">Payment Date: <strong><?= e(date('F j, Y', strtotime($selectedReceipt['payment_date']))) ?></strong></div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="text-muted small text-uppercase fw-semibold">Received From (Borrower)</div>
                    <div class="fw-bold fs-6"><?= e($selectedReceipt['borrower_name']) ?></div>
                    <div class="text-secondary small">Borrower ID: <?= e($selectedReceipt['borrower_no']) ?> &bull; <?= e($selectedReceipt['borrower_phone']) ?></div>
                    <div class="text-secondary small"><?= e($selectedReceipt['borrower_address']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="bg-light rounded-3 p-3 border">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-secondary">Applied to Loan No.:</span>
                            <span class="fw-bold"><?= e($selectedReceipt['loan_no']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-secondary">Balance Before Payment:</span>
                            <span><?= e(formatPeso($balanceBeforeReceipt)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold text-success border-top border-bottom py-1 my-1">
                            <span>Amount Paid (<?= e($selectedReceipt['payment_method']) ?>):</span>
                            <span><?= e(formatPeso($selectedReceipt['amount'])) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold text-primary small">
                            <span>Remaining Balance After Payment:</span>
                            <span><?= e(formatPeso($balanceAfterReceipt)) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top small text-secondary">
                <div>
                    <strong>Remarks:</strong> <?= e($selectedReceipt['remarks'] ?: 'Standard loan repayment installment') ?>
                </div>
                <div>
                    <strong>Recorded By:</strong> <?= e($selectedReceipt['staff_full_name'] ?: $selectedReceipt['staff_username']) ?>
                    on <?= e(date('M d, Y h:i A', strtotime($selectedReceipt['created_at']))) ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Search & Date Filter Bar -->
<div class="card mb-4 no-print">
    <div class="card-body py-3">
        <form method="GET" action="<?= e(baseUrl('payments/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search Keyword</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        class="form-control"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Receipt No., Loan No., or Borrower..."
                    >
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label small text-muted mb-1">From Date</label>
                <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label small text-muted mb-1">To Date</label>
                <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="<?= e(baseUrl('payments/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card no-print">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-cash-coin text-success me-2"></i>Payment Ledger (<?= count($payments) ?> record(s))</span>
        <span class="fw-bold text-success">Filtered Total Collected: <?= e(formatPeso($filteredTotal)) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Receipt / Ref No.</th>
                    <th>Payment Date</th>
                    <th>Loan No.</th>
                    <th>Borrower</th>
                    <th>Method</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end">Current Loan Balance</th>
                    <th>Recorded By</th>
                    <th class="text-end">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No payment records found matching your criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $pmt): ?>
                        <tr>
                            <td class="fw-semibold">
                                <a href="<?= e(baseUrl('payments/index.php?receipt_id=' . (int)$pmt['id'])) ?>" class="text-decoration-none">
                                    <?= e($pmt['reference_no']) ?>
                                </a>
                            </td>
                            <td><?= e(date('M d, Y', strtotime($pmt['payment_date']))) ?></td>
                            <td>
                                <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$pmt['loan_id'])) ?>" class="fw-semibold text-decoration-none">
                                    <?= e($pmt['loan_no']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-medium"><?= e($pmt['borrower_name']) ?></div>
                                <div class="text-muted small"><?= e($pmt['borrower_no']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($pmt['payment_method']) ?></span></td>
                            <td class="text-end fw-bold text-success"><?= e(formatPeso($pmt['amount'])) ?></td>
                            <td class="text-end fw-semibold"><?= e(formatPeso($pmt['current_loan_balance'])) ?></td>
                            <td class="text-secondary small"><?= e($pmt['staff_username']) ?></td>
                            <td class="text-end">
                                <a href="<?= e(baseUrl('payments/index.php?receipt_id=' . (int)$pmt['id'])) ?>" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-receipt"></i> View / Print
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
