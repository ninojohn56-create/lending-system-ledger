<?php
/**
 * Loans Directory, Search & Status Filter
 * File: loans/index.php
 *
 * Implements:
 * - System Function #10: View and search loan records
 * - System Function #13: Track loan statuses
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));

$allowedStatuses = ['all', 'pending', 'active', 'partially_paid', 'paid', 'overdue', 'rejected', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$whereClauses = [];
$params       = [];

if ($status !== 'all') {
    $whereClauses[]    = 'l.status = :status';
    $params[':status'] = $status;
}

if ($search !== '') {
    $whereClauses[] = '(l.loan_no LIKE :q1 OR b.full_name LIKE :q2 OR b.borrower_no LIKE :q3)';
    $likeTerm       = '%' . $search . '%';
    $params[':q1']  = $likeTerm;
    $params[':q2']  = $likeTerm;
    $params[':q3']  = $likeTerm;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "SELECT
            l.*,
            b.borrower_no,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = l.id), 0) AS total_paid
        FROM loans l
        INNER JOIN borrowers b ON b.id = l.borrower_id
        {$whereSql}
        ORDER BY l.created_at DESC, l.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Calculate filtered totals for display
$sumPrincipal = 0.0;
$sumPayable   = 0.0;
$sumBalance   = 0.0;
foreach ($loans as $row) {
    if (!in_array($row['status'], ['rejected', 'cancelled'], true)) {
        $sumPrincipal += (float)$row['principal'];
        $sumPayable   += (float)$row['total_payable'];
        $sumBalance   += (float)$row['balance'];
    }
}

$pageTitle  = 'Loans Management';
$activeMenu = 'loans';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h3 class="mb-1">Loan Agreements</h3>
        <p class="text-secondary mb-0">Create flat-interest loans, monitor maturity dates, and track repayment balances.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(baseUrl('loans/add.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Create Loan Record
        </a>
    </div>
</div>

<!-- Quick Filtered Portfolio Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">Filtered Principal Disbursed</div>
            <p class="stat-value fs-4"><?= e(formatPeso($sumPrincipal)) ?></p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">Scheduled Total Payable</div>
            <p class="stat-value fs-4"><?= e(formatPeso($sumPayable)) ?></p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">Remaining Unpaid Balance</div>
            <p class="stat-value fs-4 text-primary"><?= e(formatPeso($sumBalance)) ?></p>
        </div>
    </div>
</div>

<!-- Search & Status Filter Bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" action="<?= e(baseUrl('loans/index.php')) ?>" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        class="form-control"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Search by Loan No., Borrower Name, or Borrower No..."
                    >
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Loan Statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid in Full</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <?php if ($search !== '' || $status !== 'all'): ?>
                    <a href="<?= e(baseUrl('loans/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Loans Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text text-primary me-2"></i>Loan Ledger (<?= count($loans) ?>)</span>
        <span class="text-muted small">Interest Method: Flat Monthly Interest</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Loan No.</th>
                    <th>Borrower</th>
                    <th class="text-end">Principal</th>
                    <th>Rate &amp; Term</th>
                    <th class="text-end">Total Payable</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No loan records matched your search criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($loans as $loan): ?>
                        <?php
                        $canPay = in_array($loan['status'], ['active', 'partially_paid', 'overdue'], true) && (float)$loan['balance'] > 0;
                        ?>
                        <tr>
                            <td class="fw-semibold">
                                <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$loan['id'])) ?>" class="text-decoration-none">
                                    <?= e($loan['loan_no']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-medium"><?= e($loan['borrower_name']) ?></div>
                                <div class="text-muted small"><?= e($loan['borrower_no']) ?></div>
                            </td>
                            <td class="text-end"><?= e(formatPeso($loan['principal'])) ?></td>
                            <td>
                                <div><?= e($loan['interest_rate']) ?>% / mo</div>
                                <div class="text-muted small"><?= (int)$loan['term_months'] ?> month(s)</div>
                            </td>
                            <td class="text-end"><?= e(formatPeso($loan['total_payable'])) ?></td>
                            <td class="text-end text-success"><?= e(formatPeso($loan['total_paid'])) ?></td>
                            <td class="text-end fw-bold <?= (float)$loan['balance'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                <?= e(formatPeso($loan['balance'])) ?>
                            </td>
                            <td><?= e(date('M d, Y', strtotime($loan['due_date']))) ?></td>
                            <td><?= loanStatusBadge($loan['status']) ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$loan['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                        View
                                    </a>
                                    <?php if ($canPay): ?>
                                        <a href="<?= e(baseUrl('payments/add.php?loan_id=' . (int)$loan['id'])) ?>" class="btn btn-sm btn-success">
                                            Pay
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
