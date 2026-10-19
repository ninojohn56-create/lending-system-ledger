# 💳 QuickLend Tracking System

A full-stack, secure lending and loan management tracking system built with **PHP 8**, **MySQL / MariaDB**, **HTML5**, and **Bootstrap 5**.

---

## 🚀 Quick Start Guide

### 1. Configure Database Credentials
Open [`config.php`](file:///home/njj/Projects/ITC%20projectFInals/ITC%20project%20T1/config.php) and adjust your MySQL connection constants if needed (defaults to `127.0.0.1:3306`, user: `root`):

```php
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'lending_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
```

### 2. Initialize the Database & Seed Data
You can initialize the database using either **CLI** or **Web Browser**:

- **Option A (CLI):**
  ```bash
  php setup_db.php
  ```
- **Option B (MySQL Direct Import):**
  ```bash
  mysql -u root -p < schema.sql
  ```
- **Option C (Web Browser):**
  Navigate to `http://localhost:8000/setup_db.php` and click **"Initialize Database Now"**.

### 3. Launch Development Server
```bash
php -S localhost:8000
```
Then visit **[http://localhost:8000](http://localhost:8000)** in your browser.

---

## 📊 Database Schema & Relationships

The database utilizes InnoDB with strict foreign key constraints and utf8mb4 encoding:

1. **`borrowers`**: Stores borrower demographics, contact information, monthly income, and account standing (`active`, `inactive`, `blacklisted`).
2. **`loan_plans`**: Master table for loan types, interest rates, tenure months, and penalty rates.
3. **`loans`**: Loan applications and active accounts with principal, interest, total payable, remaining balance, due dates, and lifecycle statuses.
4. **`repayments`**: Immutable ledger of all payment transactions linked by foreign key to `loans(id)`.
5. **`audit_logs`**: System audit trail capturing user actions, timestamps, and IP addresses.

---

## 🛠️ The 13 Core PHP Functions

1. `registerBorrower($pdo, $data)`: Validates input, generates borrower code, inserts record, and logs audit.
2. `getAllBorrowers($pdo, $search, $status)`: Retrieves borrowers joined with live aggregate loan balances.
3. `getBorrowerById($pdo, $id)`: Retrieves a specific borrower by primary key.
4. `updateBorrower($pdo, $id, $data)`: Updates borrower details with duplicate-email prevention.
5. `deleteBorrower($pdo, $id)`: Constraint-aware borrower deletion / archiving if active loans exist.
6. `getLoanPlans($pdo)`: Fetches active loan plans and tenure specifications.
7. `applyLoan($pdo, $data)`: Computes interest, monthly installment, and registers a loan inside a DB transaction.
8. `updateLoanStatus($pdo, $id, $action, $remarks)`: Enforces state machine transitions (`approve`, `reject`, `disburse`).
9. `recordRepayment($pdo, $id, $amount, $method, $notes)`: Row-locks loan with `FOR UPDATE`, records receipt, updates balances, and auto-settles loan when balance is zero.
10. `getLoanDetails($pdo, $id)`: Complete dossier with borrower info, loan specifications, and repayments ledger.
11. `calculateOverduePenalty($pdo, $id)`: Automated delinquency detection and penalty calculation based on days past due date.
12. `getDashboardMetrics($pdo)`: Aggregates total disbursed, collections, outstanding portfolio, and active borrowers.
13. `logAudit($pdo, $action, $entity, $id, $desc)`: Records audit history for security compliance.
