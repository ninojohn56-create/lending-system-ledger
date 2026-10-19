<?php
/**
 * QuickLend - 1-Click CSV Data Export Utility
 * Exports clean, Excel-compatible CSV files for reporting and auditing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = getDbConnection();
$type = $_GET['type'] ?? 'loans';
$filename = "QuickLend_" . ucfirst($type) . "_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
// Add UTF-8 BOM so Excel opens special characters and currency symbols cleanly
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

if ($type === 'borrowers') {
    fputcsv($output, ['Borrower Code', 'First Name', 'Last Name', 'Email', 'Phone', 'Address', 'Monthly Income', 'Status', 'Total Loans', 'Outstanding Balance', 'Registered Date']);
    $borrowers = getAllBorrowers($pdo);
    foreach ($borrowers as $b) {
        fputcsv($output, [
            $b['borrower_code'],
            $b['first_name'],
            $b['last_name'],
            $b['email'],
            $b['phone'],
            $b['address'],
            $b['monthly_income'],
            strtoupper($b['status']),
            $b['total_loans'],
            $b['total_outstanding'],
            $b['created_at']
        ]);
    }
} elseif ($type === 'payments') {
    fputcsv($output, ['Reference #', 'Payment Date', 'Loan Code', 'Borrower Code', 'Borrower Name', 'Amount Paid', 'Penalty Paid', 'Payment Method', 'Received By', 'Notes']);
    $stmt = $pdo->query("SELECT r.*, l.loan_code, b.first_name, b.last_name, b.borrower_code 
                         FROM repayments r
                         JOIN loans l ON r.loan_id = l.id
                         JOIN borrowers b ON l.borrower_id = b.id
                         ORDER BY r.payment_date DESC, r.id DESC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['transaction_reference'],
            $row['payment_date'],
            $row['loan_code'],
            $row['borrower_code'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['amount_paid'],
            $row['penalty_paid'],
            strtoupper($row['payment_method']),
            $row['received_by'],
            $row['receipt_notes'] ?? 'None'
        ]);
    }
} else {
    // Default: Loans
    fputcsv($output, ['Loan Code', 'Borrower Code', 'Borrower Name', 'Plan', 'Principal', 'Interest Rate (%)', 'Total Interest', 'Total Payable', 'Total Paid', 'Remaining Balance', 'Monthly Installment', 'Due Date', 'Status', 'Purpose']);
    $stmt = $pdo->query("SELECT l.*, b.borrower_code, b.first_name, b.last_name, p.plan_name, p.interest_rate 
                         FROM loans l
                         JOIN borrowers b ON l.borrower_id = b.id
                         JOIN loan_plans p ON l.plan_id = p.id
                         ORDER BY l.id DESC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['loan_code'],
            $row['borrower_code'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['plan_name'],
            $row['principal_amount'],
            $row['interest_rate'],
            $row['interest_amount'],
            $row['total_payable'],
            $row['total_paid'],
            $row['remaining_balance'],
            $row['monthly_installment'],
            $row['due_date'],
            strtoupper($row['status']),
            $row['purpose']
        ]);
    }
}

fclose($output);
exit;
