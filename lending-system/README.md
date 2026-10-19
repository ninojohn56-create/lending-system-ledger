# LendCore Management System

A complete, beginner-friendly **Lending Management System** built with **HTML5**, **Bootstrap 5.3**, **PHP 8+ (PDO)**, and **MySQL / MariaDB (InnoDB)**.

Designed for clarity, strict financial accuracy in Philippine Pesos (`₱`), and easy explanation during a college project defense.

---

## 1. Quick Start & Installation Instructions

### Step 1: Import the MySQL Database Schema
Import `database/lending_system.sql` into your local MySQL / MariaDB server:

```bash
mysql < lending-system/database/lending_system.sql
```
*(Or if using a passworded root account: `mysql -u root -p < lending-system/database/lending_system.sql`)*

This creates the `test_lending_system` database with 4 InnoDB tables (`users`, `borrowers`, `loans`, `payments`) and sample test records.

### Step 2: Start the PHP Built-in Web Server
From inside the `lending-system` folder (or passing `-t lending-system`):

```bash
php -S localhost:8000 -t lending-system
```

### Step 3: Open in Browser & Initialize First Administrator
1. Open **[http://localhost:8000](http://localhost:8000)** in your browser.
2. Because no hardcoded production credentials are shipped in the database, `login.php` will display the **First-Time Initial Administrator Setup** screen when `users` is empty.
3. Click **"Quick Setup Default Admin (`admin` / `Admin@1234`)"** or enter your own custom username and password.

---

## 2. Project Folder Structure

```text
lending-system/
├── index.php                  # Entry router (redirects to dashboard.php or login.php)
├── login.php                  # Staff login & safe first-time admin setup (password_hash / password_verify)
├── logout.php                 # Session destruction & safe logout
├── dashboard.php              # Live MySQL statistics, recent loans, overdue monitor & recent payments
├── config/
│   └── database.php           # PDO connection, CSRF helpers, HTML escaping, flat-interest calculator
├── includes/
│   ├── header.php             # Shared HTML5 head, Bootstrap 5.3, flash message banner
│   ├── navbar.php             # Top navigation bar with logged-in staff profile
│   ├── sidebar.php            # Responsive left sidebar navigation
│   └── footer.php             # Shared page footer & Bootstrap JS bundle
├── borrowers/
│   ├── index.php              # Searchable borrower directory & expandable loan history profile
│   ├── add.php                # Register new borrower with server-side validation
│   ├── edit.php               # Update borrower info & account standing
│   └── delete.php             # Safe POST delete/archive handler with financial history protection
├── loans/
│   ├── index.php              # Loan ledger with search, status filter & portfolio totals
│   ├── add.php                # Create loan form with live & server-side flat-interest calculator
│   └── view.php               # Complete loan dossier, balance audit check, status controls & ledger
├── payments/
│   ├── index.php              # Payment ledger, date filter & printable official receipt
│   └── add.php                # Transactional payment recorder (FOR UPDATE lock + balance update)
├── reports/
│   └── index.php              # Loan Report, Payment Report & Outstanding Balance Report + Printing
├── assets/
│   └── css/
│       └── style.css          # Custom clean light theme & @media print styles
├── database/
│   └── lending_system.sql     # InnoDB schema, foreign keys, indexes & sample seed data
└── README.md                  # Setup guide, architecture explanation & Project Defense Reviewer
```

---

## 3. All 15 Required System Functions Mapped to Code

| # | System Function | Implemented In | How It Works |
|---|---|---|---|
| 1 | **User Login** | `login.php` | Authenticates staff via PDO prepared statement + `password_verify()` and regenerates session ID. |
| 2 | **User Logout** | `logout.php` | Clears `$_SESSION`, expires session cookie, destroys session, and redirects to `login.php`. |
| 3 | **Dashboard with Lending Statistics** | `dashboard.php` | Queries live MySQL counts and `SUM()` totals for borrowers, active loans, outstanding balance, and collections. |
| 4 | **Add Borrower** | `borrowers/add.php` | Validates name, phone, address, and unique `borrower_no`, then inserts into `borrowers`. |
| 5 | **View Borrower Records** | `borrowers/index.php` | Lists borrowers with loan counts/balances and includes an inline **Profile & Loan History** view (`?view_id=`). |
| 6 | **Edit Borrower Information** | `borrowers/edit.php` | Updates borrower contact info and active/archived status while checking duplicate `borrower_no`. |
| 7 | **Delete Eligible Borrower Records** | `borrowers/delete.php` | Blocks deletion if unpaid loans exist; archives borrowers with past loans; permanently deletes only if `total_loans = 0`. |
| 8 | **Search and Filter Borrowers** | `borrowers/index.php` | Filters by keyword (`LIKE` on ID, name, phone, address) and standing (`active`, `archived`, `all`). |
| 9 | **Create Loan Records** | `loans/add.php` | Requires an existing active borrower, validates terms, computes flat interest on the server, and inserts into `loans`. |
| 10 | **View and Search Loan Records** | `loans/index.php`, `loans/view.php` | Search by Loan No. or Borrower; filter by status; view full contract specification and payment ledger. |
| 11 | **Record Loan Payments** | `payments/add.php` | Records payments inside a PDO database transaction (`beginTransaction()` / `FOR UPDATE` / `commit()`). |
| 12 | **Calculate Remaining Loan Balance** | `payments/add.php`, `loans/view.php` | Audits `total_payable - SUM(payments.amount)`, deducts new payment, prevents overpayment, and updates `loans.balance`. |
| 13 | **Track Loan Statuses** | `config/database.php`, `loans/view.php`, `payments/add.php` | Tracks `pending`, `active`, `partially_paid`, `paid`, `overdue`, `rejected`, and `cancelled`. |
| 14 | **Generate Loan & Payment Reports** | `reports/index.php` | Generates 3 filterable reports: **Loan Portfolio**, **Payment Collections**, and **Outstanding Balances**. |
| 15 | **Print Reports & Receipts** | `reports/index.php`, `payments/index.php`, `loans/view.php` | Uses `@media print` in `assets/css/style.css` to hide navigation/buttons and print clean statements and official receipts. |

---

## 4. Database Relationships & Financial Integrity

### Entity Relationships (MySQL InnoDB)
1. **`borrowers (1) ──< loans (Many)`**
   - `loans.borrower_id` references `borrowers.id` with `ON DELETE RESTRICT ON UPDATE CASCADE`.
   - **Why:** A loan cannot exist without a registered borrower, and MySQL refuses to let anyone delete a borrower who has loan records.
2. **`loans (1) ──< payments (Many)`**
   - `payments.loan_id` references `loans.id` with `ON DELETE RESTRICT ON UPDATE CASCADE`.
   - **Why:** Every payment must belong to a specific loan, and payment history can never be orphaned or deleted.
3. **`users (1) ──< payments (Many)`**
   - `payments.recorded_by` references `users.id` with `ON DELETE RESTRICT ON UPDATE CASCADE`.
   - **Why:** Tracks accountability for which staff member recorded every official receipt.

### How Stored `loans.balance` Stays Consistent with `payments`
In `payments/add.php`, whenever a payment is recorded:
1. PHP starts an atomic database transaction (`$pdo->beginTransaction()`).
2. It locks the loan row using `SELECT * FROM loans WHERE id = :id FOR UPDATE`.
3. It computes the true historical paid amount directly from the `payments` table:
   ```sql
   SELECT COALESCE(SUM(amount), 0) FROM payments WHERE loan_id = :loan_id
   ```
4. It calculates `$trueCurrentBalance = $loan['total_payable'] - $alreadyPaid`.
5. After verifying `$paymentAmount <= $trueCurrentBalance`, it inserts the new row into `payments` and updates `loans.balance = $trueCurrentBalance - $paymentAmount` inside the **same transaction** before calling `$pdo->commit()`.

---

## 5. Flat Interest Formula & Reference Example

All loans use the **Flat Monthly Interest** formula defined in `calculateFlatLoan()` (`config/database.php`):

$$\text{Total Interest} = \text{Principal} \times \left(\frac{\text{Monthly Interest Rate}}{100}\right) \times \text{Term in Months}$$

$$\text{Total Payable} = \text{Principal} + \text{Total Interest}$$

$$\text{Monthly Due} = \frac{\text{Total Payable}}{\text{Term in Months}}$$

### Reference Test Case (`LN-2026-001`)
- **Principal:** `₱10,000.00`
- **Monthly Interest Rate:** `2%` (`0.02`)
- **Term:** `6 months`
- **Total Interest:** `₱10,000.00 × 0.02 × 6 = ₱1,200.00`
- **Total Scheduled Repayment:** `₱10,000.00 + ₱1,200.00 = ₱11,200.00`
- **Partial Payment #1 (`OR-2026-001`):** `₱2,200.00`
- **Remaining Balance:** `₱11,200.00 - ₱2,200.00 = ₱9,000.00` (`status = partially_paid`)

---

## 6. Verification & Test Checklist

- [x] **Authentication & Security:** Unauthenticated requests to `dashboard.php`, `borrowers/*`, `loans/*`, `payments/*`, and `reports/*` redirect to `login.php`.
- [x] **Password Hashing:** Staff passwords are hashed with `password_hash(..., PASSWORD_DEFAULT)` and verified with `password_verify()`.
- [x] **CSRF Protection:** Every `POST` form includes `csrfField()` and verifies tokens with `verifyCsrfToken()` (`hash_equals()`).
- [x] **Borrower Safeguards:** Borrowers with unpaid loans cannot be deleted; borrowers with paid historical loans are archived (`is_archived = 1`); borrowers with zero loans can be permanently deleted.
- [x] **Server-Side Loan Calculation:** `loans/add.php` computes interest and total payable on the server in PHP and rejects negative or zero principal/terms.
- [x] **Payment & Overpayment Protection:** `payments/add.php` rejects `amount <= 0`, rejects `amount > balance`, prevents duplicate `reference_no`, and marks the loan `paid` only when `balance` reaches `₱0.00`.

---

## 7. School Project Defense Reviewer (Q&A)

1. **Q: How does data travel from a form to MySQL in your system?**
   - **A:** The user fills out an HTML/Bootstrap `<form method="POST">` which includes a hidden CSRF token. When submitted, the PHP script checks `$_SERVER['REQUEST_METHOD'] === 'POST'`, validates the CSRF token, sanitizes and validates the inputs on the server, prepares a parameterized SQL query using `$pdo->prepare()`, binds the values safely with `$stmt->execute()`, and redirects with a session flash message.

2. **Q: How did you prevent SQL Injection?**
   - **A:** Every database query with user input uses **PDO Prepared Statements** (`$pdo->prepare()` with named placeholders like `:username` or `:loan_id`) and `PDO::ATTR_EMULATE_PREPARES => false`. User input is sent separately from the SQL command, so malicious SQL characters can never alter the query structure.

3. **Q: How did you prevent Cross-Site Scripting (XSS)?**
   - **A:** Every dynamic value printed into HTML is wrapped in our `e()` helper function (`htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`), which converts characters like `<`, `>`, and `"` into safe HTML entities.

4. **Q: Why did you use a database transaction (`beginTransaction()` / `commit()`) when recording a payment?**
   - **A:** Recording a payment requires two operations to succeed together: inserting a row into `payments` and updating `balance` and `status` in `loans`. Using a transaction with `SELECT ... FOR UPDATE` guarantees that if anything fails midway, `$pdo->rollBack()` undoes everything so the loan balance never becomes inconsistent with the payment history.

5. **Q: Why can't a staff member delete a borrower who has past loans?**
   - **A:** Financial systems must never destroy audit history. At the database level, `fk_loans_borrower` uses `ON DELETE RESTRICT`. In PHP (`borrowers/delete.php`), if a borrower has active unpaid loans, deletion is blocked; if they only have completed past loans, the system safely **archives** them (`is_archived = 1`) instead of deleting the row.
