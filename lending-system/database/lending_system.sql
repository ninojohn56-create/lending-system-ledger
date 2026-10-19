-- ============================================================================
-- LENDING MANAGEMENT SYSTEM - DATABASE SCHEMA (MySQL / MariaDB InnoDB)
-- Database Name: test_lending_system
-- Currency Convention: Philippine Peso (PHP / ₱)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `test_lending_system`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `test_lending_system`;

-- Drop tables in reverse foreign-key dependency order for clean re-import
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `loans`;
DROP TABLE IF EXISTS `borrowers`;
DROP TABLE IF EXISTS `users`;

-- ============================================================================
-- 1. USERS TABLE
-- Stores authorized staff accounts who can log in and manage the system.
-- Passwords are stored exclusively as bcrypt hashes via PHP password_hash().
-- ============================================================================
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL DEFAULT 'System Administrator',
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `uq_users_username` UNIQUE (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. BORROWERS TABLE
-- Stores client profiles. A borrower must exist before a loan can be created.
-- ============================================================================
CREATE TABLE `borrowers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `borrower_no` VARCHAR(30) NOT NULL,
    `full_name` VARCHAR(120) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `address` TEXT NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Active, 1 = Archived',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `uq_borrowers_borrower_no` UNIQUE (`borrower_no`),
    INDEX `idx_borrowers_full_name` (`full_name`),
    INDEX `idx_borrowers_phone` (`phone`),
    INDEX `idx_borrowers_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. LOANS TABLE
-- Stores loan agreements linked to a borrower.
-- Calculation Rule (Flat Interest):
--   Total Interest = Principal * (Interest Rate / 100) * Term Months
--   Total Payable  = Principal + Total Interest
-- ============================================================================
CREATE TABLE `loans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_no` VARCHAR(30) NOT NULL,
    `borrower_id` INT UNSIGNED NOT NULL,
    `principal` DECIMAL(12,2) NOT NULL,
    `interest_rate` DECIMAL(5,2) NOT NULL COMMENT 'Monthly interest rate in % (e.g., 2.00 = 2% per month)',
    `interest_method` ENUM('flat') NOT NULL DEFAULT 'flat',
    `term_months` INT UNSIGNED NOT NULL COMMENT 'Loan duration in months',
    `total_interest` DECIMAL(12,2) NOT NULL COMMENT 'Principal * (interest_rate/100) * term_months',
    `total_payable` DECIMAL(12,2) NOT NULL COMMENT 'Principal + total_interest',
    `monthly_due` DECIMAL(12,2) NOT NULL COMMENT 'total_payable / term_months',
    `balance` DECIMAL(12,2) NOT NULL COMMENT 'Remaining unpaid balance',
    `release_date` DATE NOT NULL,
    `due_date` DATE NOT NULL COMMENT 'Final maturity date of the loan',
    `status` ENUM('pending', 'active', 'partially_paid', 'paid', 'overdue', 'rejected', 'cancelled') NOT NULL DEFAULT 'active',
    `remarks` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `uq_loans_loan_no` UNIQUE (`loan_no`),
    CONSTRAINT `fk_loans_borrower` FOREIGN KEY (`borrower_id`)
        REFERENCES `borrowers` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX `idx_loans_borrower_id` (`borrower_id`),
    INDEX `idx_loans_status` (`status`),
    INDEX `idx_loans_due_date` (`due_date`),
    INDEX `idx_loans_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. PAYMENTS TABLE
-- Immutable financial ledger of all loan repayments.
-- Payment history is never deleted and serves as the audit source of truth.
-- ============================================================================
CREATE TABLE `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `payment_date` DATE NOT NULL,
    `payment_method` VARCHAR(30) NOT NULL DEFAULT 'Cash',
    `reference_no` VARCHAR(50) NOT NULL,
    `recorded_by` INT UNSIGNED NOT NULL,
    `remarks` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `uq_payments_reference_no` UNIQUE (`reference_no`),
    CONSTRAINT `fk_payments_loan` FOREIGN KEY (`loan_id`)
        REFERENCES `loans` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_recorded_by` FOREIGN KEY (`recorded_by`)
        REFERENCES `users` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX `idx_payments_loan_id` (`loan_id`),
    INDEX `idx_payments_payment_date` (`payment_date`),
    INDEX `idx_payments_recorded_by` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INITIAL SEED DATA FOR TESTING & DEMONSTRATION
-- ============================================================================

-- Sample Borrowers
INSERT INTO `borrowers` (`id`, `borrower_no`, `full_name`, `phone`, `address`, `is_archived`) VALUES
(1, 'BRW-2026-001', 'Juan Dela Cruz', '0917-123-4567', '124 Rizal Avenue, Brgy. San Isidro, Makati City', 0),
(2, 'BRW-2026-002', 'Maria Clara Santos', '0918-987-6543', '88 Commonwealth Ave., Diliman, Quezon City', 0),
(3, 'BRW-2026-003', 'Roberto Reyes Jr.', '0922-555-0192', '45 Magsaysay St., Brgy. Poblacion, Pasig City', 0),
(4, 'BRW-2026-004', 'Elena Gomez', '0915-443-8821', '19 Bonifacio Global City, Taguig City', 0);

-- Sample Loans
-- Loan 1: Exact specification example (₱10,000 principal, 2% monthly flat interest, 6 months = ₱1,200 interest, ₱11,200 total payable)
-- Has 1 partial payment of ₱2,200.00 -> Remaining Balance: ₱9,000.00 (status: partially_paid)
INSERT INTO `loans` (
    `id`, `loan_no`, `borrower_id`, `principal`, `interest_rate`, `interest_method`,
    `term_months`, `total_interest`, `total_payable`, `monthly_due`, `balance`,
    `release_date`, `due_date`, `status`, `remarks`
) VALUES
(
    1, 'LN-2026-001', 1, 10000.00, 2.00, 'flat',
    6, 1200.00, 11200.00, 1866.67, 9000.00,
    '2026-09-01', '2027-03-01', 'partially_paid', 'Standard 6-month flat interest loan (Reference test case)'
),
(
    2, 'LN-2026-002', 2, 25000.00, 2.00, 'flat',
    6, 3000.00, 28000.00, 4666.67, 0.00,
    '2026-03-15', '2026-09-15', 'paid', 'Sari-sari store inventory restocking - Paid in full'
),
(
    3, 'LN-2026-003', 3, 15000.00, 2.50, 'flat',
    4, 1500.00, 16500.00, 4125.00, 16500.00,
    '2026-05-01', '2026-09-01', 'overdue', 'Tricycle repair and maintenance loan'
);
