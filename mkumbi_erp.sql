-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Dec 29, 2025 at 11:50 AM
-- Server version: 10.6.24-MariaDB-cll-lve
-- PHP Version: 8.4.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mkumbi_erp`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`mkumbi`@`localhost` PROCEDURE `sp_get_pending_transfers` (IN `p_company_id` INT)   BEGIN
    SELECT 
        pt.*,
        p.plot_number,
        p.block_number,
        pr.project_name,
        u.full_name as initiated_by_name
    FROM plot_transfers pt
    LEFT JOIN plots p ON pt.plot_id = p.plot_id
    LEFT JOIN projects pr ON pt.project_id = pr.project_id
    LEFT JOIN users u ON pt.initiated_by = u.user_id
    WHERE pt.company_id = p_company_id 
      AND pt.approval_status = 'pending'
    ORDER BY pt.created_at DESC;
END$$

CREATE DEFINER=`mkumbi`@`localhost` PROCEDURE `sp_get_plot_movements` (IN `p_company_id` INT, IN `p_plot_id` INT, IN `p_limit` INT)   BEGIN
    SELECT 
        pm.*,
        u1.full_name as initiated_by_name,
        u2.full_name as approved_by_name
    FROM plot_movements pm
    LEFT JOIN users u1 ON pm.initiated_by = u1.user_id
    LEFT JOIN users u2 ON pm.approved_by = u2.user_id
    WHERE pm.company_id = p_company_id 
      AND (p_plot_id IS NULL OR pm.plot_id = p_plot_id)
    ORDER BY pm.movement_date DESC
    LIMIT p_limit;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `approval_actions`
--

CREATE TABLE `approval_actions` (
  `approval_action_id` int(11) NOT NULL,
  `approval_request_id` int(11) NOT NULL,
  `approval_level_id` int(11) NOT NULL,
  `action` enum('approved','rejected','returned','cancelled') NOT NULL,
  `action_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `acted_by` int(11) NOT NULL,
  `comments` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval actions log';

-- --------------------------------------------------------

--
-- Table structure for table `approval_levels`
--

CREATE TABLE `approval_levels` (
  `approval_level_id` int(11) NOT NULL,
  `workflow_id` int(11) NOT NULL,
  `level_number` int(11) NOT NULL,
  `level_name` varchar(100) NOT NULL,
  `approver_type` enum('role','user','any_manager') NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `can_skip` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval workflow levels';

-- --------------------------------------------------------

--
-- Table structure for table `approval_requests`
--

CREATE TABLE `approval_requests` (
  `approval_request_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `workflow_id` int(11) NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `reference_type` varchar(50) NOT NULL COMMENT 'payment, purchase_order, etc',
  `reference_id` int(11) NOT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `current_level` int(11) DEFAULT 1,
  `overall_status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval requests tracking';

-- --------------------------------------------------------

--
-- Table structure for table `approval_workflows`
--

CREATE TABLE `approval_workflows` (
  `workflow_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `workflow_name` varchar(200) NOT NULL,
  `workflow_code` varchar(50) NOT NULL,
  `module_name` varchar(100) NOT NULL,
  `applies_to` enum('payment','purchase_order','refund','contract','service_request','budget','expense','all') DEFAULT NULL,
  `min_amount` decimal(15,2) DEFAULT 0.00,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `auto_approve_below` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval workflow definitions';

-- --------------------------------------------------------

--
-- Table structure for table `asset_categories`
--

CREATE TABLE `asset_categories` (
  `category_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `depreciation_account_code` varchar(20) NOT NULL,
  `depreciation_method` enum('straight_line','declining_balance','units_of_production') DEFAULT 'straight_line',
  `useful_life_years` int(11) DEFAULT 5,
  `salvage_value_percentage` decimal(5,2) DEFAULT 10.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `asset_categories`
--

INSERT INTO `asset_categories` (`category_id`, `company_id`, `category_name`, `account_code`, `depreciation_account_code`, `depreciation_method`, `useful_life_years`, `salvage_value_percentage`, `description`, `created_at`) VALUES
(1, 3, 'Computer Equipment', '1510', '6210', 'straight_line', 3, 10.00, 'Computers, laptops, servers, and related equipment', '2025-12-25 18:43:49'),
(2, 3, 'Office Furniture', '1520', '6220', 'straight_line', 7, 10.00, 'Desks, chairs, cabinets, and office furniture', '2025-12-25 18:43:49'),
(3, 3, 'Vehicles', '1530', '6230', 'declining_balance', 5, 20.00, 'Company vehicles and transportation equipment', '2025-12-25 18:43:49'),
(4, 3, 'Office Equipment', '1540', '6240', 'straight_line', 5, 10.00, 'Printers, photocopiers, scanners, and office machines', '2025-12-25 18:43:49'),
(5, 3, 'Machinery & Equipment', '1550', '6250', 'straight_line', 10, 15.00, 'Manufacturing and production machinery', '2025-12-25 18:43:49'),
(6, 3, 'Building & Improvements', '1560', '6260', 'straight_line', 20, 5.00, 'Buildings, structures, and leasehold improvements', '2025-12-25 18:43:49'),
(7, 3, 'Tools & Equipment', '1570', '6270', 'straight_line', 4, 10.00, 'Hand tools, power tools, and equipment', '2025-12-25 18:43:49'),
(8, 3, 'Communication Equipment', '1580', '6280', 'straight_line', 3, 10.00, 'Phones, radios, communication devices', '2025-12-25 18:43:49'),
(9, 3, 'Land', '1590', '6290', 'straight_line', 999, 0.00, 'Land and property (non-depreciating)', '2025-12-25 18:43:49'),
(10, 3, 'Software & Licenses', '1595', '6295', 'straight_line', 3, 0.00, 'Software licenses and digital assets', '2025-12-25 18:43:49');

-- --------------------------------------------------------

--
-- Table structure for table `asset_depreciation`
--

CREATE TABLE `asset_depreciation` (
  `depreciation_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `period_date` date NOT NULL,
  `depreciation_amount` decimal(15,2) NOT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL,
  `book_value` decimal(15,2) NOT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_maintenance`
--

CREATE TABLE `asset_maintenance` (
  `maintenance_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `maintenance_type` enum('preventive','corrective','upgrade','inspection') NOT NULL,
  `maintenance_date` date NOT NULL,
  `description` text NOT NULL,
  `cost` decimal(15,2) DEFAULT 0.00,
  `vendor_name` varchar(200) DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `performed_by` varchar(200) DEFAULT NULL,
  `downtime_hours` decimal(5,2) DEFAULT 0.00,
  `status` enum('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `total_hours` decimal(5,2) DEFAULT NULL,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `status` enum('present','absent','late','leave','holiday') DEFAULT 'present',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` bigint(20) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL COMMENT 'create, update, delete, view, login, logout',
  `module_name` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System audit trail';

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `bank_account_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_category` enum('bank','mobile_money') DEFAULT 'bank',
  `account_name` varchar(200) NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) NOT NULL,
  `mobile_provider` varchar(100) DEFAULT NULL,
  `mobile_number` varchar(50) DEFAULT NULL,
  `mobile_account_name` varchar(255) DEFAULT NULL,
  `branch_name` varchar(200) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `swift_code` varchar(50) DEFAULT NULL,
  `account_type` enum('checking','savings','business','escrow') DEFAULT 'business',
  `currency` varchar(10) DEFAULT 'TSH',
  `currency_code` varchar(3) DEFAULT 'TZS',
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `gl_account_id` int(11) DEFAULT NULL COMMENT 'Link to chart of accounts',
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive','closed') NOT NULL DEFAULT 'active' COMMENT 'Account status'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company bank accounts';

--
-- Dumping data for table `bank_accounts`
--

INSERT INTO `bank_accounts` (`bank_account_id`, `company_id`, `account_category`, `account_name`, `account_number`, `bank_name`, `mobile_provider`, `mobile_number`, `mobile_account_name`, `branch_name`, `bank_branch`, `swift_code`, `account_type`, `currency`, `currency_code`, `opening_balance`, `current_balance`, `gl_account_id`, `is_active`, `is_default`, `created_at`, `updated_at`, `created_by`, `status`) VALUES
(6, 3, 'bank', 'MKUMBI INVESTMENT COMPANY LIMITED', '015C588434900', 'CRDB Bank', '', '', '', 'TAZARA BRANCH', NULL, '', 'savings', 'TSH', 'TZS', 10000000.00, 10000000.00, NULL, 1, 0, '2025-12-18 08:32:48', '2025-12-18 08:32:48', 9, 'active'),
(7, 3, 'bank', 'MKUMBI INVESTMENT COMPANY LIMITED', '015C588434901', 'CRDB Bank', '', '', '', 'TAZARA BRANCH', NULL, '', 'savings', 'TSH', 'TZS', 100000.00, 1613400.00, NULL, 1, 0, '2025-12-18 08:39:17', '2025-12-29 06:14:55', 9, 'active'),
(8, 3, 'mobile_money', 'MKUMBI INVESTMENT COMPANY LIMITED', '', '', 'Tigo Pesa', '0677220082', 'MKUMBI COMPANY', '', NULL, '', 'business', 'TSH', 'TZS', 0.00, 0.00, NULL, 1, 0, '2025-12-18 09:15:05', '2025-12-18 09:15:05', 9, 'active'),
(9, 3, 'mobile_money', 'MKUMBI INVESTMENT COMPANY LIMITED', '', '', 'Tigo Pesa', '18667310', 'TIGO LIPA', '', NULL, '', 'business', 'TSH', 'TZS', 0.00, 0.00, NULL, 1, 0, '2025-12-18 09:17:26', '2025-12-18 09:17:26', 9, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `bank_statements`
--

CREATE TABLE `bank_statements` (
  `statement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `statement_number` varchar(50) DEFAULT NULL,
  `statement_date` date NOT NULL,
  `statement_period_start` date NOT NULL,
  `statement_period_end` date NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL,
  `closing_balance` decimal(15,2) NOT NULL,
  `total_credits` decimal(15,2) DEFAULT 0.00,
  `total_debits` decimal(15,2) DEFAULT 0.00,
  `statement_file_path` varchar(255) DEFAULT NULL,
  `is_reconciled` tinyint(1) DEFAULT 0,
  `reconciliation_date` date DEFAULT NULL,
  `reconciled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank statements';

-- --------------------------------------------------------

--
-- Table structure for table `bank_transactions`
--

CREATE TABLE `bank_transactions` (
  `bank_transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `statement_id` int(11) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `value_date` date DEFAULT NULL,
  `transaction_type` enum('debit','credit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `running_balance` decimal(15,2) DEFAULT NULL,
  `is_reconciled` tinyint(1) DEFAULT 0,
  `reconciled_source_type` varchar(50) DEFAULT NULL,
  `reconciled_source_id` int(11) DEFAULT NULL,
  `reconciled_with_payment_id` int(11) DEFAULT NULL,
  `reconciliation_date` date DEFAULT NULL,
  `reconciliation_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank transaction entries';

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `budget_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `budget_name` varchar(200) NOT NULL,
  `budget_type` enum('annual','operational','project','department','special') DEFAULT 'annual',
  `budget_year` int(11) DEFAULT year(curdate()),
  `budget_period` enum('monthly','quarterly','annual') DEFAULT 'annual',
  `purpose` text DEFAULT NULL,
  `fiscal_year` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','approved','active','closed') DEFAULT 'draft',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_lines`
--

CREATE TABLE `budget_lines` (
  `budget_line_id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `budgeted_amount` decimal(15,2) NOT NULL,
  `actual_amount` decimal(15,2) DEFAULT 0.00,
  `variance` decimal(15,2) GENERATED ALWAYS AS (`budgeted_amount` - `actual_amount`) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_revisions`
--

CREATE TABLE `budget_revisions` (
  `revision_id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `revision_number` int(11) NOT NULL,
  `revision_date` date NOT NULL,
  `revised_amount` decimal(15,2) NOT NULL,
  `reason` text NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Budget revisions and amendments';

-- --------------------------------------------------------

--
-- Table structure for table `campaigns`
--

CREATE TABLE `campaigns` (
  `campaign_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `campaign_code` varchar(50) NOT NULL COMMENT 'Auto-generated: CMP-YYYY-XXXX',
  `campaign_name` varchar(200) NOT NULL,
  `campaign_type` enum('email','social_media','ppc','event','content','sms','print','radio','tv','other') NOT NULL DEFAULT 'email',
  `description` text DEFAULT NULL,
  `target_audience` varchar(500) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT 0.00,
  `actual_spent` decimal(15,2) DEFAULT 0.00,
  `actual_cost` decimal(15,2) DEFAULT 0.00,
  `target_leads` int(11) DEFAULT NULL,
  `actual_leads` int(11) DEFAULT 0,
  `target_conversions` int(11) DEFAULT NULL,
  `actual_conversions` int(11) DEFAULT 0,
  `status` enum('draft','active','paused','completed','cancelled') DEFAULT 'draft',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Marketing campaigns tracking';

-- --------------------------------------------------------

--
-- Table structure for table `cash_transactions`
--

CREATE TABLE `cash_transactions` (
  `cash_transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `transaction_number` varchar(50) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_type` enum('receipt','payment','transfer','adjustment') NOT NULL,
  `received_by` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `account_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(200) NOT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `account_category` varchar(100) DEFAULT NULL,
  `parent_account_id` int(11) DEFAULT NULL COMMENT 'For sub-accounts',
  `account_level` int(11) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `is_control_account` tinyint(1) DEFAULT 0,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chart of accounts';

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`account_id`, `company_id`, `account_code`, `account_name`, `account_type`, `account_category`, `parent_account_id`, `account_level`, `is_active`, `is_control_account`, `opening_balance`, `current_balance`, `created_at`, `updated_at`, `created_by`) VALUES
(212, 3, '1000', 'ASSETS', 'asset', 'All Company Assets', NULL, 1, 1, 1, 0.00, 1096383000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(213, 3, '2000', 'LIABILITIES', 'liability', 'All Company Liabilities', NULL, 1, 1, 1, 0.00, 813400.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(214, 3, '3000', 'EQUITY', 'equity', 'Owner\'s Equity', NULL, 1, 1, 1, 0.00, -442683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(215, 3, '4000', 'REVENUE', 'revenue', 'All Revenue Sources', NULL, 1, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(216, 3, '5000', 'EXPENSES', 'expense', 'All Company Expenses', NULL, 1, 1, 1, 0.00, 442683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(217, 3, '1100', 'Current Assets', 'asset', 'Short-term assets', 212, 2, 1, 1, 0.00, 38760000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(218, 3, '1200', 'Fixed Assets', 'asset', 'Long-term assets', 212, 2, 1, 1, 0.00, 482683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(219, 3, '1300', 'Development Properties', 'asset', 'Land projects', 212, 2, 1, 1, 0.00, 574940000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(220, 3, '2100', 'Current Liabilities', 'liability', 'Short-term obligations', 213, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(221, 3, '2200', 'Long-term Liabilities', 'liability', 'Long-term debts', 213, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(222, 3, '2300', 'Customer Deposits', 'liability', 'Advance payments', 213, 2, 1, 1, 0.00, 813400.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(223, 3, '3100', 'Owner\'s Equity', 'equity', 'Capital and earnings', 214, 2, 1, 1, 0.00, -442683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(224, 3, '4100', 'Sales Revenue', 'revenue', 'Sales income', 215, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(225, 3, '4200', 'Other Income', 'revenue', 'Other income', 215, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(226, 3, '5100', 'Cost of Sales', 'expense', 'Direct costs', 216, 2, 1, 1, 0.00, 442683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(227, 3, '6000', 'Operating Expenses', 'expense', 'Operating costs', 216, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(228, 3, '7000', 'Procurement Expenses', 'expense', 'Purchases', 216, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(229, 3, '8000', 'Financial Expenses', 'expense', 'Finance costs', 216, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(230, 3, '9000', 'Tax Expenses', 'expense', 'Tax costs', 216, 2, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(231, 3, '1110', 'Cash and Cash Equivalents', 'asset', 'Cash resources', 217, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(232, 3, '1120', 'Bank Accounts', 'asset', 'Bank balances', 217, 3, 1, 0, 0.00, 10913400.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(233, 3, '1130', 'Accounts Receivable', 'asset', 'Money owed', 217, 3, 1, 1, 0.00, 27846600.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(234, 3, '1140', 'Inventory', 'asset', 'Stock', 217, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(235, 3, '1210', 'Land and Buildings', 'asset', 'Property', 218, 3, 1, 0, 0.00, 482683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(236, 3, '1220', 'Vehicles', 'asset', 'Vehicles', 218, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(237, 3, '1230', 'Equipment', 'asset', 'Equipment', 218, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(238, 3, '1250', 'Accumulated Depreciation', 'asset', 'Depreciation', 218, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(239, 3, '1310', 'Land Under Development', 'asset', 'Project land', 219, 3, 1, 0, 0.00, 482683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(240, 3, '1320', 'Development Costs', 'asset', 'Infrastructure', 219, 3, 1, 0, 0.00, 92257000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(241, 3, '1330', 'Project Costs', 'asset', 'Project expenses', 219, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(242, 3, '2110', 'Accounts Payable', 'liability', 'Supplier debts', 220, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(243, 3, '2120', 'Tax Payable', 'liability', 'Tax due', 220, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(244, 3, '2130', 'Payroll Liabilities', 'liability', 'Employee pay', 220, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(245, 3, '2140', 'Commission Payable', 'liability', 'Commissions due', 220, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(246, 3, '2210', 'Bank Loans', 'liability', 'Loans', 221, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(247, 3, '2310', 'Plot Deposits', 'liability', 'Plot down payments', 222, 3, 1, 0, 0.00, 813400.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(248, 3, '3110', 'Share Capital', 'equity', 'Capital', 223, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(249, 3, '3120', 'Retained Earnings', 'equity', 'Profits', 223, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(250, 3, '3130', 'Current Year Earnings', 'equity', 'Current profit', 223, 3, 1, 0, 0.00, -442683000.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(251, 3, '4110', 'Plot Sales', 'revenue', 'Plot sales', 224, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(252, 3, '4120', 'Service Revenue', 'revenue', 'Services', 224, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(253, 3, '4210', 'Interest Income', 'revenue', 'Interest', 225, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(254, 3, '5110', 'Land Costs', 'expense', 'Land purchase', 226, 3, 1, 0, 0.00, 442683000.00, '2025-12-24 01:47:01', '2025-12-24 02:13:16', 9),
(255, 3, '5120', 'Development Costs', 'expense', 'Development', 226, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(256, 3, '5130', 'Commission Expenses', 'expense', 'Commissions', 226, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(257, 3, '5140', 'Refunds', 'expense', 'Refunds', 226, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(258, 3, '6100', 'Admin Expenses', 'expense', 'Admin', 227, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(259, 3, '6200', 'Marketing', 'expense', 'Marketing', 227, 3, 1, 1, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(260, 3, '6300', 'Professional Fees', 'expense', 'Services', 227, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(261, 3, '1111', 'Cash on Hand', 'asset', 'Physical cash', 231, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(262, 3, '1112', 'Petty Cash', 'asset', 'Petty cash', 231, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(263, 3, '1131', 'Trade Debtors', 'asset', 'Trade debts', 233, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(264, 3, '1132', 'Plot Sales Receivable', 'asset', 'Plot payments due', 233, 4, 1, 0, 0.00, 27846600.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(265, 3, '2111', 'Trade Creditors', 'liability', 'Suppliers', 242, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(266, 3, '2121', 'VAT Payable', 'liability', 'VAT', 243, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(267, 3, '2122', 'Income Tax Payable', 'liability', 'Income tax', 243, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(268, 3, '2123', 'WHT Payable', 'liability', 'WHT', 243, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(269, 3, '2131', 'Salaries Payable', 'liability', 'Salaries', 244, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(270, 3, '2132', 'NSSF Payable', 'liability', 'NSSF', 244, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(271, 3, '2133', 'PAYE Payable', 'liability', 'PAYE', 244, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(272, 3, '4111', 'Down Payments', 'revenue', 'Down payments', 251, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(273, 3, '4112', 'Installments', 'revenue', 'Installments', 251, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 02:13:16', 9),
(274, 3, '4113', 'Full Payments', 'revenue', 'Full payments', 251, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 02:13:16', 9),
(275, 3, '5121', 'Survey Costs', 'expense', 'Survey', 255, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(276, 3, '5122', 'Legal Fees', 'expense', 'Legal', 255, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(277, 3, '5123', 'Infrastructure', 'expense', 'Infrastructure', 255, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(278, 3, '6110', 'Salaries', 'expense', 'Salaries', 258, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(279, 3, '6120', 'Rent', 'expense', 'Rent', 258, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(280, 3, '6130', 'Utilities', 'expense', 'Utilities', 258, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(281, 3, '6210', 'Advertising', 'expense', 'Ads', 259, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(282, 3, '6220', 'Campaigns', 'expense', 'Campaigns', 259, 4, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(283, 3, '7100', 'Purchase Orders', 'expense', 'Purchases', 228, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-25 19:32:22', 9),
(284, 3, '8100', 'Interest Expense', 'expense', 'Interest', 229, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:47:01', 9),
(285, 3, '9100', 'Corporate Tax', 'expense', 'Tax', 230, 3, 1, 0, 0.00, 0.00, '2025-12-24 01:47:01', '2025-12-24 01:55:44', 9);

-- --------------------------------------------------------

--
-- Table structure for table `cheque_transactions`
--

CREATE TABLE `cheque_transactions` (
  `cheque_transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `cheque_number` varchar(50) NOT NULL,
  `cheque_date` date NOT NULL,
  `bank_name` varchar(255) NOT NULL,
  `branch_name` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payee_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','cleared','bounced','cancelled') DEFAULT 'pending',
  `cleared_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commissions`
--

CREATE TABLE `commissions` (
  `commission_id` int(11) NOT NULL,
  `commission_number` varchar(50) DEFAULT NULL,
  `commission_date` date NOT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `plot_size_sqm` decimal(10,2) DEFAULT NULL,
  `recipient_type` enum('user','external','consultant') NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'If recipient is a system user',
  `recipient_name` varchar(200) NOT NULL,
  `recipient_phone` varchar(50) DEFAULT NULL,
  `commission_type` enum('sales','referral','consultant','marketing','other') DEFAULT 'sales',
  `commission_percentage` decimal(5,2) DEFAULT NULL,
  `withholding_tax_rate` decimal(5,2) DEFAULT 5.00,
  `withholding_tax_amount` decimal(15,2) DEFAULT 0.00,
  `entitled_amount` decimal(15,2) DEFAULT 0.00,
  `commission_amount` decimal(15,2) NOT NULL,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `payment_schedule` text DEFAULT NULL,
  `total_paid` decimal(15,2) DEFAULT 0.00,
  `balance` decimal(15,2) DEFAULT 0.00,
  `paid_date` date DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `commission_tier` varchar(50) DEFAULT NULL COMMENT 'Bronze, Silver, Gold, etc',
  `base_commission_rate` decimal(5,2) DEFAULT NULL,
  `bonus_commission_rate` decimal(5,2) DEFAULT 0.00,
  `total_commission_rate` decimal(5,2) GENERATED ALWAYS AS (`base_commission_rate` + `bonus_commission_rate`) STORED,
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money') DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_account_number` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Commission tracking for sales and referrals';

--
-- Dumping data for table `commissions`
--

INSERT INTO `commissions` (`commission_id`, `commission_number`, `commission_date`, `company_id`, `reservation_id`, `plot_size_sqm`, `recipient_type`, `user_id`, `recipient_name`, `recipient_phone`, `commission_type`, `commission_percentage`, `withholding_tax_rate`, `withholding_tax_amount`, `entitled_amount`, `commission_amount`, `base_amount`, `payment_status`, `payment_schedule`, `total_paid`, `balance`, `paid_date`, `payment_reference`, `remarks`, `created_at`, `updated_at`, `created_by`, `submitted_by`, `submitted_at`, `commission_tier`, `base_commission_rate`, `bonus_commission_rate`, `payment_method`, `bank_account_id`, `notes`, `payment_account_number`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `paid_by`, `paid_at`, `rejection_reason`) VALUES
(6, 'COM-2025-00001', '2025-12-23', 3, 16, 0.00, 'user', 12, 'Hamisi Ismail Khalfani', '+255 786 133 399', 'sales', 2.50, 5.00, 9400.00, 178600.00, 188000.00, 7520000.00, 'paid', NULL, 0.00, 178600.00, NULL, NULL, NULL, '2025-12-23 06:31:09', '2025-12-23 06:31:41', 9, 9, '2025-12-23 12:01:09', NULL, NULL, 0.00, NULL, NULL, '', NULL, 9, '2025-12-23 12:01:41', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `commission_payments`
--

CREATE TABLE `commission_payments` (
  `commission_payment_id` int(11) NOT NULL,
  `commission_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payment_number` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_notes` text DEFAULT NULL,
  `paid_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `payment_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount paid',
  `notes` text DEFAULT NULL COMMENT 'Additional notes',
  `bank_account_id` int(11) DEFAULT NULL COMMENT 'Bank account used'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commission_payment_requests`
--

CREATE TABLE `commission_payment_requests` (
  `commission_payment_request_id` int(11) NOT NULL,
  `commission_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `amount_to_pay` decimal(15,2) NOT NULL,
  `payment_notes` text DEFAULT NULL,
  `request_status` enum('pending_approval','approved','rejected') DEFAULT 'pending_approval',
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commission_structures`
--

CREATE TABLE `commission_structures` (
  `commission_structure_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `structure_name` varchar(100) NOT NULL,
  `structure_code` varchar(20) DEFAULT NULL,
  `commission_type` enum('sales','referral','consultant','marketing','collection','performance') NOT NULL,
  `is_tiered` tinyint(1) DEFAULT 0,
  `base_rate` decimal(5,2) NOT NULL COMMENT 'Base percentage',
  `min_sales_amount` decimal(15,2) DEFAULT NULL,
  `target_amount` decimal(15,2) DEFAULT NULL,
  `payment_frequency` enum('immediate','monthly','quarterly','on_completion') DEFAULT 'monthly',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Commission rate structures';

--
-- Dumping data for table `commission_structures`
--

INSERT INTO `commission_structures` (`commission_structure_id`, `company_id`, `structure_name`, `structure_code`, `commission_type`, `is_tiered`, `base_rate`, `min_sales_amount`, `target_amount`, `payment_frequency`, `is_active`, `created_at`, `created_by`) VALUES
(1, 1, 'Standard Sales Commission', 'STD-SALES', 'sales', 0, 2.50, NULL, NULL, 'monthly', 1, '2025-12-23 05:49:43', 1),
(2, 3, 'Standard Sales Commission', 'STD-SALES', 'sales', 0, 2.50, NULL, NULL, 'monthly', 1, '2025-12-23 05:49:43', 9);

-- --------------------------------------------------------

--
-- Table structure for table `commission_tiers`
--

CREATE TABLE `commission_tiers` (
  `commission_tier_id` int(11) NOT NULL,
  `commission_structure_id` int(11) NOT NULL,
  `tier_name` varchar(50) NOT NULL COMMENT 'Bronze, Silver, Gold, Platinum',
  `tier_level` int(11) NOT NULL,
  `min_amount` decimal(15,2) NOT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `commission_rate` decimal(5,2) NOT NULL,
  `bonus_rate` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tiered commission rates';

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `company_id` int(11) NOT NULL,
  `company_code` varchar(20) NOT NULL COMMENT 'Unique company identifier',
  `company_name` varchar(200) NOT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `tax_identification_number` varchar(100) DEFAULT NULL COMMENT 'TIN',
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `physical_address` text DEFAULT NULL,
  `postal_address` varchar(200) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Tanzania',
  `logo_path` varchar(255) DEFAULT NULL,
  `primary_color` varchar(20) DEFAULT '#007bff',
  `secondary_color` varchar(20) DEFAULT '#6c757d',
  `fiscal_year_start` date DEFAULT NULL,
  `fiscal_year_end` date DEFAULT NULL,
  `currency_code` varchar(3) DEFAULT 'TZS',
  `date_format` varchar(20) DEFAULT 'Y-m-d',
  `timezone` varchar(50) DEFAULT 'Africa/Dar_es_Salaam',
  `subscription_plan` enum('trial','basic','professional','enterprise') DEFAULT 'trial',
  `subscription_start_date` date DEFAULT NULL,
  `subscription_end_date` date DEFAULT NULL,
  `max_users` int(11) DEFAULT 5,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-tenant company registration';

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`company_id`, `company_code`, `company_name`, `registration_number`, `tax_identification_number`, `email`, `phone`, `mobile`, `website`, `physical_address`, `postal_address`, `city`, `region`, `country`, `logo_path`, `primary_color`, `secondary_color`, `fiscal_year_start`, `fiscal_year_end`, `currency_code`, `date_format`, `timezone`, `subscription_plan`, `subscription_start_date`, `subscription_end_date`, `max_users`, `is_active`, `created_at`, `updated_at`, `created_by`, `address`, `logo`) VALUES
(3, 'MKUMBI', 'Mkumbi investment company ltd', '', '', 'info@mkumbiinvestment.co.tz', '+255 XXX XXX XXX', NULL, NULL, NULL, NULL, NULL, NULL, 'Tanzania', 'assets/img/logo2.png', '#007bff', '#6c757d', NULL, NULL, 'TZS', 'Y-m-d', 'Africa/Dar_es_Salaam', 'enterprise', '2025-12-12', '2026-12-12', 100, 1, '2025-11-29 07:56:46', '2025-12-24 10:39:04', NULL, 'ilala boma, Mafao House', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `company_loans`
--

CREATE TABLE `company_loans` (
  `loan_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `loan_number` varchar(50) NOT NULL,
  `lender_name` varchar(200) NOT NULL,
  `loan_type` enum('term_loan','overdraft','line_of_credit','mortgage','other') NOT NULL,
  `account_code` varchar(20) DEFAULT '2210',
  `loan_date` date NOT NULL,
  `loan_amount` decimal(15,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `repayment_term_months` int(11) NOT NULL,
  `monthly_payment` decimal(15,2) NOT NULL,
  `maturity_date` date NOT NULL,
  `collateral_description` text DEFAULT NULL,
  `collateral_value` decimal(15,2) DEFAULT NULL,
  `principal_outstanding` decimal(15,2) NOT NULL,
  `interest_outstanding` decimal(15,2) DEFAULT 0.00,
  `total_outstanding` decimal(15,2) NOT NULL,
  `total_paid` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','fully_paid','defaulted','restructured') DEFAULT 'active',
  `contact_person` varchar(200) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_loan_payments`
--

CREATE TABLE `company_loan_payments` (
  `payment_id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `principal_paid` decimal(15,2) NOT NULL,
  `interest_paid` decimal(15,2) NOT NULL,
  `total_paid` decimal(15,2) NOT NULL,
  `payment_method` enum('bank_transfer','cheque','direct_debit') NOT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contract_templates`
--

CREATE TABLE `contract_templates` (
  `template_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(200) NOT NULL,
  `template_code` varchar(50) NOT NULL,
  `contract_type` enum('sale','lease','installment','service','supplier','employment','other') NOT NULL,
  `template_content` longtext NOT NULL COMMENT 'HTML/RTF template with placeholders',
  `placeholders` text DEFAULT NULL COMMENT 'JSON array of available placeholders',
  `header_html` text DEFAULT NULL,
  `footer_html` text DEFAULT NULL,
  `signature_positions` text DEFAULT NULL COMMENT 'JSON config for signature positions',
  `page_size` varchar(20) DEFAULT 'A4',
  `orientation` enum('portrait','landscape') DEFAULT 'portrait',
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contract templates for auto-generation';

-- --------------------------------------------------------

--
-- Table structure for table `contract_templates_backup`
--

CREATE TABLE `contract_templates_backup` (
  `template_id` int(11) NOT NULL DEFAULT 0,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contract_type` enum('sale','lease','installment','service','supplier','employment','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'HTML/RTF template with placeholders',
  `placeholders` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'JSON array of available placeholders',
  `header_html` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `footer_html` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_positions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'JSON config for signature positions',
  `page_size` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'A4',
  `orientation` enum('portrait','landscape') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'portrait',
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cost_categories`
--

CREATE TABLE `cost_categories` (
  `cost_category_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cost_categories`
--

INSERT INTO `cost_categories` (`cost_category_id`, `company_id`, `category_name`, `description`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'Survey & Mapping', 'Land surveying, topographic surveys, GPS mapping, boundary surveys', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(2, 1, 'Legal Fees', 'Legal documentation, title deed processing, attorney fees, notary services', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(3, 1, 'Infrastructure Development', 'Road construction, drainage systems, sewerage, water supply', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(4, 1, 'Marketing & Sales', 'Advertising, promotional materials, sales commissions, showroom costs', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(5, 1, 'Administrative', 'Office supplies, utilities, staff salaries, general administration', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(6, 1, 'Land Clearing', 'Vegetation removal, demolition, site preparation, grading', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(7, 1, 'Utilities Installation', 'Electricity connection, water connection, street lighting', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(8, 1, 'Security & Fencing', 'Perimeter fencing, security systems, guard services', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(9, 1, 'Environmental Compliance', 'Environmental impact assessments, permits, mitigation measures', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(10, 1, 'Professional Services', 'Consultants, architects, engineers, project managers', 1, NULL, '2025-11-29 10:49:46', '2025-11-29 10:49:46'),
(11, 1, 'Survey & Mapping', 'Land surveying, topographic surveys, GPS mapping, boundary surveys', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(12, 1, 'Legal Fees', 'Legal documentation, title deed processing, attorney fees, notary services', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(13, 1, 'Infrastructure Development', 'Road construction, drainage systems, sewerage, water supply', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(14, 1, 'Marketing & Sales', 'Advertising, promotional materials, sales commissions, showroom costs', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(15, 1, 'Administrative', 'Office supplies, utilities, staff salaries, general administration', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(16, 1, 'Land Clearing', 'Vegetation removal, demolition, site preparation, grading', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(17, 1, 'Utilities Installation', 'Electricity connection, water connection, street lighting', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(18, 1, 'Security & Fencing', 'Perimeter fencing, security systems, guard services', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(19, 1, 'Environmental Compliance', 'Environmental impact assessments, permits, mitigation measures', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(20, 1, 'Professional Services', 'Consultants, architects, engineers, project managers', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(21, 1, 'Land Purchase', 'Actual land acquisition costs, transfer fees', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(22, 1, 'Permits & Licenses', 'Government permits, approvals, license fees', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(23, 1, 'Site Development', 'Leveling, terracing, soil improvement', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(24, 1, 'Landscaping', 'Gardens, trees, recreational areas', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20'),
(25, 1, 'Documentation', 'Printing, copying, filing, archival costs', 1, NULL, '2025-11-29 10:51:20', '2025-11-29 10:51:20');

-- --------------------------------------------------------

--
-- Table structure for table `creditors`
--

CREATE TABLE `creditors` (
  `creditor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `creditor_type` enum('supplier','contractor','consultant','employee','other') NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `creditor_name` varchar(200) NOT NULL,
  `contact_person` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `physical_address` varchar(255) DEFAULT NULL,
  `total_amount_owed` decimal(15,2) DEFAULT 0.00,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `outstanding_balance` decimal(15,2) GENERATED ALWAYS AS (`total_amount_owed` - `amount_paid`) STORED,
  `credit_days` int(11) DEFAULT 30,
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created this creditor',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL COMMENT 'User who last updated this creditor'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Creditors/Accounts Payable';

-- --------------------------------------------------------

--
-- Table structure for table `creditor_invoices`
--

CREATE TABLE `creditor_invoices` (
  `creditor_invoice_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `creditor_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `invoice_amount` decimal(15,2) NOT NULL,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) GENERATED ALWAYS AS (`invoice_amount` - `amount_paid`) STORED,
  `purchase_order_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','partially_paid','paid','overdue') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Creditor invoices';

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `full_name` varchar(300) GENERATED ALWAYS AS (concat(`first_name`,' ',ifnull(concat(`middle_name`,' '),''),`last_name`)) STORED,
  `email` varchar(150) DEFAULT NULL,
  `phone1` varchar(50) DEFAULT NULL,
  `phone2` varchar(50) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `passport_number` varchar(50) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `ward` varchar(100) DEFAULT NULL COMMENT 'Aina ya kitambuliaho',
  `village` varchar(100) DEFAULT NULL COMMENT 'Namba ya kitambuliaho',
  `street_address` text DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `guardian1_name` varchar(200) DEFAULT NULL,
  `guardian1_relationship` varchar(100) DEFAULT NULL,
  `guardian1_phone` varchar(50) DEFAULT NULL,
  `guardian2_name` varchar(200) DEFAULT NULL,
  `guardian2_relationship` varchar(100) DEFAULT NULL,
  `guardian2_phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `customer_type` enum('individual','company') DEFAULT 'individual' COMMENT 'individual or company',
  `id_number` varchar(50) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT 'Tanzanian',
  `occupation` varchar(100) DEFAULT NULL,
  `next_of_kin_name` varchar(150) DEFAULT NULL,
  `next_of_kin_phone` varchar(20) DEFAULT NULL,
  `next_of_kin_relationship` varchar(50) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `alternative_phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `postal_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer/buyer information';

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `company_id`, `first_name`, `middle_name`, `last_name`, `email`, `phone1`, `phone2`, `national_id`, `passport_number`, `region`, `district`, `ward`, `village`, `street_address`, `gender`, `profile_picture`, `guardian1_name`, `guardian1_relationship`, `guardian1_phone`, `guardian2_name`, `guardian2_relationship`, `guardian2_phone`, `is_active`, `created_at`, `updated_at`, `created_by`, `customer_type`, `id_number`, `tin_number`, `nationality`, `occupation`, `next_of_kin_name`, `next_of_kin_phone`, `next_of_kin_relationship`, `phone`, `alternative_phone`, `address`, `postal_address`, `notes`) VALUES
(1, 3, 'LAZARO', 'MPUYA', 'MATALANGE', 'matalange@gmail.com', NULL, NULL, '19760218-16113-00002-20', '', 'Dar es Salaam', 'Kinondoni', 'Kawe', 'Kawe Wazo', 'mkoani', 'male', NULL, 'OLIVER SCHOLA MATALANGE', 'parent', NULL, 'SCHOLASTICA MATALANGE', 'child', NULL, 1, '2025-12-12 14:21:24', '2025-12-12 14:21:24', 9, 'individual', '', '', 'Tanzanian', '', 'OLIVER SCHOLA MATALANGE', '0767117377', 'parent', '0685767670', '0685767670', NULL, 'P.O.BOX 25423', ''),
(3, 3, 'CAROLINA ', 'ROBERT', 'MGANGA', 'robertcarolina76@gmail.com', NULL, NULL, '19991208305110000119', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'MABIBO', 'JITEGEMEE', '', 'female', NULL, 'Modesta Mbanga Maziku', 'parent', '0754836168', 'Julius  Shinganga Malale', 'parent', '0786775173', 1, '2025-12-19 10:00:30', '2025-12-19 10:00:30', 9, 'individual', NULL, '', 'Tanzanian', 'Saloon', '', '', '', '0622023474', '0622023474', NULL, '', ''),
(4, 3, 'UPENDO', 'ROGERS', 'MSUYA', 'upendomsuya@gmail.com', NULL, NULL, '1992081512080000119', NULL, 'DAR-ES-SALAAM', 'ILALA', 'MINAZI MIREFU', 'MIGOMBANI', '', 'female', NULL, 'Rogers Daniel Msuya', 'parent', '0713293831', 'Daniel Rogers Msuya', 'sibling', '0717378003', 1, '2025-12-23 13:22:53', '2025-12-23 13:22:53', 9, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0719311613', '0625625463', NULL, '', ''),
(5, 3, 'ZAHARA ', 'HEMED', 'SHABANI', 'hemedzahra@gmail.com', NULL, NULL, '19980819151090000111', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'SINZA', 'SINZA \"A\"', '', 'female', NULL, ' Fatuma Abdul Chuma', 'parent', '0713569282', 'Rukia Chuma', 'parent', '0713319286', 1, '2025-12-23 14:05:54', '2025-12-23 14:05:54', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0788083700', '0684832732', NULL, '', ''),
(6, 3, 'NASSOR', 'HAMAD', 'OMARY', 'nassorhamad23@icloud.com', NULL, NULL, '198906261206000055', '', 'DAR-ES-SALAAM', 'ILALA', 'ILALA', 'AMANA', 'ILALA', 'male', NULL, 'Omar Sheha Athumani', 'sibling', '0652264483', 'Khamis Ali Omar', 'sibling', '078579124', 1, '2025-12-23 14:14:02', '2025-12-23 14:15:23', 11, 'individual', '', '', 'Tanzanian', '', '', '', '', '077497515', '', NULL, '', ''),
(7, 3, 'MOHAMED ', 'MIKIDADI', 'MNGWALI', 'mohamedmngwali061@gmail.com', NULL, NULL, '19771201121130000123', NULL, 'DAR-ES-SALAAM', 'ILALA', 'BUYUNI', 'NYEBURU', '', 'male', NULL, 'Pili Said Ngwalima', 'spouse', '0718535274', 'Asia Mohamed Mngwali', 'child', '0792176677', 1, '2025-12-23 14:22:47', '2025-12-23 14:22:47', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '071853598', '0621740347', NULL, 'S.L.P 20950', ''),
(8, 3, 'MAHAMOUD', 'MASHAKA', 'MAULID', '', NULL, NULL, '20010521331140000124', NULL, 'KIGOMA', 'KAKONKO', 'KAKONKO', 'KAKONKO', '', 'male', NULL, 'Rahma Mohamed', 'sibling', '0678642088', 'Irene Jacob', 'friend', '0750634719', 1, '2025-12-23 14:28:59', '2025-12-23 14:28:59', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0684183802', '0741939997', NULL, '', ''),
(9, 3, 'DILAWAR', 'HUSSAIN', 'PADHANI', '', NULL, NULL, '19640721114850000120', NULL, 'DAR-ES-SALAAM', 'ILALA', 'ILALA', 'AMANA', '', '', NULL, 'Ayyad Mustafa Dilawar', 'child', '0743606984', 'Mohammed Dilawar Padhani', 'child', '0745669991', 1, '2025-12-23 14:35:54', '2025-12-23 14:35:54', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0784606984', '', NULL, 'P.0 B0X 21638', ''),
(10, 3, 'TUSAJIGWE', 'MOSES', 'KABOJOKA', '', NULL, NULL, '1983022312106000018', NULL, 'DAR-ES-SALAAM', 'ILALA', 'KIPAWA', 'KIPUNGUNI', '', 'male', NULL, 'Beatrice Moses Kabojoka', 'sibling', '0786225200', 'Harieth Moses Kabojoka', 'sibling', '0716990800', 1, '2025-12-23 14:41:57', '2025-12-23 14:41:57', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0783446988', '0746173140', NULL, 'P.O BOX 21638', ''),
(11, 3, 'HALIMA', 'ISSA', 'MEMBE', '', NULL, NULL, '19840723611010000111', NULL, 'DODOMA', 'DODOMA', 'IYUMBU', 'IYUMBU', '', 'female', NULL, 'Radhia Shabani Mgeni', 'sibling', '0777078994', 'Mustapha Idrissa Hussein', 'sibling', '0714078483', 1, '2025-12-23 14:47:23', '2025-12-23 14:47:23', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0718817049', '0763945761', NULL, 'S.L.P 259', ''),
(12, 3, 'MIRIAM', 'MOSES', 'MNDIMA', '', NULL, NULL, '199109151141150000117', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'KIMARA', 'BARUTI', '', 'female', NULL, 'Judith James Fimbo', 'parent', '0715476349', 'Joshua Moses Eliamini', 'sibling', '0655900638', 1, '2025-12-23 14:59:07', '2025-12-23 14:59:07', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0715636851', '0740615591', NULL, '', ''),
(13, 3, 'PAUL ', 'MARIKE', 'CHANGAI', '', NULL, NULL, '', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'GOBA', 'GOBA', '', 'male', NULL, 'Christina Francis  Mangera', 'spouse', '0655733421', 'Shaniat Masoud Nassor', 'spouse', '074778875', 1, '2025-12-23 15:04:11', '2025-12-23 15:04:11', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0753733421', '', NULL, '', ''),
(14, 3, 'EUNICE', 'ZEPHANIA', 'MZOLA', '', NULL, NULL, '19950511675010000118', NULL, 'DODOMA', 'DODOMA', 'HOMBOLO\r\nBWAWANI', 'HOMBOLO BWAWANI A', '', '', NULL, ' Zephania Mzola', 'parent', '0712715473', 'Happiness Mzola', 'parent', '0717337687', 1, '2025-12-23 15:08:56', '2025-12-23 15:08:56', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0679775945', '', NULL, 'P.O BOX 2786', ''),
(15, 3, 'SAMWEL ', 'ARON', 'RICHARD', '', NULL, NULL, '19990713331040000220', NULL, 'MOROGORO', 'MOROGORO', 'MNGAZI', 'MNGAZI', '', 'male', NULL, 'Sesilia StanlausYatobanga', 'parent', '0744189022', 'Esther Isack Saguda', 'sibling', '0752289194', 1, '2025-12-23 15:13:57', '2025-12-23 15:13:57', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0694753369', '0629500977', NULL, '', ''),
(16, 3, 'JOSEPH', 'MARTIN', 'MMASSY', '', NULL, NULL, '19870331121480000325', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'KWEMBE', 'KING\'AZI', '', 'male', NULL, 'Oswald Okutu', 'friend', '0656386563', 'Ester Joseph Soka', 'sibling', '0689302947', 1, '2025-12-23 15:17:58', '2025-12-23 15:17:58', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0781156771', '0786694294', NULL, '', ''),
(17, 3, 'MAALIM', 'HAMZA', 'JABIR', '', NULL, NULL, '19800925141080000426', NULL, 'DAR-ES-SALAAM', 'KINONDONI', 'MZIMUNI', 'MWINYIMKUU', '', 'male', NULL, 'Aziza Kassim Hassan', 'spouse', '0656923192', 'Omary Hamza Jabir', 'sibling', '0713769769', 1, '2025-12-24 08:00:40', '2025-12-24 08:00:40', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0743869869', '', NULL, 'S.L.P 15517', ''),
(18, 3, 'FIKIRI', 'IDD', 'MWINYIMVUA', '', NULL, NULL, '19861025111010000422', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'GOBA', 'GOBA', '', 'male', NULL, 'Maimuna Abdallah', 'parent', '0685325633', 'Dativa Kessy', 'spouse', '0676404442', 1, '2025-12-24 08:07:14', '2025-12-24 08:07:14', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0757606164', '0680606164', NULL, '', ''),
(19, 3, 'JAFFAR ', 'SAID', 'LALIKA', '', NULL, NULL, '19810926531070000224', NULL, 'DAR-ES-SALAAM', 'ILALA', 'MAJOHE', 'KIVULE', '', 'male', NULL, 'Warda Kassim Haji', 'spouse', '0785102290', 'Said Ahmed Lalika', 'parent', '0764757970', 1, '2025-12-24 08:29:21', '2025-12-24 08:29:21', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0784443921', '0757893040', NULL, '', ''),
(20, 3, 'FARAJI', 'MURSHID', 'RUSHAGAMA', '', NULL, NULL, '19830221140000128', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'MBEZI', 'MBEZI LUIS', '', 'male', NULL, 'Mwamini Ibrahim Sengo', 'spouse', '0656924656', 'Maulid Murshid', 'sibling', '0678589833', 1, '2025-12-24 08:42:41', '2025-12-24 08:42:41', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0784817580', '0656924656', NULL, 'S.L.P 62915', ''),
(21, 3, 'ANNET', 'MWITA', 'SASI', '', NULL, NULL, '198812123114120000717', NULL, 'MARA', 'TARIME', 'MATONGO', 'MATONGO', '', 'female', NULL, 'Mwita Mwita Sasi', 'spouse', '0755439254', '', '', '', 1, '2025-12-24 08:48:15', '2025-12-24 08:48:15', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0752670189', '', NULL, '', ''),
(22, 3, 'ANISA ', 'KASSIM', 'SULEIMAN', '', NULL, NULL, '19881107711010000115', NULL, '', '', '', '', '', 'female', NULL, 'Aisha Mohammed Taib', 'parent', '0773322213', 'Kassim Suleimani Ibrahim', 'parent', '0773322213', 1, '2025-12-24 08:55:40', '2025-12-24 08:55:40', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0772428683', '', NULL, 'P.O BOX 3438', ''),
(23, 3, 'IZZA', 'NASSORO', 'OMARY', '', NULL, NULL, NULL, NULL, 'DAR-ES-SALAAM', 'ILALA', 'KIWALANI', 'YOMBO', '', 'male', NULL, 'Rashidi Nassoro Omari', 'sibling', '0763886240', 'Nassoro Omary Kingazi', 'parent', '0719760811', 1, '2025-12-24 09:03:48', '2025-12-24 09:03:48', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0693560829', '0760841244', NULL, '', ''),
(24, 3, 'REHEMA ', 'KHAMIS', 'ABDALLA', '', NULL, NULL, '19871031711160000116', NULL, '', '', '', '', '', 'female', NULL, 'Asha Khamis Abdalla', 'sibling', '0713061920', 'Fatma Salum Abdalla', 'parent', '0673414157', 1, '2025-12-24 09:10:37', '2025-12-24 09:10:37', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0716288480', '', NULL, 'P.O BOX 2412', ''),
(25, 3, 'ABDALLA', 'ALI', 'ABDALLA', '', NULL, NULL, '19830904711180000224', NULL, 'DAR-ES-SALAAM', 'TEMEKE', 'KIJICHI', 'MTONI KIJICHI', '', 'male', NULL, 'Zuwena Hemed Hassan', 'spouse', '0773629631', 'Swahad Abdalla Ali', 'child', '0774660332', 1, '2025-12-24 09:18:49', '2025-12-24 09:18:49', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0762868939', '', NULL, '', ''),
(26, 3, 'AISHA', 'ISMAIL', 'MPONZI', '', NULL, NULL, '19950305121050000410', NULL, 'DAR-ES-SALAAM', 'ILALA', 'SEGEREA', 'MGOMBANI', '', 'female', NULL, 'Ismail A Mponzi', 'parent', '0756099922', 'Mwantumu R Mkondaki', 'parent', '0652982088', 1, '2025-12-24 09:24:24', '2025-12-24 09:24:24', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0659724453', '0749200552', NULL, '', ''),
(27, 3, 'MOHAMED ', 'HUSSEIN', 'ATHUMAN', 'mabwehussein@gmail.com', NULL, NULL, '19900102815103000001129', NULL, 'DAR-ES-SALAAM', 'ILALA', 'CHANIKA', 'VIROBO', '', 'male', NULL, 'Zaituni Rashidi Athuman', 'parent', '0716336138', 'Hajira Selehe Maulidi', 'spouse', '0657272200', 1, '2025-12-24 09:32:43', '2025-12-24 09:32:43', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0658120109', '0743667035', NULL, '', ''),
(28, 3, 'SAID', 'JUMA', 'MASELE', 'saidmasele@gmail.com', NULL, NULL, NULL, 'TAE554224', 'DAR-ES-SALAAM', 'UBUNGO', 'MSIGANI', 'MSIGANI', '', 'male', NULL, 'Benjamin Masunga Chulla', 'sibling', '0756187175', 'Donath Josephat Mwanuzi', 'sibling', '0754475697', 1, '2025-12-24 09:39:26', '2025-12-24 09:39:26', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '07833697306', '0756187175', NULL, 'P.O.BOX90107', ''),
(29, 3, 'ASHERI', 'RAMADHANI', 'MURO', 'muroasheri@gmail.com', NULL, NULL, '19931125171070000124', '', 'MOROGORO', 'MOROGORO', 'NGERENGERE', 'NGERENGERE', '', 'male', NULL, 'Grolia Salenge', 'spouse', '0756880177', 'Rehema Muro', 'parent', '076535156', 1, '2025-12-24 09:45:33', '2025-12-24 09:46:30', 11, 'individual', '', '', 'Tanzanian', '', '', '', '', '0759221902', '0659825808', NULL, '', ''),
(30, 3, 'JOHN', 'JEREMIA', 'NYATUNYI', 'johnjeremiahnyatunyi67@gmail.com', NULL, NULL, '19790329121040000129', NULL, 'DAR-ES-SALAAM', 'ILALA', 'UKONGA', 'MARKAZ', '', 'male', NULL, 'Adelina Geogre Mtenga', 'spouse', '0676822098', 'Vitus Mvamba', 'sibling', '0656977558', 1, '2025-12-24 09:52:42', '2025-12-24 09:52:42', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0713743321', '0764160232', NULL, '', ''),
(31, 3, 'AMOS', 'SAMSON', 'RWEIKIZA', 'rwekizaamos@yahoo.com', NULL, NULL, '19970713673110000223', '', 'DAR-ES-SALAAM', 'KINONDONI', 'BUNJU', 'MKOANI', '', 'male', NULL, 'Christina Kahangwa', 'spouse', '0624122351', 'Johnson J Rweikiza', 'child', '0769419543', 1, '2025-12-24 09:59:47', '2025-12-24 10:01:28', 11, 'individual', '', '', 'Tanzanian', '', '', '', '', '0717390173', '0767349218', NULL, '', ''),
(32, 3, 'SAMSON', 'ATANGIMANA', 'SHADRACK', 'samsonshadrack75@gmail.com', NULL, NULL, '19970713673110000223', NULL, 'TABORA', 'TABORA CBD', 'MALOLO', 'URBAN QUARTER', '', 'male', NULL, 'Elizabeth P Dase', 'spouse', '0715106390', 'Mgreth Shushi', 'sibling', '0679597631', 1, '2025-12-24 10:08:09', '2025-12-24 10:08:09', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0744095513', '0695445101', NULL, '', ''),
(33, 3, 'FELIUS', 'MUHUMULIZA', 'MARCO', 'feliusmarco@GMAIL.COM', NULL, NULL, '19871122141250000129', NULL, 'PWANI', 'KIBAHA', 'MLANDIZI', 'MLANDIZI KATI', '', 'male', NULL, 'Rose Filbert Magesa', 'spouse', '0616166694', 'Yoshua Felius Magesa', 'child', '', 1, '2025-12-24 10:14:14', '2025-12-24 10:14:14', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0657848222', '0682012888', NULL, 'P .O.BOX30153', ''),
(34, 3, 'ALMASI', 'RASHIDI', 'AMIRI', 'rashidialmasi1@gmail.com', NULL, NULL, '19950107613110000125', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'MAKUBURI', 'KIBANGU', '', 'male', NULL, 'Damaris Amadi Nyamondo', 'spouse', '0755015561', 'Sofia Saidi Yusuph', 'parent', '0712349229', 1, '2025-12-24 10:23:02', '2025-12-24 10:23:02', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0676993353', '', NULL, '', ''),
(35, 3, 'IBRAHIM', 'JUMANNE', 'CHALAMILA', 'chalamilaibrahim@gmail.com', NULL, NULL, '19920806511060000125', NULL, 'DAR-ES-SALAAM', 'UBUNGO', 'MBEZI', 'LUIS', '', 'male', NULL, 'Ziada Namtuka', 'spouse', '0763955989', 'Haruna Chalamila', 'sibling', '0772377092', 1, '2025-12-24 10:30:01', '2025-12-24 10:30:01', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0763299985', '0716599985', NULL, '', ''),
(36, 3, 'RACHEL', 'RAMADHANI', 'MFINGILE', 'mwanahamisishamisi@gmail.com', NULL, NULL, '19970528111090000218', NULL, 'DAR-ES-SALAAM', 'KIGAMBONI', 'KIGAMBONI', 'FERRY', '', 'female', NULL, 'Neema Bemjamin Mbwelwa', 'parent', '0769392320', 'Chumu Shuari', 'sibling', '0773901931', 1, '2025-12-24 10:41:10', '2025-12-24 10:41:10', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0718874806', '', NULL, '', ''),
(37, 3, 'SELEMANI', 'ALLY', 'BILINJI', '', NULL, NULL, '19950805121010000222', NULL, 'DAR-ES-SALAAM', 'ILALA', 'ILALA', 'AMANA', '', 'male', NULL, 'Fatuma Ismail', 'parent', '0688202749', 'Abdalla Lugongo', 'parent', '0716012683', 1, '2025-12-24 10:47:13', '2025-12-24 10:47:13', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0714525617', '0613005344', NULL, '', ''),
(38, 3, 'GODWIN', 'TENESI', 'KAJELERO', 'godwintenesi42@gmail.com', NULL, NULL, '19770221351020000129', '', 'KAGERA', 'BUKOBA', 'KASHARU', 'KASHARU', '', 'male', NULL, 'Julietha Thadeo', 'spouse', '0755698747', 'Novati Tenesi Buberwa', 'sibling', '0754923960', 1, '2025-12-24 10:53:45', '2025-12-24 10:54:28', 11, 'individual', '', '', 'Tanzanian', '', '', '', '', '0765560598', '0622560598', NULL, '', ''),
(39, 3, 'OTHMAN ', 'ABBAS', 'ATHUMAN', 'othman.athuman@icloud.com', NULL, NULL, '19910313303010000325', NULL, 'GEITA', 'CHATO', 'MUUNGANO', 'MLIMANI', '', 'male', NULL, 'Hamida Shabani Ibrahim', 'spouse', '0686773710', 'Abdul Jafari Omary', 'sibling', '0716575670', 1, '2025-12-24 11:02:10', '2025-12-24 11:02:10', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0716042805', '0684225226', NULL, 'P.O.BOX 01', ''),
(40, 3, 'UPENDO', 'JOSEPH', 'KIVUYO', 'upendokivuyo@yahoo.com', NULL, NULL, NULL, NULL, 'KILIMANJARO', 'MOSHI', 'ARUSHA CHINI', 'CHEMCHEM', '', 'female', NULL, 'Bahati Hassan Mhando', 'sibling', '0757387975', '', '', '', 1, '2025-12-24 12:40:51', '2025-12-24 12:40:51', 11, 'individual', NULL, '19710422256230000118', 'Tanzanian', '', '', '', '', '0754751465', '0717486988', NULL, 'P.O.BOX138', ''),
(41, 3, 'BRENDA', 'EVELYN', 'MIHANJO', 'bmihanjo@gmail.com', NULL, NULL, NULL, 'TAE215017', 'DAR-ES-SALAAM', 'KINONDONI', 'KINONDONI', 'KINONDONI MJINI', '', 'female', NULL, 'Elizabeth Mihanjo', 'sibling', '0787000701', 'Nathanael D Zombe', 'spouse', '0623543990', 1, '2025-12-24 12:47:16', '2025-12-24 12:47:16', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0744774488', '', NULL, '', ''),
(42, 3, 'MARTINE', 'JOSEPH', 'KALALA', 'martinekalala@rocketmail.com', NULL, NULL, '19830815332150000225', NULL, 'MWANZA', 'ILEMELA', 'KAHAMA', 'KADINDA', '', 'male', NULL, 'Happy Iranga', 'spouse', '0764951411', 'Beatrice Kalala', 'child', '0764951411', 1, '2025-12-24 12:52:45', '2025-12-24 12:52:45', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0764951410', '', NULL, '', ''),
(43, 3, 'SAMSON', 'SAMSON', 'KISWAGA', '', NULL, NULL, '19751108672190000225', NULL, 'DAR-ES-SALAAM', 'ILALA', 'KITUNDA', 'KIPERA', '', 'male', NULL, 'Anna William Joseph', 'spouse', '0758089056', 'Kelvin Samson Kiswaga', 'child', '0678268554', 1, '2025-12-24 12:57:49', '2025-12-24 12:57:49', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0784374381', '0764090370', NULL, '', ''),
(44, 3, 'MOIZ', 'JUZER', 'ZAVERY', 'colourszavery@gmail.com', NULL, NULL, '19910403111030000127', NULL, 'DAR-ES-SALAAM', 'ILALA', 'ILALA', 'KARUME', '', 'male', NULL, 'Mariyah Zavery', 'parent', '0784786976', 'Taher Abbas', 'friend', '0688618139', 1, '2025-12-24 13:04:27', '2025-12-24 13:04:27', 11, 'individual', NULL, '', 'Tanzanian', '', '', '', '', '0773525352', '0677445198', NULL, '', '');

-- --------------------------------------------------------

--
-- Table structure for table `customer_invoices`
--

CREATE TABLE `customer_invoices` (
  `invoice_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `amount_due` decimal(15,2) NOT NULL,
  `status` enum('draft','sent','paid','partial','overdue','cancelled','void') DEFAULT 'draft',
  `payment_terms` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer invoices';

-- --------------------------------------------------------

--
-- Table structure for table `customer_invoice_items`
--

CREATE TABLE `customer_invoice_items` (
  `item_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Invoice line items';

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `payment_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `payment_number` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money','card','other') NOT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `mobile_money_number` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','cleared','bounced','void') DEFAULT 'cleared',
  `cleared_date` date DEFAULT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `receipt_issued` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer payments';

-- --------------------------------------------------------

--
-- Table structure for table `customer_payment_plans`
--

CREATE TABLE `customer_payment_plans` (
  `plan_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `plan_name` varchar(200) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `deposit_amount` decimal(15,2) DEFAULT 0.00,
  `deposit_paid` tinyint(1) DEFAULT 0,
  `deposit_date` date DEFAULT NULL,
  `installment_amount` decimal(15,2) NOT NULL,
  `installment_frequency` enum('daily','weekly','biweekly','monthly','quarterly','yearly') DEFAULT 'monthly',
  `number_of_installments` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `balance_remaining` decimal(15,2) NOT NULL,
  `status` enum('active','completed','defaulted','cancelled') DEFAULT 'active',
  `auto_generate_invoices` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer payment plans/schedules';

-- --------------------------------------------------------

--
-- Table structure for table `customer_statement_history`
--

CREATE TABLE `customer_statement_history` (
  `statement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `statement_number` varchar(50) NOT NULL,
  `statement_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `total_debits` decimal(15,2) DEFAULT 0.00,
  `total_credits` decimal(15,2) DEFAULT 0.00,
  `closing_balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','sent','acknowledged') DEFAULT 'draft',
  `sent_at` datetime DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_to_email` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `generated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Generated customer statement history';

-- --------------------------------------------------------

--
-- Table structure for table `customer_transactions`
--

CREATE TABLE `customer_transactions` (
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `transaction_type` enum('invoice','payment','credit_note','debit_note','refund','adjustment','opening_balance','interest','penalty','discount') NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_number` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text NOT NULL,
  `debit_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Increases customer balance (what they owe)',
  `credit_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Decreases customer balance (payments)',
  `balance` decimal(15,2) DEFAULT 0.00 COMMENT 'Running balance after this transaction',
  `plot_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','posted','void','reversed') DEFAULT 'posted',
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `reversed_transaction_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='All customer financial transactions';

-- --------------------------------------------------------

--
-- Table structure for table `customer_writeoffs`
--

CREATE TABLE `customer_writeoffs` (
  `writeoff_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `writeoff_amount` decimal(15,2) NOT NULL,
  `writeoff_reason` enum('bankruptcy','deceased','uncollectible','dispute','fraud','other') NOT NULL,
  `notes` text DEFAULT NULL,
  `writeoff_date` date NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` date DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `debtors`
--

CREATE TABLE `debtors` (
  `debtor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `debtor_type` enum('customer','plot_buyer','service_client','other') NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `debtor_name` varchar(200) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `total_amount_due` decimal(15,2) DEFAULT 0.00,
  `amount_received` decimal(15,2) DEFAULT 0.00,
  `outstanding_balance` decimal(15,2) GENERATED ALWAYS AS (`total_amount_due` - `amount_received`) STORED,
  `current_due` decimal(15,2) DEFAULT 0.00,
  `days_30` decimal(15,2) DEFAULT 0.00,
  `days_60` decimal(15,2) DEFAULT 0.00,
  `days_90_plus` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','settled','overdue','legal_action') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Debtors/Accounts Receivable';

-- --------------------------------------------------------

--
-- Table structure for table `debtor_aging_config`
--

CREATE TABLE `debtor_aging_config` (
  `config_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `aging_period_1` int(11) DEFAULT 30 COMMENT 'Current (0-30 days)',
  `aging_period_2` int(11) DEFAULT 60 COMMENT '31-60 days',
  `aging_period_3` int(11) DEFAULT 90 COMMENT '61-90 days',
  `bad_debt_threshold_days` int(11) DEFAULT 180 COMMENT 'Days after which debt is considered bad',
  `reminder_frequency_days` int(11) DEFAULT 7 COMMENT 'Days between reminders',
  `first_reminder_days` int(11) DEFAULT 7 COMMENT 'Days before due date for first reminder',
  `final_notice_days` int(11) DEFAULT 30 COMMENT 'Days overdue for final notice',
  `legal_action_days` int(11) DEFAULT 90 COMMENT 'Days overdue before legal action',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Debtor aging configuration';

-- --------------------------------------------------------

--
-- Table structure for table `debtor_reminders`
--

CREATE TABLE `debtor_reminders` (
  `reminder_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `debtor_id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `reminder_type` enum('friendly','first_notice','second_notice','final_notice','legal_notice') NOT NULL,
  `reminder_date` date NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `days_overdue` int(11) NOT NULL,
  `communication_method` enum('sms','email','phone','letter','visit') NOT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `customer_response` text DEFAULT NULL,
  `next_action_date` date DEFAULT NULL,
  `status` enum('sent','acknowledged','payment_promised','no_response','disputed') DEFAULT 'sent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Debtor reminder history';

-- --------------------------------------------------------

--
-- Table structure for table `debtor_writeoffs`
--

CREATE TABLE `debtor_writeoffs` (
  `writeoff_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `debtor_id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `writeoff_number` varchar(50) NOT NULL,
  `writeoff_date` date NOT NULL,
  `original_amount` decimal(15,2) NOT NULL,
  `writeoff_amount` decimal(15,2) NOT NULL,
  `reason` text NOT NULL,
  `writeoff_type` enum('bad_debt','goodwill','settlement','other') NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Written-off debts';

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `department_code` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `manager_user_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `company_id`, `department_name`, `department_code`, `description`, `manager_user_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Human Resources', 'HR', 'Recruitment, training, and employee relations', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(2, 1, 'Finance', 'FIN', 'Financial planning, accounting, and reporting', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(3, 1, 'Accounting', 'ACC', 'Bookkeeping, payroll, and financial records', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(4, 1, 'Information Technology', 'IT', 'IT infrastructure and support', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(5, 1, 'Operations', 'OPS', 'Day-to-day business operations', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(6, 1, 'Sales', 'SLS', 'Revenue generation and sales management', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(7, 1, 'Marketing', 'MKT', 'Brand management and promotions', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(8, 1, 'Advertising', 'ADV', 'Advertising campaigns and media buying', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(9, 1, 'Customer Service', 'CS', 'Customer support and relations', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(10, 1, 'Procurement', 'PRC', 'Purchasing and supplier management', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(11, 1, 'Logistics', 'LOG', 'Transportation and distribution', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(12, 1, 'Inventory Management', 'INV', 'Stock control and warehousing', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(13, 1, 'Warehouse', 'WH', 'Warehouse operations and storage', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(14, 1, 'Administration', 'ADM', 'General office administration', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(15, 1, 'Facilities', 'FAC', 'Office maintenance and facilities', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(16, 1, 'Office Management', 'OFF', 'Office operations and coordination', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(17, 1, 'Records Management', 'REC', 'Document and records keeping', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(18, 1, 'Research & Development', 'RND', 'Product innovation and research', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(19, 1, 'Product Development', 'PRD', 'Product design and development', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(20, 1, 'Quality Assurance', 'QUA', 'Quality control and standards', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(21, 1, 'Production', 'PRO', 'Manufacturing and production processes', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(22, 1, 'Legal', 'LEG', 'Legal affairs and contracts', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(23, 1, 'Compliance', 'CMP', 'Regulatory compliance and audits', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(24, 1, 'Internal Audit', 'AUD', 'Internal audits and controls', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(25, 1, 'Training & Development', 'TRN', 'Employee training programs', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(26, 1, 'Recruitment', 'RCT', 'Talent acquisition and hiring', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(27, 1, 'Benefits Administration', 'BEN', 'Employee benefits management', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(28, 1, 'Project Management Office', 'PMO', 'Project planning and oversight', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(29, 1, 'Projects', 'PJM', 'Project execution and delivery', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(30, 1, 'Business Development', 'BD', 'New business opportunities', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(31, 1, 'Strategy', 'STR', 'Business strategy and planning', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(32, 1, 'Risk Management', 'RIS', 'Risk assessment and mitigation', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(33, 1, 'Communications', 'COM', 'Internal and external communications', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(34, 1, 'Public Relations', 'PR', 'Media relations and reputation', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(35, 1, 'Executive Office', 'CEO', 'Top-level management and strategy', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(36, 1, 'Finance Office', 'CFO', 'Financial leadership', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(37, 1, 'Operations Office', 'COO', 'Operational leadership', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(38, 1, 'Security', 'SEC', 'Physical and information security', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(39, 1, 'Corporate Social Responsibility', 'CSR', 'Sustainability and community', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23'),
(40, 1, 'Environmental Management', 'ENV', 'Environmental compliance', NULL, 1, '2025-11-30 13:32:23', '2025-11-30 13:32:23');

-- --------------------------------------------------------

--
-- Table structure for table `direct_expenses`
--

CREATE TABLE `direct_expenses` (
  `expense_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `expense_number` varchar(50) NOT NULL,
  `category_id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `expense_date` date NOT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `description` text NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'TSH',
  `payment_method` enum('bank_transfer','cash','cheque','mobile_money','credit') DEFAULT 'bank_transfer',
  `bank_account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','rejected','paid','cancelled') DEFAULT 'draft',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `recurring` tinyint(1) DEFAULT 0,
  `recurring_frequency` enum('daily','weekly','monthly','quarterly','yearly') DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `districts`
--

CREATE TABLE `districts` (
  `district_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `region_id` int(11) NOT NULL,
  `district_name` varchar(100) NOT NULL,
  `district_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `districts`
--

INSERT INTO `districts` (`district_id`, `company_id`, `region_id`, `district_name`, `district_code`, `is_active`, `created_at`) VALUES
(1, 1, 1, 'ilala', 'DD01', 1, '2025-11-29 13:01:30');

-- --------------------------------------------------------

--
-- Table structure for table `document_sequences`
--

CREATE TABLE `document_sequences` (
  `sequence_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL COMMENT 'invoice, po, receipt, etc',
  `prefix` varchar(10) DEFAULT NULL,
  `next_number` int(11) DEFAULT 1,
  `padding` int(11) DEFAULT 4 COMMENT 'Number of digits',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auto-numbering for documents';

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Links to users table',
  `employee_number` varchar(50) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `confirmation_date` date DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `employment_type` enum('permanent','contract','casual','intern') DEFAULT 'permanent',
  `contract_end_date` date DEFAULT NULL,
  `basic_salary` decimal(15,2) DEFAULT NULL,
  `allowances` decimal(15,2) DEFAULT 0.00,
  `total_salary` decimal(15,2) GENERATED ALWAYS AS (`basic_salary` + `allowances`) STORED,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `nssf_number` varchar(50) DEFAULT NULL COMMENT 'NSSF registration number',
  `tin_number` varchar(50) DEFAULT NULL COMMENT 'TIN number',
  `emergency_contact_name` varchar(200) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL,
  `employment_status` enum('active','suspended','terminated','resigned') DEFAULT 'active',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `company_id`, `user_id`, `employee_number`, `department_id`, `position_id`, `hire_date`, `confirmation_date`, `termination_date`, `employment_type`, `contract_end_date`, `basic_salary`, `allowances`, `bank_name`, `account_number`, `bank_branch`, `nssf_number`, `tin_number`, `emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship`, `employment_status`, `is_active`, `created_at`, `updated_at`, `created_by`) VALUES
(3, 3, 10, 'MKB01', 14, NULL, '2025-01-01', '2025-01-01', NULL, 'permanent', NULL, 200000.00, 0.00, 'CRDB Bank', '0152700601800', 'Tabata', '', '', 'Ismail Khalfani Kavilinga', '+255 071 349 280', 'parent', 'active', 1, '2025-12-17 08:51:32', '2025-12-24 01:36:02', 9),
(4, 3, 11, 'MKB12', 6, NULL, '2025-12-18', '2025-03-01', NULL, 'permanent', NULL, 200000.00, 0.00, '', '', '', NULL, NULL, '', '+255 656 777 099', '', 'active', 1, '2025-12-18 08:18:53', '2025-12-18 08:18:53', 9),
(5, 3, 12, 'MKB06', 5, NULL, '2025-01-01', '2025-12-31', NULL, 'permanent', NULL, 200000.00, 0.00, 'CRDB Bank', '0152840909400', 'Lumumba', NULL, NULL, 'Jane Nditu', '+255 757 873 80', 'parent', 'active', 1, '2025-12-18 12:31:57', '2025-12-23 12:37:15', 9),
(6, 3, 13, 'MKB11', 6, NULL, '2025-02-01', '2025-02-01', NULL, 'contract', '2026-02-28', 200000.00, 0.00, 'CRDB Bank', '0152000V17C00', 'Tazara', NULL, NULL, 'Suzana Jacob Mwita', '+255 755 854 212', 'sibling', 'active', 1, '2025-12-23 12:06:00', '2025-12-23 12:08:11', 9),
(7, 3, 14, 'mk89', 12, NULL, '2025-12-24', '2025-12-24', NULL, 'permanent', NULL, 400000.00, 0.00, 'NMB Bank', '01527TCG57200', 'azikiwe', '677575', '19287333', 'jack juma', '06474748844', 'sibling', 'active', 1, '2025-12-23 22:06:55', '2025-12-23 22:06:55', 9),
(8, 3, 15, 'mk84', 36, NULL, '2025-12-24', '2025-12-24', NULL, 'permanent', NULL, 500000.00, 0.00, 'NMB Bank', '015273457200', 'azikiwe', '457575', '1924433', 'jack juma', '06474733344', 'sibling', 'active', 1, '2025-12-23 22:09:27', '2025-12-23 22:09:27', 9),
(9, 3, 16, 'MKB04', 22, NULL, '2025-12-24', '2025-01-01', NULL, 'contract', '2025-12-31', 200000.00, 0.00, 'CRDB Bank', '0152658944200', '', '', '', 'John Keneth Mapoma', '0657141893', 'other', 'active', 1, '2025-12-24 12:02:37', '2025-12-24 12:02:37', 9);

-- --------------------------------------------------------

--
-- Table structure for table `employee_loans`
--

CREATE TABLE `employee_loans` (
  `loan_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `loan_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_type_id` int(11) NOT NULL,
  `application_date` date NOT NULL,
  `loan_amount` decimal(15,2) NOT NULL,
  `interest_rate` decimal(5,2) DEFAULT 0.00,
  `monthly_installment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `repayment_period_months` int(11) NOT NULL,
  `monthly_deduction` decimal(15,2) NOT NULL,
  `purpose` text NOT NULL,
  `guarantor1_id` int(11) DEFAULT NULL,
  `guarantor2_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','disbursed','active','paid','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_amount` decimal(15,2) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `disbursement_date` date DEFAULT NULL,
  `disbursement_method` enum('bank_transfer','cash','cheque') DEFAULT 'bank_transfer',
  `bank_account_id` int(11) DEFAULT NULL,
  `disbursement_reference` varchar(100) DEFAULT NULL,
  `principal_outstanding` decimal(15,2) DEFAULT 0.00,
  `interest_outstanding` decimal(15,2) DEFAULT 0.00,
  `total_outstanding` decimal(15,2) DEFAULT 0.00,
  `total_paid` decimal(15,2) DEFAULT 0.00,
  `last_payment_date` date DEFAULT NULL,
  `next_payment_date` date DEFAULT NULL,
  `loan_account_code` varchar(20) DEFAULT '1134',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `category_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_category_id` int(11) DEFAULT NULL,
  `budget_allocation` decimal(15,2) DEFAULT 0.00,
  `requires_approval` tinyint(1) DEFAULT 1,
  `approval_limit` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_claims`
--

CREATE TABLE `expense_claims` (
  `claim_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `claim_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `claim_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'TSH',
  `payment_method` enum('bank_transfer','cash','cheque','mobile_money') DEFAULT 'bank_transfer',
  `bank_account_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('draft','submitted','pending_approval','approved','rejected','paid','cancelled') DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `supporting_docs` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_claim_items`
--

CREATE TABLE `expense_claim_items` (
  `item_id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `description` text NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `account_code` varchar(20) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `vendor_name` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_assets`
--

CREATE TABLE `fixed_assets` (
  `asset_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `asset_number` varchar(50) NOT NULL,
  `category_id` int(11) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `purchase_cost` decimal(15,2) NOT NULL,
  `installation_cost` decimal(15,2) DEFAULT 0.00,
  `total_cost` decimal(15,2) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `model_number` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `warranty_expiry_date` date DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `custodian_id` int(11) DEFAULT NULL,
  `account_code` varchar(20) NOT NULL,
  `depreciation_account_code` varchar(20) NOT NULL,
  `depreciation_method` enum('straight_line','declining_balance','units_of_production') DEFAULT 'straight_line',
  `useful_life_years` int(11) DEFAULT 5,
  `salvage_value` decimal(15,2) DEFAULT 0.00,
  `accumulated_depreciation` decimal(15,2) DEFAULT 0.00,
  `current_book_value` decimal(15,2) NOT NULL,
  `last_depreciation_date` date DEFAULT NULL,
  `status` enum('active','inactive','under_maintenance','disposed','stolen','damaged') DEFAULT 'active',
  `disposal_date` date DEFAULT NULL,
  `disposal_amount` decimal(15,2) DEFAULT NULL,
  `disposal_reason` text DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `purchase_doc_path` varchar(255) DEFAULT NULL,
  `asset_photo_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fixed_assets`
--

INSERT INTO `fixed_assets` (`asset_id`, `company_id`, `asset_number`, `category_id`, `asset_name`, `description`, `purchase_date`, `supplier_id`, `purchase_order_id`, `invoice_number`, `purchase_cost`, `installation_cost`, `total_cost`, `serial_number`, `model_number`, `manufacturer`, `warranty_expiry_date`, `location`, `department_id`, `custodian_id`, `account_code`, `depreciation_account_code`, `depreciation_method`, `useful_life_years`, `salvage_value`, `accumulated_depreciation`, `current_book_value`, `last_depreciation_date`, `status`, `disposal_date`, `disposal_amount`, `disposal_reason`, `approval_status`, `approved_by`, `approved_at`, `purchase_doc_path`, `asset_photo_path`, `notes`, `created_by`, `created_at`, `updated_at`, `updated_by`) VALUES
(1, 3, 'AST00001', 1, 'HP log book 4', '15 gb ram', '2025-12-25', NULL, NULL, 'yt85849', 300000.00, 3000.00, 303000.00, 'sn84894', 'model 4', 'HP', NULL, 'mafao haouse', NULL, 11, '1500', '6200', 'straight_line', 3, 30300.00, 0.00, 303000.00, NULL, 'active', NULL, NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '', 9, '2025-12-25 18:46:20', '2025-12-25 18:57:57', 9);

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `quantity_on_hand` decimal(15,2) DEFAULT 0.00,
  `quantity_reserved` decimal(15,2) DEFAULT 0.00,
  `quantity_available` decimal(15,2) GENERATED ALWAYS AS (`quantity_on_hand` - `quantity_reserved`) STORED,
  `reorder_level` decimal(15,2) DEFAULT 0.00,
  `unit_cost` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_audits`
--

CREATE TABLE `inventory_audits` (
  `audit_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `audit_number` varchar(50) NOT NULL,
  `store_id` int(11) NOT NULL,
  `audit_date` date NOT NULL,
  `auditor_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_audit_lines`
--

CREATE TABLE `inventory_audit_lines` (
  `audit_line_id` int(11) NOT NULL,
  `audit_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `expected_quantity` decimal(15,2) DEFAULT 0.00 COMMENT 'System quantity before audit',
  `actual_quantity` decimal(15,2) DEFAULT 0.00 COMMENT 'Physical count',
  `variance` decimal(15,2) GENERATED ALWAYS AS (`actual_quantity` - `expected_quantity`) STORED,
  `variance_value` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `counted_at` timestamp NULL DEFAULT NULL,
  `counted_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `movement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `movement_type` enum('in','out','transfer','adjustment','purchase','sale') NOT NULL,
  `from_store_id` int(11) DEFAULT NULL,
  `to_store_id` int(11) DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL,
  `unit_cost` decimal(15,2) DEFAULT 0.00,
  `total_value` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_cost`) STORED,
  `reference_number` varchar(100) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'purchase_order, sales_order, etc',
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `movement_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `quotation_id` int(11) DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) GENERATED ALWAYS AS (`total_amount` - `amount_paid`) STORED,
  `status` enum('draft','sent','partially_paid','paid','overdue','cancelled') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `invoice_item_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `line_total` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT 'pcs',
  `cost_price` decimal(15,2) DEFAULT 0.00,
  `selling_price` decimal(15,2) DEFAULT 0.00,
  `reorder_level` decimal(10,2) DEFAULT 0.00,
  `minimum_stock` decimal(10,2) DEFAULT 0.00,
  `maximum_stock` decimal(10,2) DEFAULT 0.00,
  `current_stock` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_categories`
--

CREATE TABLE `item_categories` (
  `category_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `parent_category_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `journal_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `journal_number` varchar(50) NOT NULL,
  `journal_date` date NOT NULL,
  `journal_type` enum('general','sales','purchase','cash','bank','adjustment') DEFAULT 'general',
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `total_debit` decimal(15,2) DEFAULT 0.00,
  `total_credit` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','posted','cancelled') DEFAULT 'draft',
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal entry headers';

-- --------------------------------------------------------

--
-- Table structure for table `journal_entry_lines`
--

CREATE TABLE `journal_entry_lines` (
  `line_id` int(11) NOT NULL,
  `journal_id` int(11) NOT NULL,
  `line_number` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `debit_amount` decimal(15,2) DEFAULT 0.00,
  `credit_amount` decimal(15,2) DEFAULT 0.00,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'invoice, payment, etc',
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal entry line items';

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `lead_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `lead_number` varchar(50) NOT NULL DEFAULT '',
  `company_name` varchar(200) NOT NULL DEFAULT '',
  `industry` varchar(100) DEFAULT NULL,
  `company_size` varchar(50) DEFAULT NULL,
  `website` varchar(150) DEFAULT NULL,
  `contact_person` varchar(200) NOT NULL DEFAULT '',
  `job_title` varchar(150) DEFAULT NULL,
  `lead_source` enum('website','referral','walk_in','phone','email','social_media','advertisement','other') NOT NULL,
  `lead_status` enum('new','contacted','qualified','proposal','negotiation','won','lost') DEFAULT 'new',
  `full_name` varchar(200) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Tanzania',
  `source` enum('website','referral','social_media','email_campaign','cold_call','event','advertisement','partner','other') NOT NULL DEFAULT 'website',
  `campaign_id` int(11) DEFAULT NULL,
  `status` enum('new','contacted','qualified','proposal','negotiation','converted','lost') DEFAULT 'new',
  `alternative_phone` varchar(50) DEFAULT NULL,
  `interested_in` enum('plot_purchase','land_services','consultation','construction','other') DEFAULT NULL,
  `budget_range` varchar(100) DEFAULT NULL,
  `preferred_location` varchar(200) DEFAULT NULL,
  `preferred_plot_size` varchar(100) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL COMMENT 'Sales person',
  `estimated_value` decimal(15,2) DEFAULT NULL,
  `expected_close_date` date DEFAULT NULL,
  `lead_score` int(11) DEFAULT 5,
  `requirements` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_contact_date` date DEFAULT NULL,
  `next_follow_up_date` date DEFAULT NULL,
  `follow_up_notes` text DEFAULT NULL,
  `converted_to_customer_id` int(11) DEFAULT NULL,
  `conversion_date` date DEFAULT NULL,
  `lost_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales leads management';

--
-- Dumping data for table `leads`
--

INSERT INTO `leads` (`lead_id`, `company_id`, `lead_number`, `company_name`, `industry`, `company_size`, `website`, `contact_person`, `job_title`, `lead_source`, `lead_status`, `full_name`, `email`, `phone`, `address`, `city`, `country`, `source`, `campaign_id`, `status`, `alternative_phone`, `interested_in`, `budget_range`, `preferred_location`, `preferred_plot_size`, `assigned_to`, `estimated_value`, `expected_close_date`, `lead_score`, `requirements`, `notes`, `last_contact_date`, `next_follow_up_date`, `follow_up_notes`, `converted_to_customer_id`, `conversion_date`, `lost_reason`, `created_at`, `updated_at`, `created_by`, `is_active`) VALUES
(2, 3, 'LEAD-2025-7387', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer001', '', '0762254152', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '5M', 'Any', '400SQM', 11, NULL, NULL, 5, 'Plot near the road', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 09:36:29', '2025-12-23 09:36:29', 11, 1),
(3, 3, 'LEAD-2025-8332', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer002', '', '0756661599', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '2M', '', '400SQM', 11, NULL, NULL, 5, 'Area around beach ', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 09:45:47', '2025-12-23 09:45:47', 11, 1),
(4, 3, 'LEAD-2025-4948', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer003', '', '0784220261', '', 'Dar es salaam', 'Tanzania', 'website', NULL, 'new', '', 'plot_purchase', '6M', 'Any', '900SQM', 11, NULL, NULL, 5, 'Plot near vikindu', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 09:49:41', '2025-12-23 09:49:41', 11, 1),
(5, 3, 'LEAD-2025-3520', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer004', '', '0620489625', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '4M', 'KIBAHA', '700SQM', 11, NULL, NULL, 5, 'PLOT NEAR TUMBI HOSPITAL', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 09:54:24', '2025-12-23 11:29:19', 11, 1),
(6, 3, 'LEAD-2025-6948', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer005', '', '0766957489', '', 'Dar es salaam', 'Tanzania', 'website', NULL, 'new', '', 'plot_purchase', '6M', 'KIBAHA', '400SQM', 11, NULL, NULL, 5, 'PLOT KIBAHA', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 09:57:54', '2025-12-23 11:28:46', 11, 1),
(7, 3, 'LEAD-2025-7566', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer006', '', '0784114138', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '8M', 'Any', '554SQM', 11, NULL, NULL, 5, 'ANY', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 10:03:05', '2025-12-23 10:03:05', 11, 1),
(8, 3, 'LEAD-2025-8512', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer007', '', '07184114138', '', 'Dar es salaam', 'Tanzania', 'website', NULL, 'new', '', 'plot_purchase', '5M', 'Any', '200SQM', 11, NULL, NULL, 5, 'ANY', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 10:55:14', '2025-12-23 10:55:14', 11, 1),
(9, 3, 'LEAD-2025-9009', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer008', '', '0752904141', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '100M', 'Any', '7000SQM', 11, NULL, NULL, 5, 'ANY', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 10:59:08', '2025-12-23 10:59:08', 11, 1),
(10, 3, 'LEAD-2025-7806', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer009', '', '0747479805', '', 'Dar es salaam', 'Tanzania', 'website', NULL, 'new', '', 'plot_purchase', '2M', 'VUMILIA UKOONI', '200SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:03:23', '2025-12-23 11:03:23', 11, 1),
(11, 3, 'LEAD-2025-4046', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer010', '', '0716006787', '', 'Dar es salaam', 'Tanzania', 'email_campaign', NULL, 'new', '', 'plot_purchase', '6M', 'KIBAHA', '300SQM', 11, NULL, NULL, 5, '', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:05:47', '2025-12-23 11:05:47', 11, 1),
(12, 3, 'LEAD-2025-3873', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer011', '', '0716006787', '', 'Dar es salaam', 'Tanzania', 'social_media', NULL, 'new', '', 'plot_purchase', '5M', 'VUMILIA UKOONI', '200SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:07:52', '2025-12-23 11:07:52', 11, 1),
(13, 3, 'LEAD-2025-6298', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer012', '', '0686509319', '', 'Dar es salaam', 'Tanzania', 'event', NULL, 'new', '', 'plot_purchase', '8M', 'Any', '900SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:11:26', '2025-12-23 11:11:26', 11, 1),
(14, 3, 'LEAD-2025-3252', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer013', '', '0754974679', '', 'Dar es salaam', 'Tanzania', 'event', NULL, 'new', '', 'plot_purchase', '', 'Any', '200SQM', 11, NULL, NULL, 5, 'ANY', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:13:55', '2025-12-23 11:13:55', 11, 1),
(15, 3, 'LEAD-2025-7401', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer013', '', '0716006787', '', 'Dar es salaam', 'Tanzania', 'partner', NULL, 'new', '', 'plot_purchase', '5M', 'Any', '900SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:18:06', '2025-12-23 11:18:06', 11, 1),
(16, 3, 'LEAD-2025-3447', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer014', '', '0678683227', '', 'Dar es salaam', 'Tanzania', 'partner', NULL, 'new', '', 'plot_purchase', '6M', 'CHANIKA', '200SQM', 11, NULL, NULL, 5, 'ANY', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:20:12', '2025-12-23 11:20:12', 11, 1),
(17, 3, 'LEAD-2025-8394', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'Customer015', '', '0763497479', '', 'Dar es salaam', 'Tanzania', 'referral', NULL, 'new', '', '', '8M', 'Any', '900SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:22:41', '2025-12-23 11:22:41', 11, 1),
(18, 3, 'LEAD-2025-1865', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'IVAN', '', '0767801773', '', '', 'Tanzania', 'advertisement', NULL, 'new', '', 'plot_purchase', '2M', 'Any', '100SQM', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:24:46', '2025-12-23 11:30:13', 11, 1),
(19, 3, 'LEAD-2025-8934', '', NULL, NULL, NULL, '', NULL, 'website', 'new', 'ROJA', '', '0795434470', '', 'Dar es salaam', 'Tanzania', 'other', NULL, 'new', '', 'plot_purchase', '5M', 'PINGO', 'HEKA2', 11, NULL, NULL, 5, 'Any', '', NULL, '2025-12-26', NULL, NULL, NULL, NULL, '2025-12-23 11:26:38', '2025-12-23 11:27:56', 11, 1);

-- --------------------------------------------------------

--
-- Table structure for table `leave_applications`
--

CREATE TABLE `leave_applications` (
  `leave_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `application_date` date NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `leave_type_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `leave_type_name` varchar(100) NOT NULL,
  `leave_code` varchar(20) DEFAULT NULL,
  `days_per_year` int(11) DEFAULT 0,
  `is_paid` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payments`
--

CREATE TABLE `loan_payments` (
  `payment_id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `principal_paid` decimal(15,2) NOT NULL,
  `interest_paid` decimal(15,2) NOT NULL,
  `total_paid` decimal(15,2) NOT NULL,
  `payment_method` enum('salary_deduction','bank_transfer','cash','cheque') NOT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_repayment_schedule`
--

CREATE TABLE `loan_repayment_schedule` (
  `schedule_id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_status` enum('pending','paid','partial','overdue') DEFAULT 'pending',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `balance_outstanding` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_types`
--

CREATE TABLE `loan_types` (
  `loan_type_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `max_term_months` int(11) DEFAULT NULL,
  `interest_rate` decimal(5,2) DEFAULT 0.00,
  `requires_guarantor` tinyint(1) DEFAULT 0,
  `requires_collateral` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL,
  `username` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `is_successful` tinyint(1) NOT NULL,
  `failure_reason` varchar(200) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Login attempt tracking for security';

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`attempt_id`, `username`, `ip_address`, `user_agent`, `is_successful`, `failure_reason`, `attempted_at`) VALUES
(43, 'marketing.mkumbiinvestment', '169.255.114.66', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 1, NULL, '2025-12-24 12:57:15'),
(74, 'admin', '217.29.129.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 1, NULL, '2025-12-29 05:21:45');

-- --------------------------------------------------------

--
-- Table structure for table `notification_templates`
--

CREATE TABLE `notification_templates` (
  `template_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `template_code` varchar(50) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` enum('email','sms','system','print') NOT NULL,
  `trigger_event` varchar(100) DEFAULT NULL COMMENT 'payment_received, contract_signed, etc',
  `subject` varchar(200) DEFAULT NULL,
  `message_body` text NOT NULL,
  `available_variables` text DEFAULT NULL COMMENT 'JSON array of available placeholders',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notification message templates';

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_number` varchar(50) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_money','cheque','card') DEFAULT 'cash',
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `depositor_name` varchar(255) DEFAULT NULL,
  `deposit_bank` varchar(255) DEFAULT NULL,
  `deposit_account` varchar(100) DEFAULT NULL,
  `transfer_from_bank` varchar(255) DEFAULT NULL,
  `transfer_from_account` varchar(100) DEFAULT NULL,
  `transfer_to_bank` varchar(255) DEFAULT NULL,
  `transfer_to_account` varchar(100) DEFAULT NULL,
  `mobile_money_provider` varchar(100) DEFAULT NULL,
  `mobile_money_number` varchar(50) DEFAULT NULL,
  `mobile_money_name` varchar(255) DEFAULT NULL,
  `to_account_id` int(11) DEFAULT NULL,
  `cash_transaction_id` int(11) DEFAULT NULL,
  `cheque_transaction_id` int(11) DEFAULT NULL,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `receipt_number` varchar(50) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('pending_approval','pending','approved','rejected','cancelled') DEFAULT 'pending_approval' COMMENT 'Payment status with approval workflow',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL COMMENT 'Reason for rejection',
  `rejected_by` int(11) DEFAULT NULL COMMENT 'User who rejected payment',
  `rejected_at` timestamp NULL DEFAULT NULL COMMENT 'When payment was rejected',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL COMMENT 'User who submitted payment',
  `submitted_at` timestamp NULL DEFAULT NULL COMMENT 'When payment was submitted',
  `payment_type` enum('down_payment','installment','full_payment','service_payment','refund','other') DEFAULT 'installment',
  `payment_category` enum('down_payment','installment','full_payment','penalty','other') DEFAULT 'installment',
  `voucher_number` varchar(50) DEFAULT NULL,
  `is_reconciled` tinyint(1) DEFAULT 0,
  `reconciled_at` datetime DEFAULT NULL,
  `reconciliation_date` date DEFAULT NULL,
  `reconciled_by` int(11) DEFAULT NULL,
  `expected_down_payment` decimal(15,2) DEFAULT 0.00 COMMENT 'Required down payment amount',
  `actual_down_payment_paid` decimal(15,2) DEFAULT 0.00 COMMENT 'Total down payment received',
  `down_payment_balance` decimal(15,2) DEFAULT 0.00 COMMENT 'Remaining down payment',
  `installment_number` int(11) DEFAULT NULL COMMENT 'Which installment number',
  `is_down_payment_complete` tinyint(1) DEFAULT 0,
  `receipt_generated_at` datetime DEFAULT NULL,
  `payment_stage` varchar(50) DEFAULT NULL COMMENT 'down_payment or installment',
  `expected_amount` decimal(15,2) DEFAULT NULL COMMENT 'Expected amount for this stage',
  `is_partial` tinyint(1) DEFAULT 0 COMMENT 'Is this a partial payment',
  `stage_balance_before` decimal(15,2) DEFAULT NULL COMMENT 'Balance before this payment',
  `stage_balance_after` decimal(15,2) DEFAULT NULL COMMENT 'Balance after this payment'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment transactions';

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `company_id`, `reservation_id`, `payment_date`, `payment_number`, `amount`, `payment_method`, `bank_name`, `account_number`, `transaction_reference`, `depositor_name`, `deposit_bank`, `deposit_account`, `transfer_from_bank`, `transfer_from_account`, `transfer_to_bank`, `transfer_to_account`, `mobile_money_provider`, `mobile_money_number`, `mobile_money_name`, `to_account_id`, `cash_transaction_id`, `cheque_transaction_id`, `tax_amount`, `receipt_number`, `receipt_path`, `remarks`, `status`, `approved_by`, `approved_at`, `rejection_reason`, `rejected_by`, `rejected_at`, `created_at`, `created_by`, `submitted_by`, `submitted_at`, `payment_type`, `payment_category`, `voucher_number`, `is_reconciled`, `reconciled_at`, `reconciliation_date`, `reconciled_by`, `expected_down_payment`, `actual_down_payment_paid`, `down_payment_balance`, `installment_number`, `is_down_payment_complete`, `receipt_generated_at`, `payment_stage`, `expected_amount`, `is_partial`, `stage_balance_before`, `stage_balance_after`) VALUES
(21, 3, 25, '2025-12-29', 'PAY-2025-0001', 200000.00, 'bank_transfer', 'lazaro mpuya', NULL, '', NULL, NULL, NULL, 'CRDB Bank', '01526772009', NULL, NULL, NULL, NULL, NULL, 7, NULL, NULL, 0.00, 'REC-2025-0001', NULL, '', 'approved', 9, '2025-12-29 06:14:55', NULL, NULL, NULL, '2025-12-29 06:14:13', 9, 9, '2025-12-29 06:14:13', 'installment', 'installment', NULL, 0, NULL, NULL, NULL, 0.00, 0.00, 0.00, NULL, 0, '2025-12-29 11:44:55', 'down_payment', 400000.00, 1, 400000.00, 200000.00);

--
-- Triggers `payments`
--
DELIMITER $$
CREATE TRIGGER `after_payment_status_change` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO `payment_approvals` (
            `payment_id`,
            `company_id`,
            `action`,
            `action_by`,
            `previous_status`,
            `new_status`,
            `comments`
        ) VALUES (
            NEW.payment_id,
            NEW.company_id,
            CASE 
                WHEN NEW.status = 'approved' THEN 'approved'
                WHEN NEW.status = 'rejected' THEN 'rejected'
                WHEN NEW.status = 'cancelled' THEN 'cancelled'
                ELSE 'submitted'
            END,
            COALESCE(NEW.approved_by, NEW.rejected_by, NEW.created_by),
            OLD.status,
            NEW.status,
            CASE 
                WHEN NEW.status = 'rejected' THEN NEW.rejection_reason
                ELSE NULL
            END
        );
        
        IF NEW.status = 'approved' THEN
            UPDATE `payment_schedules`
            SET `payment_status` = 'paid',
                `is_paid` = 1,
                `paid_amount` = NEW.amount,
                `paid_date` = NEW.payment_date
            WHERE `payment_id` = NEW.payment_id
              AND `company_id` = NEW.company_id;
        ELSEIF NEW.status = 'rejected' THEN
            UPDATE `payment_schedules`
            SET `payment_status` = 'unpaid',
                `payment_id` = NULL
            WHERE `payment_id` = NEW.payment_id
              AND `company_id` = NEW.company_id;
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE `payment_allocations` (
  `allocation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `allocation_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment allocations to invoices';

-- --------------------------------------------------------

--
-- Table structure for table `payment_approvals`
--

CREATE TABLE `payment_approvals` (
  `approval_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `payment_type` enum('project_creditor','expense','petty_cash','other') DEFAULT 'project_creditor',
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `action` enum('submitted','approved','rejected','cancelled') NOT NULL,
  `action_by` int(11) NOT NULL,
  `action_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `comments` text DEFAULT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_approvals`
--

INSERT INTO `payment_approvals` (`approval_id`, `payment_id`, `payment_type`, `status`, `approved_by`, `approval_date`, `company_id`, `action`, `action_by`, `action_at`, `comments`, `previous_status`, `new_status`) VALUES
(30, 19, 'project_creditor', 'pending', NULL, NULL, 3, 'approved', 9, '2025-12-29 05:35:31', NULL, 'pending_approval', 'approved'),
(31, 20, 'project_creditor', 'pending', NULL, NULL, 3, 'approved', 9, '2025-12-29 06:05:46', NULL, 'pending_approval', 'approved'),
(32, 21, 'project_creditor', 'pending', NULL, NULL, 3, 'approved', 9, '2025-12-29 06:14:55', NULL, 'pending_approval', 'approved');

-- --------------------------------------------------------

--
-- Table structure for table `payment_plan_schedule`
--

CREATE TABLE `payment_plan_schedule` (
  `schedule_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(15,2) NOT NULL,
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `balance` decimal(15,2) NOT NULL,
  `status` enum('pending','paid','partial','overdue','waived') DEFAULT 'pending',
  `invoice_id` int(11) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `days_overdue` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment plan installment schedule';

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipts`
--

CREATE TABLE `payment_receipts` (
  `receipt_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `amount_received` decimal(15,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_for` varchar(255) DEFAULT NULL COMMENT 'Description of what payment is for',
  `received_by` int(11) NOT NULL,
  `status` enum('draft','issued','cancelled') DEFAULT 'issued',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `generated_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_recovery`
--

CREATE TABLE `payment_recovery` (
  `recovery_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `recovery_number` varchar(50) NOT NULL,
  `recovery_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `total_debt` decimal(15,2) NOT NULL,
  `amount_recovered` decimal(15,2) DEFAULT 0.00,
  `outstanding_balance` decimal(15,2) GENERATED ALWAYS AS (`total_debt` - `amount_recovered`) STORED,
  `recovery_method` enum('legal_action','negotiation','payment_plan','asset_seizure','write_off') NOT NULL,
  `status` enum('initiated','in_progress','partially_recovered','fully_recovered','written_off') DEFAULT 'initiated',
  `assigned_to` int(11) DEFAULT NULL COMMENT 'Recovery officer',
  `follow_up_date` date DEFAULT NULL,
  `resolution_date` date DEFAULT NULL,
  `legal_notice_path` varchar(255) DEFAULT NULL,
  `agreement_path` varchar(255) DEFAULT NULL,
  `recovery_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment recovery tracking';

-- --------------------------------------------------------

--
-- Table structure for table `payment_schedules`
--

CREATE TABLE `payment_schedules` (
  `schedule_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL COMMENT '1, 2, 3... up to total periods',
  `due_date` date NOT NULL,
  `installment_amount` decimal(15,2) NOT NULL,
  `is_paid` tinyint(1) DEFAULT 0,
  `payment_status` enum('unpaid','pending_approval','paid','rejected','overdue') DEFAULT 'unpaid' COMMENT 'Payment status for this installment',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `payment_id` int(11) DEFAULT NULL COMMENT 'Links to actual payment',
  `paid_date` date DEFAULT NULL,
  `is_overdue` tinyint(1) DEFAULT 0,
  `days_overdue` int(11) DEFAULT 0,
  `late_fee` decimal(15,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auto-generated payment schedules';

-- --------------------------------------------------------

--
-- Table structure for table `payment_statements`
--

CREATE TABLE `payment_statements` (
  `statement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `statement_number` varchar(50) NOT NULL,
  `statement_date` date NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `total_charges` decimal(15,2) DEFAULT 0.00,
  `total_payments` decimal(15,2) DEFAULT 0.00,
  `closing_balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','sent','viewed') DEFAULT 'draft',
  `generated_by` int(11) NOT NULL,
  `generated_at` datetime DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_vouchers`
--

CREATE TABLE `payment_vouchers` (
  `voucher_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `voucher_number` varchar(50) NOT NULL,
  `voucher_type` enum('payment','receipt','refund','adjustment') NOT NULL,
  `voucher_date` date NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `voucher_pdf_path` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment vouchers and receipts';

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `payroll_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `payroll_month` int(11) NOT NULL,
  `payroll_year` int(11) NOT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('draft','processed','paid','cancelled') DEFAULT 'draft',
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_details`
--

CREATE TABLE `payroll_details` (
  `payroll_detail_id` int(11) NOT NULL,
  `payroll_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `basic_salary` decimal(15,2) NOT NULL,
  `allowances` decimal(15,2) DEFAULT 0.00,
  `overtime_pay` decimal(15,2) DEFAULT 0.00,
  `bonus` decimal(15,2) DEFAULT 0.00,
  `gross_salary` decimal(15,2) GENERATED ALWAYS AS (`basic_salary` + `allowances` + `overtime_pay` + `bonus`) STORED,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `nssf_amount` decimal(15,2) DEFAULT 0.00,
  `nhif_amount` decimal(15,2) DEFAULT 0.00,
  `loan_deduction` decimal(15,2) DEFAULT 0.00,
  `other_deductions` decimal(15,2) DEFAULT 0.00,
  `total_deductions` decimal(15,2) GENERATED ALWAYS AS (`tax_amount` + `nssf_amount` + `nhif_amount` + `loan_deduction` + `other_deductions`) STORED,
  `net_salary` decimal(15,2) GENERATED ALWAYS AS (`gross_salary` - `total_deductions`) STORED,
  `payment_status` enum('pending','paid') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payroll_details`
--

INSERT INTO `payroll_details` (`payroll_detail_id`, `payroll_id`, `employee_id`, `basic_salary`, `allowances`, `overtime_pay`, `bonus`, `tax_amount`, `nssf_amount`, `nhif_amount`, `loan_deduction`, `other_deductions`, `payment_status`, `payment_date`, `payment_reference`, `created_at`) VALUES
(1, 1, 1, 1200000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', NULL, NULL, '2025-12-12 15:32:56'),
(2, 1, 2, 3000000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', NULL, NULL, '2025-12-12 15:32:56');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permission_id` int(11) NOT NULL,
  `module_name` varchar(100) NOT NULL,
  `permission_code` varchar(100) NOT NULL,
  `permission_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System permissions';

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_accounts`
--

CREATE TABLE `petty_cash_accounts` (
  `petty_cash_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_code` varchar(20) DEFAULT '1112',
  `account_name` varchar(100) NOT NULL,
  `custodian_id` int(11) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `maximum_limit` decimal(15,2) DEFAULT 50000.00,
  `minimum_balance` decimal(15,2) DEFAULT 5000.00,
  `transaction_limit` decimal(15,2) DEFAULT 10000.00,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `last_replenishment_date` date DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_categories`
--

CREATE TABLE `petty_cash_categories` (
  `category_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(20) DEFAULT NULL,
  `account_code` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `petty_cash_categories`
--

INSERT INTO `petty_cash_categories` (`category_id`, `company_id`, `category_name`, `category_code`, `account_code`, `description`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'Office Supplies', 'OFFICE', '5100', 'Stationery, printing, and office consumables', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(2, 3, 'Transportation', 'TRANS', '5200', 'Taxi, fuel, parking, and travel expenses', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(3, 3, 'Meals & Entertainment', 'MEALS', '5300', 'Staff meals, refreshments, and entertainment', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(4, 3, 'Communication', 'COMM', '5400', 'Airtime, internet, courier services', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(5, 3, 'Utilities', 'UTIL', '5500', 'Electricity tokens, water, small utility payments', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(6, 3, 'Repairs & Maintenance', 'REPAIR', '5600', 'Minor repairs and maintenance', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(7, 3, 'Bank Charges', 'BANK', '5700', 'Bank fees, transaction charges', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35'),
(8, 3, 'Miscellaneous', 'MISC', '5900', 'Other miscellaneous expenses', 1, NULL, '2025-12-25 19:19:35', '2025-12-25 19:19:35');

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_reconciliations`
--

CREATE TABLE `petty_cash_reconciliations` (
  `reconciliation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reconciliation_number` varchar(50) NOT NULL,
  `reconciliation_date` date NOT NULL,
  `custodian_id` int(11) NOT NULL,
  `expected_balance` decimal(15,2) NOT NULL,
  `actual_balance` decimal(15,2) NOT NULL,
  `difference` decimal(15,2) NOT NULL,
  `variance_notes` text DEFAULT NULL,
  `reconciled_by` int(11) DEFAULT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','completed','variance_reported') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_replenishments`
--

CREATE TABLE `petty_cash_replenishments` (
  `replenishment_id` int(11) NOT NULL,
  `petty_cash_id` int(11) NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `request_date` date NOT NULL,
  `requested_amount` decimal(15,2) NOT NULL,
  `current_balance` decimal(15,2) NOT NULL,
  `justification` text NOT NULL,
  `status` enum('pending','approved','rejected','disbursed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_amount` decimal(15,2) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `disbursed_by` int(11) DEFAULT NULL,
  `disbursed_at` datetime DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_transactions`
--

CREATE TABLE `petty_cash_transactions` (
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('replenishment','expense','return') NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text NOT NULL,
  `payee` varchar(200) DEFAULT NULL,
  `custodian_id` int(11) NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money') DEFAULT 'cash',
  `receipt_number` varchar(100) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `account_code` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `petty_cash_transactions`
--

INSERT INTO `petty_cash_transactions` (`transaction_id`, `company_id`, `reference_number`, `transaction_date`, `transaction_type`, `category_id`, `amount`, `description`, `payee`, `custodian_id`, `payment_method`, `receipt_number`, `receipt_path`, `approval_status`, `approved_by`, `approved_at`, `approval_notes`, `account_code`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'PC-202500001', '2025-12-25', 'replenishment', NULL, 300000.00, 'cash in hand', 'jojo', 9, 'cash', '', '../../uploads/petty_cash/PC-202500001_1766690439.pdf', 'approved', 9, '2025-12-26 09:11:33', '', '', '', 9, '2025-12-25 19:20:39', '2025-12-26 09:11:33');

-- --------------------------------------------------------

--
-- Table structure for table `plots`
--

CREATE TABLE `plots` (
  `plot_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `plot_number` varchar(50) NOT NULL,
  `block_number` varchar(50) DEFAULT NULL,
  `area_sqm` decimal(10,2) NOT NULL DEFAULT 0.00,
  `area` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_per_sqm` decimal(15,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `final_price` decimal(15,2) GENERATED ALWAYS AS (`selling_price` - `discount_amount`) STORED,
  `plot_size` decimal(10,2) NOT NULL COMMENT 'Size in square meters',
  `price_per_unit` decimal(15,2) NOT NULL COMMENT 'Price per square meter',
  `total_price` decimal(15,2) GENERATED ALWAYS AS (`plot_size` * `price_per_unit`) STORED,
  `survey_plan_number` varchar(100) DEFAULT NULL,
  `town_plan_number` varchar(100) DEFAULT NULL,
  `gps_coordinates` varchar(200) DEFAULT NULL,
  `status` enum('available','reserved','sold','blocked') DEFAULT 'available',
  `corner_plot` tinyint(1) DEFAULT 0,
  `coordinates` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Individual plots/land parcels';

--
-- Dumping data for table `plots`
--

INSERT INTO `plots` (`plot_id`, `company_id`, `project_id`, `plot_number`, `block_number`, `area_sqm`, `area`, `price_per_sqm`, `selling_price`, `discount_amount`, `plot_size`, `price_per_unit`, `survey_plan_number`, `town_plan_number`, `gps_coordinates`, `status`, `corner_plot`, `coordinates`, `notes`, `is_active`, `created_at`, `updated_at`, `created_by`) VALUES
(10, 3, 4, '1', 'v', 0.00, 375.00, 30000.00, 11250000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-10 12:06:23', '2025-12-17 09:45:18', 6),
(11, 3, 4, '2', 'v', 0.00, 460.00, 30000.00, 13800000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-10 12:08:59', '2025-12-18 00:54:38', 6),
(12, 3, 4, '3', 'v', 0.00, 520.00, 30000.00, 15600000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:12:24', '2025-12-17 09:49:15', 6),
(13, 3, 4, '4', 'v', 0.00, 516.00, 30000.00, 15480000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:13:02', '2025-12-18 00:55:03', 6),
(14, 3, 4, '5', 'v', 0.00, 568.00, 30000.00, 17040000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:13:54', '2025-12-17 09:50:51', 6),
(15, 3, 4, '6', 'v', 0.00, 520.00, 30000.00, 15600000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:14:23', '2025-12-17 09:51:34', 6),
(16, 3, 4, '7', 'v', 0.00, 616.00, 30000.00, 18480000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:14:56', '2025-12-17 09:52:06', 6),
(17, 3, 4, '8', 'v', 0.00, 525.00, 30000.00, 15750000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:15:19', '2025-12-17 09:53:07', 6),
(18, 3, 4, '9', 'v', 0.00, 567.00, 30000.00, 17010000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:15:47', '2025-12-17 09:55:45', 6),
(19, 3, 4, '10', 'v', 0.00, 464.00, 30000.00, 13920000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 12:16:12', '2025-12-17 09:56:13', 6),
(25, 3, 4, '11', 'v', 0.00, 604.00, 35000.00, 21140000.00, 0.00, 0.00, 0.00, '', '', '', 'reserved', 0, '', '', 1, '2025-12-10 13:50:55', '2025-12-18 10:57:53', 6),
(26, 3, 4, '12', 'v', 0.00, 375.00, 30000.00, 11250000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-10 13:52:20', '2025-12-17 10:04:34', 6),
(27, 3, 5, '35', 'E', 0.00, 465.00, 10000.00, 4650000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-17 12:00:09', '2025-12-17 12:00:09', 9),
(28, 3, 5, '36', 'E', 0.00, 544.00, 9000.00, 4896000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-17 12:05:22', '2025-12-17 12:05:22', 9),
(29, 3, 5, '37', 'E', 0.00, 570.00, 9000.00, 5130000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:06:59', '2025-12-17 12:07:43', 9),
(30, 3, 5, '38', 'E', 0.00, 530.00, 10000.00, 5300000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:09:23', '2025-12-17 12:09:23', 9),
(31, 3, 5, '39', 'E', 0.00, 545.00, 10000.00, 5450000.00, 0.00, 0.00, 0.00, '', '', '', 'reserved', 0, '', '', 1, '2025-12-17 12:13:08', '2025-12-29 06:04:05', 9),
(32, 3, 5, '40', 'E', 0.00, 520.00, 8000.00, 4160000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:27:12', '2025-12-17 12:27:12', 9),
(33, 3, 5, '41', 'E', 0.00, 544.00, 10000.00, 5440000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:28:07', '2025-12-17 12:28:07', 9),
(34, 3, 5, '42', 'E', 0.00, 490.00, 8000.00, 3920000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:31:42', '2025-12-17 12:32:16', 9),
(35, 3, 5, '43', 'E', 0.00, 523.00, 10000.00, 5230000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-17 12:33:29', '2025-12-17 12:33:29', 9),
(36, 3, 5, '44', 'E', 0.00, 430.00, 8000.00, 3440000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-17 12:34:11', '2025-12-17 12:34:11', 9),
(37, 3, 5, '45', 'E', 0.00, 475.00, 10000.00, 4750000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-17 12:36:23', '2025-12-17 12:36:23', 9),
(38, 3, 5, '46', 'E', 0.00, 480.00, 8500.00, 4080000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:37:18', '2025-12-17 12:37:18', 9),
(39, 3, 5, '47', 'E', 0.00, 470.00, 8500.00, 3995000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:38:07', '2025-12-17 12:38:07', 9),
(40, 3, 5, '48', 'E', 0.00, 410.00, 8500.00, 3485000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:39:06', '2025-12-17 12:39:06', 9),
(41, 3, 5, '49', 'E', 0.00, 320.00, 10000.00, 3200000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-17 12:40:46', '2025-12-17 12:40:46', 9),
(42, 3, 6, '1', 'P', 0.00, 519.00, 8000.00, 4152000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-17 13:59:08', '2025-12-18 10:21:06', 9),
(43, 3, 6, '2', 'P', 0.00, 367.00, 8000.00, 2936000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-17 14:01:55', '2025-12-18 10:22:14', 9),
(45, 3, 6, '3', 'P', 0.00, 495.00, 10000.00, 4950000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'reserved', 0, '', '', 1, '2025-12-18 10:19:37', '2025-12-29 05:32:01', 11),
(46, 3, 6, '4', 'P', 0.00, 460.00, 8000.00, 3680000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:24:57', '2025-12-18 10:24:57', 11),
(47, 3, 6, '5', 'P', 0.00, 475.00, 7000.00, 3325000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:35:44', '2025-12-18 10:35:44', 11),
(48, 3, 6, '6', 'P', 0.00, 439.00, 7000.00, 3073000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'reserved', 0, '', '', 1, '2025-12-18 10:37:03', '2025-12-29 06:13:18', 11),
(49, 3, 6, '7', 'P', 0.00, 501.00, 7000.00, 3507000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:40:45', '2025-12-18 10:40:45', 11),
(50, 3, 6, '8', 'P', 0.00, 438.00, 7000.00, 3066000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:41:47', '2025-12-18 10:41:47', 11),
(51, 3, 6, '9', 'P', 0.00, 580.00, 7000.00, 4060000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:44:07', '2025-12-18 10:44:07', 11),
(52, 3, 6, '10', 'P', 0.00, 464.00, 7000.00, 3248000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 10:45:55', '2025-12-18 10:45:55', 11),
(53, 3, 6, '11', 'P', 0.00, 685.00, 7000.00, 4795000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 10:51:01', '2025-12-18 10:51:01', 11),
(54, 3, 6, '12', 'P', 0.00, 496.00, 7000.00, 3472000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 10:57:18', '2025-12-18 10:57:18', 11),
(55, 3, 6, '13', 'P', 0.00, 433.00, 7000.00, 3031000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:30:05', '2025-12-18 12:30:05', 11),
(56, 3, 6, '14', 'P', 0.00, 402.00, 7000.00, 2814000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:31:53', '2025-12-18 12:31:53', 11),
(57, 3, 6, '15', 'P', 0.00, 425.00, 7000.00, 2975000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 12:33:39', '2025-12-18 12:33:39', 11),
(58, 3, 6, '16', 'P', 0.00, 430.00, 7000.00, 3010000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 12:36:39', '2025-12-18 12:36:39', 11),
(59, 3, 6, '17', 'P', 0.00, 432.00, 7000.00, 3024000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:37:49', '2025-12-18 12:37:49', 11),
(60, 3, 6, '18', 'P', 0.00, 423.00, 7000.00, 2961000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:38:44', '2025-12-18 12:38:44', 11),
(61, 3, 6, '19', 'P', 0.00, 405.00, 7000.00, 2835000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:40:03', '2025-12-18 12:40:03', 11),
(62, 3, 6, '20', 'P', 0.00, 390.00, 7000.00, 2730000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:41:48', '2025-12-18 12:41:48', 11),
(63, 3, 6, '21', 'P', 0.00, 341.00, 7000.00, 2387000.00, 0.00, 0.00, 0.00, '', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 12:42:39', '2025-12-18 12:42:39', 11),
(64, 3, 6, '22', 'P', 0.00, 330.00, 7000.00, 2310000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 0, '', '', 1, '2025-12-18 12:45:08', '2025-12-18 12:45:08', 11),
(65, 3, 6, '23', 'P', 0.00, 390.00, 7000.00, 2730000.00, 0.00, 0.00, 0.00, 'E\'370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:46:57', '2025-12-18 12:46:57', 11),
(66, 3, 6, '24', 'P', 0.00, 334.00, 7000.00, 2338000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:49:39', '2025-12-18 13:08:31', 11),
(67, 3, 6, '25', 'P', 0.00, 528.00, 7000.00, 3696000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:51:41', '2025-12-18 12:51:41', 11),
(68, 3, 6, '26', 'P', 0.00, 507.00, 7000.00, 3549000.00, 0.00, 0.00, 0.00, '', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:58:10', '2025-12-18 12:58:10', 11),
(69, 3, 6, '27', 'P', 0.00, 443.00, 7000.00, 3101000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 12:59:35', '2025-12-18 12:59:35', 11),
(70, 3, 6, '28', 'P', 0.00, 442.00, 7000.00, 3094000.00, 0.00, 0.00, 0.00, 'E,370/53', '19/KSW/421/042023', '', 'available', 1, '', '', 1, '2025-12-18 13:21:17', '2025-12-18 13:21:17', 11),
(71, 3, 15, '374', 'G', 0.00, 554.00, 5000.00, 2770000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:32:21', '2025-12-18 13:32:21', 11),
(72, 3, 15, '375', 'G', 0.00, 665.00, 5000.00, 3325000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:33:43', '2025-12-18 13:33:43', 11),
(73, 3, 15, '376', 'G', 0.00, 451.00, 5000.00, 2255000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:35:24', '2025-12-18 13:35:24', 11),
(74, 3, 15, '377', 'G', 0.00, 451.00, 5000.00, 2255000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:36:41', '2025-12-18 13:36:41', 11),
(75, 3, 15, '378', 'G', 0.00, 452.00, 5000.00, 2260000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:37:31', '2025-12-18 13:37:31', 11),
(77, 3, 15, '379', 'G', 0.00, 450.00, 5000.00, 2250000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:39:41', '2025-12-18 13:39:41', 11),
(78, 3, 15, '400', 'G', 0.00, 450.00, 5000.00, 2250000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:42:21', '2025-12-18 13:42:21', 11),
(79, 3, 15, '401', 'G', 0.00, 450.00, 5000.00, 2250000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:43:41', '2025-12-18 13:43:41', 11),
(80, 3, 15, '402', 'G', 0.00, 450.00, 5000.00, 2250000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:45:26', '2025-12-18 13:45:26', 11),
(81, 3, 15, '403', 'G', 0.00, 448.00, 5000.00, 2240000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:46:54', '2025-12-18 13:46:54', 11),
(82, 3, 15, '404', 'G', 0.00, 448.00, 5000.00, 2240000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:49:30', '2025-12-18 13:49:30', 11),
(83, 3, 15, '405', 'G', 0.00, 476.00, 5000.00, 2380000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:50:29', '2025-12-18 13:50:29', 11),
(84, 3, 15, '406', 'G', 0.00, 395.00, 5000.00, 1975000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:51:52', '2025-12-18 13:51:52', 11),
(85, 3, 15, '407', 'G', 0.00, 488.00, 5000.00, 2440000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:53:36', '2025-12-18 13:53:36', 11),
(86, 3, 15, '408', 'G', 0.00, 806.00, 5000.00, 4030000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 13:55:03', '2025-12-18 13:55:03', 11),
(87, 3, 15, '409', 'G', 0.00, 604.00, 5000.00, 3020000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:58:23', '2025-12-18 13:58:23', 11),
(88, 3, 15, '410', 'G', 0.00, 601.00, 5000.00, 3005000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 13:59:21', '2025-12-18 13:59:21', 11),
(89, 3, 15, '411', 'G', 0.00, 655.00, 5000.00, 3275000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:00:32', '2025-12-18 14:00:32', 11),
(90, 3, 15, '412', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:01:34', '2025-12-18 14:01:34', 11),
(91, 3, 15, '413', 'G', 0.00, 652.00, 5000.00, 3260000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:02:17', '2025-12-18 14:02:17', 11),
(92, 3, 15, '414', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:03:15', '2025-12-18 14:03:15', 11),
(93, 3, 15, '415', 'G', 0.00, 643.00, 5000.00, 3215000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:04:38', '2025-12-18 14:04:38', 11),
(94, 3, 15, '416', 'G', 0.00, 601.00, 5000.00, 3005000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:05:50', '2025-12-18 14:05:50', 11),
(95, 3, 15, '417', 'G', 0.00, 642.00, 5000.00, 3210000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:07:15', '2025-12-18 14:07:15', 11),
(96, 3, 15, '418', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:08:47', '2025-12-18 14:08:47', 11),
(97, 3, 15, '419', 'G', 0.00, 643.00, 5000.00, 3215000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:10:10', '2025-12-18 14:10:10', 11),
(98, 3, 15, '420', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:12:27', '2025-12-18 14:12:27', 11),
(99, 3, 15, '421', 'G', 0.00, 645.00, 5000.00, 3225000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-18 14:13:08', '2025-12-18 14:13:08', 11),
(100, 3, 15, '422', 'G', 0.00, 494.00, 5000.00, 2470000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 14:14:29', '2025-12-18 14:16:22', 11),
(101, 3, 15, '423', 'G', 0.00, 676.00, 5000.00, 3380000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-18 14:17:41', '2025-12-18 14:17:41', 11),
(102, 3, 15, '425', 'G', 0.00, 588.00, 5000.00, 2940000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 06:52:22', '2025-12-19 06:52:22', 11),
(103, 3, 15, '426', 'G', 0.00, 650.00, 5000.00, 3250000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 06:53:14', '2025-12-19 06:53:14', 11),
(104, 3, 15, '427', 'G', 0.00, 589.00, 5000.00, 2945000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:36:57', '2025-12-19 07:36:57', 11),
(105, 3, 15, '428', 'G', 0.00, 601.00, 5000.00, 3005000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:37:41', '2025-12-19 07:37:41', 11),
(106, 3, 15, '429', 'G', 0.00, 602.00, 5000.00, 3010000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:39:46', '2025-12-19 07:39:46', 11),
(107, 3, 15, '430', 'G', 0.00, 607.00, 5000.00, 3035000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:40:44', '2025-12-19 07:40:44', 11),
(108, 3, 15, '431', 'G', 0.00, 595.00, 5000.00, 2975000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:41:18', '2025-12-19 07:41:18', 11),
(109, 3, 15, '432', 'G', 0.00, 596.00, 5000.00, 2980000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:41:50', '2025-12-19 07:41:50', 11),
(110, 3, 15, '433', 'G', 0.00, 594.00, 5000.00, 2970000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:42:28', '2025-12-19 07:42:28', 11),
(111, 3, 15, '434', 'G', 0.00, 519.00, 5000.00, 2595000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:43:19', '2025-12-19 07:43:19', 11),
(112, 3, 15, '435', 'G', 0.00, 718.00, 5000.00, 3590000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:44:08', '2025-12-19 07:44:08', 11),
(113, 3, 15, '436', 'G', 0.00, 528.00, 5000.00, 2640000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:45:11', '2025-12-19 07:45:11', 11),
(114, 3, 15, '437', 'G', 0.00, 573.00, 5000.00, 2865000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:47:16', '2025-12-19 07:47:16', 11),
(115, 3, 15, '438', 'G', 0.00, 736.00, 5000.00, 3680000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:48:39', '2025-12-19 07:48:39', 11),
(116, 3, 15, '439', 'G', 0.00, 580.00, 5000.00, 2900000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:49:38', '2025-12-19 07:49:38', 11),
(117, 3, 15, '440', 'G', 0.00, 613.00, 5000.00, 3065000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:50:35', '2025-12-19 07:50:35', 11),
(118, 3, 15, '441', 'G', 0.00, 356.00, 5000.00, 1780000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:51:41', '2025-12-19 07:51:41', 11),
(119, 3, 15, '442', 'G', 0.00, 781.00, 5000.00, 3905000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:52:22', '2025-12-19 07:52:22', 11),
(120, 3, 15, '443', 'G', 0.00, 1018.00, 5000.00, 5090000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:54:05', '2025-12-19 07:54:05', 11),
(121, 3, 15, '444', 'G', 0.00, 815.00, 5000.00, 4075000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:56:21', '2025-12-19 07:56:21', 11),
(122, 3, 15, '445', 'G', 0.00, 886.00, 5000.00, 4430000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:57:34', '2025-12-19 07:57:34', 11),
(123, 3, 15, '446', 'G', 0.00, 791.00, 5000.00, 3955000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 07:58:28', '2025-12-19 07:58:28', 11),
(124, 3, 15, '447', 'G', 0.00, 1127.00, 5000.00, 5635000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 07:59:11', '2025-12-19 07:59:11', 11),
(125, 3, 15, '448', 'G', 0.00, 1202.00, 5000.00, 6010000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:00:00', '2025-12-19 08:00:00', 11),
(126, 3, 15, '449', 'G', 0.00, 731.00, 5000.00, 3655000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:01:11', '2025-12-19 08:01:11', 11),
(127, 3, 15, '450', 'G', 0.00, 1190.00, 5000.00, 5950000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:02:23', '2025-12-19 08:02:23', 11),
(128, 3, 15, '451', 'G', 0.00, 764.00, 5000.00, 3820000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:03:15', '2025-12-19 08:03:15', 11),
(129, 3, 15, '452', 'G', 0.00, 712.00, 5000.00, 3560000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:04:15', '2025-12-19 08:04:15', 11),
(130, 3, 15, '453', 'G', 0.00, 821.00, 5000.00, 4105000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:05:08', '2025-12-19 09:05:01', 11),
(131, 3, 15, '454', 'G', 0.00, 493.00, 5000.00, 2465000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:06:44', '2025-12-19 08:06:44', 11),
(132, 3, 15, '455', 'G', 0.00, 321.00, 5000.00, 1605000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 08:07:41', '2025-12-19 08:07:41', 11),
(133, 3, 15, '456', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:08:36', '2025-12-19 08:08:36', 11),
(134, 3, 15, '457', 'G', 0.00, 541.00, 5000.00, 2705000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:11:11', '2025-12-19 08:11:11', 11),
(135, 3, 15, '458', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:11:59', '2025-12-19 08:11:59', 11),
(136, 3, 15, '459', 'G', 0.00, 541.00, 5000.00, 2705000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:12:51', '2025-12-19 08:12:51', 11),
(137, 3, 15, '460', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:14:01', '2025-12-19 08:14:01', 11),
(138, 3, 15, '461', 'G', 0.00, 542.00, 5000.00, 2710000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:14:58', '2025-12-19 08:14:58', 11),
(139, 3, 15, '462', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:16:03', '2025-12-19 08:16:03', 11),
(140, 3, 15, '463', 'G', 0.00, 541.00, 5000.00, 2705000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:17:07', '2025-12-19 08:17:07', 11),
(141, 3, 15, '464', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:18:11', '2025-12-19 08:18:11', 11),
(142, 3, 15, '465', 'G', 0.00, 540.00, 5000.00, 2700000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:19:07', '2025-12-19 08:19:07', 11),
(143, 3, 15, '466', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:20:03', '2025-12-19 08:20:03', 11),
(144, 3, 15, '467', 'G', 0.00, 539.00, 5000.00, 2695000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 08:24:55', '2025-12-19 08:24:55', 11),
(145, 3, 16, '1', '', 0.00, 530.00, 15000.00, 7950000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, 'BOKOMNEMELA - MKALAMBATI', 'Commercial/Residential', 1, '2025-12-19 08:52:13', '2025-12-19 08:52:13', 9),
(146, 3, 16, '2', '', 0.00, 460.00, 15000.00, 6900000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 08:54:53', '2025-12-19 08:54:53', 9),
(147, 3, 16, '3', '', 0.00, 430.00, 15000.00, 6450000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 08:56:12', '2025-12-19 08:56:12', 9),
(148, 3, 16, '4', '', 0.00, 500.00, 15000.00, 7500000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:00:22', '2025-12-19 09:00:22', 9),
(149, 3, 16, '5', '', 0.00, 400.00, 15000.00, 6000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:08:57', '2025-12-19 09:19:33', 9),
(150, 3, 16, '6', '', 0.00, 490.00, 15000.00, 7350000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:11:43', '2025-12-19 09:11:43', 9),
(151, 3, 15, '468', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:12:09', '2025-12-19 09:12:09', 11),
(152, 3, 16, '7', '', 0.00, 470.00, 15000.00, 7050000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:12:55', '2025-12-19 09:12:55', 9),
(153, 3, 15, '469', 'G', 0.00, 539.00, 5000.00, 2695000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:13:12', '2025-12-19 09:13:12', 11),
(154, 3, 15, '470', 'G', 0.00, 420.00, 5000.00, 2100000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:13:58', '2025-12-19 09:13:58', 11),
(155, 3, 16, '8', '', 0.00, 440.00, 15000.00, 6600000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:14:03', '2025-12-19 09:14:03', 9),
(156, 3, 16, '9', '', 0.00, 500.00, 15000.00, 7500000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:15:30', '2025-12-19 09:15:30', 9),
(157, 3, 15, '471', 'G', 0.00, 539.00, 5000.00, 2695000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:15:33', '2025-12-19 09:15:33', 11),
(158, 3, 15, '472', 'G', 0.00, 420.00, 4500.00, 1890000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:16:46', '2025-12-23 13:34:38', 11),
(159, 3, 16, '10', '', 0.00, 490.00, 13000.00, 6370000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', 'COMMERCIAL/ RESIDENTIAL', 1, '2025-12-19 09:17:38', '2025-12-19 09:17:38', 9),
(160, 3, 15, '473', 'G', 0.00, 538.00, 5000.00, 2690000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:17:47', '2025-12-19 09:17:47', 11),
(161, 3, 15, '474', 'G', 0.00, 374.00, 5000.00, 1870000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:18:43', '2025-12-19 09:18:43', 11),
(162, 3, 16, '11', '', 0.00, 400.00, 15000.00, 6000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:18:57', '2025-12-19 09:18:57', 9),
(163, 3, 15, '475', 'G', 0.00, 592.00, 5000.00, 2960000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:19:42', '2025-12-19 09:19:42', 11),
(164, 3, 16, '12', '', 0.00, 480.00, 15000.00, 7200000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:21:02', '2025-12-19 09:21:02', 9),
(165, 3, 15, '476', 'G', 0.00, 474.00, 5000.00, 2370000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:21:02', '2025-12-19 09:21:02', 11),
(166, 3, 15, '477', 'G', 0.00, 575.00, 5000.00, 2875000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:21:49', '2025-12-19 09:21:49', 11),
(167, 3, 16, '13', '', 0.00, 470.00, 16000.00, 7520000.00, 0.00, 0.00, 0.00, '', '', '', 'reserved', 0, 'BOKOMNEMELA - MKALAMBATI', '', 1, '2025-12-19 09:21:53', '2025-12-19 10:24:55', 9),
(168, 3, 16, '14', '', 0.00, 440.00, 16000.00, 7040000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-19 09:22:55', '2025-12-19 10:05:18', 9),
(169, 3, 15, '478', 'G', 0.00, 421.00, 5000.00, 2105000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:22:59', '2025-12-19 09:22:59', 11),
(170, 3, 16, '15', '', 0.00, 480.00, 15000.00, 7200000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, 'BOKOMNEMELA- MKALAMBATI', '', 1, '2025-12-19 09:24:36', '2025-12-19 09:24:36', 9),
(171, 3, 15, '479', 'G', 0.00, 553.00, 5000.00, 2765000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:28:49', '2025-12-19 09:28:49', 11),
(172, 3, 15, '480', 'G', 0.00, 512.00, 5000.00, 2560000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:32:02', '2025-12-19 09:32:02', 11),
(173, 3, 15, '481', 'G', 0.00, 576.00, 5000.00, 2880000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:32:56', '2025-12-19 09:32:56', 11),
(174, 3, 15, '482', 'G', 0.00, 534.00, 5000.00, 2670000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:34:17', '2025-12-19 09:34:17', 11),
(175, 3, 15, '483', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:35:39', '2025-12-19 09:35:39', 11),
(176, 3, 15, '484', 'G', 0.00, 556.00, 5000.00, 2780000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 0, '', '', 1, '2025-12-19 09:36:47', '2025-12-19 09:36:47', 11),
(177, 3, 15, '485', 'G', 0.00, 600.00, 5000.00, 3000000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:38:08', '2025-12-19 09:38:08', 11),
(178, 3, 15, '486', 'G', 0.00, 576.00, 5000.00, 2880000.00, 0.00, 0.00, 0.00, 'E\'359/1155', '19/KBH/790/D42022', '', 'available', 1, '', '', 1, '2025-12-19 09:39:43', '2025-12-19 09:39:43', 11),
(179, 3, 19, '94', 'A', 0.00, 990.00, 150000.00, 148500000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:16:16', '2025-12-19 14:16:16', 11),
(180, 3, 19, '95', 'A', 0.00, 569.00, 150000.00, 85350000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:17:22', '2025-12-19 14:17:22', 11),
(181, 3, 19, '96', 'A', 0.00, 552.00, 150000.00, 82800000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:18:37', '2025-12-19 14:18:37', 11),
(182, 3, 19, '97', 'A', 0.00, 649.00, 150000.00, 97350000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:24:05', '2025-12-19 14:24:05', 11),
(183, 3, 19, '98', 'A', 0.00, 574.00, 150000.00, 86100000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:24:54', '2025-12-19 14:24:54', 11),
(185, 3, 19, '99', 'A', 0.00, 740.00, 150000.00, 111000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:25:43', '2025-12-19 14:25:43', 11),
(186, 3, 19, '100', 'A', 0.00, 596.00, 150000.00, 89400000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:27:24', '2025-12-19 15:17:10', 11),
(187, 3, 19, '101', 'A', 0.00, 791.00, 150000.00, 118650000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:29:27', '2025-12-22 11:57:16', 11),
(188, 3, 19, '104', 'A', 0.00, 450.00, 150000.00, 67500000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:30:35', '2025-12-19 14:30:35', 11),
(189, 3, 19, '105', 'A', 0.00, 400.00, 150000.00, 60000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:33:48', '2025-12-19 14:33:48', 11),
(190, 3, 19, '106', 'A', 0.00, 400.00, 150000.00, 60000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:35:11', '2025-12-19 14:35:11', 11),
(191, 3, 19, '108', 'A', 0.00, 400.00, 150000.00, 60000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:37:57', '2025-12-19 14:37:57', 11),
(192, 3, 19, '111', 'A', 0.00, 470.00, 150000.00, 70500000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 14:39:53', '2025-12-19 14:39:53', 11),
(193, 3, 19, '112', 'A', 0.00, 581.00, 150000.00, 87150000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:51:37', '2025-12-19 14:51:37', 11),
(194, 3, 19, '113', 'A', 0.00, 560.00, 150000.00, 84000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 14:52:57', '2025-12-19 14:52:57', 11),
(195, 3, 19, '114', 'A', 0.00, 411.00, 150000.00, 61650000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:54:37', '2025-12-19 14:54:37', 11),
(196, 3, 19, '115', 'A', 0.00, 600.00, 150000.00, 90000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 14:57:37', '2025-12-19 14:59:03', 11),
(197, 3, 19, '116', 'A', 0.00, 558.00, 150000.00, 83700000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 15:00:37', '2025-12-19 15:00:37', 11),
(198, 3, 19, '118', 'A', 0.00, 778.00, 150000.00, 116700000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:02:12', '2025-12-19 15:02:12', 11),
(199, 3, 19, '119', 'A', 0.00, 590.00, 150000.00, 88500000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 15:03:13', '2025-12-19 15:03:13', 11),
(200, 3, 19, '120', 'A', 0.00, 833.00, 150000.00, 124950000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:04:25', '2025-12-19 15:04:25', 11),
(201, 3, 19, '121', 'A', 0.00, 774.00, 150000.00, 116100000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:06:26', '2025-12-19 15:06:26', 11),
(202, 3, 19, '122', 'A', 0.00, 786.00, 150000.00, 117900000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:08:50', '2025-12-19 15:08:50', 11),
(203, 3, 19, '123', 'A', 0.00, 858.00, 150000.00, 128700000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:11:31', '2025-12-19 15:11:31', 11),
(204, 3, 19, '124', 'A', 0.00, 255.00, 150000.00, 38250000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 15:12:23', '2025-12-19 15:12:23', 11),
(205, 3, 19, '125', 'A', 0.00, 400.00, 150000.00, 60000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:13:16', '2025-12-19 15:13:16', 11),
(206, 3, 19, '102', 'A', 0.00, 352.00, 150000.00, 52800000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 1, '', '', 1, '2025-12-19 15:14:31', '2025-12-19 15:14:31', 11),
(207, 3, 19, '109', 'A', 0.00, 400.00, 150000.00, 60000000.00, 0.00, 0.00, 0.00, 'E\'315/3093', '19/KBH/157/072010B', '', 'available', 0, '', '', 1, '2025-12-19 15:15:37', '2025-12-19 15:15:37', 11),
(208, 3, 20, '25', 'E', 0.00, 433.00, 9000.00, 3897000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 1, '', '', 1, '2025-12-22 07:08:26', '2025-12-22 07:08:26', 11),
(209, 3, 20, '27', 'E', 0.00, 437.00, 9000.00, 3933000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:11:39', '2025-12-22 07:11:39', 11),
(210, 3, 20, '28', 'E', 0.00, 338.00, 9000.00, 3042000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 1, '', '', 1, '2025-12-22 07:12:43', '2025-12-22 07:12:43', 11),
(211, 3, 20, '30', 'E', 0.00, 431.00, 9000.00, 3879000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:14:40', '2025-12-22 07:14:40', 11),
(212, 3, 20, '32', 'E', 0.00, 520.00, 9000.00, 4680000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:17:54', '2025-12-22 07:17:54', 11),
(213, 3, 20, '33', 'E', 0.00, 444.00, 9000.00, 3996000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:19:00', '2025-12-22 07:19:00', 11),
(214, 3, 20, '38', 'E', 0.00, 336.00, 9000.00, 3024000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:23:08', '2025-12-22 07:23:08', 11),
(215, 3, 20, '40', 'E', 0.00, 326.00, 9000.00, 2934000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:24:25', '2025-12-22 07:24:25', 11),
(216, 3, 20, '41', 'E', 0.00, 383.00, 9000.00, 3447000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:27:11', '2025-12-22 07:27:11', 11),
(217, 3, 20, '43', 'E', 0.00, 387.00, 9000.00, 3483000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:28:25', '2025-12-22 07:28:25', 11),
(218, 3, 20, '45', 'E', 0.00, 409.00, 9000.00, 3681000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:30:10', '2025-12-22 07:30:10', 11),
(219, 3, 20, '46', 'E', 0.00, 285.00, 9000.00, 2565000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 1, '', '', 1, '2025-12-22 07:31:32', '2025-12-22 07:31:32', 11),
(220, 3, 20, '47', 'E', 0.00, 328.00, 9000.00, 2952000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:32:56', '2025-12-22 07:32:56', 11),
(221, 3, 20, '50', 'E', 0.00, 549.00, 9000.00, 4941000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 1, '', '', 1, '2025-12-22 07:34:20', '2025-12-22 07:34:20', 11),
(222, 3, 20, '52', 'E', 0.00, 576.00, 9000.00, 5184000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:35:16', '2025-12-22 07:35:16', 11),
(223, 3, 20, '54', 'E', 0.00, 482.00, 9000.00, 4338000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:36:14', '2025-12-22 07:36:14', 11),
(224, 3, 20, '56', 'E', 0.00, 503.00, 9000.00, 4527000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 0, '', '', 1, '2025-12-22 07:37:29', '2025-12-22 07:37:29', 11),
(225, 3, 20, '58', 'E', 0.00, 403.00, 9000.00, 3627000.00, 0.00, 0.00, 0.00, 'E\'359/1198', '19/MLD/30/082011', '', 'available', 1, '', '', 1, '2025-12-22 07:38:52', '2025-12-22 07:38:52', 11),
(226, 3, 22, '190', 'K', 0.00, 817.00, 8000.00, 6536000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 07:59:02', '2025-12-22 10:59:25', 11),
(227, 3, 22, '192', 'K', 0.00, 817.00, 8000.00, 6536000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:02:03', '2025-12-22 10:26:21', 11),
(228, 3, 22, '194', 'K', 0.00, 817.00, 8000.00, 6536000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:03:20', '2025-12-22 10:27:55', 11),
(229, 3, 22, '196', 'K', 0.00, 1160.00, 8000.00, 9280000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 1, '', '', 1, '2025-12-22 08:05:08', '2025-12-22 10:28:45', 11),
(230, 3, 22, '203', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:06:53', '2025-12-22 10:30:29', 11),
(231, 3, 22, '205', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:08:14', '2025-12-22 12:02:19', 11),
(232, 3, 22, '206', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:10:16', '2025-12-22 12:03:19', 11),
(233, 3, 22, '207', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:11:33', '2025-12-22 12:04:57', 11),
(234, 3, 22, '208', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:17:10', '2025-12-22 12:05:31', 11),
(235, 3, 22, '209', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:18:38', '2025-12-22 12:06:26', 11),
(236, 3, 22, '211', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:21:30', '2025-12-22 12:07:14', 11),
(237, 3, 22, '212', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:22:28', '2025-12-22 12:08:04', 11),
(238, 3, 22, '204', 'K', 0.00, 750.00, 8000.00, 6000000.00, 0.00, 0.00, 0.00, 'E\'376/475', 'TEM1/228/072016', '', 'available', 0, '', '', 1, '2025-12-22 08:25:00', '2025-12-22 12:00:21', 11),
(239, 3, 24, '1', 'E', 0.00, 680.00, 6000.00, 4080000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 12:41:48', '2025-12-22 12:41:48', 11),
(240, 3, 24, '2', 'E', 0.00, 431.00, 6000.00, 2586000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:42:56', '2025-12-22 12:42:56', 11),
(241, 3, 24, '3', 'E', 0.00, 646.00, 6000.00, 3876000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:44:29', '2025-12-22 12:44:29', 11),
(242, 3, 24, '4', 'E', 0.00, 452.00, 6000.00, 2712000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:46:28', '2025-12-22 12:46:28', 11),
(244, 3, 24, '5', 'E', 0.00, 661.00, 6000.00, 3966000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:47:12', '2025-12-22 12:47:12', 11),
(245, 3, 24, '6', 'E', 0.00, 469.00, 6000.00, 2814000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:48:22', '2025-12-22 12:48:22', 11),
(246, 3, 24, '7', 'E', 0.00, 680.00, 6000.00, 4080000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:50:04', '2025-12-22 12:50:04', 11),
(247, 3, 24, '8', 'E', 0.00, 484.00, 6000.00, 2904000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:50:59', '2025-12-22 12:50:59', 11),
(248, 3, 24, '9', 'E', 0.00, 696.00, 6000.00, 4176000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:52:08', '2025-12-22 12:52:08', 11),
(249, 3, 24, '10', 'E', 0.00, 466.00, 6000.00, 2796000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 12:53:58', '2025-12-22 12:53:58', 11),
(250, 3, 24, '11', 'E', 0.00, 448.00, 6000.00, 2688000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 12:58:23', '2025-12-22 12:58:23', 11),
(251, 3, 24, '12', 'E', 0.00, 512.00, 6000.00, 3072000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:00:02', '2025-12-22 13:00:02', 11),
(252, 3, 24, '13', 'E', 0.00, 711.00, 6000.00, 4266000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:02:05', '2025-12-22 13:02:05', 11),
(253, 3, 24, '14', 'E', 0.00, 532.00, 6000.00, 3192000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:02:56', '2025-12-22 13:02:56', 11),
(254, 3, 24, '15', 'E', 0.00, 732.00, 6000.00, 4392000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:06:17', '2025-12-22 13:06:17', 11),
(255, 3, 24, '16', 'E', 0.00, 803.00, 6000.00, 4818000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 13:07:51', '2025-12-22 13:07:51', 11),
(256, 3, 24, '17', 'E', 0.00, 1119.00, 6000.00, 6714000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 13:09:57', '2025-12-22 13:09:57', 11),
(257, 3, 24, '18', 'E', 0.00, 400.00, 6000.00, 2400000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:11:26', '2025-12-22 13:11:26', 11),
(258, 3, 24, '19', 'E', 0.00, 417.00, 6000.00, 2502000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:19:55', '2025-12-22 13:19:55', 11),
(259, 3, 24, '20', 'E', 0.00, 453.00, 6000.00, 2718000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:21:27', '2025-12-22 13:21:27', 11),
(260, 3, 24, '21', 'E', 0.00, 521.00, 6000.00, 3126000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:23:03', '2025-12-22 13:23:03', 11),
(261, 3, 24, '22', 'E', 0.00, 764.00, 6000.00, 4584000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 13:28:00', '2025-12-22 13:28:00', 11),
(262, 3, 24, '23', 'E', 0.00, 389.00, 6000.00, 2334000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 13:29:56', '2025-12-22 13:29:56', 11),
(263, 3, 24, '24', 'E', 0.00, 403.00, 6000.00, 2418000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:32:22', '2025-12-22 13:32:22', 11),
(264, 3, 24, '25', 'E', 0.00, 438.00, 6000.00, 2628000.00, 0.00, 0.00, 0.00, '', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:33:41', '2025-12-22 13:33:41', 11),
(265, 3, 24, '26', 'E', 0.00, 469.00, 6000.00, 2814000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:34:57', '2025-12-22 13:34:57', 11),
(266, 3, 24, '27', 'E', 0.00, 479.00, 6000.00, 2874000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:36:46', '2025-12-22 13:36:46', 11),
(267, 3, 24, '28', 'E', 0.00, 460.00, 6000.00, 2760000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:37:48', '2025-12-22 13:37:48', 11),
(268, 3, 24, '29', 'E', 0.00, 567.00, 6000.00, 3402000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 1, '', '', 1, '2025-12-22 13:38:59', '2025-12-22 13:38:59', 11),
(269, 3, 24, '30', 'E', 0.00, 482.00, 6000.00, 2892000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:40:03', '2025-12-22 13:40:03', 11),
(270, 3, 24, '31', 'E', 0.00, 460.00, 6000.00, 2760000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:41:26', '2025-12-22 13:41:26', 11),
(271, 3, 24, '32', 'E', 0.00, 439.00, 6000.00, 2634000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:42:50', '2025-12-22 13:42:50', 11),
(272, 3, 24, '33', 'E', 0.00, 416.00, 6000.00, 2496000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:45:13', '2025-12-22 13:45:13', 11),
(273, 3, 24, '34', 'E', 0.00, 405.00, 6000.00, 2430000.00, 0.00, 0.00, 0.00, 'E\'277/112', '19/KBA/275/122021', '', 'available', 0, '', '', 1, '2025-12-22 13:46:28', '2025-12-22 13:46:28', 11),
(274, 3, 25, '168', 'H', 0.00, 591.00, 8000.00, 4728000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 13:53:54', '2025-12-23 08:39:20', 11),
(275, 3, 25, '169', 'H', 0.00, 583.00, 8000.00, 4664000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 13:54:55', '2025-12-23 08:43:35', 11),
(276, 3, 25, '170', 'H', 0.00, 596.00, 8000.00, 4768000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 13:56:08', '2025-12-23 08:47:01', 11),
(277, 3, 25, '171', 'H', 0.00, 583.00, 8000.00, 4664000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 13:57:40', '2025-12-23 08:49:47', 11),
(278, 3, 25, '172', 'H', 0.00, 586.00, 8000.00, 4688000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 1, '', '', 1, '2025-12-22 13:58:45', '2025-12-23 08:51:31', 11),
(279, 3, 25, '173', 'H', 0.00, 563.00, 8000.00, 4504000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 1, '', '', 1, '2025-12-22 13:59:50', '2025-12-23 08:54:49', 11),
(280, 3, 25, '174', 'H', 0.00, 572.00, 8000.00, 4576000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 1, '', '', 1, '2025-12-22 14:00:58', '2025-12-23 08:56:42', 11);
INSERT INTO `plots` (`plot_id`, `company_id`, `project_id`, `plot_number`, `block_number`, `area_sqm`, `area`, `price_per_sqm`, `selling_price`, `discount_amount`, `plot_size`, `price_per_unit`, `survey_plan_number`, `town_plan_number`, `gps_coordinates`, `status`, `corner_plot`, `coordinates`, `notes`, `is_active`, `created_at`, `updated_at`, `created_by`) VALUES
(281, 3, 25, '175', 'H', 0.00, 566.00, 8000.00, 4528000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 1, '', '', 1, '2025-12-22 14:03:15', '2025-12-23 08:58:40', 11),
(282, 3, 25, '176', 'H', 0.00, 603.00, 8000.00, 4824000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 14:06:10', '2025-12-23 09:01:05', 11),
(283, 3, 25, '177', 'H', 0.00, 603.00, 8000.00, 4824000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 14:07:20', '2025-12-23 09:02:29', 11),
(284, 3, 25, '178', 'H', 0.00, 603.00, 8000.00, 4824000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 14:08:24', '2025-12-23 09:05:26', 11),
(285, 3, 25, '179', 'H', 0.00, 603.00, 8000.00, 4824000.00, 0.00, 0.00, 0.00, 'E\'360/392', 'TEM 1/05/12013D', '', 'available', 0, '', '', 1, '2025-12-22 14:09:43', '2025-12-23 09:07:24', 11),
(286, 3, 26, '1', '', 0.00, 1311.00, 18000.00, 23598000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 0, '', '', 1, '2025-12-22 14:13:30', '2025-12-22 14:31:39', 11),
(287, 3, 23, '1', '', 0.00, 520.00, 30000.00, 15600000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-22 14:20:03', '2025-12-22 14:20:03', 11),
(288, 3, 23, '2', '', 0.00, 710.00, 40000.00, 28400000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-22 14:21:18', '2025-12-23 08:31:51', 11),
(289, 3, 23, '3', '', 0.00, 960.00, 30000.00, 28800000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-22 14:22:11', '2025-12-22 14:22:11', 11),
(290, 3, 26, '2', '', 0.00, 920.00, 18000.00, 16560000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 1, '', '', 1, '2025-12-22 14:29:39', '2025-12-22 14:29:39', 11),
(291, 3, 26, '3', '', 0.00, 2203.00, 18000.00, 39654000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 0, '', '', 1, '2025-12-22 14:38:47', '2025-12-22 14:38:47', 11),
(292, 3, 26, '4', '', 0.00, 1480.00, 18000.00, 26640000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 1, '', '', 1, '2025-12-22 14:39:49', '2025-12-22 14:39:49', 11),
(293, 3, 26, '5', '', 0.00, 2234.00, 18000.00, 40212000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 1, '', '', 1, '2025-12-22 14:41:33', '2025-12-22 14:41:33', 11),
(294, 3, 26, '6', '', 0.00, 1478.00, 18000.00, 26604000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 1, '', '', 1, '2025-12-22 14:42:42', '2025-12-22 14:42:42', 11),
(295, 3, 26, '7', '', 0.00, 1359.00, 18000.00, 24462000.00, 0.00, 0.00, 0.00, '', 'DSM/01/KGMC/03004', '', 'available', 0, '', '', 1, '2025-12-22 14:43:54', '2025-12-22 14:43:54', 11),
(296, 3, 27, '1', '', 0.00, 600.00, 20000.00, 12000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-24 11:44:53', '2025-12-24 11:44:53', 11),
(297, 3, 27, '2', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:47:05', '2025-12-24 11:47:05', 11),
(298, 3, 27, '3', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:50:09', '2025-12-24 11:50:09', 11),
(299, 3, 27, '4', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:51:05', '2025-12-24 11:51:05', 11),
(300, 3, 27, '5', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:53:32', '2025-12-24 11:53:32', 11),
(301, 3, 27, '6', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:54:13', '2025-12-24 11:54:13', 11),
(302, 3, 27, '7', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 11:55:39', '2025-12-24 11:55:39', 11),
(303, 3, 27, '8', '', 0.00, 500.00, 20000.00, 10000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-24 11:57:53', '2025-12-24 11:57:53', 11),
(304, 3, 27, '9', '', 0.00, 600.00, 20000.00, 12000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-24 12:01:32', '2025-12-24 12:01:32', 11),
(305, 3, 27, '10', '', 0.00, 630.00, 20000.00, 12600000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:02:39', '2025-12-24 12:02:39', 11),
(306, 3, 27, '11', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:03:46', '2025-12-24 12:03:46', 11),
(307, 3, 27, '12', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:04:26', '2025-12-24 12:04:26', 11),
(308, 3, 27, '13', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:05:09', '2025-12-24 12:05:09', 11),
(309, 3, 27, '14', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:06:18', '2025-12-24 12:06:18', 11),
(310, 3, 27, '15', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:08:32', '2025-12-24 12:08:32', 11),
(311, 3, 27, '16', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:10:41', '2025-12-24 12:10:41', 11),
(312, 3, 27, '17', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:11:38', '2025-12-24 12:11:38', 11),
(313, 3, 27, '18', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:13:25', '2025-12-24 12:13:25', 11),
(314, 3, 27, '19', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:13:48', '2025-12-24 12:13:48', 11),
(315, 3, 27, '20', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:14:44', '2025-12-24 12:14:44', 11),
(316, 3, 27, '21', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:15:30', '2025-12-24 12:15:30', 11),
(317, 3, 27, '22', '', 0.00, 400.00, 20000.00, 8000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:15:55', '2025-12-24 12:15:55', 11),
(318, 3, 27, '23', '', 0.00, 500.00, 20000.00, 10000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 1, '', '', 1, '2025-12-24 12:17:08', '2025-12-24 12:20:51', 11),
(319, 3, 27, '24', '', 0.00, 550.00, 20000.00, 11000000.00, 0.00, 0.00, 0.00, '', '', '', 'available', 0, '', '', 1, '2025-12-24 12:18:07', '2025-12-24 12:18:07', 11);

--
-- Triggers `plots`
--
DELIMITER $$
CREATE TRIGGER `after_plot_insert` AFTER INSERT ON `plots` FOR EACH ROW BEGIN
    UPDATE projects 
    SET total_plots = total_plots + 1,
        available_plots = CASE 
            WHEN NEW.status = 'available' THEN available_plots + 1 
            ELSE available_plots 
        END,
        reserved_plots = CASE 
            WHEN NEW.status = 'reserved' THEN reserved_plots + 1 
            ELSE reserved_plots 
        END,
        sold_plots = CASE 
            WHEN NEW.status = 'sold' THEN sold_plots + 1 
            ELSE sold_plots 
        END
    WHERE project_id = NEW.project_id 
      AND company_id = NEW.company_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_plot_price_change` AFTER UPDATE ON `plots` FOR EACH ROW BEGIN
    IF OLD.selling_price != NEW.selling_price THEN
        INSERT INTO plot_pricing_history 
        (company_id, plot_id, project_id, previous_price, new_price, 
         price_per_sqm, effective_date, changed_by, created_at)
        VALUES 
        (NEW.company_id, NEW.plot_id, NEW.project_id, OLD.selling_price, 
         NEW.selling_price, NEW.price_per_sqm, CURDATE(), 
         @current_user_id, NOW());
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_plot_price_update` AFTER UPDATE ON `plots` FOR EACH ROW BEGIN
    IF OLD.selling_price != NEW.selling_price THEN
        INSERT INTO plot_movements 
        (company_id, plot_id, project_id, movement_type, movement_date,
         previous_price, new_price, initiated_by, approval_status)
        VALUES 
        (NEW.company_id, NEW.plot_id, NEW.project_id, 'price_change', NOW(),
         OLD.selling_price, NEW.selling_price, COALESCE(@current_user_id, 1), 'approved');
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_plot_status_change` AFTER UPDATE ON `plots` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO plot_status_history 
        (company_id, plot_id, project_id, previous_status, new_status, 
         status_date, changed_by, created_at)
        VALUES 
        (NEW.company_id, NEW.plot_id, NEW.project_id, OLD.status, NEW.status,
         CURDATE(), @current_user_id, NOW());
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_plot_status_update` AFTER UPDATE ON `plots` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO plot_movements 
        (company_id, plot_id, project_id, movement_type, movement_date,
         previous_status, new_status, initiated_by, approval_status)
        VALUES 
        (NEW.company_id, NEW.plot_id, NEW.project_id, 'status_change', NOW(),
         OLD.status, NEW.status, COALESCE(@current_user_id, 1), 'approved');
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_plot_update` AFTER UPDATE ON `plots` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        UPDATE projects 
        SET 
            available_plots = (
                SELECT COUNT(*) FROM plots 
                WHERE project_id = NEW.project_id 
                  AND company_id = NEW.company_id 
                  AND status = 'available'
            ),
            reserved_plots = (
                SELECT COUNT(*) FROM plots 
                WHERE project_id = NEW.project_id 
                  AND company_id = NEW.company_id 
                  AND status = 'reserved'
            ),
            sold_plots = (
                SELECT COUNT(*) FROM plots 
                WHERE project_id = NEW.project_id 
                  AND company_id = NEW.company_id 
                  AND status = 'sold'
            )
        WHERE project_id = NEW.project_id 
          AND company_id = NEW.company_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `plot_availability_schedule`
--

CREATE TABLE `plot_availability_schedule` (
  `schedule_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `availability_start_date` date NOT NULL,
  `availability_end_date` date DEFAULT NULL,
  `scheduled_status` varchar(50) NOT NULL,
  `current_status` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `auto_apply` tinyint(1) DEFAULT 1,
  `applied` tinyint(1) DEFAULT 0,
  `applied_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Schedule future plot availability changes';

-- --------------------------------------------------------

--
-- Table structure for table `plot_contracts`
--

CREATE TABLE `plot_contracts` (
  `contract_id` int(11) NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `auto_generated` tinyint(1) DEFAULT 0,
  `generation_date` datetime DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `contract_number` varchar(50) NOT NULL,
  `contract_date` date NOT NULL,
  `contract_type` enum('sale','lease','installment') DEFAULT 'installment',
  `contract_duration_months` int(11) DEFAULT NULL COMMENT 'For installment contracts',
  `contract_terms` text DEFAULT NULL COMMENT 'Full terms and conditions',
  `special_conditions` text DEFAULT NULL,
  `seller_name` varchar(200) NOT NULL,
  `seller_id_number` varchar(50) DEFAULT NULL,
  `buyer_name` varchar(200) NOT NULL,
  `buyer_id_number` varchar(50) DEFAULT NULL,
  `witness1_name` varchar(200) DEFAULT NULL,
  `witness1_id_number` varchar(50) DEFAULT NULL,
  `witness1_signature_path` varchar(255) DEFAULT NULL,
  `witness2_name` varchar(200) DEFAULT NULL,
  `witness2_id_number` varchar(50) DEFAULT NULL,
  `witness2_signature_path` varchar(255) DEFAULT NULL,
  `lawyer_name` varchar(200) DEFAULT NULL,
  `notary_name` varchar(200) DEFAULT NULL,
  `notary_stamp_number` varchar(100) DEFAULT NULL,
  `contract_template_path` varchar(255) DEFAULT NULL,
  `signed_contract_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','pending_signature','signed','completed','cancelled') DEFAULT 'draft',
  `signed_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `cancelled_date` date DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sale contracts and agreements';

--
-- Dumping data for table `plot_contracts`
--

INSERT INTO `plot_contracts` (`contract_id`, `template_id`, `auto_generated`, `generation_date`, `company_id`, `reservation_id`, `contract_number`, `contract_date`, `contract_type`, `contract_duration_months`, `contract_terms`, `special_conditions`, `seller_name`, `seller_id_number`, `buyer_name`, `buyer_id_number`, `witness1_name`, `witness1_id_number`, `witness1_signature_path`, `witness2_name`, `witness2_id_number`, `witness2_signature_path`, `lawyer_name`, `notary_name`, `notary_stamp_number`, `contract_template_path`, `signed_contract_path`, `status`, `signed_date`, `completion_date`, `cancelled_date`, `cancellation_reason`, `cancelled_by`, `created_at`, `updated_at`, `created_by`) VALUES
(1, NULL, 0, NULL, 3, 16, 'CNT-2025-0003837', '2025-12-22', 'lease', 20, '', '', 'Mkumbi investment company ltd', '', 'CAROLINA  ROBERT MGANGA', '19991208305110000119', 'gg', '544', NULL, 'gg', '55', NULL, 'gff', '', '', NULL, NULL, 'draft', NULL, NULL, NULL, NULL, NULL, '2025-12-22 09:42:02', '2025-12-22 09:42:02', 9),
(2, NULL, 0, NULL, 3, 15, 'CNT-2025-0003915', '2025-12-22', 'installment', NULL, '', '', 'Mkumbi investment company ltd', '', 'LAZARO MPUYA MATALANGE', '19760218-16113-00002-20', '', '', NULL, '', '', NULL, '', '', '', NULL, NULL, 'draft', NULL, NULL, NULL, NULL, NULL, '2025-12-22 11:50:21', '2025-12-22 11:50:21', 9);

-- --------------------------------------------------------

--
-- Table structure for table `plot_documents`
--

CREATE TABLE `plot_documents` (
  `document_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `document_type` enum('survey_plan','title_deed','layout_map','photo','contract','other') NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot-related documents';

-- --------------------------------------------------------

--
-- Table structure for table `plot_features`
--

CREATE TABLE `plot_features` (
  `feature_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `feature_type` enum('location','amenity','utility','access','view','other') NOT NULL,
  `feature_name` varchar(100) NOT NULL,
  `feature_value` varchar(255) DEFAULT NULL,
  `is_premium` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot features and amenities';

-- --------------------------------------------------------

--
-- Table structure for table `plot_holds`
--

CREATE TABLE `plot_holds` (
  `hold_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `hold_type` enum('customer_interest','internal_review','maintenance','legal_issue','dispute','management_hold','other') NOT NULL,
  `hold_reason` text NOT NULL,
  `hold_start_date` datetime NOT NULL DEFAULT current_timestamp(),
  `hold_end_date` datetime DEFAULT NULL,
  `expected_release_date` date DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `hold_fee` decimal(15,2) DEFAULT 0.00,
  `auto_release` tinyint(1) DEFAULT 0,
  `status` enum('active','released','expired','cancelled') DEFAULT 'active',
  `release_date` datetime DEFAULT NULL,
  `release_reason` text DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Temporary plot holds and blocks';

--
-- Triggers `plot_holds`
--
DELIMITER $$
CREATE TRIGGER `before_plot_hold_check` BEFORE UPDATE ON `plot_holds` FOR EACH ROW BEGIN
    IF NEW.status = 'active' 
       AND NEW.expected_release_date IS NOT NULL 
       AND NEW.expected_release_date < CURDATE()
       AND NEW.auto_release = 1 THEN
        SET NEW.status = 'expired';
        SET NEW.release_date = NOW();
        SET NEW.release_reason = 'Auto-released: Expected release date passed';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `plot_inspections`
--

CREATE TABLE `plot_inspections` (
  `inspection_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `inspection_date` date NOT NULL,
  `inspection_type` enum('routine','pre_sale','customer_viewing','maintenance','dispute') NOT NULL,
  `inspector_name` varchar(200) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `condition_rating` enum('excellent','good','fair','poor') DEFAULT 'good',
  `issues_found` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `follow_up_required` tinyint(1) DEFAULT 0,
  `follow_up_date` date DEFAULT NULL,
  `inspection_report_path` varchar(500) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot inspection records';

-- --------------------------------------------------------

--
-- Table structure for table `plot_inventory_snapshots`
--

CREATE TABLE `plot_inventory_snapshots` (
  `snapshot_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `snapshot_date` date NOT NULL,
  `total_plots` int(11) NOT NULL DEFAULT 0,
  `available_plots` int(11) NOT NULL DEFAULT 0,
  `reserved_plots` int(11) NOT NULL DEFAULT 0,
  `sold_plots` int(11) NOT NULL DEFAULT 0,
  `blocked_plots` int(11) NOT NULL DEFAULT 0,
  `total_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `available_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sold_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Daily inventory snapshots for reporting';

-- --------------------------------------------------------

--
-- Table structure for table `plot_movements`
--

CREATE TABLE `plot_movements` (
  `movement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `movement_type` enum('status_change','reservation','sale','cancellation','transfer','hold','release','price_change','other') NOT NULL,
  `movement_date` datetime NOT NULL DEFAULT current_timestamp(),
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `previous_customer_id` int(11) DEFAULT NULL,
  `new_customer_id` int(11) DEFAULT NULL,
  `previous_price` decimal(15,2) DEFAULT NULL,
  `new_price` decimal(15,2) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `supporting_document_path` varchar(500) DEFAULT NULL,
  `initiated_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Complete plot movement and activity tracking';

--
-- Dumping data for table `plot_movements`
--

INSERT INTO `plot_movements` (`movement_id`, `company_id`, `plot_id`, `project_id`, `movement_type`, `movement_date`, `previous_status`, `new_status`, `previous_customer_id`, `new_customer_id`, `previous_price`, `new_price`, `reservation_id`, `sale_id`, `reason`, `remarks`, `supporting_document_path`, `initiated_by`, `approved_by`, `approval_date`, `approval_status`, `created_at`, `updated_at`) VALUES
(1, 3, 226, 22, 'price_change', '2025-12-22 16:29:09', NULL, NULL, NULL, NULL, 6536000.00, 6544000.00, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-22 10:59:09', '2025-12-22 10:59:09'),
(2, 3, 226, 22, 'price_change', '2025-12-22 16:29:25', NULL, NULL, NULL, NULL, 6544000.00, 6536000.00, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-22 10:59:25', '2025-12-22 10:59:25'),
(3, 3, 187, 19, 'price_change', '2025-12-22 17:27:16', NULL, NULL, NULL, NULL, 52800000.00, 118650000.00, NULL, NULL, NULL, NULL, NULL, 11, NULL, NULL, 'approved', '2025-12-22 11:57:16', '2025-12-22 11:57:16'),
(4, 3, 285, 25, 'price_change', '2025-12-22 19:46:28', NULL, NULL, NULL, NULL, 4816000.00, 4824000.00, NULL, NULL, NULL, NULL, NULL, 11, NULL, NULL, 'approved', '2025-12-22 14:16:28', '2025-12-22 14:16:28'),
(5, 3, 288, 23, 'price_change', '2025-12-23 14:01:51', NULL, NULL, NULL, NULL, 21300000.00, 28400000.00, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-23 08:31:51', '2025-12-23 08:31:51'),
(6, 3, 158, 15, 'price_change', '2025-12-23 19:00:29', NULL, NULL, NULL, NULL, 2100000.00, 1890000.00, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-23 13:30:29', '2025-12-23 13:30:29'),
(7, 3, 45, 6, 'status_change', '2025-12-29 11:02:01', 'available', 'reserved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-29 05:32:01', '2025-12-29 05:32:01'),
(8, 3, 31, 5, 'status_change', '2025-12-29 11:34:05', 'available', 'reserved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-29 06:04:05', '2025-12-29 06:04:05'),
(9, 3, 48, 6, 'status_change', '2025-12-29 11:43:18', 'available', 'reserved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 9, NULL, NULL, 'approved', '2025-12-29 06:13:18', '2025-12-29 06:13:18');

-- --------------------------------------------------------

--
-- Table structure for table `plot_pricing_history`
--

CREATE TABLE `plot_pricing_history` (
  `pricing_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `previous_price` decimal(15,2) DEFAULT NULL,
  `new_price` decimal(15,2) NOT NULL,
  `price_per_sqm` decimal(10,2) DEFAULT NULL,
  `effective_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track plot price changes over time';

--
-- Dumping data for table `plot_pricing_history`
--

INSERT INTO `plot_pricing_history` (`pricing_id`, `company_id`, `plot_id`, `project_id`, `previous_price`, `new_price`, `price_per_sqm`, `effective_date`, `reason`, `changed_by`, `created_at`) VALUES
(1, 3, 226, 22, 6536000.00, 6544000.00, 8000.00, '2025-12-22', NULL, 9, '2025-12-22 10:59:09'),
(2, 3, 226, 22, 6544000.00, 6536000.00, 8000.00, '2025-12-22', NULL, 9, '2025-12-22 10:59:25'),
(3, 3, 187, 19, 52800000.00, 118650000.00, 150000.00, '2025-12-22', NULL, 11, '2025-12-22 11:57:16'),
(4, 3, 285, 25, 4816000.00, 4824000.00, 8000.00, '2025-12-22', NULL, 11, '2025-12-22 14:16:28'),
(5, 3, 288, 23, 21300000.00, 28400000.00, 40000.00, '2025-12-23', NULL, 9, '2025-12-23 08:31:51'),
(6, 3, 158, 15, 2100000.00, 1890000.00, 4500.00, '2025-12-23', NULL, 9, '2025-12-23 13:30:29');

-- --------------------------------------------------------

--
-- Table structure for table `plot_reservation_queue`
--

CREATE TABLE `plot_reservation_queue` (
  `queue_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `queue_date` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_position` int(11) NOT NULL,
  `interest_level` enum('high','medium','low') DEFAULT 'medium',
  `budget_range` varchar(100) DEFAULT NULL,
  `contact_preference` enum('phone','email','sms','visit') DEFAULT 'phone',
  `notes` text DEFAULT NULL,
  `status` enum('waiting','contacted','viewing_scheduled','converted','cancelled','expired') DEFAULT 'waiting',
  `contacted_by` int(11) DEFAULT NULL,
  `contacted_at` datetime DEFAULT NULL,
  `converted_to_reservation_id` int(11) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot interest queue management';

-- --------------------------------------------------------

--
-- Table structure for table `plot_status_history`
--

CREATE TABLE `plot_status_history` (
  `history_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `status_date` date NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track plot status changes over time';

--
-- Dumping data for table `plot_status_history`
--

INSERT INTO `plot_status_history` (`history_id`, `company_id`, `plot_id`, `project_id`, `previous_status`, `new_status`, `status_date`, `changed_by`, `reason`, `reservation_id`, `created_at`) VALUES
(1, 3, 45, 6, 'available', 'reserved', '2025-12-29', 9, NULL, NULL, '2025-12-29 05:32:01'),
(2, 3, 31, 5, 'available', 'reserved', '2025-12-29', 9, NULL, NULL, '2025-12-29 06:04:05'),
(3, 3, 48, 6, 'available', 'reserved', '2025-12-29', 9, NULL, NULL, '2025-12-29 06:13:18');

-- --------------------------------------------------------

--
-- Table structure for table `plot_stock_movements`
--

CREATE TABLE `plot_stock_movements` (
  `movement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `movement_date` date NOT NULL,
  `movement_type` enum('acquisition','development','reservation','sale','cancellation','return','transfer') NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `cost_value` decimal(15,2) DEFAULT 0.00 COMMENT 'Cost value at this movement',
  `selling_value` decimal(15,2) DEFAULT 0.00 COMMENT 'Selling value at this movement',
  `customer_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot inventory movement tracking';

-- --------------------------------------------------------

--
-- Table structure for table `plot_swaps`
--

CREATE TABLE `plot_swaps` (
  `swap_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `swap_date` date NOT NULL,
  `plot_1_id` int(11) NOT NULL,
  `plot_2_id` int(11) NOT NULL,
  `customer_1_id` int(11) NOT NULL,
  `customer_2_id` int(11) NOT NULL,
  `reservation_1_id` int(11) DEFAULT NULL,
  `reservation_2_id` int(11) DEFAULT NULL,
  `price_difference` decimal(15,2) DEFAULT 0.00,
  `adjustment_paid_by` enum('customer_1','customer_2','none') DEFAULT 'none',
  `swap_fee` decimal(15,2) DEFAULT 0.00,
  `swap_reason` text DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `completion_date` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `swap_agreement_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `initiated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot swap/exchange transactions';

-- --------------------------------------------------------

--
-- Table structure for table `plot_transfers`
--

CREATE TABLE `plot_transfers` (
  `transfer_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `transfer_type` enum('customer_change','ownership_transfer','reassignment','swap','upgrade','downgrade') NOT NULL,
  `transfer_date` date NOT NULL,
  `from_customer_id` int(11) DEFAULT NULL,
  `to_customer_id` int(11) NOT NULL,
  `from_reservation_id` int(11) DEFAULT NULL,
  `to_reservation_id` int(11) DEFAULT NULL,
  `transfer_fee` decimal(15,2) DEFAULT 0.00,
  `transfer_reason` text DEFAULT NULL,
  `previous_plot_id` int(11) DEFAULT NULL COMMENT 'For swaps/upgrades',
  `price_adjustment` decimal(15,2) DEFAULT 0.00,
  `approval_required` tinyint(1) DEFAULT 1,
  `approval_status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `transfer_document_path` varchar(500) DEFAULT NULL,
  `legal_document_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `initiated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot ownership and assignment transfers';

-- --------------------------------------------------------

--
-- Table structure for table `plot_valuations`
--

CREATE TABLE `plot_valuations` (
  `valuation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `valuation_date` date NOT NULL,
  `valuation_type` enum('market','cost','forced_sale','insurance') NOT NULL,
  `valued_amount` decimal(15,2) NOT NULL,
  `valuer_name` varchar(200) DEFAULT NULL,
  `valuer_company` varchar(200) DEFAULT NULL,
  `valuation_report_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Professional plot valuations';

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position_title` varchar(100) NOT NULL,
  `position_code` varchar(20) DEFAULT NULL,
  `job_description` text DEFAULT NULL,
  `min_salary` decimal(15,2) DEFAULT NULL,
  `max_salary` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_name` varchar(200) NOT NULL,
  `project_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `region_id` int(11) DEFAULT NULL,
  `region_name` varchar(100) DEFAULT NULL,
  `district_id` int(11) DEFAULT NULL,
  `district_name` varchar(100) DEFAULT NULL,
  `ward_id` int(11) DEFAULT NULL,
  `ward_name` varchar(100) DEFAULT NULL,
  `village_id` int(11) DEFAULT NULL,
  `village_name` varchar(100) DEFAULT NULL,
  `physical_location` text DEFAULT NULL,
  `total_area` decimal(15,2) DEFAULT NULL COMMENT 'Total area in square meters',
  `total_plots` int(11) DEFAULT 0,
  `available_plots` int(11) DEFAULT 0,
  `reserved_plots` int(11) DEFAULT 0,
  `sold_plots` int(11) DEFAULT 0,
  `acquisition_date` date DEFAULT NULL,
  `closing_date` date DEFAULT NULL,
  `title_deed_path` varchar(255) DEFAULT NULL,
  `survey_plan_path` varchar(255) DEFAULT NULL,
  `contract_attachment_path` varchar(255) DEFAULT NULL,
  `coordinates_path` varchar(255) DEFAULT NULL,
  `status` enum('planning','active','completed','suspended') DEFAULT 'planning',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `land_purchase_price` decimal(15,2) DEFAULT NULL COMMENT 'Total cost to acquire land',
  `total_operational_costs` decimal(15,2) DEFAULT 0.00 COMMENT 'Survey, legal, development costs',
  `total_investment` decimal(15,2) GENERATED ALWAYS AS (`land_purchase_price` + `total_operational_costs`) STORED,
  `cost_per_sqm` decimal(10,2) DEFAULT NULL COMMENT 'Buying cost per square meter',
  `selling_price_per_sqm` decimal(10,2) DEFAULT NULL COMMENT 'Selling price per square meter',
  `profit_margin_percentage` decimal(5,2) DEFAULT 0.00,
  `total_expected_revenue` decimal(15,2) DEFAULT 0.00,
  `total_actual_revenue` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot development projects';

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `company_id`, `project_name`, `project_code`, `description`, `region_id`, `region_name`, `district_id`, `district_name`, `ward_id`, `ward_name`, `village_id`, `village_name`, `physical_location`, `total_area`, `total_plots`, `available_plots`, `reserved_plots`, `sold_plots`, `acquisition_date`, `closing_date`, `title_deed_path`, `survey_plan_path`, `contract_attachment_path`, `coordinates_path`, `status`, `is_active`, `created_at`, `updated_at`, `created_by`, `land_purchase_price`, `total_operational_costs`, `cost_per_sqm`, `selling_price_per_sqm`, `profit_margin_percentage`, `total_expected_revenue`, `total_actual_revenue`) VALUES
(4, 3, 'VUMILIA UKOONI', 'PRJ-2025-9344', 'VUMILIA UKOONI', 1, 'DAR-ES-SALAAM', 1, 'KIGAMBONI', 1, 'KISARAWE II', 1, 'VUMILIA UKOONI', '', 6110.00, 12, 11, 1, 0, '2025-10-01', '2025-12-31', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-10 12:03:19', '2025-12-22 15:12:59', 6, 60000000.00, 10000000.00, 11456.63, 30000.00, 161.86, 183300000.00, 0.00),
(5, 3, 'KIBAHA BOKOMNEMELA-2', 'PRJ-2025-7743', '', 1, 'PWANI', NULL, 'KIBAHA', NULL, 'BOKOMNEMELA', NULL, 'MPIJI', '', 7316.00, 15, 14, 1, 0, '2025-12-15', '2026-02-15', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-17 11:56:55', '2025-12-29 06:04:05', 9, 14300000.00, 2000000.00, 2227.99, 8000.00, 259.07, 58528000.00, 0.00),
(6, 3, 'CHANIKA HOMBOZA', 'PRJ-2025-5911', '', 0, 'PWANI', 0, 'KISARAWE', 0, 'MSIMBU', 0, 'GUMBA', '', 12574.00, 28, 26, 2, 0, '2022-11-09', '2022-11-09', NULL, 'uploads/projects/survey_plan_1765979235_6942b46368584.pdf', NULL, NULL, 'active', 1, '2025-12-17 13:47:15', '2025-12-29 06:13:18', 9, 20000000.00, 5000000.00, 1988.23, 7000.00, 252.07, 88018000.00, 0.00),
(15, 3, 'MLANDIZI VIKURUTI', 'PRJ-2025-6927', '', NULL, 'Pwani', NULL, 'Kibaha', NULL, 'Kibaha', NULL, 'Mlandizi', '', 53970.00, 92, 92, 0, 0, '2022-03-18', '2022-07-31', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-18 13:10:09', '2025-12-19 09:39:43', 9, 31140000.00, 22100000.00, 986.47, 5000.00, 406.86, 269850000.00, 0.00),
(16, 3, 'KIBAHA BOKOMNEMELA-3', 'PRJ-2025-3509', '', NULL, 'PWANI', NULL, 'KIBAHA', NULL, 'BOKOMNEMELA', NULL, 'BOKOMNEMELA', '', 6980.00, 15, 14, 1, 0, '2025-05-10', '2025-06-10', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-19 08:43:48', '2025-12-19 13:25:31', 9, 30000000.00, 10000000.00, 5730.66, 13000.00, 126.85, 90740000.00, 0.00),
(19, 3, 'KIBAHA KWAMFIPA', 'PRJ-2025-3003', '', NULL, 'PWANI', NULL, 'KIBAHA CBD', NULL, 'KIBAHA', NULL, 'SIMBANI', '', 16317.00, 28, 28, 0, 0, '2022-10-19', '2025-10-19', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-19 13:45:53', '2025-12-19 15:30:40', 9, 125000000.00, 5000000.00, 7967.15, 150000.00, 999.99, 2447550000.00, 0.00),
(20, 3, 'MLANDIZI VIKURUTI - 2', 'PRJ-2025-2649', '', NULL, 'PWANI', NULL, 'KIBAHA', NULL, 'MLANDIZI', NULL, 'VIKURUTI MJINI', '', 7570.00, 36, 18, 0, 0, '2023-12-11', '2025-10-31', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-19 14:27:41', '2025-12-22 07:38:52', 9, 29600000.00, 2400000.00, 4227.21, 9000.00, 112.91, 68130000.00, 0.00),
(22, 3, 'KIMBIJI GOLANI', 'PRJ-2025-1719', '', NULL, 'DAR-ES-SALAAM', NULL, NULL, NULL, NULL, NULL, NULL, '', 10361.00, 26, 13, 0, 0, '2023-04-13', '2024-09-30', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-19 14:43:36', '2025-12-22 08:25:00', 9, 31083000.00, 317000.00, 3030.60, 8000.00, 163.97, 82888000.00, 0.00),
(23, 3, 'KIGAMBONI DEGE', 'PRJ-2025-3514', '', NULL, 'DAR-ES-SALAAM', NULL, 'KIGAMBONI', NULL, 'SOMANGILA', NULL, 'DEGE', '', 2190.00, 6, 3, 0, 0, '2025-12-15', '2026-02-15', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-22 08:09:26', '2025-12-22 15:15:39', 9, 25000000.00, 5000000.00, 13698.63, 30000.00, 119.00, 65700000.00, 0.00),
(24, 3, 'KIBAHA BOKOMNEMELA-1', 'PRJ-2025-2749', '', NULL, 'PWANI', NULL, 'KIBAHA', NULL, 'BOKOMNEMELA', NULL, 'MPIJI', '', 18484.00, 68, 34, 0, 0, '2021-11-19', '2022-01-31', NULL, NULL, NULL, NULL, 'active', 1, '2025-12-22 12:29:11', '2025-12-22 13:46:28', 9, 21660000.00, 8340000.00, 1623.03, 6000.00, 269.68, 110904000.00, 0.00),
(25, 3, 'KIGAMBONI MWASONGA-2', 'PRJ-2025-3976', '', NULL, 'DAR-ES-SALAAM', NULL, 'KIGAMBONI', NULL, 'PEMBAMNAZI', NULL, 'TUNDWI CENTRE', '', 7052.00, 24, 12, 0, 0, '2022-01-24', '2022-06-23', NULL, NULL, NULL, NULL, 'planning', 1, '2025-12-22 12:42:22', '2025-12-22 14:09:43', 9, 30900000.00, 4100000.00, 4963.13, 8000.00, 61.19, 56416000.00, 0.00),
(26, 3, 'KIMBIJI KWAMORIS', 'PRJ-2025-2449', '', NULL, 'DAR-ES-SALAAM', NULL, 'KIGAMBONI', NULL, 'PEMBAMNAZI', NULL, 'KWA MORISI', '', 10985.00, 14, 7, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 'active', 1, '2025-12-22 13:17:13', '2025-12-22 14:43:54', 9, 24000000.00, 6000000.00, 2731.00, 18000.00, 559.10, 197730000.00, 0.00),
(27, 3, 'BAGAMOYO UKUNI', 'PRJ-2025-8911', '', NULL, 'PWANI', NULL, 'BAGAMOYO', NULL, 'KIROMO', NULL, 'MIZUGUNI', '', 10580.00, 48, 24, 0, 0, '2025-12-08', '2026-02-28', NULL, NULL, NULL, NULL, 'planning', 1, '2025-12-24 09:46:59', '2025-12-24 12:18:07', 9, 40000000.00, 12000000.00, 4914.93, 20000.00, 306.92, 211600000.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `project_costs`
--

CREATE TABLE `project_costs` (
  `cost_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `cost_category` enum('land_purchase','survey','legal_fees','title_processing','development','marketing','consultation','other') NOT NULL,
  `cost_description` text NOT NULL,
  `cost_amount` decimal(15,2) NOT NULL,
  `cost_date` date NOT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Project operational costs tracking';

--
-- Dumping data for table `project_costs`
--

INSERT INTO `project_costs` (`cost_id`, `company_id`, `project_id`, `cost_category`, `cost_description`, `cost_amount`, `cost_date`, `receipt_number`, `attachment_path`, `approved_by`, `approved_at`, `remarks`, `created_at`, `created_by`) VALUES
(17, 3, 5, 'land_purchase', 'purchased', 2000000.00, '2025-12-18', '', NULL, 9, '2025-12-18 10:27:53', '', '2025-12-18 10:27:39', 9);

-- --------------------------------------------------------

--
-- Table structure for table `project_creditors`
--

CREATE TABLE `project_creditors` (
  `project_creditor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `creditor_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL COMMENT 'Link to suppliers table',
  `creditor_type` enum('supplier','contractor','consultant','seller','other') NOT NULL,
  `contract_number` varchar(100) DEFAULT NULL,
  `contract_date` date DEFAULT NULL,
  `total_contract_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `contract_amount` decimal(15,2) GENERATED ALWAYS AS (`total_contract_amount`) STORED COMMENT 'Alias for total_contract_amount',
  `contract_description` text DEFAULT NULL COMMENT 'Contract description',
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `amount_outstanding` decimal(15,2) GENERATED ALWAYS AS (`total_contract_amount` - `amount_paid`) STORED,
  `payment_terms` text DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `status` enum('active','completed','terminated','suspended') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Project creditors and contractors management';

-- --------------------------------------------------------

--
-- Table structure for table `project_creditor_payments`
--

CREATE TABLE `project_creditor_payments` (
  `payment_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_creditor_id` int(11) NOT NULL COMMENT 'Link to project_creditors table',
  `payment_schedule_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `payment_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('bank_transfer','cash','cheque','mobile_money') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','paid','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Creditor payment records';

--
-- Triggers `project_creditor_payments`
--
DELIMITER $$
CREATE TRIGGER `after_creditor_payment_insert` AFTER INSERT ON `project_creditor_payments` FOR EACH ROW BEGIN
    IF NEW.status = 'approved' OR NEW.status = 'paid' THEN
        UPDATE project_creditors 
        SET amount_paid = COALESCE(amount_paid, 0) + NEW.payment_amount
        WHERE project_creditor_id = NEW.project_creditor_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_creditor_payment_update` AFTER UPDATE ON `project_creditor_payments` FOR EACH ROW BEGIN
    -- If status changed to approved/paid, add the amount
    IF (NEW.status = 'approved' OR NEW.status = 'paid') AND 
       (OLD.status != 'approved' AND OLD.status != 'paid') THEN
        UPDATE project_creditors 
        SET amount_paid = COALESCE(amount_paid, 0) + NEW.payment_amount
        WHERE project_creditor_id = NEW.project_creditor_id;
    
    -- If status changed from approved/paid to something else, subtract the amount
    ELSEIF (OLD.status = 'approved' OR OLD.status = 'paid') AND 
           (NEW.status != 'approved' AND NEW.status != 'paid') THEN
        UPDATE project_creditors 
        SET amount_paid = COALESCE(amount_paid, 0) - OLD.payment_amount
        WHERE project_creditor_id = OLD.project_creditor_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `project_creditor_schedules`
--

CREATE TABLE `project_creditor_schedules` (
  `schedule_id` int(11) NOT NULL,
  `project_creditor_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `scheduled_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) GENERATED ALWAYS AS (`scheduled_amount` - `paid_amount`) STORED,
  `payment_status` enum('unpaid','partial','paid','overdue') DEFAULT 'unpaid',
  `payment_date` date DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Creditor payment schedules';

-- --------------------------------------------------------

--
-- Table structure for table `project_sellers`
--

CREATE TABLE `project_sellers` (
  `seller_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `seller_name` varchar(200) NOT NULL COMMENT 'Project owner/seller name',
  `seller_phone` varchar(50) DEFAULT NULL,
  `seller_nida` varchar(50) DEFAULT NULL COMMENT 'National ID number',
  `seller_tin` varchar(50) DEFAULT NULL COMMENT 'TIN number',
  `seller_address` text DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_amount` decimal(15,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Simplified land sellers information';

--
-- Dumping data for table `project_sellers`
--

INSERT INTO `project_sellers` (`seller_id`, `project_id`, `company_id`, `seller_name`, `seller_phone`, `seller_nida`, `seller_tin`, `seller_address`, `purchase_date`, `purchase_amount`, `notes`, `created_at`, `created_by`) VALUES
(3, 4, 3, 'Deogratius Gaston Luvakule', '767571432', '198111223471620000229', '', '', '2025-10-10', 60000000.00, '', '2025-12-10 12:03:19', 6),
(4, 5, 3, 'STIVE NATHAN MIFWA', '0788199866', '', '', '', '0000-00-00', 14300000.00, '', '2025-12-17 11:56:55', 9),
(5, 6, 3, 'James Wiliam Mgoya', '+255 712 540 188', '', '', '', '0000-00-00', 20000000.00, '', '2025-12-17 13:47:15', 9),
(14, 16, 3, 'STIVE NATHAN MIFWA', '788199866', '', '', '', '2025-05-10', 30000000.00, '', '2025-12-19 08:43:48', 9),
(16, 19, 3, 'NASSIR ABDALLAH ALSINAN', '0625269611', '', '', 'Kwamfipa - Simbani', '2022-10-19', 125000000.00, '', '2025-12-19 13:45:53', 9),
(17, 20, 3, 'Kelvin Fredrick Mpemba', '0754494604', '', '', '', '2023-12-11', 29600000.00, '', '2025-12-19 14:27:41', 9),
(19, 22, 3, 'Emmanuel Edward Burishi', '0786616200', '', '', '', '2023-04-13', 31083000.00, '', '2025-12-19 14:43:36', 9),
(20, 23, 3, 'JESCA FERDINAND CHENDELA', '0789547599', '', '', '', '2025-12-15', 25000000.00, '', '2025-12-22 08:09:26', 9),
(21, 24, 3, 'STIVE NATHAN MIFWA', '0788199866', '', '', '', '2021-11-19', 21660000.00, '', '2025-12-22 12:29:11', 9),
(22, 25, 3, 'PETER BENSON NGENDA', '0756960231', '', '', '', '2022-01-24', 30900000.00, '', '2025-12-22 12:42:22', 9),
(23, 27, 3, 'WILLYCKY KAVYNJIKA', '0658877654', '', '', '', '2025-12-08', 40000000.00, '', '2025-12-24 09:46:59', 9);

-- --------------------------------------------------------

--
-- Table structure for table `project_statements`
--

CREATE TABLE `project_statements` (
  `statement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `statement_number` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money','other') NOT NULL DEFAULT 'bank_transfer',
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('paid','pending','cancelled') NOT NULL DEFAULT 'paid',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `purchase_order_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `po_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `requisition_id` int(11) DEFAULT NULL,
  `payment_terms` varchar(200) DEFAULT NULL,
  `delivery_terms` varchar(200) DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','submitted','approved','received','closed','cancelled') DEFAULT 'draft',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `po_item_id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `line_total` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `quantity_received` decimal(10,2) DEFAULT 0.00,
  `quantity_remaining` decimal(10,2) GENERATED ALWAYS AS (`quantity` - `quantity_received`) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requisitions`
--

CREATE TABLE `purchase_requisitions` (
  `requisition_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `requisition_number` varchar(50) NOT NULL,
  `requisition_date` date NOT NULL,
  `required_date` date DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','ordered','cancelled') DEFAULT 'draft',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `quotation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `quotation_number` varchar(50) NOT NULL,
  `quotation_date` date NOT NULL,
  `valid_until_date` date DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `quote_date` date NOT NULL DEFAULT curdate(),
  `valid_until` date NOT NULL DEFAULT (curdate() + interval 30 day),
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `payment_terms` text DEFAULT NULL,
  `delivery_terms` text DEFAULT NULL,
  `status` enum('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `terms_conditions` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ;

--
-- Dumping data for table `quotations`
--

INSERT INTO `quotations` (`quotation_id`, `company_id`, `quotation_number`, `quotation_date`, `valid_until_date`, `customer_id`, `lead_id`, `quote_date`, `valid_until`, `subtotal`, `tax_amount`, `discount_amount`, `total_amount`, `payment_terms`, `delivery_terms`, `status`, `is_active`, `terms_conditions`, `notes`, `created_at`, `updated_at`, `created_by`) VALUES
(1, 3, 'QT-2025-0001', '2025-12-26', '2026-01-25', 31, NULL, '2025-12-26', '2026-01-25', 11325000.00, 2038500.00, 0.00, 13363500.00, '40% downpayment\r\n24 month installations', 'title deed', 'draft', 1, '', '', '2025-12-26 05:51:39', '2025-12-26 05:51:39', 9);

-- --------------------------------------------------------

--
-- Table structure for table `quotation_items`
--

CREATE TABLE `quotation_items` (
  `quotation_item_id` int(11) NOT NULL,
  `quotation_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT 'unit',
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `details` text DEFAULT NULL,
  `line_total` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quotation_items`
--

INSERT INTO `quotation_items` (`quotation_item_id`, `quotation_id`, `description`, `item_id`, `item_description`, `quantity`, `unit`, `unit_price`, `total_price`, `details`, `created_at`) VALUES
(1, 1, '', NULL, 'Plot #2 - BAGAMOYO UKUNI\nBlock: \nArea: 400.00 sqm\nLocation: \n', 1.00, 'plot', 8000000.00, 8000000.00, NULL, '2025-12-26 05:51:39'),
(2, 1, '', NULL, 'Plot #5 - CHANIKA HOMBOZA\nBlock: P\nArea: 475.00 sqm\nLocation: \n', 1.00, 'plot', 3325000.00, 3325000.00, NULL, '2025-12-26 05:51:39');

-- --------------------------------------------------------

--
-- Table structure for table `refunds`
--

CREATE TABLE `refunds` (
  `refund_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `refund_number` varchar(50) NOT NULL,
  `refund_date` date NOT NULL,
  `refund_reason` enum('cancellation','overpayment','plot_unavailable','customer_request','dispute','other') NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `original_payment_id` int(11) DEFAULT NULL,
  `original_amount` decimal(15,2) NOT NULL,
  `refund_amount` decimal(15,2) NOT NULL,
  `penalty_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Deduction if any',
  `net_refund_amount` decimal(15,2) GENERATED ALWAYS AS (`refund_amount` - `penalty_amount`) STORED,
  `refund_method` enum('bank_transfer','cheque','cash','mobile_money') NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','processed','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `refund_voucher_path` varchar(255) DEFAULT NULL,
  `supporting_documents_path` varchar(255) DEFAULT NULL,
  `detailed_reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment refunds management';

-- --------------------------------------------------------

--
-- Table structure for table `regions`
--

CREATE TABLE `regions` (
  `region_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `region_name` varchar(100) NOT NULL,
  `region_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `regions`
--

INSERT INTO `regions` (`region_id`, `company_id`, `region_name`, `region_code`, `is_active`, `created_at`) VALUES
(1, 1, 'Dar es salaam', 'DR01', 1, '2025-11-29 13:00:57');

-- --------------------------------------------------------

--
-- Table structure for table `requisition_items`
--

CREATE TABLE `requisition_items` (
  `requisition_item_id` int(11) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `estimated_unit_price` decimal(15,2) DEFAULT NULL,
  `estimated_total_price` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `estimated_unit_price`) STORED,
  `specifications` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `reservation_number` varchar(50) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `down_payment` decimal(15,2) DEFAULT 0.00,
  `remaining_balance` decimal(15,2) GENERATED ALWAYS AS (`total_amount` - `down_payment`) STORED,
  `payment_periods` int(11) DEFAULT 20 COMMENT 'Number of installment periods',
  `installment_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `title_holder_name` varchar(200) DEFAULT NULL,
  `title_deed_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','active','completed','cancelled') DEFAULT 'draft',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `contract_template_id` int(11) DEFAULT NULL,
  `payment_stage` varchar(50) DEFAULT 'pending_down_payment' COMMENT 'pending_down_payment, paying_down_payment, down_payment_complete, paying_installments, completed',
  `down_payment_paid` decimal(15,2) DEFAULT 0.00 COMMENT 'Amount paid towards down payment',
  `down_payment_balance` decimal(15,2) DEFAULT 0.00 COMMENT 'Remaining down payment balance',
  `installments_paid_count` int(11) DEFAULT 0 COMMENT 'Number of installments paid',
  `last_installment_date` date DEFAULT NULL COMMENT 'Date of last installment payment'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plot reservations and sales';

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `company_id`, `customer_id`, `plot_id`, `reservation_date`, `reservation_number`, `total_amount`, `down_payment`, `payment_periods`, `installment_amount`, `discount_percentage`, `discount_amount`, `title_holder_name`, `title_deed_path`, `status`, `is_active`, `created_at`, `updated_at`, `created_by`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `rejection_reason`, `completed_at`, `contract_template_id`, `payment_stage`, `down_payment_paid`, `down_payment_balance`, `installments_paid_count`, `last_installment_date`) VALUES
(25, 3, 25, 48, '2025-12-29', 'RES-2025-0001', 3073000.00, 400000.00, 20, 133650.00, 0.00, 0.00, '', NULL, 'active', 1, '2025-12-29 06:13:18', '2025-12-29 06:14:55', 9, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'paying_installments', 0.00, 400000.00, 0, '2025-12-29');

-- --------------------------------------------------------

--
-- Table structure for table `reservation_cancellations`
--

CREATE TABLE `reservation_cancellations` (
  `cancellation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `cancellation_number` varchar(50) NOT NULL,
  `cancellation_date` date NOT NULL,
  `cancellation_reason` enum('customer_request','payment_default','mutual_agreement','breach_of_contract','plot_unavailable','other') NOT NULL,
  `detailed_reason` text DEFAULT NULL,
  `total_amount_paid` decimal(15,2) DEFAULT 0.00,
  `refund_amount` decimal(15,2) DEFAULT 0.00,
  `penalty_amount` decimal(15,2) DEFAULT 0.00,
  `amount_forfeited` decimal(15,2) DEFAULT 0.00,
  `plot_id` int(11) NOT NULL,
  `plot_return_status` enum('returned_to_market','reserved_for_other','blocked') DEFAULT 'returned_to_market',
  `plot_returned_date` date DEFAULT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `contract_termination_date` date DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancellation_letter_path` varchar(255) DEFAULT NULL,
  `termination_agreement_path` varchar(255) DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Reservation cancellations tracking';

--
-- Triggers `reservation_cancellations`
--
DELIMITER $$
CREATE TRIGGER `after_cancellation_insert` AFTER INSERT ON `reservation_cancellations` FOR EACH ROW BEGIN
    IF NEW.plot_return_status = 'returned_to_market' THEN
        UPDATE plots 
        SET status = 'available',
            updated_at = NOW()
        WHERE plot_id = NEW.plot_id 
        AND company_id = NEW.company_id;
        
        UPDATE reservations
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE reservation_id = NEW.reservation_id
        AND company_id = NEW.company_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_permission_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Role-permission mappings';

-- --------------------------------------------------------

--
-- Table structure for table `sales_quotations`
--

CREATE TABLE `sales_quotations` (
  `quotation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `quotation_number` varchar(50) NOT NULL,
  `quotation_date` date NOT NULL,
  `valid_until_date` date NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `quotation_type` enum('plot_sale','service','mixed') DEFAULT 'plot_sale',
  `plot_id` int(11) DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `payment_terms` text DEFAULT NULL,
  `down_payment_required` decimal(15,2) DEFAULT NULL,
  `installment_months` int(11) DEFAULT NULL,
  `status` enum('draft','sent','viewed','accepted','rejected','expired','revised') DEFAULT 'draft',
  `accepted_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `converted_to_reservation_id` int(11) DEFAULT NULL,
  `quotation_pdf_path` varchar(255) DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales quotations';

-- --------------------------------------------------------

--
-- Table structure for table `service_quotations`
--

CREATE TABLE `service_quotations` (
  `quotation_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `quotation_number` varchar(50) NOT NULL,
  `quotation_date` date NOT NULL,
  `valid_until_date` date NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `service_request_id` int(11) DEFAULT NULL,
  `service_type_id` int(11) NOT NULL,
  `plot_size` decimal(10,2) DEFAULT NULL,
  `location_details` text DEFAULT NULL,
  `quoted_amount` decimal(15,2) NOT NULL,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_terms` text DEFAULT NULL,
  `scope_of_work` text DEFAULT NULL,
  `deliverables` text DEFAULT NULL,
  `timeline` varchar(200) DEFAULT NULL,
  `status` enum('draft','sent','accepted','rejected','expired','revised') DEFAULT 'draft',
  `accepted_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `quotation_pdf_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Service quotations';

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

CREATE TABLE `service_requests` (
  `service_request_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `request_date` date NOT NULL,
  `service_type_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `service_description` text NOT NULL,
  `plot_size` decimal(10,2) DEFAULT NULL COMMENT 'If applicable',
  `location_details` text DEFAULT NULL,
  `quoted_price` decimal(15,2) DEFAULT NULL,
  `final_price` decimal(15,2) DEFAULT NULL,
  `requested_start_date` date DEFAULT NULL,
  `actual_start_date` date DEFAULT NULL,
  `expected_completion_date` date DEFAULT NULL,
  `actual_completion_date` date DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL COMMENT 'User/consultant assigned',
  `status` enum('pending','quoted','approved','in_progress','completed','cancelled','on_hold') DEFAULT 'pending',
  `quotation_path` varchar(255) DEFAULT NULL,
  `completion_report_path` varchar(255) DEFAULT NULL,
  `payment_status` enum('unpaid','partial','paid') DEFAULT 'unpaid',
  `amount_paid` decimal(15,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Service requests and orders';

-- --------------------------------------------------------

--
-- Table structure for table `service_types`
--

CREATE TABLE `service_types` (
  `service_type_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `service_code` varchar(20) DEFAULT NULL,
  `service_name` varchar(200) NOT NULL,
  `service_category` enum('land_evaluation','title_processing','consultation','construction','survey','legal','other') NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(15,2) DEFAULT NULL,
  `price_unit` varchar(50) DEFAULT NULL COMMENT 'per sqm, per plot, flat fee',
  `estimated_duration_days` int(11) DEFAULT NULL,
  `requires_approval` tinyint(1) DEFAULT 1,
  `approval_amount_threshold` decimal(15,2) DEFAULT 0.00,
  `documents_required` text DEFAULT NULL COMMENT 'JSON array of required documents',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Service offerings catalog';

-- --------------------------------------------------------

--
-- Table structure for table `sms_campaigns`
--

CREATE TABLE `sms_campaigns` (
  `campaign_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `campaign_name` varchar(200) NOT NULL,
  `campaign_type` enum('promotional','reminder','notification','alert','custom') NOT NULL,
  `target_audience` enum('all_customers','active_customers','debtors','leads','custom_group','manual_selection') NOT NULL,
  `message_template_id` int(11) DEFAULT NULL,
  `message_content` text NOT NULL,
  `scheduled_date` datetime DEFAULT NULL,
  `send_immediately` tinyint(1) DEFAULT 0,
  `total_recipients` int(11) DEFAULT 0,
  `sent_count` int(11) DEFAULT 0,
  `delivered_count` int(11) DEFAULT 0,
  `failed_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','completed','cancelled','failed') DEFAULT 'draft',
  `sender_id` varchar(20) DEFAULT NULL COMMENT 'SMS sender name',
  `cost_per_sms` decimal(8,4) DEFAULT 0.0000,
  `total_cost` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS campaigns';

-- --------------------------------------------------------

--
-- Table structure for table `sms_contact_groups`
--

CREATE TABLE `sms_contact_groups` (
  `group_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `total_contacts` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS contact groups';

-- --------------------------------------------------------

--
-- Table structure for table `sms_group_contacts`
--

CREATE TABLE `sms_group_contacts` (
  `group_contact_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `contact_name` varchar(200) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contacts in SMS groups';

-- --------------------------------------------------------

--
-- Table structure for table `sms_recipients`
--

CREATE TABLE `sms_recipients` (
  `recipient_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `recipient_name` varchar(200) DEFAULT NULL,
  `personalized_message` text DEFAULT NULL,
  `send_status` enum('pending','sent','delivered','failed','invalid_number') DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `sms_provider_id` varchar(100) DEFAULT NULL,
  `cost` decimal(8,4) DEFAULT 0.0000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS campaign recipients and delivery status';

-- --------------------------------------------------------

--
-- Table structure for table `sms_settings`
--

CREATE TABLE `sms_settings` (
  `setting_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `provider_name` varchar(100) DEFAULT 'Bongo Live' COMMENT 'SMS gateway provider',
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `sender_id` varchar(20) DEFAULT NULL,
  `api_url` varchar(255) DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `auto_send_payment_receipts` tinyint(1) DEFAULT 0,
  `auto_send_reminders` tinyint(1) DEFAULT 0,
  `reminder_days_before_due` int(11) DEFAULT 3,
  `is_active` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS gateway settings';

-- --------------------------------------------------------

--
-- Table structure for table `sms_templates`
--

CREATE TABLE `sms_templates` (
  `template_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(200) NOT NULL,
  `template_category` enum('payment_reminder','payment_received','contract_ready','title_deed_update','promotional','general') NOT NULL,
  `message_template` text NOT NULL COMMENT 'Template with placeholders',
  `available_variables` text DEFAULT NULL COMMENT 'JSON array of available variables',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS message templates';

-- --------------------------------------------------------

--
-- Table structure for table `stock_alerts`
--

CREATE TABLE `stock_alerts` (
  `alert_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `alert_type` enum('low_stock','out_of_stock','overstock') NOT NULL,
  `quantity_on_hand` decimal(15,2) DEFAULT 0.00,
  `reorder_level` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','resolved','ignored') DEFAULT 'active',
  `notified_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `movement_number` varchar(50) NOT NULL,
  `movement_date` date NOT NULL,
  `movement_type` enum('purchase','sale','transfer','adjustment','return') NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_cost` decimal(15,2) DEFAULT NULL,
  `total_cost` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_cost`) STORED,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stores`
--

CREATE TABLE `stores` (
  `store_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `store_code` varchar(50) NOT NULL,
  `store_name` varchar(255) NOT NULL,
  `store_type` enum('warehouse','retail','distribution','transit') DEFAULT 'warehouse',
  `location` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `capacity` decimal(15,2) DEFAULT NULL COMMENT 'Maximum items capacity',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `store_locations`
--

CREATE TABLE `store_locations` (
  `store_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `store_code` varchar(20) NOT NULL,
  `store_name` varchar(100) NOT NULL,
  `store_type` enum('main','branch','warehouse','site') DEFAULT 'main',
  `physical_location` text DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `store_manager_id` int(11) DEFAULT NULL,
  `storage_capacity` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Store/warehouse locations';

-- --------------------------------------------------------

--
-- Table structure for table `store_stock`
--

CREATE TABLE `store_stock` (
  `store_stock_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_on_hand` decimal(10,2) DEFAULT 0.00,
  `quantity_reserved` decimal(10,2) DEFAULT 0.00,
  `quantity_available` decimal(10,2) GENERATED ALWAYS AS (`quantity_on_hand` - `quantity_reserved`) STORED,
  `reorder_level` decimal(10,2) DEFAULT NULL,
  `reorder_quantity` decimal(10,2) DEFAULT NULL,
  `bin_location` varchar(50) DEFAULT NULL,
  `shelf_number` varchar(50) DEFAULT NULL,
  `last_movement_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stock levels per store location';

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `supplier_name` varchar(200) NOT NULL,
  `supplier_code` varchar(50) DEFAULT NULL,
  `supplier_type` varchar(50) DEFAULT 'other',
  `category` varchar(100) DEFAULT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `tin_number` varchar(100) DEFAULT NULL,
  `contact_person` varchar(200) DEFAULT NULL,
  `contact_title` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `alternative_phone` varchar(20) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `physical_address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Tanzania',
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `swift_code` varchar(50) DEFAULT NULL,
  `payment_terms` varchar(50) DEFAULT 'net_30',
  `account_number` varchar(50) DEFAULT NULL,
  `credit_days` int(11) DEFAULT 30,
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `lead_time_days` int(11) DEFAULT 0,
  `rating` tinyint(1) DEFAULT 3,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `log_id` bigint(20) NOT NULL,
  `log_level` enum('info','warning','error','critical') NOT NULL,
  `log_message` text NOT NULL,
  `module_name` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `line_number` int(11) DEFAULT NULL,
  `context_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_data`)),
  `stack_trace` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System error and debug logs';

-- --------------------------------------------------------

--
-- Table structure for table `system_roles`
--

CREATE TABLE `system_roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `role_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 0 COMMENT 'Cannot be deleted if TRUE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System-wide role definitions';

--
-- Dumping data for table `system_roles`
--

INSERT INTO `system_roles` (`role_id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES
(1, 'Super Admin', 'SUPER_ADMIN', 'Platform super administrator', 1, '2025-11-29 07:19:26'),
(2, 'Company Admin', 'COMPANY_ADMIN', 'Company administrator with full access', 1, '2025-11-29 07:19:26'),
(3, 'Manager', 'MANAGER', 'Department manager', 1, '2025-11-29 07:19:26'),
(4, 'Accountant', 'ACCOUNTANT', 'Finance and accounting staff', 1, '2025-11-29 07:19:26'),
(5, 'Finance Officer', 'FINANCE_OFFICER', 'Finance department staff', 1, '2025-11-29 07:19:26'),
(6, 'HR Officer', 'HR_OFFICER', 'Human resources staff', 1, '2025-11-29 07:19:26'),
(7, 'Procurement Officer', 'PROCUREMENT', 'Procurement and purchasing staff', 1, '2025-11-29 07:19:26'),
(8, 'Sales Officer', 'SALES', 'Sales and marketing staff', 1, '2025-11-29 07:19:26'),
(9, 'Inventory Clerk', 'INVENTORY', 'Inventory management staff', 1, '2025-11-29 07:19:26'),
(10, 'Receptionist', 'RECEPTIONIST', 'Front office staff', 1, '2025-11-29 07:19:26'),
(11, 'Auditor', 'AUDITOR', 'Internal/external auditor (read-only)', 1, '2025-11-29 07:19:26'),
(12, 'User', 'USER', 'Regular user with limited access', 1, '2025-11-29 07:19:26');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `setting_category` varchar(100) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `data_type` enum('string','integer','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurable system settings';

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `task_number` varchar(50) NOT NULL,
  `task_title` varchar(255) NOT NULL,
  `task_description` text DEFAULT NULL,
  `task_type` enum('general','project','sales','finance','hr','procurement','other') NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `assigned_to` int(11) NOT NULL COMMENT 'User who will do the task',
  `assigned_by` int(11) NOT NULL COMMENT 'Supervisor who assigned',
  `department_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `completion_date` date DEFAULT NULL,
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `actual_hours` decimal(5,2) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','on_hold','cancelled','rejected') DEFAULT 'pending',
  `completion_percentage` int(11) DEFAULT 0,
  `requires_approval` tinyint(1) DEFAULT 1,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `attachments` text DEFAULT NULL COMMENT 'JSON array of file paths',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Task management';

-- --------------------------------------------------------

--
-- Table structure for table `task_checklists`
--

CREATE TABLE `task_checklists` (
  `checklist_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `item_description` varchar(255) NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `display_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Task checklists';

-- --------------------------------------------------------

--
-- Table structure for table `task_updates`
--

CREATE TABLE `task_updates` (
  `update_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `update_date` datetime NOT NULL DEFAULT current_timestamp(),
  `update_type` enum('status_change','progress_update','comment','attachment','approval_request','approval_response') NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `completion_percentage` int(11) DEFAULT NULL,
  `update_description` text DEFAULT NULL,
  `hours_spent` decimal(5,2) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `updated_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Task progress updates';

-- --------------------------------------------------------

--
-- Table structure for table `tax_transactions`
--

CREATE TABLE `tax_transactions` (
  `tax_transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `transaction_number` varchar(50) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('sales','purchase','payroll','withholding','other') NOT NULL,
  `tax_type_id` int(11) NOT NULL,
  `taxable_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','filed','paid','cancelled') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tax transactions and collections';

--
-- Dumping data for table `tax_transactions`
--

INSERT INTO `tax_transactions` (`tax_transaction_id`, `company_id`, `transaction_number`, `transaction_date`, `transaction_type`, `tax_type_id`, `taxable_amount`, `tax_amount`, `total_amount`, `customer_id`, `supplier_id`, `invoice_number`, `description`, `status`, `payment_date`, `remarks`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'TAX-WHT-2025-00001', '2025-12-23', 'withholding', 1, 188000.00, 9400.00, 197400.00, NULL, NULL, NULL, 'Withholding tax for commission COM-2025-00001', 'pending', NULL, NULL, 9, '2025-12-23 05:59:41', '2025-12-23 05:59:41'),
(2, 3, 'TAX-WHT-2025-00002', '2025-12-23', 'withholding', 1, 528500.00, 26425.00, 554925.00, NULL, NULL, NULL, 'Withholding tax for commission COM-2025-00002', 'pending', NULL, NULL, 9, '2025-12-23 06:03:45', '2025-12-23 06:03:45'),
(3, 3, 'TAX-WHT-2025-00001', '2025-12-23', 'withholding', 1, 188000.00, 9400.00, 197400.00, NULL, NULL, NULL, 'Withholding tax for commission COM-2025-00001', 'pending', NULL, NULL, 9, '2025-12-23 06:31:09', '2025-12-23 06:31:09');

-- --------------------------------------------------------

--
-- Table structure for table `tax_types`
--

CREATE TABLE `tax_types` (
  `tax_type_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_code` varchar(20) NOT NULL,
  `tax_category` enum('vat','withholding','excise','customs','other') NOT NULL,
  `tax_rate` decimal(10,4) NOT NULL,
  `calculation_method` enum('percentage','fixed','tiered') DEFAULT 'percentage',
  `applies_to` enum('sales','purchases','both') DEFAULT 'both',
  `account_code` varchar(20) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_compound` tinyint(1) DEFAULT 0,
  `is_inclusive` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tax_types`
--

INSERT INTO `tax_types` (`tax_type_id`, `company_id`, `tax_name`, `tax_code`, `tax_category`, `tax_rate`, `calculation_method`, `applies_to`, `account_code`, `effective_date`, `expiry_date`, `description`, `is_compound`, `is_inclusive`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'Standard VAT 18%', 'VAT18', 'vat', 18.0000, 'percentage', 'both', NULL, NULL, NULL, 'Standard Value Added Tax rate', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(2, 3, 'Zero-Rated VAT', 'VAT0', 'vat', 0.0000, 'percentage', 'sales', NULL, NULL, NULL, 'Zero-rated supplies (exports, basic food items, medicines)', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(3, 3, 'Exempt VAT', 'VATEX', 'vat', 0.0000, 'percentage', 'sales', NULL, NULL, NULL, 'VAT exempt supplies (financial services, education, medical)', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(4, 3, 'WHT - Services 5%', 'WHT5', 'withholding', 5.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Withholding tax on services rendered by residents', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(5, 3, 'WHT - Consultancy 15%', 'WHT15', 'withholding', 15.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Withholding tax on consultancy and professional fees', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(6, 3, 'WHT - Dividends 10%', 'WHTDIV10', 'withholding', 10.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Withholding tax on dividend payments to residents', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(7, 3, 'WHT - Rent 10%', 'WHTRENT', 'withholding', 10.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Withholding tax on rental income', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(8, 3, 'WHT - Royalties 15%', 'WHTROY', 'withholding', 15.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Withholding tax on royalty payments', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(9, 3, 'WHT - Interest 10%', 'WHTINT', 'withholding', 8.0000, 'percentage', 'purchases', '', NULL, NULL, 'Withholding tax on interest payments', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 20:26:03'),
(10, 3, 'Skills & Development Levy 5%', 'SDL5', 'other', 5.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Skills and Development Levy on services', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(11, 3, 'Excise Duty - Alcohol 30%', 'EXCALC30', 'excise', 30.0000, 'percentage', 'sales', NULL, NULL, NULL, 'Excise duty on alcoholic beverages', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(12, 3, 'Excise Duty - Tobacco 50%', 'EXCTOB50', 'excise', 50.0000, 'percentage', 'sales', NULL, NULL, NULL, 'Excise duty on tobacco products', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03'),
(13, 3, 'Import Duty - Standard 25%', 'IMPSTD25', 'customs', 25.0000, 'percentage', 'purchases', NULL, NULL, NULL, 'Standard import duty rate', 0, 0, 1, NULL, '2025-12-25 19:46:03', '2025-12-25 19:46:03');

-- --------------------------------------------------------

--
-- Table structure for table `title_deed_costs`
--

CREATE TABLE `title_deed_costs` (
  `cost_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `processing_id` int(11) NOT NULL,
  `cost_type` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_by` varchar(50) DEFAULT 'customer',
  `payment_date` date DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `title_deed_processing`
--

CREATE TABLE `title_deed_processing` (
  `processing_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `processing_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `plot_id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `current_stage` varchar(50) DEFAULT 'startup',
  `total_cost` decimal(15,2) DEFAULT 0.00,
  `customer_contribution` decimal(15,2) DEFAULT 0.00,
  `started_date` date DEFAULT NULL,
  `expected_completion_date` date DEFAULT NULL,
  `actual_completion_date` date DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `title_deed_processing`
--

INSERT INTO `title_deed_processing` (`processing_id`, `company_id`, `processing_number`, `customer_id`, `plot_id`, `reservation_id`, `current_stage`, `total_cost`, `customer_contribution`, `started_date`, `expected_completion_date`, `actual_completion_date`, `assigned_to`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'TD-2025-0001', 3, 167, 16, 'startup', 100000.00, 5000.00, '2025-12-22', '2025-12-22', NULL, 10, '', 9, '2025-12-22 13:43:50', '2025-12-22 13:43:50'),
(2, 3, 'TD-2025-0002', 4, 158, 17, 'municipal', 300000.00, 300000.00, '2025-12-24', '2026-06-24', NULL, 16, 'start up', 11, '2025-12-24 12:48:35', '2025-12-26 02:39:54');

-- --------------------------------------------------------

--
-- Table structure for table `title_deed_stages`
--

CREATE TABLE `title_deed_stages` (
  `stage_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `processing_id` int(11) NOT NULL,
  `stage_name` varchar(50) NOT NULL,
  `stage_order` int(11) NOT NULL,
  `stage_status` varchar(20) DEFAULT 'pending',
  `started_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `documents` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `title_deed_stages`
--

INSERT INTO `title_deed_stages` (`stage_id`, `company_id`, `processing_id`, `stage_name`, `stage_order`, `stage_status`, `started_date`, `completed_date`, `notes`, `documents`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 1, 'startup', 1, 'in_progress', '2025-12-22', NULL, NULL, NULL, 9, '2025-12-22 13:43:50', '2025-12-22 13:43:50'),
(2, 3, 2, 'startup', 1, 'in_progress', '2025-12-24', NULL, NULL, NULL, 11, '2025-12-24 12:48:35', '2025-12-24 12:48:35'),
(3, 3, 2, 'municipal', 2, 'in_progress', '2025-12-26', '0000-00-00', '', NULL, 9, '2025-12-26 02:39:54', '2025-12-26 02:39:54');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL COMMENT 'Multi-tenant link',
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `full_name` varchar(300) GENERATED ALWAYS AS (concat(`first_name`,' ',ifnull(concat(`middle_name`,' '),''),`last_name`)) STORED,
  `phone1` varchar(50) DEFAULT NULL,
  `phone2` varchar(50) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `ward` varchar(100) DEFAULT NULL,
  `village` varchar(100) DEFAULT NULL,
  `street_address` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `guardian1_name` varchar(200) DEFAULT NULL,
  `guardian1_relationship` varchar(100) DEFAULT NULL,
  `guardian2_name` varchar(200) DEFAULT NULL,
  `guardian2_relationship` varchar(100) DEFAULT NULL,
  `can_get_commission` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `is_email_verified` tinyint(1) DEFAULT 0,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `password_reset_token` varchar(100) DEFAULT NULL,
  `password_reset_expires` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User accounts (multi-tenant)';

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `company_id`, `username`, `email`, `password_hash`, `first_name`, `middle_name`, `last_name`, `phone1`, `phone2`, `region`, `district`, `ward`, `village`, `street_address`, `profile_picture`, `gender`, `date_of_birth`, `national_id`, `guardian1_name`, `guardian1_relationship`, `guardian2_name`, `guardian2_relationship`, `can_get_commission`, `is_active`, `is_email_verified`, `is_admin`, `is_super_admin`, `last_login_at`, `last_login_ip`, `password_reset_token`, `password_reset_expires`, `created_at`, `updated_at`, `created_by`) VALUES
(9, 3, 'admin', 'admin@mkumbiinvestment.co.tz', 'Mk@mb!@2025', 'admin', '', 'mkumbi', '0745381762', '', '', '', '', '', 'Dar Es Salaam, Kinyerez', NULL, 'female', '0000-00-00', '', NULL, NULL, NULL, NULL, 0, 1, 0, 0, 1, NULL, NULL, NULL, NULL, '2025-12-11 20:03:40', '2025-12-18 09:02:38', NULL),
(10, 3, 'hamisiismail69.hi', 'hamisiismail69.hi@gmail.com', '$2y$10$FL9f79K3akAe.liEI4YzpeeXLE.TJ6Lx99omuDrOCP/VIE0LfnXS6', 'Hamisi', 'Ismail', 'Khalfani', '+255 786 133 399', '+255 716 133 39', '', '', '', '', 'Ilala 25423', NULL, 'male', '1992-11-05', '19921105121050000225', NULL, NULL, NULL, NULL, 0, 1, 0, 0, 0, NULL, NULL, NULL, NULL, '2025-12-17 08:51:32', '2025-12-24 01:36:02', 9),
(11, 3, 'marketing.mkumbiinvestment', 'marketing.mkumbiinvestment@gmail.com', '123456', 'Sara', 'Michael', 'Mbumi', '+255 656 777 099', '', '', '', '', '', '', NULL, 'male', '2000-01-01', '', NULL, NULL, NULL, NULL, 1, 1, 0, 0, 0, NULL, NULL, NULL, NULL, '2025-12-18 08:18:53', '2025-12-18 08:20:44', 9),
(12, 3, 'account@mkumbiinvestment.co.tz', 'account@mkumbiinvestment.co.tz', '123456', 'Kaizar', 'Andrea', 'Nyenza', '+255 752 759 940', '+255', '', '', '', '', '', NULL, 'male', '2022-03-25', '20020325161130000123', NULL, NULL, NULL, NULL, 0, 1, 0, 0, 0, NULL, NULL, NULL, NULL, '2025-12-18 12:31:57', '2025-12-23 12:37:15', 9),
(13, 3, 'marketing', 'marketing@mkumbiinvestment.co.tz', '$2y$10$ZzP7O.iUmQ4KaVEHB6kCauT1ZC4pWkagS/f0GP6YW3r7Ap/pWru6C', 'Irene', 'Jacob', 'Mwita', '+255 750 634 719', '', 'Dar es salaam', 'Ilala', 'Ukonga', 'Mazizini', '', NULL, 'female', '1998-06-20', '1998', NULL, NULL, NULL, NULL, 0, 1, 0, 0, 0, NULL, NULL, NULL, NULL, '2025-12-23 12:06:00', '2025-12-23 12:08:11', 9),
(16, 3, 'land', 'land@mkumbiinvestment.co.tz', '$2y$10$5RRHcXkqZBxkygfV8d4OZ.PysJA2fo5I5GX7yhPiQBy2wp5EZpO4a', 'Willy', 'Juma', 'Ngoma', '0622182087', '0652318759', 'DAR-ES-SALAAM', 'KINONDONI', 'MWANANYAMALA', 'MWINJUMA', '', NULL, 'male', '1995-12-24', '1995122445112000727', NULL, NULL, NULL, NULL, 0, 1, 0, 0, 0, NULL, NULL, NULL, NULL, '2025-12-24 12:02:37', '2025-12-24 12:02:37', 9);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_role_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-role assignments';

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_role_id`, `user_id`, `role_id`, `assigned_at`, `assigned_by`) VALUES
(3, 11, 4, '2025-12-18 08:20:44', 9),
(4, 11, 11, '2025-12-18 08:20:44', 9),
(5, 11, 5, '2025-12-18 08:20:44', 9),
(6, 11, 9, '2025-12-18 08:20:44', 9),
(7, 11, 8, '2025-12-18 08:20:44', 9),
(30, 12, 4, '2025-12-18 13:29:46', 9),
(31, 12, 11, '2025-12-18 13:29:46', 9),
(32, 12, 2, '2025-12-18 13:29:46', 9),
(33, 12, 5, '2025-12-18 13:29:46', 9),
(34, 12, 6, '2025-12-18 13:29:46', 9),
(35, 12, 9, '2025-12-18 13:29:46', 9),
(36, 12, 3, '2025-12-18 13:29:46', 9),
(37, 12, 7, '2025-12-18 13:29:46', 9),
(38, 12, 10, '2025-12-18 13:29:46', 9),
(39, 12, 8, '2025-12-18 13:29:46', 9),
(40, 12, 12, '2025-12-18 13:29:46', 9);

-- --------------------------------------------------------

--
-- Table structure for table `villages`
--

CREATE TABLE `villages` (
  `village_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `ward_id` int(11) NOT NULL,
  `village_name` varchar(100) NOT NULL,
  `village_code` varchar(20) DEFAULT NULL,
  `chairman_name` varchar(200) DEFAULT NULL,
  `chairman_phone` varchar(50) DEFAULT NULL,
  `mtendaji_name` varchar(200) DEFAULT NULL COMMENT 'Village Executive Officer',
  `mtendaji_phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Village information with leadership';

--
-- Dumping data for table `villages`
--

INSERT INTO `villages` (`village_id`, `company_id`, `ward_id`, `village_name`, `village_code`, `chairman_name`, `chairman_phone`, `mtendaji_name`, `mtendaji_phone`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'kariakoo', 'VD001', 'gloria john', '07363636737', 'juma jackson', '06748849493', 1, '2025-11-29 13:03:24', '2025-11-29 13:03:24');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_active_plot_holds`
-- (See below for the actual view)
--
CREATE TABLE `vw_active_plot_holds` (
`hold_id` int(11)
,`company_id` int(11)
,`plot_id` int(11)
,`plot_number` varchar(50)
,`block_number` varchar(50)
,`project_name` varchar(200)
,`project_code` varchar(50)
,`hold_type` enum('customer_interest','internal_review','maintenance','legal_issue','dispute','management_hold','other')
,`hold_reason` text
,`hold_start_date` datetime
,`expected_release_date` date
,`hold_duration_days` int(8)
,`customer_id` int(11)
,`priority` enum('low','medium','high','critical')
,`status` enum('active','released','expired','cancelled')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_customer_aging`
-- (See below for the actual view)
--
CREATE TABLE `vw_customer_aging` (
`customer_id` int(11)
,`company_id` int(11)
,`customer_name` varchar(201)
,`phone` varchar(20)
,`email` varchar(150)
,`total_invoiced` decimal(37,2)
,`total_paid` decimal(37,2)
,`current_balance` decimal(38,2)
,`current_amount` decimal(38,2)
,`days_1_30` decimal(38,2)
,`days_31_60` decimal(38,2)
,`days_61_90` decimal(38,2)
,`days_over_90` decimal(38,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_customer_balances`
-- (See below for the actual view)
--
CREATE TABLE `vw_customer_balances` (
`customer_id` int(11)
,`company_id` int(11)
,`customer_name` varchar(201)
,`phone` varchar(20)
,`email` varchar(150)
,`total_debits` decimal(37,2)
,`total_credits` decimal(37,2)
,`balance` decimal(38,2)
,`last_transaction_date` date
,`transaction_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_plot_activity_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_plot_activity_summary` (
`company_id` int(11)
,`plot_id` int(11)
,`plot_number` varchar(50)
,`block_number` varchar(50)
,`project_name` varchar(200)
,`project_code` varchar(50)
,`total_movements` bigint(21)
,`status_changes` bigint(21)
,`reservations` bigint(21)
,`sales` bigint(21)
,`cancellations` bigint(21)
,`transfers` bigint(21)
,`last_activity_date` datetime
,`first_activity_date` datetime
,`days_since_last_activity` int(8)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_plot_availability`
-- (See below for the actual view)
--
CREATE TABLE `vw_plot_availability` (
`plot_id` int(11)
,`company_id` int(11)
,`project_id` int(11)
,`project_name` varchar(200)
,`project_code` varchar(50)
,`plot_number` varchar(50)
,`block_number` varchar(50)
,`area` decimal(10,2)
,`selling_price` decimal(15,2)
,`price_per_sqm` decimal(15,2)
,`status` enum('available','reserved','sold','blocked')
,`corner_plot` tinyint(1)
,`coordinates` varchar(50)
,`days_in_inventory` int(8)
,`inventory_category` varchar(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_plot_inventory_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_plot_inventory_summary` (
`company_id` int(11)
,`project_id` int(11)
,`project_name` varchar(200)
,`project_code` varchar(50)
,`total_plots` bigint(21)
,`available_plots` decimal(22,0)
,`reserved_plots` decimal(22,0)
,`sold_plots` decimal(22,0)
,`blocked_plots` decimal(22,0)
,`total_inventory_value` decimal(37,2)
,`available_value` decimal(37,2)
,`sold_value` decimal(37,2)
,`avg_plot_price` decimal(19,6)
,`min_plot_price` decimal(15,2)
,`max_plot_price` decimal(15,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_plot_transfer_stats`
-- (See below for the actual view)
--
CREATE TABLE `vw_plot_transfer_stats` (
`company_id` int(11)
,`project_id` int(11)
,`project_name` varchar(200)
,`total_transfers` bigint(21)
,`pending_transfers` bigint(21)
,`approved_transfers` bigint(21)
,`completed_transfers` bigint(21)
,`rejected_transfers` bigint(21)
,`total_transfer_fees` decimal(37,2)
,`avg_transfer_fee` decimal(19,6)
,`swap_count` bigint(21)
,`upgrade_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_statement`
-- (See below for the actual view)
--
CREATE TABLE `v_customer_statement` (
`transaction_date` date
,`reference` varchar(50)
,`receipt_number` varchar(50)
,`description` varchar(50)
,`debit` decimal(15,2)
,`credit` decimal(15,2)
,`balance` decimal(15,2)
,`reservation_number` varchar(50)
,`customer_id` int(11)
,`customer_name` varchar(300)
,`company_id` int(11)
,`status` varchar(16)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_payment_history`
-- (See below for the actual view)
--
CREATE TABLE `v_payment_history` (
`payment_id` int(11)
,`company_id` int(11)
,`reservation_id` int(11)
,`payment_date` date
,`payment_number` varchar(50)
,`receipt_number` varchar(50)
,`amount` decimal(15,2)
,`payment_stage` varchar(50)
,`installment_number` int(11)
,`expected_amount` decimal(15,2)
,`is_partial` tinyint(1)
,`stage_balance_before` decimal(15,2)
,`stage_balance_after` decimal(15,2)
,`payment_method` enum('cash','bank_transfer','mobile_money','cheque','card')
,`status` enum('pending_approval','pending','approved','rejected','cancelled')
,`reservation_number` varchar(50)
,`current_payment_stage` varchar(50)
,`total_down_payment_required` decimal(15,2)
,`down_payment_paid` decimal(15,2)
,`down_payment_balance` decimal(15,2)
,`installment_amount_required` decimal(15,2)
,`installments_paid_count` int(11)
,`reservation_total` decimal(15,2)
,`customer_name` varchar(300)
,`customer_phone` varchar(20)
,`plot_number` varchar(50)
,`project_name` varchar(200)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_title_deed_processing_details`
-- (See below for the actual view)
--
CREATE TABLE `v_title_deed_processing_details` (
`processing_id` int(11)
,`processing_number` varchar(50)
,`company_id` int(11)
,`current_stage` varchar(50)
,`total_cost` decimal(15,2)
,`customer_contribution` decimal(15,2)
,`started_date` date
,`expected_completion_date` date
,`actual_completion_date` date
,`customer_id` int(11)
,`customer_name` varchar(300)
,`customer_phone` varchar(20)
,`customer_email` varchar(150)
,`plot_id` int(11)
,`plot_number` varchar(50)
,`block_number` varchar(50)
,`plot_area` decimal(10,2)
,`project_id` int(11)
,`project_name` varchar(200)
,`assigned_to_name` varchar(300)
,`completed_stages` bigint(21)
,`total_cost_entries` bigint(21)
,`created_at` timestamp
,`updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `wards`
--

CREATE TABLE `wards` (
  `ward_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `district_id` int(11) NOT NULL,
  `ward_name` varchar(100) NOT NULL,
  `ward_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wards`
--

INSERT INTO `wards` (`ward_id`, `company_id`, `district_id`, `ward_name`, `ward_code`, `is_active`, `created_at`) VALUES
(1, 1, 1, 'iala', 'WD001', 1, '2025-11-29 13:02:26');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approval_actions`
--
ALTER TABLE `approval_actions`
  ADD PRIMARY KEY (`approval_action_id`);

--
-- Indexes for table `approval_levels`
--
ALTER TABLE `approval_levels`
  ADD PRIMARY KEY (`approval_level_id`);

--
-- Indexes for table `approval_requests`
--
ALTER TABLE `approval_requests`
  ADD PRIMARY KEY (`approval_request_id`);

--
-- Indexes for table `approval_workflows`
--
ALTER TABLE `approval_workflows`
  ADD PRIMARY KEY (`workflow_id`);

--
-- Indexes for table `asset_categories`
--
ALTER TABLE `asset_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `asset_depreciation`
--
ALTER TABLE `asset_depreciation`
  ADD PRIMARY KEY (`depreciation_id`);

--
-- Indexes for table `asset_maintenance`
--
ALTER TABLE `asset_maintenance`
  ADD PRIMARY KEY (`maintenance_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`bank_account_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `bank_statements`
--
ALTER TABLE `bank_statements`
  ADD PRIMARY KEY (`statement_id`);

--
-- Indexes for table `bank_transactions`
--
ALTER TABLE `bank_transactions`
  ADD PRIMARY KEY (`bank_transaction_id`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`budget_id`);

--
-- Indexes for table `budget_lines`
--
ALTER TABLE `budget_lines`
  ADD PRIMARY KEY (`budget_line_id`);

--
-- Indexes for table `budget_revisions`
--
ALTER TABLE `budget_revisions`
  ADD PRIMARY KEY (`revision_id`),
  ADD KEY `idx_budget` (`budget_id`);

--
-- Indexes for table `campaigns`
--
ALTER TABLE `campaigns`
  ADD PRIMARY KEY (`campaign_id`);

--
-- Indexes for table `cash_transactions`
--
ALTER TABLE `cash_transactions`
  ADD PRIMARY KEY (`cash_transaction_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_date` (`transaction_date`),
  ADD KEY `idx_cash_date` (`transaction_date`),
  ADD KEY `idx_cash_type` (`transaction_type`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`account_id`);

--
-- Indexes for table `cheque_transactions`
--
ALTER TABLE `cheque_transactions`
  ADD PRIMARY KEY (`cheque_transaction_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `commissions`
--
ALTER TABLE `commissions`
  ADD PRIMARY KEY (`commission_id`),
  ADD UNIQUE KEY `idx_commission_number` (`commission_number`,`company_id`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_user_commissions` (`user_id`),
  ADD KEY `idx_reservation_commissions` (`reservation_id`);

--
-- Indexes for table `commission_payments`
--
ALTER TABLE `commission_payments`
  ADD PRIMARY KEY (`commission_payment_id`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `commission_payment_requests`
--
ALTER TABLE `commission_payment_requests`
  ADD PRIMARY KEY (`commission_payment_request_id`);

--
-- Indexes for table `commission_structures`
--
ALTER TABLE `commission_structures`
  ADD PRIMARY KEY (`commission_structure_id`);

--
-- Indexes for table `commission_tiers`
--
ALTER TABLE `commission_tiers`
  ADD PRIMARY KEY (`commission_tier_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`company_id`);

--
-- Indexes for table `company_loans`
--
ALTER TABLE `company_loans`
  ADD PRIMARY KEY (`loan_id`);

--
-- Indexes for table `company_loan_payments`
--
ALTER TABLE `company_loan_payments`
  ADD PRIMARY KEY (`payment_id`);

--
-- Indexes for table `contract_templates`
--
ALTER TABLE `contract_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD UNIQUE KEY `unique_template_code` (`company_id`,`template_code`);

--
-- Indexes for table `cost_categories`
--
ALTER TABLE `cost_categories`
  ADD PRIMARY KEY (`cost_category_id`);

--
-- Indexes for table `creditors`
--
ALTER TABLE `creditors`
  ADD PRIMARY KEY (`creditor_id`);

--
-- Indexes for table `creditor_invoices`
--
ALTER TABLE `creditor_invoices`
  ADD PRIMARY KEY (`creditor_invoice_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`);

--
-- Indexes for table `customer_invoices`
--
ALTER TABLE `customer_invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `unique_invoice_number` (`company_id`,`invoice_number`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_invoice_date` (`invoice_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_plot` (`plot_id`);

--
-- Indexes for table `customer_invoice_items`
--
ALTER TABLE `customer_invoice_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `unique_payment_number` (`company_id`,`payment_number`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_plot` (`plot_id`);

--
-- Indexes for table `customer_payment_plans`
--
ALTER TABLE `customer_payment_plans`
  ADD PRIMARY KEY (`plan_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `customer_statement_history`
--
ALTER TABLE `customer_statement_history`
  ADD PRIMARY KEY (`statement_id`),
  ADD UNIQUE KEY `unique_statement_number` (`company_id`,`statement_number`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_statement_date` (`statement_date`);

--
-- Indexes for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_transaction_date` (`transaction_date`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_transaction_number` (`transaction_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_reservation` (`reservation_id`);

--
-- Indexes for table `customer_writeoffs`
--
ALTER TABLE `customer_writeoffs`
  ADD PRIMARY KEY (`writeoff_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_reservation` (`reservation_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_writeoff_date` (`writeoff_date`),
  ADD KEY `idx_writeoff_lookup` (`company_id`,`status`,`writeoff_date`);

--
-- Indexes for table `debtors`
--
ALTER TABLE `debtors`
  ADD PRIMARY KEY (`debtor_id`);

--
-- Indexes for table `debtor_aging_config`
--
ALTER TABLE `debtor_aging_config`
  ADD PRIMARY KEY (`config_id`),
  ADD UNIQUE KEY `unique_company` (`company_id`);

--
-- Indexes for table `debtor_reminders`
--
ALTER TABLE `debtor_reminders`
  ADD PRIMARY KEY (`reminder_id`),
  ADD KEY `idx_debtor` (`debtor_id`);

--
-- Indexes for table `debtor_writeoffs`
--
ALTER TABLE `debtor_writeoffs`
  ADD PRIMARY KEY (`writeoff_id`),
  ADD KEY `idx_debtor` (`debtor_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `direct_expenses`
--
ALTER TABLE `direct_expenses`
  ADD PRIMARY KEY (`expense_id`);

--
-- Indexes for table `districts`
--
ALTER TABLE `districts`
  ADD PRIMARY KEY (`district_id`);

--
-- Indexes for table `document_sequences`
--
ALTER TABLE `document_sequences`
  ADD PRIMARY KEY (`sequence_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD KEY `idx_nssf` (`nssf_number`),
  ADD KEY `idx_tin` (`tin_number`);

--
-- Indexes for table `employee_loans`
--
ALTER TABLE `employee_loans`
  ADD PRIMARY KEY (`loan_id`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `expense_claims`
--
ALTER TABLE `expense_claims`
  ADD PRIMARY KEY (`claim_id`);

--
-- Indexes for table `expense_claim_items`
--
ALTER TABLE `expense_claim_items`
  ADD PRIMARY KEY (`item_id`);

--
-- Indexes for table `fixed_assets`
--
ALTER TABLE `fixed_assets`
  ADD PRIMARY KEY (`asset_id`),
  ADD KEY `fk_fixed_assets_updated_by` (`updated_by`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`);

--
-- Indexes for table `inventory_audits`
--
ALTER TABLE `inventory_audits`
  ADD PRIMARY KEY (`audit_id`);

--
-- Indexes for table `inventory_audit_lines`
--
ALTER TABLE `inventory_audit_lines`
  ADD PRIMARY KEY (`audit_line_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`movement_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`invoice_item_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`);

--
-- Indexes for table `item_categories`
--
ALTER TABLE `item_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`journal_id`);

--
-- Indexes for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  ADD PRIMARY KEY (`line_id`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`lead_id`);

--
-- Indexes for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD PRIMARY KEY (`leave_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`leave_type_id`);

--
-- Indexes for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD PRIMARY KEY (`payment_id`);

--
-- Indexes for table `loan_repayment_schedule`
--
ALTER TABLE `loan_repayment_schedule`
  ADD PRIMARY KEY (`schedule_id`);

--
-- Indexes for table `loan_types`
--
ALTER TABLE `loan_types`
  ADD PRIMARY KEY (`loan_type_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`attempt_id`);

--
-- Indexes for table `notification_templates`
--
ALTER TABLE `notification_templates`
  ADD PRIMARY KEY (`template_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_to_account` (`to_account_id`),
  ADD KEY `idx_payment_category` (`payment_category`);

--
-- Indexes for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_transaction` (`transaction_id`);

--
-- Indexes for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `idx_payment_id` (`payment_id`),
  ADD KEY `idx_payment_type` (`payment_id`,`payment_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `payment_plan_schedule`
--
ALTER TABLE `payment_plan_schedule`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `idx_plan` (`plan_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD PRIMARY KEY (`receipt_id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `received_by` (`received_by`),
  ADD KEY `idx_receipt_number` (`receipt_number`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_reservation` (`reservation_id`);

--
-- Indexes for table `payment_recovery`
--
ALTER TABLE `payment_recovery`
  ADD PRIMARY KEY (`recovery_id`);

--
-- Indexes for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  ADD PRIMARY KEY (`schedule_id`);

--
-- Indexes for table `payment_statements`
--
ALTER TABLE `payment_statements`
  ADD PRIMARY KEY (`statement_id`),
  ADD UNIQUE KEY `statement_number` (`statement_number`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `generated_by` (`generated_by`),
  ADD KEY `idx_statement_number` (`statement_number`),
  ADD KEY `idx_reservation` (`reservation_id`),
  ADD KEY `idx_customer` (`customer_id`);

--
-- Indexes for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  ADD PRIMARY KEY (`voucher_id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`payroll_id`);

--
-- Indexes for table `payroll_details`
--
ALTER TABLE `payroll_details`
  ADD PRIMARY KEY (`payroll_detail_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_id`);

--
-- Indexes for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  ADD PRIMARY KEY (`petty_cash_id`);

--
-- Indexes for table `petty_cash_categories`
--
ALTER TABLE `petty_cash_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `petty_cash_reconciliations`
--
ALTER TABLE `petty_cash_reconciliations`
  ADD PRIMARY KEY (`reconciliation_id`),
  ADD KEY `idx_company_date` (`company_id`,`reconciliation_date`),
  ADD KEY `idx_reconciliation_number` (`reconciliation_number`),
  ADD KEY `idx_custodian` (`custodian_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `petty_cash_replenishments`
--
ALTER TABLE `petty_cash_replenishments`
  ADD PRIMARY KEY (`replenishment_id`);

--
-- Indexes for table `petty_cash_transactions`
--
ALTER TABLE `petty_cash_transactions`
  ADD PRIMARY KEY (`transaction_id`);

--
-- Indexes for table `plots`
--
ALTER TABLE `plots`
  ADD PRIMARY KEY (`plot_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_project_status` (`project_id`,`status`),
  ADD KEY `idx_company_project` (`company_id`,`project_id`),
  ADD KEY `idx_price_range` (`selling_price`),
  ADD KEY `idx_status_project` (`status`,`project_id`),
  ADD KEY `idx_created_status` (`created_at`,`status`);

--
-- Indexes for table `plot_availability_schedule`
--
ALTER TABLE `plot_availability_schedule`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_dates` (`availability_start_date`,`availability_end_date`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `plot_contracts`
--
ALTER TABLE `plot_contracts`
  ADD PRIMARY KEY (`contract_id`);

--
-- Indexes for table `plot_documents`
--
ALTER TABLE `plot_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `plot_features`
--
ALTER TABLE `plot_features`
  ADD PRIMARY KEY (`feature_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `plot_holds`
--
ALTER TABLE `plot_holds`
  ADD PRIMARY KEY (`hold_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_hold_dates` (`hold_start_date`,`hold_end_date`),
  ADD KEY `idx_customer` (`customer_id`);

--
-- Indexes for table `plot_inspections`
--
ALTER TABLE `plot_inspections`
  ADD PRIMARY KEY (`inspection_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_inspection_date` (`inspection_date`);

--
-- Indexes for table `plot_inventory_snapshots`
--
ALTER TABLE `plot_inventory_snapshots`
  ADD PRIMARY KEY (`snapshot_id`),
  ADD UNIQUE KEY `unique_snapshot` (`company_id`,`project_id`,`snapshot_date`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_snapshot_date` (`snapshot_date`);

--
-- Indexes for table `plot_movements`
--
ALTER TABLE `plot_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_movement_date` (`movement_date`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_new_customer` (`new_customer_id`),
  ADD KEY `idx_approval_status` (`approval_status`);

--
-- Indexes for table `plot_pricing_history`
--
ALTER TABLE `plot_pricing_history`
  ADD PRIMARY KEY (`pricing_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_effective_date` (`effective_date`);

--
-- Indexes for table `plot_reservation_queue`
--
ALTER TABLE `plot_reservation_queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `plot_status_history`
--
ALTER TABLE `plot_status_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_status_date` (`status_date`);

--
-- Indexes for table `plot_stock_movements`
--
ALTER TABLE `plot_stock_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_date` (`movement_date`);

--
-- Indexes for table `plot_swaps`
--
ALTER TABLE `plot_swaps`
  ADD PRIMARY KEY (`swap_id`),
  ADD KEY `idx_plot_1` (`plot_1_id`),
  ADD KEY `idx_plot_2` (`plot_2_id`),
  ADD KEY `idx_customer_1` (`customer_1_id`),
  ADD KEY `idx_customer_2` (`customer_2_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_status` (`approval_status`);

--
-- Indexes for table `plot_transfers`
--
ALTER TABLE `plot_transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_from_customer` (`from_customer_id`),
  ADD KEY `idx_to_customer` (`to_customer_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_status` (`approval_status`),
  ADD KEY `idx_transfer_date` (`transfer_date`);

--
-- Indexes for table `plot_valuations`
--
ALTER TABLE `plot_valuations`
  ADD PRIMARY KEY (`valuation_id`),
  ADD KEY `idx_plot` (`plot_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`position_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `project_costs`
--
ALTER TABLE `project_costs`
  ADD PRIMARY KEY (`cost_id`);

--
-- Indexes for table `project_creditors`
--
ALTER TABLE `project_creditors`
  ADD PRIMARY KEY (`project_creditor_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_creditor` (`creditor_id`),
  ADD KEY `idx_project_creditor_id` (`project_creditor_id`),
  ADD KEY `idx_company_project` (`company_id`,`project_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `project_creditor_payments`
--
ALTER TABLE `project_creditor_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_project_creditor` (`project_creditor_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_payment_date` (`payment_date`);

--
-- Indexes for table `project_creditor_schedules`
--
ALTER TABLE `project_creditor_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `idx_project_creditor` (`project_creditor_id`);

--
-- Indexes for table `project_sellers`
--
ALTER TABLE `project_sellers`
  ADD PRIMARY KEY (`seller_id`),
  ADD KEY `idx_project_sellers_company_project` (`company_id`,`project_id`);

--
-- Indexes for table `project_statements`
--
ALTER TABLE `project_statements`
  ADD PRIMARY KEY (`statement_id`),
  ADD UNIQUE KEY `statement_number` (`statement_number`,`company_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `payment_date` (`payment_date`),
  ADD KEY `status` (`status`),
  ADD KEY `fk_project_statements_user` (`created_by`),
  ADD KEY `idx_project_statements_company_project` (`company_id`,`project_id`),
  ADD KEY `idx_project_statements_status_date` (`status`,`payment_date`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`purchase_order_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`po_item_id`);

--
-- Indexes for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  ADD PRIMARY KEY (`requisition_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`quotation_id`);

--
-- Indexes for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD PRIMARY KEY (`quotation_item_id`);

--
-- Indexes for table `refunds`
--
ALTER TABLE `refunds`
  ADD PRIMARY KEY (`refund_id`);

--
-- Indexes for table `regions`
--
ALTER TABLE `regions`
  ADD PRIMARY KEY (`region_id`);

--
-- Indexes for table `requisition_items`
--
ALTER TABLE `requisition_items`
  ADD PRIMARY KEY (`requisition_item_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `idx_contract_template` (`contract_template_id`);

--
-- Indexes for table `reservation_cancellations`
--
ALTER TABLE `reservation_cancellations`
  ADD PRIMARY KEY (`cancellation_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_permission_id`);

--
-- Indexes for table `sales_quotations`
--
ALTER TABLE `sales_quotations`
  ADD PRIMARY KEY (`quotation_id`);

--
-- Indexes for table `service_quotations`
--
ALTER TABLE `service_quotations`
  ADD PRIMARY KEY (`quotation_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_service_request` (`service_request_id`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`service_request_id`);

--
-- Indexes for table `service_types`
--
ALTER TABLE `service_types`
  ADD PRIMARY KEY (`service_type_id`);

--
-- Indexes for table `sms_campaigns`
--
ALTER TABLE `sms_campaigns`
  ADD PRIMARY KEY (`campaign_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `sms_contact_groups`
--
ALTER TABLE `sms_contact_groups`
  ADD PRIMARY KEY (`group_id`);

--
-- Indexes for table `sms_group_contacts`
--
ALTER TABLE `sms_group_contacts`
  ADD PRIMARY KEY (`group_contact_id`),
  ADD KEY `idx_group` (`group_id`);

--
-- Indexes for table `sms_recipients`
--
ALTER TABLE `sms_recipients`
  ADD PRIMARY KEY (`recipient_id`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_customer` (`customer_id`);

--
-- Indexes for table `sms_settings`
--
ALTER TABLE `sms_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `unique_company` (`company_id`);

--
-- Indexes for table `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`template_id`);

--
-- Indexes for table `stock_alerts`
--
ALTER TABLE `stock_alerts`
  ADD PRIMARY KEY (`alert_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`movement_id`);

--
-- Indexes for table `stores`
--
ALTER TABLE `stores`
  ADD PRIMARY KEY (`store_id`);

--
-- Indexes for table `store_locations`
--
ALTER TABLE `store_locations`
  ADD PRIMARY KEY (`store_id`);

--
-- Indexes for table `store_stock`
--
ALTER TABLE `store_stock`
  ADD PRIMARY KEY (`store_stock_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `system_roles`
--
ALTER TABLE `system_roles`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_assigned_by` (`assigned_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `task_checklists`
--
ALTER TABLE `task_checklists`
  ADD PRIMARY KEY (`checklist_id`),
  ADD KEY `idx_task` (`task_id`);

--
-- Indexes for table `task_updates`
--
ALTER TABLE `task_updates`
  ADD PRIMARY KEY (`update_id`),
  ADD KEY `idx_task` (`task_id`);

--
-- Indexes for table `tax_transactions`
--
ALTER TABLE `tax_transactions`
  ADD PRIMARY KEY (`tax_transaction_id`);

--
-- Indexes for table `tax_types`
--
ALTER TABLE `tax_types`
  ADD PRIMARY KEY (`tax_type_id`),
  ADD UNIQUE KEY `unique_tax_code` (`company_id`,`tax_code`),
  ADD KEY `idx_company_active` (`company_id`,`is_active`),
  ADD KEY `idx_tax_code` (`tax_code`),
  ADD KEY `idx_tax_category` (`tax_category`);

--
-- Indexes for table `title_deed_costs`
--
ALTER TABLE `title_deed_costs`
  ADD PRIMARY KEY (`cost_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `processing_id` (`processing_id`),
  ADD KEY `cost_type` (`cost_type`),
  ADD KEY `idx_company_processing` (`company_id`,`processing_id`),
  ADD KEY `idx_cost_type` (`cost_type`);

--
-- Indexes for table `title_deed_processing`
--
ALTER TABLE `title_deed_processing`
  ADD PRIMARY KEY (`processing_id`),
  ADD UNIQUE KEY `processing_number` (`processing_number`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `plot_id` (`plot_id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `current_stage` (`current_stage`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_company_stage` (`company_id`,`current_stage`),
  ADD KEY `idx_company_customer` (`company_id`,`customer_id`),
  ADD KEY `idx_started_date` (`started_date`),
  ADD KEY `idx_expected_completion` (`expected_completion_date`);

--
-- Indexes for table `title_deed_stages`
--
ALTER TABLE `title_deed_stages`
  ADD PRIMARY KEY (`stage_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `processing_id` (`processing_id`),
  ADD KEY `stage_name` (`stage_name`),
  ADD KEY `stage_status` (`stage_status`),
  ADD KEY `idx_company_processing` (`company_id`,`processing_id`),
  ADD KEY `idx_stage_status` (`stage_name`,`stage_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_role_id`);

--
-- Indexes for table `villages`
--
ALTER TABLE `villages`
  ADD PRIMARY KEY (`village_id`);

--
-- Indexes for table `wards`
--
ALTER TABLE `wards`
  ADD PRIMARY KEY (`ward_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approval_actions`
--
ALTER TABLE `approval_actions`
  MODIFY `approval_action_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_levels`
--
ALTER TABLE `approval_levels`
  MODIFY `approval_level_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_requests`
--
ALTER TABLE `approval_requests`
  MODIFY `approval_request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_workflows`
--
ALTER TABLE `approval_workflows`
  MODIFY `workflow_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_categories`
--
ALTER TABLE `asset_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `asset_depreciation`
--
ALTER TABLE `asset_depreciation`
  MODIFY `depreciation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_maintenance`
--
ALTER TABLE `asset_maintenance`
  MODIFY `maintenance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `bank_account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `bank_statements`
--
ALTER TABLE `bank_statements`
  MODIFY `statement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_transactions`
--
ALTER TABLE `bank_transactions`
  MODIFY `bank_transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `budget_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_lines`
--
ALTER TABLE `budget_lines`
  MODIFY `budget_line_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_revisions`
--
ALTER TABLE `budget_revisions`
  MODIFY `revision_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `campaigns`
--
ALTER TABLE `campaigns`
  MODIFY `campaign_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_transactions`
--
ALTER TABLE `cash_transactions`
  MODIFY `cash_transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=286;

--
-- AUTO_INCREMENT for table `cheque_transactions`
--
ALTER TABLE `cheque_transactions`
  MODIFY `cheque_transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commissions`
--
ALTER TABLE `commissions`
  MODIFY `commission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `commission_payments`
--
ALTER TABLE `commission_payments`
  MODIFY `commission_payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commission_payment_requests`
--
ALTER TABLE `commission_payment_requests`
  MODIFY `commission_payment_request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commission_structures`
--
ALTER TABLE `commission_structures`
  MODIFY `commission_structure_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `commission_tiers`
--
ALTER TABLE `commission_tiers`
  MODIFY `commission_tier_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `company_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `company_loans`
--
ALTER TABLE `company_loans`
  MODIFY `loan_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_loan_payments`
--
ALTER TABLE `company_loan_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contract_templates`
--
ALTER TABLE `contract_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cost_categories`
--
ALTER TABLE `cost_categories`
  MODIFY `cost_category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `creditors`
--
ALTER TABLE `creditors`
  MODIFY `creditor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `creditor_invoices`
--
ALTER TABLE `creditor_invoices`
  MODIFY `creditor_invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `customer_invoices`
--
ALTER TABLE `customer_invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_invoice_items`
--
ALTER TABLE `customer_invoice_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_payment_plans`
--
ALTER TABLE `customer_payment_plans`
  MODIFY `plan_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_statement_history`
--
ALTER TABLE `customer_statement_history`
  MODIFY `statement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_writeoffs`
--
ALTER TABLE `customer_writeoffs`
  MODIFY `writeoff_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debtors`
--
ALTER TABLE `debtors`
  MODIFY `debtor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debtor_aging_config`
--
ALTER TABLE `debtor_aging_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debtor_reminders`
--
ALTER TABLE `debtor_reminders`
  MODIFY `reminder_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debtor_writeoffs`
--
ALTER TABLE `debtor_writeoffs`
  MODIFY `writeoff_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `direct_expenses`
--
ALTER TABLE `direct_expenses`
  MODIFY `expense_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `districts`
--
ALTER TABLE `districts`
  MODIFY `district_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_sequences`
--
ALTER TABLE `document_sequences`
  MODIFY `sequence_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `employee_loans`
--
ALTER TABLE `employee_loans`
  MODIFY `loan_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `expense_claims`
--
ALTER TABLE `expense_claims`
  MODIFY `claim_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_claim_items`
--
ALTER TABLE `expense_claim_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_assets`
--
ALTER TABLE `fixed_assets`
  MODIFY `asset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_audits`
--
ALTER TABLE `inventory_audits`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_audit_lines`
--
ALTER TABLE `inventory_audit_lines`
  MODIFY `audit_line_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_categories`
--
ALTER TABLE `item_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `journal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  MODIFY `line_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `lead_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `leave_applications`
--
ALTER TABLE `leave_applications`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `leave_type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_payments`
--
ALTER TABLE `loan_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_repayment_schedule`
--
ALTER TABLE `loan_repayment_schedule`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_types`
--
ALTER TABLE `loan_types`
  MODIFY `loan_type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `notification_templates`
--
ALTER TABLE `notification_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `payment_plan_schedule`
--
ALTER TABLE `payment_plan_schedule`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  MODIFY `receipt_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_recovery`
--
ALTER TABLE `payment_recovery`
  MODIFY `recovery_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_statements`
--
ALTER TABLE `payment_statements`
  MODIFY `statement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  MODIFY `voucher_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `payroll_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payroll_details`
--
ALTER TABLE `payroll_details`
  MODIFY `payroll_detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  MODIFY `petty_cash_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `petty_cash_categories`
--
ALTER TABLE `petty_cash_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `petty_cash_reconciliations`
--
ALTER TABLE `petty_cash_reconciliations`
  MODIFY `reconciliation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `petty_cash_replenishments`
--
ALTER TABLE `petty_cash_replenishments`
  MODIFY `replenishment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `petty_cash_transactions`
--
ALTER TABLE `petty_cash_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `plots`
--
ALTER TABLE `plots`
  MODIFY `plot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=320;

--
-- AUTO_INCREMENT for table `plot_availability_schedule`
--
ALTER TABLE `plot_availability_schedule`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_contracts`
--
ALTER TABLE `plot_contracts`
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `plot_documents`
--
ALTER TABLE `plot_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_features`
--
ALTER TABLE `plot_features`
  MODIFY `feature_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_holds`
--
ALTER TABLE `plot_holds`
  MODIFY `hold_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_inspections`
--
ALTER TABLE `plot_inspections`
  MODIFY `inspection_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_inventory_snapshots`
--
ALTER TABLE `plot_inventory_snapshots`
  MODIFY `snapshot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_movements`
--
ALTER TABLE `plot_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `plot_pricing_history`
--
ALTER TABLE `plot_pricing_history`
  MODIFY `pricing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `plot_reservation_queue`
--
ALTER TABLE `plot_reservation_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_status_history`
--
ALTER TABLE `plot_status_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `plot_stock_movements`
--
ALTER TABLE `plot_stock_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_swaps`
--
ALTER TABLE `plot_swaps`
  MODIFY `swap_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_transfers`
--
ALTER TABLE `plot_transfers`
  MODIFY `transfer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plot_valuations`
--
ALTER TABLE `plot_valuations`
  MODIFY `valuation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `position_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `project_costs`
--
ALTER TABLE `project_costs`
  MODIFY `cost_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `project_creditors`
--
ALTER TABLE `project_creditors`
  MODIFY `project_creditor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `project_creditor_payments`
--
ALTER TABLE `project_creditor_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `project_creditor_schedules`
--
ALTER TABLE `project_creditor_schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_sellers`
--
ALTER TABLE `project_sellers`
  MODIFY `seller_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `project_statements`
--
ALTER TABLE `project_statements`
  MODIFY `statement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `purchase_order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  MODIFY `requisition_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `quotation_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `refunds`
--
ALTER TABLE `refunds`
  MODIFY `refund_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regions`
--
ALTER TABLE `regions`
  MODIFY `region_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `requisition_items`
--
ALTER TABLE `requisition_items`
  MODIFY `requisition_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `reservation_cancellations`
--
ALTER TABLE `reservation_cancellations`
  MODIFY `cancellation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `role_permission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_quotations`
--
ALTER TABLE `sales_quotations`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_quotations`
--
ALTER TABLE `service_quotations`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `service_request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_types`
--
ALTER TABLE `service_types`
  MODIFY `service_type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_campaigns`
--
ALTER TABLE `sms_campaigns`
  MODIFY `campaign_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_contact_groups`
--
ALTER TABLE `sms_contact_groups`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_group_contacts`
--
ALTER TABLE `sms_group_contacts`
  MODIFY `group_contact_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_recipients`
--
ALTER TABLE `sms_recipients`
  MODIFY `recipient_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_settings`
--
ALTER TABLE `sms_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_templates`
--
ALTER TABLE `sms_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_alerts`
--
ALTER TABLE `stock_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stores`
--
ALTER TABLE `stores`
  MODIFY `store_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `store_locations`
--
ALTER TABLE `store_locations`
  MODIFY `store_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `store_stock`
--
ALTER TABLE `store_stock`
  MODIFY `store_stock_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_roles`
--
ALTER TABLE `system_roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_checklists`
--
ALTER TABLE `task_checklists`
  MODIFY `checklist_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_updates`
--
ALTER TABLE `task_updates`
  MODIFY `update_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_transactions`
--
ALTER TABLE `tax_transactions`
  MODIFY `tax_transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tax_types`
--
ALTER TABLE `tax_types`
  MODIFY `tax_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `title_deed_costs`
--
ALTER TABLE `title_deed_costs`
  MODIFY `cost_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `title_deed_processing`
--
ALTER TABLE `title_deed_processing`
  MODIFY `processing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `title_deed_stages`
--
ALTER TABLE `title_deed_stages`
  MODIFY `stage_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `user_role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `villages`
--
ALTER TABLE `villages`
  MODIFY `village_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wards`
--
ALTER TABLE `wards`
  MODIFY `ward_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- --------------------------------------------------------

--
-- Structure for view `vw_active_plot_holds`
--
DROP TABLE IF EXISTS `vw_active_plot_holds`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_active_plot_holds`  AS SELECT `ph`.`hold_id` AS `hold_id`, `ph`.`company_id` AS `company_id`, `ph`.`plot_id` AS `plot_id`, `p`.`plot_number` AS `plot_number`, `p`.`block_number` AS `block_number`, `pr`.`project_name` AS `project_name`, `pr`.`project_code` AS `project_code`, `ph`.`hold_type` AS `hold_type`, `ph`.`hold_reason` AS `hold_reason`, `ph`.`hold_start_date` AS `hold_start_date`, `ph`.`expected_release_date` AS `expected_release_date`, to_days(coalesce(`ph`.`expected_release_date`,curdate())) - to_days(`ph`.`hold_start_date`) AS `hold_duration_days`, `ph`.`customer_id` AS `customer_id`, `ph`.`priority` AS `priority`, `ph`.`status` AS `status` FROM ((`plot_holds` `ph` left join `plots` `p` on(`ph`.`plot_id` = `p`.`plot_id`)) left join `projects` `pr` on(`p`.`project_id` = `pr`.`project_id`)) WHERE `ph`.`status` = 'active' ;

-- --------------------------------------------------------

--
-- Structure for view `vw_customer_aging`
--
DROP TABLE IF EXISTS `vw_customer_aging`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_customer_aging`  AS SELECT `c`.`customer_id` AS `customer_id`, `c`.`company_id` AS `company_id`, concat(`c`.`first_name`,' ',`c`.`last_name`) AS `customer_name`, `c`.`phone` AS `phone`, `c`.`email` AS `email`, coalesce(sum(case when `t`.`transaction_type` in ('invoice','debit_note','opening_balance','interest','penalty') then `t`.`debit_amount` else 0 end),0) AS `total_invoiced`, coalesce(sum(case when `t`.`transaction_type` in ('payment','credit_note','refund','discount') then `t`.`credit_amount` else 0 end),0) AS `total_paid`, coalesce(sum(`t`.`debit_amount` - `t`.`credit_amount`),0) AS `current_balance`, coalesce(sum(case when `t`.`transaction_date` >= curdate() then `t`.`debit_amount` - `t`.`credit_amount` else 0 end),0) AS `current_amount`, coalesce(sum(case when `t`.`transaction_date` between curdate() - interval 30 day and curdate() - interval 1 day then `t`.`debit_amount` - `t`.`credit_amount` else 0 end),0) AS `days_1_30`, coalesce(sum(case when `t`.`transaction_date` between curdate() - interval 60 day and curdate() - interval 31 day then `t`.`debit_amount` - `t`.`credit_amount` else 0 end),0) AS `days_31_60`, coalesce(sum(case when `t`.`transaction_date` between curdate() - interval 90 day and curdate() - interval 61 day then `t`.`debit_amount` - `t`.`credit_amount` else 0 end),0) AS `days_61_90`, coalesce(sum(case when `t`.`transaction_date` < curdate() - interval 90 day then `t`.`debit_amount` - `t`.`credit_amount` else 0 end),0) AS `days_over_90` FROM (`customers` `c` left join `customer_transactions` `t` on(`c`.`customer_id` = `t`.`customer_id` and `c`.`company_id` = `t`.`company_id` and `t`.`status` = 'posted')) WHERE `c`.`is_active` = 1 GROUP BY `c`.`customer_id`, `c`.`company_id`, `c`.`first_name`, `c`.`last_name`, `c`.`phone`, `c`.`email` HAVING `current_balance` <> 0 ;

-- --------------------------------------------------------

--
-- Structure for view `vw_customer_balances`
--
DROP TABLE IF EXISTS `vw_customer_balances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_customer_balances`  AS SELECT `c`.`customer_id` AS `customer_id`, `c`.`company_id` AS `company_id`, concat(`c`.`first_name`,' ',`c`.`last_name`) AS `customer_name`, `c`.`phone` AS `phone`, `c`.`email` AS `email`, coalesce(sum(case when `t`.`transaction_type` in ('invoice','debit_note','opening_balance','interest','penalty') then `t`.`debit_amount` else 0 end),0) AS `total_debits`, coalesce(sum(case when `t`.`transaction_type` in ('payment','credit_note','refund','discount') then `t`.`credit_amount` else 0 end),0) AS `total_credits`, coalesce(sum(`t`.`debit_amount` - `t`.`credit_amount`),0) AS `balance`, max(`t`.`transaction_date`) AS `last_transaction_date`, count(`t`.`transaction_id`) AS `transaction_count` FROM (`customers` `c` left join `customer_transactions` `t` on(`c`.`customer_id` = `t`.`customer_id` and `c`.`company_id` = `t`.`company_id` and `t`.`status` = 'posted')) WHERE `c`.`is_active` = 1 GROUP BY `c`.`customer_id`, `c`.`company_id`, `c`.`first_name`, `c`.`last_name`, `c`.`phone`, `c`.`email` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_plot_activity_summary`
--
DROP TABLE IF EXISTS `vw_plot_activity_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_plot_activity_summary`  AS SELECT `pm`.`company_id` AS `company_id`, `pm`.`plot_id` AS `plot_id`, `p`.`plot_number` AS `plot_number`, `p`.`block_number` AS `block_number`, `pr`.`project_name` AS `project_name`, `pr`.`project_code` AS `project_code`, count(`pm`.`movement_id`) AS `total_movements`, count(case when `pm`.`movement_type` = 'status_change' then 1 end) AS `status_changes`, count(case when `pm`.`movement_type` = 'reservation' then 1 end) AS `reservations`, count(case when `pm`.`movement_type` = 'sale' then 1 end) AS `sales`, count(case when `pm`.`movement_type` = 'cancellation' then 1 end) AS `cancellations`, count(case when `pm`.`movement_type` = 'transfer' then 1 end) AS `transfers`, max(`pm`.`movement_date`) AS `last_activity_date`, min(`pm`.`movement_date`) AS `first_activity_date`, to_days(curdate()) - to_days(max(`pm`.`movement_date`)) AS `days_since_last_activity` FROM ((`plot_movements` `pm` left join `plots` `p` on(`pm`.`plot_id` = `p`.`plot_id`)) left join `projects` `pr` on(`pm`.`project_id` = `pr`.`project_id`)) GROUP BY `pm`.`company_id`, `pm`.`plot_id`, `p`.`plot_number`, `p`.`block_number`, `pr`.`project_name`, `pr`.`project_code` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_plot_availability`
--
DROP TABLE IF EXISTS `vw_plot_availability`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_plot_availability`  AS SELECT `p`.`plot_id` AS `plot_id`, `p`.`company_id` AS `company_id`, `p`.`project_id` AS `project_id`, `pr`.`project_name` AS `project_name`, `pr`.`project_code` AS `project_code`, `p`.`plot_number` AS `plot_number`, `p`.`block_number` AS `block_number`, `p`.`area` AS `area`, `p`.`selling_price` AS `selling_price`, `p`.`price_per_sqm` AS `price_per_sqm`, `p`.`status` AS `status`, `p`.`corner_plot` AS `corner_plot`, `p`.`coordinates` AS `coordinates`, to_days(curdate()) - to_days(`p`.`created_at`) AS `days_in_inventory`, CASE WHEN `p`.`status` = 'available' AND to_days(curdate()) - to_days(`p`.`created_at`) > 180 THEN 'slow_moving' WHEN `p`.`status` = 'available' AND to_days(curdate()) - to_days(`p`.`created_at`) > 90 THEN 'normal' WHEN `p`.`status` = 'available' THEN 'new' ELSE NULL END AS `inventory_category` FROM (`plots` `p` left join `projects` `pr` on(`p`.`project_id` = `pr`.`project_id`)) WHERE `p`.`is_active` = 1 ;

-- --------------------------------------------------------

--
-- Structure for view `vw_plot_inventory_summary`
--
DROP TABLE IF EXISTS `vw_plot_inventory_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_plot_inventory_summary`  AS SELECT `p`.`company_id` AS `company_id`, `pr`.`project_id` AS `project_id`, `pr`.`project_name` AS `project_name`, `pr`.`project_code` AS `project_code`, count(`p`.`plot_id`) AS `total_plots`, sum(case when `p`.`status` = 'available' then 1 else 0 end) AS `available_plots`, sum(case when `p`.`status` = 'reserved' then 1 else 0 end) AS `reserved_plots`, sum(case when `p`.`status` = 'sold' then 1 else 0 end) AS `sold_plots`, sum(case when `p`.`status` = 'blocked' then 1 else 0 end) AS `blocked_plots`, sum(`p`.`selling_price`) AS `total_inventory_value`, sum(case when `p`.`status` = 'available' then `p`.`selling_price` else 0 end) AS `available_value`, sum(case when `p`.`status` = 'sold' then `p`.`selling_price` else 0 end) AS `sold_value`, avg(`p`.`selling_price`) AS `avg_plot_price`, min(`p`.`selling_price`) AS `min_plot_price`, max(`p`.`selling_price`) AS `max_plot_price` FROM (`plots` `p` left join `projects` `pr` on(`p`.`project_id` = `pr`.`project_id`)) WHERE `p`.`is_active` = 1 GROUP BY `p`.`company_id`, `pr`.`project_id`, `pr`.`project_name`, `pr`.`project_code` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_plot_transfer_stats`
--
DROP TABLE IF EXISTS `vw_plot_transfer_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `vw_plot_transfer_stats`  AS SELECT `pt`.`company_id` AS `company_id`, `pr`.`project_id` AS `project_id`, `pr`.`project_name` AS `project_name`, count(`pt`.`transfer_id`) AS `total_transfers`, count(case when `pt`.`approval_status` = 'pending' then 1 end) AS `pending_transfers`, count(case when `pt`.`approval_status` = 'approved' then 1 end) AS `approved_transfers`, count(case when `pt`.`approval_status` = 'completed' then 1 end) AS `completed_transfers`, count(case when `pt`.`approval_status` = 'rejected' then 1 end) AS `rejected_transfers`, sum(`pt`.`transfer_fee`) AS `total_transfer_fees`, avg(`pt`.`transfer_fee`) AS `avg_transfer_fee`, count(case when `pt`.`transfer_type` = 'swap' then 1 end) AS `swap_count`, count(case when `pt`.`transfer_type` = 'upgrade' then 1 end) AS `upgrade_count` FROM ((`plot_transfers` `pt` left join `plots` `p` on(`pt`.`plot_id` = `p`.`plot_id`)) left join `projects` `pr` on(`pt`.`project_id` = `pr`.`project_id`)) GROUP BY `pt`.`company_id`, `pr`.`project_id`, `pr`.`project_name` ;

-- --------------------------------------------------------

--
-- Structure for view `v_customer_statement`
--
DROP TABLE IF EXISTS `v_customer_statement`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `v_customer_statement`  AS SELECT `p`.`payment_date` AS `transaction_date`, `p`.`payment_number` AS `reference`, `p`.`receipt_number` AS `receipt_number`, CASE `description` ELSE `p`.`payment_stage` AS `end` END FROM ((`payments` `p` join `reservations` `r` on(`p`.`reservation_id` = `r`.`reservation_id`)) join `customers` `c` on(`r`.`customer_id` = `c`.`customer_id`)) WHERE `p`.`status` = 'approved'union all select `r`.`reservation_date` AS `transaction_date`,`r`.`reservation_number` AS `reference`,NULL AS `receipt_number`,'Plot Reservation' AS `description`,0 AS `debit`,`r`.`total_amount` AS `credit`,`r`.`total_amount` AS `balance`,`r`.`reservation_number` AS `reservation_number`,`c`.`customer_id` AS `customer_id`,`c`.`full_name` AS `customer_name`,`r`.`company_id` AS `company_id`,`r`.`status` AS `status` from (`reservations` `r` join `customers` `c` on(`r`.`customer_id` = `c`.`customer_id`)) where `r`.`status` in ('active','completed') order by `transaction_date`,`reference`  ;

-- --------------------------------------------------------

--
-- Structure for view `v_payment_history`
--
DROP TABLE IF EXISTS `v_payment_history`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `v_payment_history`  AS SELECT `p`.`payment_id` AS `payment_id`, `p`.`company_id` AS `company_id`, `p`.`reservation_id` AS `reservation_id`, `p`.`payment_date` AS `payment_date`, `p`.`payment_number` AS `payment_number`, `p`.`receipt_number` AS `receipt_number`, `p`.`amount` AS `amount`, `p`.`payment_stage` AS `payment_stage`, `p`.`installment_number` AS `installment_number`, `p`.`expected_amount` AS `expected_amount`, `p`.`is_partial` AS `is_partial`, `p`.`stage_balance_before` AS `stage_balance_before`, `p`.`stage_balance_after` AS `stage_balance_after`, `p`.`payment_method` AS `payment_method`, `p`.`status` AS `status`, `r`.`reservation_number` AS `reservation_number`, `r`.`payment_stage` AS `current_payment_stage`, `r`.`down_payment` AS `total_down_payment_required`, `r`.`down_payment_paid` AS `down_payment_paid`, `r`.`down_payment_balance` AS `down_payment_balance`, `r`.`installment_amount` AS `installment_amount_required`, `r`.`installments_paid_count` AS `installments_paid_count`, `r`.`total_amount` AS `reservation_total`, `c`.`full_name` AS `customer_name`, `c`.`phone` AS `customer_phone`, `pl`.`plot_number` AS `plot_number`, `pr`.`project_name` AS `project_name` FROM ((((`payments` `p` join `reservations` `r` on(`p`.`reservation_id` = `r`.`reservation_id` and `p`.`company_id` = `r`.`company_id`)) join `customers` `c` on(`r`.`customer_id` = `c`.`customer_id`)) join `plots` `pl` on(`r`.`plot_id` = `pl`.`plot_id`)) join `projects` `pr` on(`pl`.`project_id` = `pr`.`project_id`)) WHERE `p`.`status` = 'approved' ;

-- --------------------------------------------------------

--
-- Structure for view `v_title_deed_processing_details`
--
DROP TABLE IF EXISTS `v_title_deed_processing_details`;

CREATE ALGORITHM=UNDEFINED DEFINER=`mkumbi`@`localhost` SQL SECURITY DEFINER VIEW `v_title_deed_processing_details`  AS SELECT `tdp`.`processing_id` AS `processing_id`, `tdp`.`processing_number` AS `processing_number`, `tdp`.`company_id` AS `company_id`, `tdp`.`current_stage` AS `current_stage`, `tdp`.`total_cost` AS `total_cost`, `tdp`.`customer_contribution` AS `customer_contribution`, `tdp`.`started_date` AS `started_date`, `tdp`.`expected_completion_date` AS `expected_completion_date`, `tdp`.`actual_completion_date` AS `actual_completion_date`, `c`.`customer_id` AS `customer_id`, `c`.`full_name` AS `customer_name`, `c`.`phone` AS `customer_phone`, `c`.`email` AS `customer_email`, `p`.`plot_id` AS `plot_id`, `p`.`plot_number` AS `plot_number`, `p`.`block_number` AS `block_number`, `p`.`area` AS `plot_area`, `pr`.`project_id` AS `project_id`, `pr`.`project_name` AS `project_name`, `u`.`full_name` AS `assigned_to_name`, (select count(0) from `title_deed_stages` where `title_deed_stages`.`processing_id` = `tdp`.`processing_id` and `title_deed_stages`.`stage_status` = 'completed') AS `completed_stages`, (select count(0) from `title_deed_costs` where `title_deed_costs`.`processing_id` = `tdp`.`processing_id`) AS `total_cost_entries`, `tdp`.`created_at` AS `created_at`, `tdp`.`updated_at` AS `updated_at` FROM ((((`title_deed_processing` `tdp` left join `customers` `c` on(`tdp`.`customer_id` = `c`.`customer_id`)) left join `plots` `p` on(`tdp`.`plot_id` = `p`.`plot_id`)) left join `projects` `pr` on(`p`.`project_id` = `pr`.`project_id`)) left join `users` `u` on(`tdp`.`assigned_to` = `u`.`user_id`)) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cash_transactions`
--
ALTER TABLE `cash_transactions`
  ADD CONSTRAINT `cash_transactions_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  ADD CONSTRAINT `cash_transactions_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`),
  ADD CONSTRAINT `cash_transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `cheque_transactions`
--
ALTER TABLE `cheque_transactions`
  ADD CONSTRAINT `cheque_transactions_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  ADD CONSTRAINT `cheque_transactions_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`),
  ADD CONSTRAINT `cheque_transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `customer_writeoffs`
--
ALTER TABLE `customer_writeoffs`
  ADD CONSTRAINT `fk_writeoff_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_writeoff_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_writeoff_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE SET NULL;

--
-- Constraints for table `fixed_assets`
--
ALTER TABLE `fixed_assets`
  ADD CONSTRAINT `fk_fixed_assets_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD CONSTRAINT `payment_receipts_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  ADD CONSTRAINT `payment_receipts_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`),
  ADD CONSTRAINT `payment_receipts_ibfk_3` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`),
  ADD CONSTRAINT `payment_receipts_ibfk_4` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `payment_receipts_ibfk_5` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `payment_statements`
--
ALTER TABLE `payment_statements`
  ADD CONSTRAINT `payment_statements_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  ADD CONSTRAINT `payment_statements_ibfk_2` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`),
  ADD CONSTRAINT `payment_statements_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `payment_statements_ibfk_4` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `project_statements`
--
ALTER TABLE `project_statements`
  ADD CONSTRAINT `fk_project_statements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_project_statements_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_project_statements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservation_template` FOREIGN KEY (`contract_template_id`) REFERENCES `contract_templates` (`template_id`) ON DELETE SET NULL;

--
-- Constraints for table `title_deed_costs`
--
ALTER TABLE `title_deed_costs`
  ADD CONSTRAINT `title_deed_costs_ibfk_1` FOREIGN KEY (`processing_id`) REFERENCES `title_deed_processing` (`processing_id`) ON DELETE CASCADE;

--
-- Constraints for table `title_deed_stages`
--
ALTER TABLE `title_deed_stages`
  ADD CONSTRAINT `title_deed_stages_ibfk_1` FOREIGN KEY (`processing_id`) REFERENCES `title_deed_processing` (`processing_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
