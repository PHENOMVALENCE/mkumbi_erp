-- ============================================================================
-- MULTI-TENANT ERP SYSTEM - COMPLETE DATABASE SCHEMA
-- Database: erp_system_db
-- Version: 1.0
-- Date: November 2025
-- ============================================================================

-- Create Database
CREATE DATABASE IF NOT EXISTS erp_system_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erp_system_db;

-- ============================================================================
-- SECTION 1: SYSTEM & COMPANY TABLES (Multi-Tenant Foundation)
-- ============================================================================

-- Table: companies (Main tenant table)
CREATE TABLE companies (
    company_id INT PRIMARY KEY AUTO_INCREMENT,
    company_code VARCHAR(20) UNIQUE NOT NULL COMMENT 'Unique company identifier',
    company_name VARCHAR(200) NOT NULL,
    registration_number VARCHAR(100),
    tax_identification_number VARCHAR(100) COMMENT 'TIN',
    
    -- Contact Information
    email VARCHAR(150),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    website VARCHAR(200),
    
    -- Address
    physical_address TEXT,
    postal_address VARCHAR(200),
    city VARCHAR(100),
    region VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Tanzania',
    
    -- Branding
    logo_path VARCHAR(255),
    primary_color VARCHAR(20) DEFAULT '#007bff',
    secondary_color VARCHAR(20) DEFAULT '#6c757d',
    
    -- Settings
    fiscal_year_start DATE,
    fiscal_year_end DATE,
    currency_code VARCHAR(3) DEFAULT 'TZS',
    date_format VARCHAR(20) DEFAULT 'Y-m-d',
    timezone VARCHAR(50) DEFAULT 'Africa/Dar_es_Salaam',
    
    -- Subscription
    subscription_plan ENUM('trial', 'basic', 'professional', 'enterprise') DEFAULT 'trial',
    subscription_start_date DATE,
    subscription_end_date DATE,
    max_users INT DEFAULT 5,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    INDEX idx_company_code (company_code),
    INDEX idx_active (is_active),
    INDEX idx_subscription_plan (subscription_plan)
) ENGINE=InnoDB COMMENT='Multi-tenant company registration';

-- ============================================================================
-- SECTION 2: USER MANAGEMENT & AUTHENTICATION
-- ============================================================================

-- Table: system_roles (Predefined system roles)
CREATE TABLE system_roles (
    role_id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) UNIQUE NOT NULL,
    role_code VARCHAR(20) UNIQUE NOT NULL,
    description TEXT,
    is_system_role BOOLEAN DEFAULT FALSE COMMENT 'Cannot be deleted if TRUE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_role_code (role_code)
) ENGINE=InnoDB COMMENT='System-wide role definitions';

-- Insert default roles
INSERT INTO system_roles (role_name, role_code, description, is_system_role) VALUES
('Super Admin', 'SUPER_ADMIN', 'Platform super administrator', TRUE),
('Company Admin', 'COMPANY_ADMIN', 'Company administrator with full access', TRUE),
('Manager', 'MANAGER', 'Department manager', TRUE),
('Accountant', 'ACCOUNTANT', 'Finance and accounting staff', TRUE),
('Finance Officer', 'FINANCE_OFFICER', 'Finance department staff', TRUE),
('HR Officer', 'HR_OFFICER', 'Human resources staff', TRUE),
('Procurement Officer', 'PROCUREMENT', 'Procurement and purchasing staff', TRUE),
('Sales Officer', 'SALES', 'Sales and marketing staff', TRUE),
('Inventory Clerk', 'INVENTORY', 'Inventory management staff', TRUE),
('Receptionist', 'RECEPTIONIST', 'Front office staff', TRUE),
('Auditor', 'AUDITOR', 'Internal/external auditor (read-only)', TRUE),
('User', 'USER', 'Regular user with limited access', TRUE);

-- Table: users (All system users)
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL COMMENT 'Multi-tenant link',
    
    -- Login Credentials
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    
    -- Personal Information
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    full_name VARCHAR(300) GENERATED ALWAYS AS (CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name)) STORED,
    
    -- Contact
    phone1 VARCHAR(50),
    phone2 VARCHAR(50),
    
    -- Location
    region VARCHAR(100),
    district VARCHAR(100),
    ward VARCHAR(100),
    village VARCHAR(100),
    street_address TEXT,
    
    -- Profile
    profile_picture VARCHAR(255),
    gender ENUM('male', 'female', 'other'),
    date_of_birth DATE,
    national_id VARCHAR(50),
    
    -- Guardian Information (from requirements)
    guardian1_name VARCHAR(200),
    guardian1_relationship VARCHAR(100),
    guardian2_name VARCHAR(200),
    guardian2_relationship VARCHAR(100),
    
    -- Commission Eligibility (from requirements)
    can_get_commission BOOLEAN DEFAULT FALSE,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_email_verified BOOLEAN DEFAULT FALSE,
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    password_reset_token VARCHAR(100),
    password_reset_expires TIMESTAMP NULL,
    
    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_user (company_id, user_id),
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_full_name (full_name)
) ENGINE=InnoDB COMMENT='User accounts (multi-tenant)';

-- Table: user_roles (User role assignments)
CREATE TABLE user_roles (
    user_role_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES system_roles(role_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role_id),
    INDEX idx_user (user_id),
    INDEX idx_role (role_id)
) ENGINE=InnoDB COMMENT='User-role assignments';

-- Table: permissions (Granular permissions)
CREATE TABLE permissions (
    permission_id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(100) NOT NULL,
    permission_code VARCHAR(100) UNIQUE NOT NULL,
    permission_name VARCHAR(150) NOT NULL,
    description TEXT,
    
    INDEX idx_module (module_name),
    INDEX idx_code (permission_code)
) ENGINE=InnoDB COMMENT='System permissions';

-- Table: role_permissions (Role-permission mapping)
CREATE TABLE role_permissions (
    role_permission_id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    
    FOREIGN KEY (role_id) REFERENCES system_roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, permission_id)
) ENGINE=InnoDB COMMENT='Role-permission mappings';

-- ============================================================================
-- SECTION 3: LOCATION HIERARCHY (From Requirements)
-- ============================================================================

-- Table: regions
CREATE TABLE regions (
    region_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    region_name VARCHAR(100) NOT NULL,
    region_code VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_region (company_id, region_name)
) ENGINE=InnoDB;

-- Table: districts
CREATE TABLE districts (
    district_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    region_id INT NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    district_code VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (region_id) REFERENCES regions(region_id) ON DELETE CASCADE,
    INDEX idx_company_district (company_id, region_id, district_name)
) ENGINE=InnoDB;

-- Table: wards
CREATE TABLE wards (
    ward_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    district_id INT NOT NULL,
    ward_name VARCHAR(100) NOT NULL,
    ward_code VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (district_id) REFERENCES districts(district_id) ON DELETE CASCADE,
    INDEX idx_company_ward (company_id, district_id, ward_name)
) ENGINE=InnoDB;

-- Table: villages
CREATE TABLE villages (
    village_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    ward_id INT NOT NULL,
    village_name VARCHAR(100) NOT NULL,
    village_code VARCHAR(20),
    
    -- Village Leadership (from requirements)
    chairman_name VARCHAR(200),
    chairman_phone VARCHAR(50),
    mtendaji_name VARCHAR(200) COMMENT 'Village Executive Officer',
    mtendaji_phone VARCHAR(50),
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (ward_id) REFERENCES wards(ward_id) ON DELETE CASCADE,
    INDEX idx_company_village (company_id, ward_id, village_name)
) ENGINE=InnoDB COMMENT='Village information with leadership';

-- ============================================================================
-- SECTION 4: PLOT/LAND MANAGEMENT MODULE (From Requirements)
-- ============================================================================

-- Table: projects (Plot development projects)
CREATE TABLE projects (
    project_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Basic Information
    project_name VARCHAR(200) NOT NULL,
    project_code VARCHAR(50) UNIQUE,
    description TEXT,
    
    -- Location
    region_id INT,
    district_id INT,
    ward_id INT,
    village_id INT,
    physical_location TEXT,
    
    -- Project Details
    total_area DECIMAL(15,2) COMMENT 'Total area in square meters',
    total_plots INT DEFAULT 0,
    available_plots INT DEFAULT 0,
    reserved_plots INT DEFAULT 0,
    sold_plots INT DEFAULT 0,
    
    -- Dates
    acquisition_date DATE,
    closing_date DATE,
    
    -- Attachments
    title_deed_path VARCHAR(255),
    survey_plan_path VARCHAR(255),
    contract_attachment_path VARCHAR(255),
    coordinates_path VARCHAR(255),
    
    -- Status
    status ENUM('planning', 'active', 'completed', 'suspended') DEFAULT 'planning',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (region_id) REFERENCES regions(region_id),
    FOREIGN KEY (district_id) REFERENCES districts(district_id),
    FOREIGN KEY (ward_id) REFERENCES wards(ward_id),
    FOREIGN KEY (village_id) REFERENCES villages(village_id),
    INDEX idx_company_project (company_id, project_code),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Plot development projects';

-- Table: plots (Individual plots within projects)
CREATE TABLE plots (
    plot_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    project_id INT NOT NULL,
    
    -- Plot Identification
    plot_number VARCHAR(50) NOT NULL,
    block_number VARCHAR(50),
    
    -- Size & Pricing (from requirements)
    plot_size DECIMAL(10,2) NOT NULL COMMENT 'Size in square meters',
    price_per_unit DECIMAL(15,2) NOT NULL COMMENT 'Price per square meter',
    total_price DECIMAL(15,2) GENERATED ALWAYS AS (plot_size * price_per_unit) STORED,
    
    -- Survey Information
    survey_plan_number VARCHAR(100),
    town_plan_number VARCHAR(100),
    
    -- Coordinates
    gps_coordinates VARCHAR(200),
    
    -- Status
    status ENUM('available', 'reserved', 'sold', 'blocked') DEFAULT 'available',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    UNIQUE KEY unique_plot (project_id, plot_number, block_number),
    INDEX idx_company_plot (company_id, project_id),
    INDEX idx_status (status),
    INDEX idx_plot_number (plot_number)
) ENGINE=InnoDB COMMENT='Individual plots/land parcels';

-- Table: customers (Plot buyers/clients)
CREATE TABLE customers (
    customer_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Personal Information
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    full_name VARCHAR(300) GENERATED ALWAYS AS (CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name)) STORED,
    
    -- Contact
    email VARCHAR(150),
    phone1 VARCHAR(50),
    phone2 VARCHAR(50),
    
    -- Identification
    national_id VARCHAR(50),
    passport_number VARCHAR(50),
    
    -- Location (Swahili fields from requirements)
    region VARCHAR(100),
    district VARCHAR(100),
    ward VARCHAR(100) COMMENT 'Aina ya kitambuliaho',
    village VARCHAR(100) COMMENT 'Namba ya kitambuliaho',
    street_address TEXT,
    
    -- Additional Info
    gender ENUM('male', 'female', 'other'),
    profile_picture VARCHAR(255),
    
    -- Guardian Information (from requirements)
    guardian1_name VARCHAR(200),
    guardian1_relationship VARCHAR(100),
    guardian2_name VARCHAR(200),
    guardian2_relationship VARCHAR(100),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_customer (company_id, customer_id),
    INDEX idx_full_name (full_name),
    INDEX idx_phone (phone1),
    INDEX idx_email (email)
) ENGINE=InnoDB COMMENT='Customer/buyer information';

-- Table: reservations (Plot reservations/bookings)
CREATE TABLE reservations (
    reservation_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- References
    customer_id INT NOT NULL,
    plot_id INT NOT NULL,
    
    -- Reservation Details
    reservation_date DATE NOT NULL,
    reservation_number VARCHAR(50) UNIQUE,
    
    -- Payment Terms (from requirements - 20 payment periods)
    total_amount DECIMAL(15,2) NOT NULL,
    down_payment DECIMAL(15,2) DEFAULT 0,
    remaining_balance DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - down_payment) STORED,
    payment_periods INT DEFAULT 20 COMMENT 'Number of installment periods',
    installment_amount DECIMAL(15,2),
    
    -- Discount
    discount_percentage DECIMAL(5,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Title Information
    title_holder_name VARCHAR(200),
    
    -- File Attachments
    title_deed_path VARCHAR(255),
    
    -- Status
    status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    INDEX idx_company_reservation (company_id, reservation_id),
    INDEX idx_customer (customer_id),
    INDEX idx_plot (plot_id),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Plot reservations and sales';

-- Table: payments (Payment tracking for reservations)
CREATE TABLE payments (
    payment_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Payment Details
    payment_date DATE NOT NULL,
    payment_number VARCHAR(50) UNIQUE,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card') DEFAULT 'cash',
    
    -- Bank/Mobile Details
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    transaction_reference VARCHAR(100),
    
    -- Tax (from requirements)
    tax_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Receipt
    receipt_number VARCHAR(50),
    receipt_path VARCHAR(255),
    
    -- Notes
    remarks TEXT,
    
    -- Status
    status ENUM('pending', 'approved', 'cancelled') DEFAULT 'approved',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    INDEX idx_company_payment (company_id, reservation_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Payment transactions';

-- Table: commissions (Commission tracking - from requirements)
CREATE TABLE commissions (
    commission_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Commission Recipient
    recipient_type ENUM('user', 'external', 'consultant') NOT NULL,
    user_id INT COMMENT 'If recipient is a system user',
    recipient_name VARCHAR(200) NOT NULL,
    recipient_phone VARCHAR(50),
    
    -- Commission Details
    commission_type ENUM('sales', 'referral', 'consultant', 'marketing', 'other') DEFAULT 'sales',
    commission_percentage DECIMAL(5,2),
    commission_amount DECIMAL(15,2) NOT NULL,
    
    -- Payment Status
    payment_status ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending',
    paid_date DATE,
    payment_reference VARCHAR(100),
    
    -- Notes
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_company_commission (company_id, reservation_id),
    INDEX idx_recipient (recipient_type, user_id),
    INDEX idx_status (payment_status)
) ENGINE=InnoDB COMMENT='Commission tracking for sales and referrals';

-- ============================================================================
-- SECTION 5: FINANCE & ACCOUNTING MODULE
-- ============================================================================

-- Table: chart_of_accounts (Account master)
CREATE TABLE chart_of_accounts (
    account_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Account Details
    account_code VARCHAR(20) NOT NULL,
    account_name VARCHAR(200) NOT NULL,
    account_type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    account_category VARCHAR(100),
    
    -- Hierarchy
    parent_account_id INT COMMENT 'For sub-accounts',
    account_level INT DEFAULT 1,
    
    -- Properties
    is_active BOOLEAN DEFAULT TRUE,
    is_control_account BOOLEAN DEFAULT FALSE,
    opening_balance DECIMAL(15,2) DEFAULT 0,
    current_balance DECIMAL(15,2) DEFAULT 0,
    
    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_account_id) REFERENCES chart_of_accounts(account_id),
    UNIQUE KEY unique_account_code (company_id, account_code),
    INDEX idx_company_account (company_id, account_type),
    INDEX idx_account_code (account_code)
) ENGINE=InnoDB COMMENT='Chart of accounts';

-- Table: journal_entries (Journal entry headers)
CREATE TABLE journal_entries (
    journal_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Entry Details
    journal_number VARCHAR(50) UNIQUE NOT NULL,
    journal_date DATE NOT NULL,
    journal_type ENUM('general', 'sales', 'purchase', 'cash', 'bank', 'adjustment') DEFAULT 'general',
    
    -- Reference
    reference_number VARCHAR(100),
    description TEXT,
    
    -- Amounts
    total_debit DECIMAL(15,2) DEFAULT 0,
    total_credit DECIMAL(15,2) DEFAULT 0,
    
    -- Status
    status ENUM('draft', 'posted', 'cancelled') DEFAULT 'draft',
    posted_by INT,
    posted_at TIMESTAMP NULL,
    
    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_journal (company_id, journal_date),
    INDEX idx_journal_number (journal_number),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Journal entry headers';

-- Table: journal_entry_lines (Journal entry details)
CREATE TABLE journal_entry_lines (
    line_id INT PRIMARY KEY AUTO_INCREMENT,
    journal_id INT NOT NULL,
    
    -- Line Details
    line_number INT NOT NULL,
    account_id INT NOT NULL,
    description TEXT,
    
    -- Amounts
    debit_amount DECIMAL(15,2) DEFAULT 0,
    credit_amount DECIMAL(15,2) DEFAULT 0,
    
    -- References
    reference_type VARCHAR(50) COMMENT 'invoice, payment, etc',
    reference_id INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (journal_id) REFERENCES journal_entries(journal_id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(account_id),
    INDEX idx_journal (journal_id),
    INDEX idx_account (account_id)
) ENGINE=InnoDB COMMENT='Journal entry line items';

-- Table: budgets (Budget management)
CREATE TABLE budgets (
    budget_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Budget Period
    budget_name VARCHAR(200) NOT NULL,
    fiscal_year INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    
    -- Status
    status ENUM('draft', 'approved', 'active', 'closed') DEFAULT 'draft',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_budget (company_id, fiscal_year)
) ENGINE=InnoDB;

-- Table: budget_lines (Budget line items)
CREATE TABLE budget_lines (
    budget_line_id INT PRIMARY KEY AUTO_INCREMENT,
    budget_id INT NOT NULL,
    account_id INT NOT NULL,
    
    -- Amounts
    budgeted_amount DECIMAL(15,2) NOT NULL,
    actual_amount DECIMAL(15,2) DEFAULT 0,
    variance DECIMAL(15,2) GENERATED ALWAYS AS (budgeted_amount - actual_amount) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (budget_id) REFERENCES budgets(budget_id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(account_id),
    INDEX idx_budget (budget_id),
    INDEX idx_account (account_id)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 6: HUMAN RESOURCES MODULE
-- ============================================================================

-- Table: departments
CREATE TABLE departments (
    department_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    department_name VARCHAR(100) NOT NULL,
    department_code VARCHAR(20),
    description TEXT,
    
    -- Manager
    manager_user_id INT,
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (manager_user_id) REFERENCES users(user_id),
    UNIQUE KEY unique_dept_code (company_id, department_code),
    INDEX idx_company_dept (company_id, department_name)
) ENGINE=InnoDB;

-- Table: positions
CREATE TABLE positions (
    position_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    department_id INT,
    
    position_title VARCHAR(100) NOT NULL,
    position_code VARCHAR(20),
    job_description TEXT,
    
    -- Salary Range
    min_salary DECIMAL(15,2),
    max_salary DECIMAL(15,2),
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id),
    INDEX idx_company_position (company_id, position_title)
) ENGINE=InnoDB;

-- Table: employees
CREATE TABLE employees (
    employee_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    user_id INT UNIQUE NOT NULL COMMENT 'Links to users table',
    
    -- Employment Details
    employee_number VARCHAR(50) UNIQUE NOT NULL,
    department_id INT,
    position_id INT,
    
    -- Employment Dates
    hire_date DATE NOT NULL,
    confirmation_date DATE,
    termination_date DATE,
    
    -- Employment Type
    employment_type ENUM('permanent', 'contract', 'casual', 'intern') DEFAULT 'permanent',
    contract_end_date DATE,
    
    -- Salary
    basic_salary DECIMAL(15,2),
    allowances DECIMAL(15,2) DEFAULT 0,
    total_salary DECIMAL(15,2) GENERATED ALWAYS AS (basic_salary + allowances) STORED,
    
    -- Bank Details
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    bank_branch VARCHAR(100),
    
    -- Emergency Contact
    emergency_contact_name VARCHAR(200),
    emergency_contact_phone VARCHAR(50),
    emergency_contact_relationship VARCHAR(100),
    
    -- Status
    employment_status ENUM('active', 'suspended', 'terminated', 'resigned') DEFAULT 'active',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id),
    FOREIGN KEY (position_id) REFERENCES positions(position_id),
    INDEX idx_company_employee (company_id, employee_number),
    INDEX idx_status (employment_status)
) ENGINE=InnoDB;

-- Table: attendance
CREATE TABLE attendance (
    attendance_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    employee_id INT NOT NULL,
    
    attendance_date DATE NOT NULL,
    check_in_time TIME,
    check_out_time TIME,
    
    -- Calculated Hours
    total_hours DECIMAL(5,2),
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    
    -- Status
    status ENUM('present', 'absent', 'late', 'leave', 'holiday') DEFAULT 'present',
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    UNIQUE KEY unique_attendance (employee_id, attendance_date),
    INDEX idx_company_attendance (company_id, attendance_date)
) ENGINE=InnoDB;

-- Table: leave_types
CREATE TABLE leave_types (
    leave_type_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    leave_type_name VARCHAR(100) NOT NULL,
    leave_code VARCHAR(20),
    days_per_year INT DEFAULT 0,
    is_paid BOOLEAN DEFAULT TRUE,
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_leave_type (company_id, leave_type_name)
) ENGINE=InnoDB;

-- Table: leave_applications
CREATE TABLE leave_applications (
    leave_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    
    -- Leave Period
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL,
    
    -- Application
    reason TEXT,
    application_date DATE NOT NULL,
    
    -- Approval
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id),
    INDEX idx_company_leave (company_id, employee_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Table: payroll
CREATE TABLE payroll (
    payroll_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Payroll Period
    payroll_month INT NOT NULL,
    payroll_year INT NOT NULL,
    payment_date DATE,
    
    -- Status
    status ENUM('draft', 'processed', 'paid', 'cancelled') DEFAULT 'draft',
    processed_by INT,
    processed_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    UNIQUE KEY unique_payroll_period (company_id, payroll_month, payroll_year),
    INDEX idx_company_payroll (company_id, payroll_year, payroll_month)
) ENGINE=InnoDB;

-- Table: payroll_details
CREATE TABLE payroll_details (
    payroll_detail_id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_id INT NOT NULL,
    employee_id INT NOT NULL,
    
    -- Earnings
    basic_salary DECIMAL(15,2) NOT NULL,
    allowances DECIMAL(15,2) DEFAULT 0,
    overtime_pay DECIMAL(15,2) DEFAULT 0,
    bonus DECIMAL(15,2) DEFAULT 0,
    gross_salary DECIMAL(15,2) GENERATED ALWAYS AS (basic_salary + allowances + overtime_pay + bonus) STORED,
    
    -- Deductions
    tax_amount DECIMAL(15,2) DEFAULT 0,
    nssf_amount DECIMAL(15,2) DEFAULT 0,
    nhif_amount DECIMAL(15,2) DEFAULT 0,
    loan_deduction DECIMAL(15,2) DEFAULT 0,
    other_deductions DECIMAL(15,2) DEFAULT 0,
    total_deductions DECIMAL(15,2) GENERATED ALWAYS AS (tax_amount + nssf_amount + nhif_amount + loan_deduction + other_deductions) STORED,
    
    -- Net Pay
    net_salary DECIMAL(15,2) GENERATED ALWAYS AS (gross_salary - total_deductions) STORED,
    
    -- Payment
    payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    payment_date DATE,
    payment_reference VARCHAR(100),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (payroll_id) REFERENCES payroll(payroll_id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    UNIQUE KEY unique_payroll_employee (payroll_id, employee_id),
    INDEX idx_payroll (payroll_id),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 7: PROCUREMENT MODULE
-- ============================================================================

-- Table: suppliers
CREATE TABLE suppliers (
    supplier_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Supplier Details
    supplier_name VARCHAR(200) NOT NULL,
    supplier_code VARCHAR(50),
    registration_number VARCHAR(100),
    tin_number VARCHAR(100),
    
    -- Contact
    contact_person VARCHAR(200),
    email VARCHAR(150),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    
    -- Address
    physical_address TEXT,
    city VARCHAR(100),
    region VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Tanzania',
    
    -- Banking
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    
    -- Credit Terms
    credit_days INT DEFAULT 30,
    credit_limit DECIMAL(15,2) DEFAULT 0,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier_code (company_id, supplier_code),
    INDEX idx_company_supplier (company_id, supplier_name)
) ENGINE=InnoDB;

-- Table: purchase_requisitions
CREATE TABLE purchase_requisitions (
    requisition_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Requisition Details
    requisition_number VARCHAR(50) UNIQUE NOT NULL,
    requisition_date DATE NOT NULL,
    required_date DATE,
    
    -- Department/Requester
    department_id INT,
    requested_by INT NOT NULL,
    
    -- Purpose
    purpose TEXT,
    
    -- Status
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'ordered', 'cancelled') DEFAULT 'draft',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id),
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    INDEX idx_company_requisition (company_id, requisition_date),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Table: requisition_items
CREATE TABLE requisition_items (
    requisition_item_id INT PRIMARY KEY AUTO_INCREMENT,
    requisition_id INT NOT NULL,
    
    -- Item Details
    item_description TEXT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_of_measure VARCHAR(50),
    estimated_unit_price DECIMAL(15,2),
    estimated_total_price DECIMAL(15,2) GENERATED ALWAYS AS (quantity * estimated_unit_price) STORED,
    
    -- Specifications
    specifications TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (requisition_id) REFERENCES purchase_requisitions(requisition_id) ON DELETE CASCADE,
    INDEX idx_requisition (requisition_id)
) ENGINE=InnoDB;

-- Table: purchase_orders
CREATE TABLE purchase_orders (
    purchase_order_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- PO Details
    po_number VARCHAR(50) UNIQUE NOT NULL,
    po_date DATE NOT NULL,
    delivery_date DATE,
    
    -- Supplier
    supplier_id INT NOT NULL,
    
    -- Reference
    requisition_id INT,
    
    -- Terms
    payment_terms VARCHAR(200),
    delivery_terms VARCHAR(200),
    
    -- Amounts
    subtotal DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Status
    status ENUM('draft', 'submitted', 'approved', 'received', 'closed', 'cancelled') DEFAULT 'draft',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    -- Notes
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (requisition_id) REFERENCES purchase_requisitions(requisition_id),
    INDEX idx_company_po (company_id, po_date),
    INDEX idx_supplier (supplier_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Table: purchase_order_items
CREATE TABLE purchase_order_items (
    po_item_id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_order_id INT NOT NULL,
    
    -- Item Details
    item_description TEXT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_of_measure VARCHAR(50),
    unit_price DECIMAL(15,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    -- Receiving
    quantity_received DECIMAL(10,2) DEFAULT 0,
    quantity_remaining DECIMAL(10,2) GENERATED ALWAYS AS (quantity - quantity_received) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id) ON DELETE CASCADE,
    INDEX idx_po (purchase_order_id)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 8: INVENTORY MODULE
-- ============================================================================

-- Table: item_categories
CREATE TABLE item_categories (
    category_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(20),
    description TEXT,
    parent_category_id INT,
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_category_id) REFERENCES item_categories(category_id),
    UNIQUE KEY unique_category_code (company_id, category_code),
    INDEX idx_company_category (company_id, category_name)
) ENGINE=InnoDB;

-- Table: items
CREATE TABLE items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Item Details
    item_code VARCHAR(50) NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    
    -- Units
    unit_of_measure VARCHAR(50) DEFAULT 'pcs',
    
    -- Pricing
    cost_price DECIMAL(15,2) DEFAULT 0,
    selling_price DECIMAL(15,2) DEFAULT 0,
    
    -- Stock Control
    reorder_level DECIMAL(10,2) DEFAULT 0,
    minimum_stock DECIMAL(10,2) DEFAULT 0,
    maximum_stock DECIMAL(10,2) DEFAULT 0,
    
    -- Stock Tracking
    current_stock DECIMAL(10,2) DEFAULT 0,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES item_categories(category_id),
    UNIQUE KEY unique_item_code (company_id, item_code),
    INDEX idx_company_item (company_id, item_name),
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- Table: stock_movements
CREATE TABLE stock_movements (
    movement_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Movement Details
    movement_number VARCHAR(50) UNIQUE NOT NULL,
    movement_date DATE NOT NULL,
    movement_type ENUM('purchase', 'sale', 'transfer', 'adjustment', 'return') NOT NULL,
    
    -- Item
    item_id INT NOT NULL,
    
    -- Quantity
    quantity DECIMAL(10,2) NOT NULL,
    unit_cost DECIMAL(15,2),
    total_cost DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    
    -- Reference
    reference_type VARCHAR(50),
    reference_id INT,
    
    -- Notes
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_company_movement (company_id, movement_date),
    INDEX idx_item (item_id),
    INDEX idx_type (movement_type)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 9: SALES MODULE
-- ============================================================================

-- Table: quotations
CREATE TABLE quotations (
    quotation_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Quotation Details
    quotation_number VARCHAR(50) UNIQUE NOT NULL,
    quotation_date DATE NOT NULL,
    valid_until_date DATE,
    
    -- Customer
    customer_id INT NOT NULL,
    
    -- Amounts
    subtotal DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Terms
    payment_terms TEXT,
    delivery_terms TEXT,
    
    -- Status
    status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') DEFAULT 'draft',
    
    -- Notes
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_company_quotation (company_id, quotation_date),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Table: quotation_items
CREATE TABLE quotation_items (
    quotation_item_id INT PRIMARY KEY AUTO_INCREMENT,
    quotation_id INT NOT NULL,
    
    -- Item
    item_id INT,
    item_description TEXT NOT NULL,
    
    -- Pricing
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (quotation_id) REFERENCES quotations(quotation_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_quotation (quotation_id)
) ENGINE=InnoDB;

-- Table: invoices
CREATE TABLE invoices (
    invoice_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Invoice Details
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE,
    
    -- Customer
    customer_id INT NOT NULL,
    
    -- Reference
    quotation_id INT,
    
    -- Amounts
    subtotal DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    amount_paid DECIMAL(15,2) DEFAULT 0,
    balance_due DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - amount_paid) STORED,
    
    -- Status
    status ENUM('draft', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    
    -- Notes
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (quotation_id) REFERENCES quotations(quotation_id),
    INDEX idx_company_invoice (company_id, invoice_date),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Table: invoice_items
CREATE TABLE invoice_items (
    invoice_item_id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    
    -- Item
    item_id INT,
    item_description TEXT NOT NULL,
    
    -- Pricing
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 10: AUDIT & SYSTEM LOGS
-- ============================================================================

-- Table: audit_logs
CREATE TABLE audit_logs (
    log_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    user_id INT,
    
    -- Action Details
    action_type VARCHAR(50) NOT NULL COMMENT 'create, update, delete, view, login, logout',
    module_name VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    
    -- Changes
    old_values JSON,
    new_values JSON,
    
    -- Request Info
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_company (company_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action_type),
    INDEX idx_module (module_name),
    INDEX idx_created (created_at)
) ENGINE=InnoDB COMMENT='System audit trail';

-- Table: system_logs
CREATE TABLE system_logs (
    log_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    
    -- Log Details
    log_level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
    log_message TEXT NOT NULL,
    
    -- Context
    module_name VARCHAR(100),
    file_path VARCHAR(255),
    line_number INT,
    
    -- Additional Data
    context_data JSON,
    stack_trace TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_level (log_level),
    INDEX idx_module (module_name),
    INDEX idx_created (created_at)
) ENGINE=InnoDB COMMENT='System error and debug logs';

-- Table: login_attempts
CREATE TABLE login_attempts (
    attempt_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    
    username VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    
    -- Result
    is_successful BOOLEAN NOT NULL,
    failure_reason VARCHAR(200),
    
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_ip (ip_address),
    INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB COMMENT='Login attempt tracking for security';

-- ============================================================================
-- SECTION 11: SYSTEM SETTINGS & CONFIGURATIONS
-- ============================================================================

-- Table: system_settings
CREATE TABLE system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    
    -- Setting
    setting_category VARCHAR(100) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    data_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    
    -- Description
    description TEXT,
    
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_setting (company_id, setting_category, setting_key),
    INDEX idx_company_category (company_id, setting_category)
) ENGINE=InnoDB COMMENT='Configurable system settings';

-- Table: document_sequences
CREATE TABLE document_sequences (
    sequence_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Document Type
    document_type VARCHAR(50) NOT NULL COMMENT 'invoice, po, receipt, etc',
    prefix VARCHAR(10),
    next_number INT DEFAULT 1,
    padding INT DEFAULT 4 COMMENT 'Number of digits',
    
    -- Format: PREFIX + PADDED_NUMBER (e.g., INV-0001)
    
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_doc_sequence (company_id, document_type),
    INDEX idx_company (company_id)
) ENGINE=InnoDB COMMENT='Auto-numbering for documents';

-- ============================================================================
-- END OF DATABASE SCHEMA
-- ============================================================================

-- Insert sample document sequences for a company
-- This would be done after company registration
-- INSERT INTO document_sequences (company_id, document_type, prefix, next_number, padding) VALUES
-- (1, 'invoice', 'INV-', 1, 5),
-- (1, 'quotation', 'QT-', 1, 5),
-- (1, 'receipt', 'RCP-', 1, 5),
-- (1, 'purchase_order', 'PO-', 1, 5),
-- (1, 'requisition', 'REQ-', 1, 5),
-- (1, 'journal', 'JV-', 1, 5),
-- (1, 'plot_reservation', 'PLT-', 1, 4);