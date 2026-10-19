<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');

$sql = "SELECT r.*, l.loan_code, b.id AS borrower_id, b.first_name, b.last_name, b.borrower_code 
        FROM repayments r
        INNER JOIN loans l ON r.loan_id = l.id
        INNER JOIN borrowers b ON l.borrower_id = b.id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (r.transaction_reference LIKE :search OR l.loan_code LIKE :search OR b.first_name LIKE :search OR b.last_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($method)) {
    $sql .= " AND r.payment_method = :method";
    $params[':method'] = $method;
}

$sql .= " ORDER BY r.payment_date DESC, r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Aggregation summary
$totalCollected = array_reduce($payments, fn($acc, $item) => $acc + (float)$item['amount_paid'], 0.0);
$totalPenalties = array_reduce($payments, fn($acc, $item) => $acc + (float)$item['penalty_paid'], 0.0);

require_once __DIR__ . '/header.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h3 fw-bold mb-1">Repayments & Collections Ledger</h2>
        <p class="text-muted mb-0">Complete historical audit trail of all loan payments received.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=payments" class="btn btn-outline-success d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-file-earmark-excel"></i>
            <span>Export CSV</span>
        </a>
    </div>
</div>

<!-- Financial Summary Strip -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white border-start border-4 border-success">
            <span class="small text-muted fw-bold text-uppercase">Total Collections Listed</span>
            <h3 class="fw-bold my-1 text-success"><?= formatCurrency($totalCollected) ?></h3>
            <span class="small text-muted">Across <?= count($payments) ?> individual transactions</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white border-start border-4 border-warning">
            <span class="small text-muted fw-bold text-uppercase">Total Penalties Paid</span>
            <h3 class="fw-bold my-1 text-warning"><?= formatCurrency($totalPenalties) ?></h3>
            <span class="small text-muted">Late payment fees recovered</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white border-start border-4 border-primary">
            <span class="small text-muted fw-bold text-uppercase">Net Receipts</span>
            <h3 class="fw-bold my-1 text-primary"><?= formatCurrency($totalCollected + $totalPenalties) ?></h3>
            <span class="small text-muted">Gross revenue remitted</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4 bg-white">
    <form method="GET" action="payments.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search reference, loan code, borrower name..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-4">
            <select name="method" class="form-select">
                <option value="">All Payment Channels</option>
                <option value="cash" <?= ($method === 'cash') ? 'selected' : '' ?>>Cash</option>
                <option value="bank_transfer" <?= ($method === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="gcash" <?= ($method === 'gcash') ? 'selected' : '' ?>>GCash / E-Wallet</option>
                <option value="check" <?= ($method === 'check') ? 'selected' : '' ?>>Check</option>
                <option value="online" <?= ($method === 'online') ? 'selected' : '' ?>>Online Payment</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-dark px-3">Filter</button>
            <?php if (!empty($search) || !empty($method)): ?>
                <a href="payments.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Payments Ledger Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Ref #</th>
                    <th>Date</th>
                    <th>Loan Code</th>
                    <th>Borrower</th>
                    <th>Amount Paid</th>
                    <th>Penalty</th>
                    <th>Payment Method</th>
                    <th>Notes</th>
                    <th class="text-end">Account</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No payment records found matching your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark font-monospace"><?= htmlspecialchars($p['transaction_reference']) ?></span>
                            </td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td>
                                <span class="fw-semibold text-primary"><?= htmlspecialchars($p['loan_code']) ?></span>
                            </td>
                            <td>
                                <a href="borrower_view.php?id=<?= $p['borrower_id'] ?>" class="text-decoration-none">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></div>
                                </a>
                                <small class="text-muted"><?= htmlspecialchars($p['borrower_code']) ?></small>
                            </td>
                            <td class="fw-bold text-success"><?= formatCurrency($p['amount_paid']) ?></td>
                            <td class="text-muted"><?= ($p['penalty_paid'] > 0) ? formatCurrency($p['penalty_paid']) : '-' ?></td>
                            <td>
                                <span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($p['payment_method']) ?></span>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($p['receipt_notes'] ?? 'None') ?></td>
                            <td class="text-end">
                                <a href="loan_view.php?id=<?= $p['loan_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Loan Dossier">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
