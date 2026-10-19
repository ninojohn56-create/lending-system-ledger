-- =====================================================================
-- LENDING TRACKING SYSTEM - DATABASE SCHEMA (MySQL / MariaDB)
-- Character Set: utf8mb4 | Collation: utf8mb4_unicode_ci
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `lending_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lending_db`;

-- Drop existing tables in reverse dependency order
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `repayments`;
DROP TABLE IF EXISTS `loans`;
DROP TABLE IF EXISTS `loan_plans`;
DROP TABLE IF EXISTS `borrowers`;

-- ---------------------------------------------------------------------
-- TABLE 1: Borrowers (Clients applying for or holding loans)
-- ---------------------------------------------------------------------
CREATE TABLE `borrowers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `borrower_code` VARCHAR(20) NOT NULL UNIQUE,
    `first_name` VARCHAR(60) NOT NULL,
    `last_name` VARCHAR(60) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone` VARCHAR(25) NOT NULL,
    `address` TEXT NOT NULL,
    `monthly_income` DECIMAL(12,2) NOT NULL,
    `status` ENUM('active', 'inactive', 'blacklisted') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_borrower_code` (`borrower_code`),
    INDEX `idx_borrower_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 2: Loan Plans (Configurable interest rates, terms, penalties)
-- ---------------------------------------------------------------------
CREATE TABLE `loan_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plan_name` VARCHAR(80) NOT NULL,
    `interest_rate` DECIMAL(5,2) NOT NULL COMMENT 'Interest rate in percentage (e.g. 5.00 for 5%)',
    `tenure_months` INT NOT NULL COMMENT 'Duration in months (e.g. 6, 12, 24)',
    `penalty_rate` DECIMAL(5,2) NOT NULL DEFAULT 2.00 COMMENT 'Penalty % charged when payment is overdue',
    `description` VARCHAR(255) NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 3: Loans (Loan applications and approved accounts)
-- ---------------------------------------------------------------------
CREATE TABLE `loans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `loan_code` VARCHAR(30) NOT NULL UNIQUE,
    `borrower_id` INT NOT NULL,
    `plan_id` INT NOT NULL,
    `principal_amount` DECIMAL(12,2) NOT NULL,
    `interest_amount` DECIMAL(12,2) NOT NULL,
    `total_payable` DECIMAL(12,2) NOT NULL COMMENT 'Principal + Interest Amount',
    `total_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `remaining_balance` DECIMAL(12,2) NOT NULL,
    `monthly_installment` DECIMAL(12,2) NOT NULL,
    `release_date` DATE NULL,
    `due_date` DATE NOT NULL,
    `status` ENUM('pending', 'approved', 'disbursed', 'completed', 'defaulted', 'rejected') NOT NULL DEFAULT 'pending',
    `purpose` VARCHAR(255) NOT NULL,
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_loans_borrower` FOREIGN KEY (`borrower_id`) 
        REFERENCES `borrowers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_loans_plan` FOREIGN KEY (`plan_id`) 
        REFERENCES `loan_plans` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_loan_status` (`status`),
    INDEX `idx_loan_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 4: Repayments (Collection ledger / payment transactions)
-- ---------------------------------------------------------------------
CREATE TABLE `repayments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_reference` VARCHAR(40) NOT NULL UNIQUE,
    `loan_id` INT NOT NULL,
    `amount_paid` DECIMAL(12,2) NOT NULL,
    `penalty_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_date` DATE NOT NULL,
    `payment_method` ENUM('cash', 'bank_transfer', 'gcash', 'check', 'online') NOT NULL DEFAULT 'cash',
    `received_by` VARCHAR(60) NOT NULL DEFAULT 'Admin',
    `receipt_notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_repayments_loan` FOREIGN KEY (`loan_id`) 
        REFERENCES `loans` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_repayment_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLE 5: Audit Logs (System actions tracking)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action_type` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(30) NOT NULL,
    `entity_id` INT NOT NULL,
    `description` TEXT NOT NULL,
    `performed_by` VARCHAR(60) NOT NULL DEFAULT 'Admin',
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_action` (`action_type`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA FOR DEMO & TESTING
-- =====================================================================

INSERT INTO `borrowers` (`borrower_code`, `first_name`, `last_name`, `email`, `phone`, `address`, `monthly_income`, `status`) VALUES
('BOR-1001', 'Juan', 'Dela Cruz', 'juan.delacruz@example.com', '+63 912 345 6789', '124 Rizal St., Manila, Philippines', 45000.00, 'active'),
('BOR-1002', 'Maria', 'Santos', 'maria.santos@example.com', '+63 928 765 4321', '88 Quezon Ave., Quezon City', 62000.00, 'active'),
('BOR-1003', 'Carlos', 'Reyes', 'carlos.reyes@example.com', '+63 935 888 1234', '15 Bonifacio Drive, Taguig City', 38000.00, 'active'),
('BOR-1004', 'Elena', 'Torres', 'elena.torres@example.com', '+63 947 111 2233', '42 Mabini St., Cebu City', 28000.00, 'active');

INSERT INTO `loan_plans` (`plan_name`, `interest_rate`, `tenure_months`, `penalty_rate`, `description`, `status`) VALUES
('Express Micro Loan (3 Months)', 3.50, 3, 2.00, 'Short-term emergency cash assistance with low interest', 'active'),
('Standard Personal Loan (6 Months)', 6.00, 6, 2.50, 'Standard personal financing plan for general needs', 'active'),
('Small Business Growth (12 Months)', 10.00, 12, 3.00, 'Working capital loan for inventory and business growth', 'active'),
('Long-Term Capital (24 Months)', 18.00, 24, 3.50, 'Equipment and asset acquisition financing', 'active');

INSERT INTO `loans` (`loan_code`, `borrower_id`, `plan_id`, `principal_amount`, `interest_amount`, `total_payable`, `total_paid`, `remaining_balance`, `monthly_installment`, `release_date`, `due_date`, `status`, `purpose`, `remarks`) VALUES
('LN-2026-0001', 1, 2, 50000.00, 3000.00, 53000.00, 17666.67, 35333.33, 8833.33, '2026-08-01', '2027-02-01', 'disbursed', 'Home Renovation', 'All requirements verified'),
('LN-2026-0002', 2, 3, 100000.00, 10000.00, 110000.00, 110000.00, 0.00, 9166.67, '2025-10-01', '2026-10-01', 'completed', 'Retail Store Expansion', 'Fully paid on schedule'),
('LN-2026-0003', 3, 1, 15000.00, 525.00, 15525.00, 0.00, 15525.00, 5175.00, NULL, '2026-11-15', 'pending', 'Medical Emergency', 'Awaiting credit committee sign-off');

INSERT INTO `repayments` (`transaction_reference`, `loan_id`, `amount_paid`, `penalty_paid`, `payment_date`, `payment_method`, `received_by`, `receipt_notes`) VALUES
('TXN-20260901-001', 1, 8833.33, 0.00, '2026-09-01', 'bank_transfer', 'Admin', 'Month 1 installment paid via BDO online'),
('TXN-20261001-002', 1, 8833.34, 0.00, '2026-10-01', 'gcash', 'Admin', 'Month 2 installment paid via GCash');
