<?php
/**
 * Borrowers Directory, Search, Filter & Borrower Profile / Loan History View
 * File: borrowers/index.php
 *
 * Implements:
 * - System Function #5: View borrower records (with loan history dossier)
 * - System Function #8: Search and filter borrowers
 * - Safe delete / archive trigger with confirmation
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

// Search and filter query parameters
$search = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['status'] ?? 'active'); // 'active', 'archived', 'all'
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;

$whereClauses = [];
$params       = [];

if ($filter === 'active') {
    $whereClauses[] = 'b.is_archived = 0';
} elseif ($filter === 'archived') {
    $whereClauses[] = 'b.is_archived = 1';
}

if ($search !== '') {
    $whereClauses[] = '(b.borrower_no LIKE :q1 OR b.full_name LIKE :q2 OR b.phone LIKE :q3 OR b.address LIKE :q4)';
    $likeTerm       = '%' . $search . '%';
    $params[':q1']  = $likeTerm;
    $params[':q2']  = $likeTerm;
    $params[':q3']  = $likeTerm;
    $params[':q4']  = $likeTerm;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Retrieve borrowers joined with aggregate loan counts and balances
$sql = "SELECT
            b.id,
            b.borrower_no,
            b.full_name,
            b.phone,
            b.address,
            b.is_archived,
            b.created_at,
            COUNT(l.id) AS total_loans,
            COUNT(CASE WHEN l.status IN ('active', 'partially_paid', 'overdue') AND l.balance > 0 THEN 1 END) AS open_loans,
            COALESCE(SUM(CASE WHEN l.status IN ('active', 'partially_paid', 'overdue') THEN l.balance ELSE 0 END), 0) AS total_balance
        FROM borrowers b
        LEFT JOIN loans l ON l.borrower_id = b.id
        {$whereSql}
        GROUP BY b.id, b.borrower_no, b.full_name, b.phone, b.address, b.is_archived, b.created_at
        ORDER BY b.is_archived ASC, b.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$borrowers = $stmt->fetchAll();

// Optional: Load selected borrower profile + loan history when ?view_id= is provided
$selectedBorrower = null;
$borrowerLoans    = [];
if ($viewId > 0) {
    $bStmt = $pdo->prepare('SELECT * FROM borrowers WHERE id = :id LIMIT 1');
    $bStmt->execute([':id' => $viewId]);
    $selectedBorrower = $bStmt->fetch();

    if ($selectedBorrower) {
        $lStmt = $pdo->prepare(
            'SELECT
                l.*,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = l.id), 0) AS total_paid
             FROM loans l
             WHERE l.borrower_id = :borrower_id
             ORDER BY l.created_at DESC, l.id DESC'
        );
        $lStmt->execute([':borrower_id' => $viewId]);
        $borrowerLoans = $lStmt->fetchAll();
    }
}

$pageTitle  = 'Borrowers Management';
$activeMenu = 'borrowers';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h3 class="mb-1">Borrower Records</h3>
        <p class="text-secondary mb-0">Register clients, view loan history, and manage borrower eligibility.</p>
    </div>
    <div>
        <a href="<?= e(baseUrl('borrowers/add.php')) ?>" class="btn btn-primary">
            <i class="bi bi-person-plus-fill me-1"></i>Add New Borrower
        </a>
    </div>
</div>

<?php if ($selectedBorrower): ?>
    <!-- Borrower Profile & Loan History Dossier Card -->
    <div class="card border-primary mb-4 shadow-sm">
        <div class="card-header bg-primary bg-opacity-10 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="badge bg-primary me-2"><?= e($selectedBorrower['borrower_no']) ?></span>
                <span class="fw-bold text-dark fs-5"><?= e($selectedBorrower['full_name']) ?></span>
                <?php if ((int)$selectedBorrower['is_archived'] === 1): ?>
                    <span class="badge-status badge-archived ms-2">Archived</span>
                <?php else: ?>
                    <span class="badge-status badge-active-borrower ms-2">Active Client</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ((int)$selectedBorrower['is_archived'] === 0): ?>
                    <a href="<?= e(baseUrl('loans/add.php?borrower_id=' . (int)$selectedBorrower['id'])) ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>New Loan for Client
                    </a>
                <?php endif; ?>
                <a href="<?= e(baseUrl('borrowers/edit.php?id=' . (int)$selectedBorrower['id'])) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
                <a href="<?= e(baseUrl('borrowers/index.php')) ?>" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-x-lg me-1"></i>Close Profile
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="text-muted small text-uppercase fw-semibold">Contact Number</div>
                    <div class="fw-medium"><i class="bi bi-telephone text-primary me-1"></i><?= e($selectedBorrower['phone']) ?></div>
                </div>
                <div class="col-md-5">
                    <div class="text-muted small text-uppercase fw-semibold">Residential / Business Address</div>
                    <div class="fw-medium"><i class="bi bi-geo-alt text-primary me-1"></i><?= e($selectedBorrower['address']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase fw-semibold">Registered Date</div>
                    <div class="fw-medium"><?= e(date('F j, Y', strtotime($selectedBorrower['created_at']))) ?></div>
                </div>
            </div>

            <h6 class="fw-bold border-top pt-3 mb-3">
                <i class="bi bi-journal-text text-primary me-1"></i>Borrower Loan History (<?= count($borrowerLoans) ?>)
            </h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover border">
                    <thead>
                        <tr>
                            <th>Loan No.</th>
                            <th class="text-end">Principal</th>
                            <th>Rate / Term</th>
                            <th class="text-end">Total Payable</th>
                            <th class="text-end">Total Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($borrowerLoans)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-3">This borrower has no loan history yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($borrowerLoans as $bLoan): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$bLoan['id'])) ?>" class="text-decoration-none">
                                            <?= e($bLoan['loan_no']) ?>
                                        </a>
                                    </td>
                                    <td class="text-end"><?= e(formatPeso($bLoan['principal'])) ?></td>
                                    <td><?= e($bLoan['interest_rate']) ?>%/mo &times; <?= (int)$bLoan['term_months'] ?> mos</td>
                                    <td class="text-end"><?= e(formatPeso($bLoan['total_payable'])) ?></td>
                                    <td class="text-end text-success"><?= e(formatPeso($bLoan['total_paid'])) ?></td>
                                    <td class="text-end fw-bold"><?= e(formatPeso($bLoan['balance'])) ?></td>
                                    <td><?= e(date('M d, Y', strtotime($bLoan['due_date']))) ?></td>
                                    <td><?= loanStatusBadge($bLoan['status']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= e(baseUrl('loans/view.php?id=' . (int)$bLoan['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                            Details
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
<?php endif; ?>

<!-- Search & Filter Bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" action="<?= e(baseUrl('borrowers/index.php')) ?>" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        class="form-control"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Search by Borrower No., Full Name, Phone, or Address..."
                    >
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <select name="status" class="form-select">
                    <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active Borrowers Only</option>
                    <option value="archived" <?= $filter === 'archived' ? 'selected' : '' ?>>Archived Borrowers</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Borrowers</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <?php if ($search !== '' || $filter !== 'active'): ?>
                    <a href="<?= e(baseUrl('borrowers/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Borrowers Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people text-primary me-2"></i>Registered Borrowers (<?= count($borrowers) ?>)</span>
        <span class="text-muted small">Click <strong>Profile</strong> to view a client's loan history</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Borrower No.</th>
                    <th>Full Name</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th class="text-center">Loans</th>
                    <th class="text-end">Outstanding Balance</th>
                    <th>Standing</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($borrowers)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No borrower records matched your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($borrowers as $borrower): ?>
                        <?php
                        $totalLoans = (int)$borrower['total_loans'];
                        $openLoans  = (int)$borrower['open_loans'];
                        $isArchived = (int)$borrower['is_archived'] === 1;
                        ?>
                        <tr>
                            <td class="fw-semibold">
                                <a href="<?= e(baseUrl('borrowers/index.php?view_id=' . (int)$borrower['id'] . '&status=' . urlencode($filter) . '&q=' . urlencode($search))) ?>" class="text-decoration-none">
                                    <?= e($borrower['borrower_no']) ?>
                                </a>
                            </td>
                            <td class="fw-medium"><?= e($borrower['full_name']) ?></td>
                            <td><?= e($borrower['phone']) ?></td>
                            <td class="text-secondary small" style="max-width: 260px;"><?= e($borrower['address']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">
                                    <?= $totalLoans ?> total (<?= $openLoans ?> open)
                                </span>
                            </td>
                            <td class="text-end fw-semibold <?= (float)$borrower['total_balance'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                <?= e(formatPeso($borrower['total_balance'])) ?>
                            </td>
                            <td>
                                <?php if ($isArchived): ?>
                                    <span class="badge-status badge-archived">Archived</span>
                                <?php else: ?>
                                    <span class="badge-status badge-active-borrower">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a href="<?= e(baseUrl('borrowers/index.php?view_id=' . (int)$borrower['id'] . '&status=' . urlencode($filter) . '&q=' . urlencode($search))) ?>"
                                       class="btn btn-sm btn-outline-primary" title="View Borrower Profile & Loan History">
                                        <i class="bi bi-eye"></i> Profile
                                    </a>
                                    <a href="<?= e(baseUrl('borrowers/edit.php?id=' . (int)$borrower['id'])) ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Edit Borrower">
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <?php if (!$isArchived): ?>
                                        <?php if ($openLoans > 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary opacity-50" disabled
                                                    title="Cannot delete or archive borrower with an active unpaid loan">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <?php
                                            $confirmMsg = $totalLoans > 0
                                                ? 'This borrower has past loan history. To preserve financial audit records, this client will be safely ARCHIVED instead of permanently deleted. Proceed?'
                                                : 'Permanently delete borrower ' . $borrower['full_name'] . ' (' . $borrower['borrower_no'] . ')? This client has no loan records.';
                                            ?>
                                            <form method="POST" action="<?= e(baseUrl('borrowers/delete.php')) ?>" class="d-inline"
                                                  onsubmit="return confirm(<?= e(json_encode($confirmMsg)) ?>);">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= (int)$borrower['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        title="<?= $totalLoans > 0 ? 'Archive Borrower (Has Past Loans)' : 'Delete Borrower' ?>">
                                                    <i class="bi <?= $totalLoans > 0 ? 'bi-archive' : 'bi-trash' ?>"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
