<?php
/**
 * System Dashboard & Real-Time Lending Statistics
 * File: dashboard.php
 *
 * Implements:
 * - System Function #3: Dashboard with live lending statistics retrieved from MySQL
 * - Automatic overdue status synchronization
 * - Recent loan activity table & Upcoming / Overdue loans monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
requireLogin();

// Automatically mark active/partially_paid loans past their due_date as overdue
syncOverdueLoans($pdo);

// 1. Total Active Borrowers (non-archived) & Archived count
$borrowerStats = $pdo->query(
    "SELECT
        COUNT(CASE WHEN is_archived = 0 THEN 1 END) AS active_borrowers,
        COUNT(CASE WHEN is_archived = 1 THEN 1 END) AS archived_borrowers
     FROM borrowers"
)->fetch();

// 2. Loan Portfolio Aggregations (Active / Partially Paid / Overdue / Paid)
$loanStats = $pdo->query(
    "SELECT
        COUNT(CASE WHEN status IN ('active', 'partially_paid', 'overdue') THEN 1 END) AS open_loans_count,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) AS overdue_loans_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) AS paid_loans_count,
        COALESCE(SUM(CASE WHEN status NOT IN ('rejected', 'cancelled') THEN principal ELSE 0 END), 0) AS total_principal_disbursed,
        COALESCE(SUM(CASE WHEN status IN ('active', 'partially_paid', 'overdue') THEN balance ELSE 0 END), 0) AS total_outstanding_balance
     FROM loans"
)->fetch();

// 3. Total Payments Collected
$paymentStats = $pdo->query(
    "SELECT
        COUNT(*) AS total_payment_count,
        COALESCE(SUM(amount), 0) AS total_collected
     FROM payments"
)->fetch();

// 4. Recent Loan Activity (Latest 6 loans joined with borrower details)
$recentLoansStmt = $pdo->query(
    "SELECT
        l.id,
        l.loan_no,
        l.principal,
        l.interest_rate,
        l.term_months,
        l.total_payable,
        l.balance,
        l.release_date,
        l.due_date,
        l.status,
        b.id AS borrower_id,
        b.borrower_no,
        b.full_name AS borrower_name
     FROM loans l
     INNER JOIN borrowers b ON b.id = l.borrower_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 6"
);
$recentLoans = $recentLoansStmt->fetchAll();

// 5. Overdue & Upcoming Maturing Loans (Open loans ordered by earliest due_date)
$attentionLoansStmt = $pdo->query(
    "SELECT
        l.id,
        l.loan_no,
        l.monthly_due,
        l.balance,
        l.due_date,
        l.status,
        DATEDIFF(l.due_date, CURDATE()) AS days_until_due,
        b.id AS borrower_id,
        b.full_name AS borrower_name,
        b.phone AS borrower_phone
     FROM loans l
     INNER JOIN borrowers b ON b.id = l.borrower_id
     WHERE l.status IN ('overdue', 'active', 'partially_paid')
       AND l.balance > 0
     ORDER BY l.due_date ASC
     LIMIT 6"
);
$attentionLoans = $attentionLoansStmt->fetchAll();

// 6. Recent Payments Collected (Latest 5 receipts)
$recentPaymentsStmt = $pdo->query(
    "SELECT
        p.id,
        p.loan_id,
        p.amount,
        p.payment_date,
        p.payment_method,
        p.reference_no,
        l.loan_no,
        b.full_name AS borrower_name,
        u.username AS staff_username
     FROM payments p
     INNER JOIN loans l ON l.id = p.loan_id
     INNER JOIN borrowers b ON b.id = l.borrower_id
     INNER JOIN users u ON u.id = p.recorded_by
     ORDER BY p.payment_date DESC, p.id DESC
     LIMIT 5"
);
$recentPayments = $recentPaymentsStmt->fetchAll();

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header & Quick Actions -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h3 class="mb-1">Executive Lending Dashboard</h3>
        <p class="text-secondary mb-0">Live portfolio metrics, active loan balances, and collection activity from MySQL.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="<?= e(baseUrl('borrowers/add.php')) ?>" class="btn btn-outline-primary">
            <i class="bi bi-person-plus me-1"></i>New Borrower
        </a>
        <a href="<?= e(baseUrl('loans/add.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Create Loan
        </a>
        <a href="<?= e(baseUrl('payments/add.php')) ?>" class="btn btn-success">
            <i class="bi bi-cash-stack me-1"></i>Record Payment
        </a>
    </div>
</div>

<!-- Key Financial & Operational Stat Cards -->
<div class="row g-3 mb-4">
    <!-- 1. Total Borrowers -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Borrowers</div>
                    <p class="stat-value"><?= e(number_format((int)$borrowerStats['active_borrowers'])) ?></p>
                </div>
                <span class="bg-primary bg-opacity-10 text-primary rounded-3 p-2 fs-5">
                    <i class="bi bi-people-fill"></i>
                </span>
            </div>
            <div class="text-secondary small mt-2">
                Active registered clients
                <?php if ((int)$borrowerStats['archived_borrowers'] > 0): ?>
                    (&plus;<?= (int)$borrowerStats['archived_borrowers'] ?> archived)
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2. Active / Open Loans -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Active / Open Loans</div>
                    <p class="stat-value"><?= e(number_format((int)$loanStats['open_loans_count'])) ?></p>
                </div>
                <span class="bg-info bg-opacity-10 text-info rounded-3 p-2 fs-5">
                    <i class="bi bi-journal-check"></i>
                </span>
            </div>
            <div class="text-secondary small mt-2">
                <span class="text-success fw-medium"><?= (int)$loanStats['paid_loans_count'] ?> fully paid</span>
                &bull;
                <span class="<?= (int)$loanStats['overdue_loans_count'] > 0 ? 'text-danger fw-semibold' : 'text-secondary' ?>">
                    <?= (int)$loanStats['overdue_loans_count'] ?> overdue
                </span>
            </div>
        </div>
    </div>

    <!-- 3. Total Outstanding Balance -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Outstanding Balance</div>
                    <p class="stat-value text-primary"><?= e(formatPeso($loanStats['total_outstanding_balance'])) ?></p>
                </div>
                <span class="bg-warning bg-opacity-10 text-warning rounded-3 p-2 fs-5">
                    <i class="bi bi-hourglass-split"></i>
                </span>
            </div>
            <div class="text-secondary small mt-2">
                Principal Disbursed: <?= e(formatPeso($loanStats['total_principal_disbursed'])) ?>
            </div>
        </div>
    </div>

    <!-- 4. Total Payments Collected -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Payments Collected</div>
                    <p class="stat-value text-success"><?= e(formatPeso($paymentStats['total_collected'])) ?></p>
                </div>
                <span class="bg-success bg-opacity-10 text-success rounded-3 p-2 fs-5">
                    <i class="bi bi-cash-coin"></i>
                </span>
            </div>
            <div class="text-secondary small mt-2">
                Across <?= e(number_format((int)$paymentStats['total_payment_count'])) ?> official payment receipt(s)
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Recent Loan Activity -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history text-primary me-2"></i>Recent Loan Activity</span>
                <a href="<?= e(baseUrl('loans/index.php')) ?>" class="btn btn-sm btn-outline-secondary">View All Loans</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Loan No.</th>
                            <th>Borrower</th>
                            <th class="text-end">Total Payable</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLoans)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No loan records found yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLoans as $loan): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$loan['id'])) ?>" class="text-decoration-none">
                                            <?= e($loan['loan_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= e($loan['borrower_name']) ?></div>
                                        <div class="text-muted small"><?= e($loan['borrower_no']) ?> &bull; <?= (int)$loan['term_months'] ?> mos @ <?= e($loan['interest_rate']) ?>%/mo</div>
                                    </td>
                                    <td class="text-end"><?= e(formatPeso($loan['total_payable'])) ?></td>
                                    <td class="text-end fw-semibold"><?= e(formatPeso($loan['balance'])) ?></td>
                                    <td><?= loanStatusBadge($loan['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column: Overdue & Upcoming Maturities -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-event text-danger me-2"></i>Overdue &amp; Upcoming Maturities</span>
                <a href="<?= e(baseUrl('reports/index.php?tab=balances')) ?>" class="btn btn-sm btn-outline-secondary">Balance Report</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Loan / Borrower</th>
                            <th>Maturity Date</th>
                            <th class="text-end">Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attentionLoans)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No outstanding active or overdue loans.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attentionLoans as $item): ?>
                                <?php
                                $daysUntil = (int)$item['days_until_due'];
                                $isOverdue = $daysUntil < 0 || $item['status'] === 'overdue';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$item['id'])) ?>" class="fw-semibold text-decoration-none">
                                            <?= e($item['loan_no']) ?>
                                        </a>
                                        <div class="text-secondary small"><?= e($item['borrower_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-medium <?= $isOverdue ? 'text-danger' : '' ?>">
                                            <?= e(date('M d, Y', strtotime($item['due_date']))) ?>
                                        </div>
                                        <div class="small <?= $isOverdue ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                            <?php if ($daysUntil < 0): ?>
                                                <?= abs($daysUntil) ?> day(s) overdue
                                            <?php elseif ($daysUntil === 0): ?>
                                                Due today
                                            <?php else: ?>
                                                In <?= $daysUntil ?> day(s)
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold"><?= e(formatPeso($item['balance'])) ?></td>
                                    <td>
                                        <a href="<?= e(baseUrl('payments/add.php?loan_id=' . (int)$item['id'])) ?>" class="btn btn-sm btn-outline-success" title="Record Payment">
                                            Pay
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Recent Payments Collected Section -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt text-success me-2"></i>Latest Recorded Repayments</span>
        <a href="<?= e(baseUrl('payments/index.php')) ?>" class="btn btn-sm btn-outline-secondary">All Payments</a>
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
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentPayments)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No payments recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentPayments as $pmt): ?>
                        <tr>
                            <td class="fw-semibold">
                                <a href="<?= e(baseUrl('payments/index.php?receipt_id=' . (int)$pmt['id'])) ?>" class="text-decoration-none">
                                    <?= e($pmt['reference_no']) ?>
                                </a>
                            </td>
                            <td><?= e(date('M d, Y', strtotime($pmt['payment_date']))) ?></td>
                            <td>
                                <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$pmt['loan_id'])) ?>" class="text-decoration-none">
                                    <?= e($pmt['loan_no']) ?>
                                </a>
                            </td>
                            <td><?= e($pmt['borrower_name']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($pmt['payment_method']) ?></span></td>
                            <td class="text-end fw-bold text-success"><?= e(formatPeso($pmt['amount'])) ?></td>
                            <td class="text-secondary small"><?= e($pmt['staff_username']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
