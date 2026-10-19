<?php
/**
 * Safe Delete / Archive Borrower Handler
 * File: borrowers/delete.php
 *
 * Implements System Function #7: Delete eligible borrower records with safeguards
 *
 * Safeguard Policy:
 * 1. Only accepts POST requests with a valid CSRF token.
 * 2. If the borrower has any open/unpaid loans -> Reject deletion/archiving with an error message.
 * 3. If the borrower has historical loans (e.g. paid/cancelled) -> Never permanently delete financial
 *    audit history; safely archive the borrower (is_archived = 1) instead.
 * 4. If the borrower has zero loans -> Permanently delete the borrower row from MySQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('warning', 'Invalid request method for deleting a borrower.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('danger', 'Security token mismatch. Please try again.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'Invalid borrower ID.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM borrowers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$borrower = $stmt->fetch();

if (!$borrower) {
    setFlash('danger', 'Borrower record not found.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

// Check loan counts for this borrower
$loanSummaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_loans,
        COUNT(CASE WHEN status IN ('pending', 'active', 'partially_paid', 'overdue') AND balance > 0 THEN 1 END) AS open_loans
     FROM loans
     WHERE borrower_id = :id"
);
$loanSummaryStmt->execute([':id' => $id]);
$loanSummary = $loanSummaryStmt->fetch();

$totalLoans = (int)$loanSummary['total_loans'];
$openLoans  = (int)$loanSummary['open_loans'];

// Safeguard 1: Block if borrower has any active/unpaid loan balance
if ($openLoans > 0) {
    setFlash(
        'danger',
        'Cannot delete or archive borrower "' . $borrower['full_name'] . '" because they still have '
        . $openLoans . ' active/unpaid loan(s). All balances must be settled first.'
    );
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

// Safeguard 2: If borrower has past loan history, archive instead of deleting so audit trail is preserved
if ($totalLoans > 0) {
    $archiveStmt = $pdo->prepare('UPDATE borrowers SET is_archived = 1 WHERE id = :id');
    $archiveStmt->execute([':id' => $id]);

    setFlash(
        'info',
        'Borrower "' . $borrower['full_name'] . '" (' . $borrower['borrower_no'] . ') has historical loan records '
        . 'and was safely archived to preserve financial transaction history.'
    );
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

// Safeguard 3: Borrower has zero loans -> Eligible for clean permanent deletion
$deleteStmt = $pdo->prepare('DELETE FROM borrowers WHERE id = :id');
$deleteStmt->execute([':id' => $id]);

setFlash(
    'success',
    'Borrower "' . $borrower['full_name'] . '" (' . $borrower['borrower_no'] . ') had no loan records and was permanently deleted.'
);
header('Location: ' . baseUrl('borrowers/index.php'));
exit;
