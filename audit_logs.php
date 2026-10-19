<?php
/**
 * System Activity & Security Audit Trail
 * Provides a tamper-evident event log of all administrative actions, payments, and approvals.
 */

$pageTitle = "System Activity & Audit Log";
require_once __DIR__ . '/header.php';

$actionType = trim($_GET['action_type'] ?? '');
$search = trim($_GET['search'] ?? '');
$limit = min(500, max(20, (int)($_GET['limit'] ?? 100)));

$logs = getAuditLogs($pdo, $limit, $actionType, $search);

// Metrics calculation for summary cards
$totalLogsCount = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$paymentLogsCount = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'PAYMENT_RECORDED'")->fetchColumn();
$loanLogsCount = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type IN ('LOAN_APPLIED', 'STATUS_CHANGE')")->fetchColumn();
$borrowerLogsCount = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type IN ('BORROWER_CREATED', 'BORROWER_UPDATED')")->fetchColumn();
?>

<!-- Title & Action Toolbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">
            <i class="bi bi-shield-check text-primary me-2"></i>Security & Activity Audit Log
        </h3>
        <p class="text-muted mb-0">Immutable chronological record of administrative transactions, loan actions, and repayments.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="audit_logs.php" class="btn btn-outline-secondary d-flex align-items-center gap-2">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </a>
    </div>
</div>

<!-- Summary Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-primary"><i class="bi bi-journal-text"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Event Entries</span>
            <h4 class="fw-bold mt-2 mb-0"><?= number_format($totalLogsCount) ?></h4>
            <small class="text-muted mt-1">Logged system actions</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-success"><i class="bi bi-receipt"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Payment Postings</span>
            <h4 class="fw-bold text-success mt-2 mb-0"><?= number_format($paymentLogsCount) ?></h4>
            <small class="text-muted mt-1">Collections recorded</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-info"><i class="bi bi-cash-stack"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Loan Status Updates</span>
            <h4 class="fw-bold text-info mt-2 mb-0"><?= number_format($loanLogsCount) ?></h4>
            <small class="text-muted mt-1">Applications & disbursements</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-warning"><i class="bi bi-person-lines-fill"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Borrower Profile Logs</span>
            <h4 class="fw-bold text-warning mt-2 mb-0"><?= number_format($borrowerLogsCount) ?></h4>
            <small class="text-muted mt-1">Registrations & edits</small>
        </div>
    </div>
</div>

<!-- Search & Filters Toolbar -->
<div class="card border-0 shadow-sm p-3 mb-4 bg-white">
    <form method="GET" action="audit_logs.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search event description, user, or IP address..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="action_type" class="form-select">
                <option value="">All Action Categories</option>
                <option value="PAYMENT_RECORDED" <?= ($actionType === 'PAYMENT_RECORDED') ? 'selected' : '' ?>>Repayment Posted</option>
                <option value="STATUS_CHANGE" <?= ($actionType === 'STATUS_CHANGE') ? 'selected' : '' ?>>Loan Status Change</option>
                <option value="LOAN_APPLIED" <?= ($actionType === 'LOAN_APPLIED') ? 'selected' : '' ?>>New Loan Application</option>
                <option value="BORROWER_CREATED" <?= ($actionType === 'BORROWER_CREATED') ? 'selected' : '' ?>>Borrower Registered</option>
                <option value="BORROWER_UPDATED" <?= ($actionType === 'BORROWER_UPDATED') ? 'selected' : '' ?>>Borrower Modified</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="limit" class="form-select">
                <option value="50" <?= ($limit === 50) ? 'selected' : '' ?>>Last 50 Events</option>
                <option value="100" <?= ($limit === 100) ? 'selected' : '' ?>>Last 100 Events</option>
                <option value="250" <?= ($limit === 250) ? 'selected' : '' ?>>Last 250 Events</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-dark px-3">Filter</button>
            <?php if (!empty($search) || !empty($actionType)): ?>
                <a href="audit_logs.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Audit Log Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-columns-reverse me-2 text-primary"></i>System Audit Trail Events</h6>
        <span class="badge bg-light text-dark border"><?= count($logs) ?> Events Displayed</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 170px;">Timestamp</th>
                    <th>Action Category</th>
                    <th>Target Record</th>
                    <th>Event Description</th>
                    <th>Operator</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-shield-slash fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No activity events found matching the specified filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $badgeClass = 'bg-secondary';
                            switch ($log['action_type']) {
                                case 'PAYMENT_RECORDED': $badgeClass = 'bg-success'; break;
                                case 'LOAN_APPLIED':     $badgeClass = 'bg-primary'; break;
                                case 'STATUS_CHANGE':    $badgeClass = 'bg-warning text-dark'; break;
                                case 'BORROWER_CREATED': $badgeClass = 'bg-info text-dark'; break;
                                case 'BORROWER_UPDATED': $badgeClass = 'bg-secondary'; break;
                            }
                        ?>
                        <tr>
                            <td class="small text-muted font-monospace">
                                <i class="bi bi-clock me-1"></i><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?> font-monospace">
                                    <?= htmlspecialchars($log['action_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= htmlspecialchars(strtoupper($log['entity_type'])) ?> #<?= (int)$log['entity_id'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-medium text-dark"><?= htmlspecialchars($log['description']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border">
                                    <i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($log['performed_by']) ?>
                                </span>
                            </td>
                            <td class="font-monospace small text-muted">
                                <?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
