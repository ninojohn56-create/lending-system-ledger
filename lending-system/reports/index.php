<?php
/**
 * Financial Reports & Print-Friendly Generator
 * File: reports/index.php
 *
 * Implements:
 * - System Function #14: Generate loan, payment, and outstanding balance reports
 * - System Function #15: Print reports with clean print-friendly CSS layout
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

$tab      = trim((string)($_GET['tab'] ?? 'loans'));
$search   = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$status   = trim((string)($_GET['status'] ?? 'all'));
$method   = trim((string)($_GET['method'] ?? 'all'));

if (!in_array($tab, ['loans', 'payments', 'balances'], true)) {
    $tab = 'loans';
}

$reportTitle = match ($tab) {
    'payments' => 'Payment Collection Report',
    'balances' => 'Outstanding Loan Balances & Overdue Aging Report',
    default    => 'Loan Portfolio & Disbursement Report',
};

$rows   = [];
$totals = [
    'principal'     => 0.0,
    'interest'      => 0.0,
    'total_payable' => 0.0,
    'total_paid'    => 0.0,
    'balance'       => 0.0,
    'collected'     => 0.0,
];

// ----------------------------------------------------------------------------
// TAB 1: LOAN PORTFOLIO REPORT
// ----------------------------------------------------------------------------
if ($tab === 'loans') {
    $where  = [];
    $params = [];

    if ($status !== 'all' && in_array($status, ['pending', 'active', 'partially_paid', 'paid', 'overdue', 'rejected', 'cancelled'], true)) {
        $where[]           = 'l.status = :status';
        $params[':status'] = $status;
    }
    if ($dateFrom !== '' && strtotime($dateFrom) !== false) {
        $where[]              = 'l.release_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '' && strtotime($dateTo) !== false) {
        $where[]            = 'l.release_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if ($search !== '') {
        $where[]       = '(l.loan_no LIKE :q1 OR b.full_name LIKE :q2 OR b.borrower_no LIKE :q3)';
        $like          = '%' . $search . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(
        "SELECT
            l.*,
            b.borrower_no,
            b.full_name AS borrower_name,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = l.id), 0) AS total_paid
         FROM loans l
         INNER JOIN borrowers b ON b.id = l.borrower_id
         {$whereSql}
         ORDER BY l.release_date DESC, l.id DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        if (!in_array($r['status'], ['rejected', 'cancelled'], true)) {
            $totals['principal']     += (float)$r['principal'];
            $totals['interest']      += (float)$r['total_interest'];
            $totals['total_payable'] += (float)$r['total_payable'];
            $totals['total_paid']    += (float)$r['total_paid'];
            $totals['balance']       += (float)$r['balance'];
        }
    }
}

// ----------------------------------------------------------------------------
// TAB 2: PAYMENT COLLECTION REPORT
// ----------------------------------------------------------------------------
if ($tab === 'payments') {
    $where  = [];
    $params = [];

    if ($method !== 'all' && in_array($method, ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Check'], true)) {
        $where[]           = 'p.payment_method = :method';
        $params[':method'] = $method;
    }
    if ($dateFrom !== '' && strtotime($dateFrom) !== false) {
        $where[]              = 'p.payment_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '' && strtotime($dateTo) !== false) {
        $where[]            = 'p.payment_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if ($search !== '') {
        $where[]       = '(p.reference_no LIKE :q1 OR l.loan_no LIKE :q2 OR b.full_name LIKE :q3)';
        $like          = '%' . $search . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(
        "SELECT
            p.*,
            l.loan_no,
            b.borrower_no,
            b.full_name AS borrower_name,
            u.username AS staff_username,
            u.full_name AS staff_full_name
         FROM payments p
         INNER JOIN loans l ON l.id = p.loan_id
         INNER JOIN borrowers b ON b.id = l.borrower_id
         INNER JOIN users u ON u.id = p.recorded_by
         {$whereSql}
         ORDER BY p.payment_date DESC, p.id DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $totals['collected'] += (float)$r['amount'];
    }
}

// ----------------------------------------------------------------------------
// TAB 3: OUTSTANDING BALANCE & AGING REPORT
// ----------------------------------------------------------------------------
if ($tab === 'balances') {
    $where  = ["l.status IN ('active', 'partially_paid', 'overdue')", 'l.balance > 0'];
    $params = [];

    if ($status === 'overdue') {
        $where[] = "l.status = 'overdue'";
    }
    if ($search !== '') {
        $where[]       = '(l.loan_no LIKE :q1 OR b.full_name LIKE :q2 OR b.borrower_no LIKE :q3 OR b.phone LIKE :q4)';
        $like          = '%' . $search . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT
            l.*,
            DATEDIFF(l.due_date, CURDATE()) AS days_until_due,
            b.borrower_no,
            b.full_name AS borrower_name,
            b.phone AS borrower_phone,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = l.id), 0) AS total_paid
         FROM loans l
         INNER JOIN borrowers b ON b.id = l.borrower_id
         {$whereSql}
         ORDER BY l.due_date ASC, l.balance DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $totals['total_payable'] += (float)$r['total_payable'];
        $totals['total_paid']    += (float)$r['total_paid'];
        $totals['balance']       += (float)$r['balance'];
    }
}

$pageTitle  = $reportTitle;
$activeMenu = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Print-Only Official Report Header -->
<div class="d-none d-print-block border-bottom pb-3 mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h3 class="fw-bold mb-1"><?= e(APP_NAME) ?></h3>
            <h5 class="text-secondary mb-0"><?= e($reportTitle) ?></h5>
        </div>
        <div class="text-end small">
            <div><strong>Generated On:</strong> <?= e(date('F j, Y h:i A')) ?></div>
            <div><strong>Prepared By:</strong> <?= e($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Staff')) ?></div>
            <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
                <div><strong>Date Filter:</strong> <?= e($dateFrom ?: 'Start') ?> to <?= e($dateTo ?: 'Present') ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Screen Page Header & Print Button -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
    <div>
        <h3 class="mb-1">Financial Reports &amp; Printing</h3>
        <p class="text-secondary mb-0">Generate filtered loan, collection, and outstanding balance statements for auditing and printing.</p>
    </div>
    <div>
        <button type="button" onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer-fill me-1"></i>Print Current Report
        </button>
    </div>
</div>

<!-- Report Navigation Tabs -->
<ul class="nav nav-tabs mb-4 no-print">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'loans' ? 'active fw-semibold' : '' ?>"
           href="<?= e(baseUrl('reports/index.php?tab=loans')) ?>">
            <i class="bi bi-journal-text me-1"></i>1. Loan Portfolio Report
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'payments' ? 'active fw-semibold' : '' ?>"
           href="<?= e(baseUrl('reports/index.php?tab=payments')) ?>">
            <i class="bi bi-cash-coin me-1"></i>2. Payment Collection Report
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'balances' ? 'active fw-semibold' : '' ?>"
           href="<?= e(baseUrl('reports/index.php?tab=balances')) ?>">
            <i class="bi bi-exclamation-diamond me-1"></i>3. Outstanding Balance Report
        </a>
    </li>
</ul>

<!-- Filter Form -->
<div class="card mb-4 no-print">
    <div class="card-body py-3">
        <form method="GET" action="<?= e(baseUrl('reports/index.php')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">

            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search Keyword</label>
                <input
                    type="text"
                    class="form-control"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Borrower name, Loan No., or Receipt No..."
                >
            </div>

            <?php if ($tab === 'loans'): ?>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Loan Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Release From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Release To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
                </div>
            <?php elseif ($tab === 'payments'): ?>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Method</label>
                    <select name="method" class="form-select">
                        <option value="all" <?= $method === 'all' ? 'selected' : '' ?>>All Methods</option>
                        <?php foreach (['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Check'] as $m): ?>
                            <option value="<?= e($m) ?>" <?= $method === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Paid From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small text-muted mb-1">Paid To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
                </div>
            <?php else: ?>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small text-muted mb-1">Aging Filter</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Open Unpaid Loans</option>
                        <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Past-Due / Overdue Loans Only</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="<?= e(baseUrl('reports/index.php?tab=' . urlencode($tab))) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Report Data Table -->
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-table text-primary me-2"></i><?= e($reportTitle) ?> (<?= count($rows) ?> row(s))</span>
        <span class="text-muted small">All currency values in Philippine Peso (<?= e(APP_CURRENCY) ?>)</span>
    </div>

    <div class="table-responsive">
        <?php if ($tab === 'loans'): ?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Loan No.</th>
                        <th>Borrower</th>
                        <th>Release Date</th>
                        <th>Due Date</th>
                        <th>Term &amp; Rate</th>
                        <th class="text-end">Principal</th>
                        <th class="text-end">Interest</th>
                        <th class="text-end">Total Payable</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No loan records found for this report filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($r['loan_no']) ?></td>
                                <td><?= e($r['borrower_name']) ?> <span class="text-muted small">(<?= e($r['borrower_no']) ?>)</span></td>
                                <td><?= e(date('M d, Y', strtotime($r['release_date']))) ?></td>
                                <td><?= e(date('M d, Y', strtotime($r['due_date']))) ?></td>
                                <td><?= (int)$r['term_months'] ?>m @ <?= e($r['interest_rate']) ?>%</td>
                                <td class="text-end"><?= e(formatPeso($r['principal'])) ?></td>
                                <td class="text-end"><?= e(formatPeso($r['total_interest'])) ?></td>
                                <td class="text-end fw-semibold"><?= e(formatPeso($r['total_payable'])) ?></td>
                                <td class="text-end text-success"><?= e(formatPeso($r['total_paid'])) ?></td>
                                <td class="text-end fw-bold"><?= e(formatPeso($r['balance'])) ?></td>
                                <td><?= loanStatusBadge($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Active Portfolio Totals:</td>
                            <td class="text-end"><?= e(formatPeso($totals['principal'])) ?></td>
                            <td class="text-end"><?= e(formatPeso($totals['interest'])) ?></td>
                            <td class="text-end"><?= e(formatPeso($totals['total_payable'])) ?></td>
                            <td class="text-end text-success"><?= e(formatPeso($totals['total_paid'])) ?></td>
                            <td class="text-end text-primary"><?= e(formatPeso($totals['balance'])) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>

        <?php elseif ($tab === 'payments'): ?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Receipt / Ref No.</th>
                        <th>Payment Date</th>
                        <th>Loan No.</th>
                        <th>Borrower</th>
                        <th>Payment Method</th>
                        <th>Recorded By</th>
                        <th>Remarks</th>
                        <th class="text-end">Amount Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No payment transactions found for this report filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($r['reference_no']) ?></td>
                                <td><?= e(date('M d, Y', strtotime($r['payment_date']))) ?></td>
                                <td><?= e($r['loan_no']) ?></td>
                                <td><?= e($r['borrower_name']) ?> <span class="text-muted small">(<?= e($r['borrower_no']) ?>)</span></td>
                                <td><?= e($r['payment_method']) ?></td>
                                <td><?= e($r['staff_full_name'] ?: $r['staff_username']) ?></td>
                                <td class="small text-secondary"><?= e($r['remarks'] ?? '—') ?></td>
                                <td class="text-end fw-bold text-success"><?= e(formatPeso($r['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="7" class="text-end">Total Collections:</td>
                            <td class="text-end text-success fs-6"><?= e(formatPeso($totals['collected'])) ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>

        <?php else: ?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Loan No.</th>
                        <th>Borrower</th>
                        <th>Contact Phone</th>
                        <th>Maturity Due Date</th>
                        <th>Aging / Days</th>
                        <th class="text-end">Total Payable</th>
                        <th class="text-end">Total Paid</th>
                        <th class="text-end">Outstanding Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No outstanding unpaid loan balances found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $days = (int)$r['days_until_due'];
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= e($r['loan_no']) ?></td>
                                <td><?= e($r['borrower_name']) ?> <span class="text-muted small">(<?= e($r['borrower_no']) ?>)</span></td>
                                <td><?= e($r['borrower_phone']) ?></td>
                                <td><?= e(date('M d, Y', strtotime($r['due_date']))) ?></td>
                                <td class="<?= $days < 0 ? 'text-danger fw-bold' : 'text-secondary' ?>">
                                    <?php if ($days < 0): ?>
                                        <?= abs($days) ?> day(s) past due
                                    <?php elseif ($days === 0): ?>
                                        Due today
                                    <?php else: ?>
                                        <?= $days ?> day(s) remaining
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= e(formatPeso($r['total_payable'])) ?></td>
                                <td class="text-end text-success"><?= e(formatPeso($r['total_paid'])) ?></td>
                                <td class="text-end fw-bold text-primary"><?= e(formatPeso($r['balance'])) ?></td>
                                <td><?= loanStatusBadge($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Total Outstanding Portfolio:</td>
                            <td class="text-end"><?= e(formatPeso($totals['total_payable'])) ?></td>
                            <td class="text-end text-success"><?= e(formatPeso($totals['total_paid'])) ?></td>
                            <td class="text-end text-primary fs-6"><?= e(formatPeso($totals['balance'])) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
