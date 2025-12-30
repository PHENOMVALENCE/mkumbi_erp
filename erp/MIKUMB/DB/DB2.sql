-- ============================================================================
-- COMPREHENSIVE MULTI-TENANT ERP SYSTEM - EXPANDED DATABASE SCHEMA
-- Version: 2.0 - FULL FEATURE SET
-- Database: erp_system_db
-- ============================================================================

USE erp_system_db;

-- ============================================================================
-- SECTION 1: ENHANCED PROJECTS & PLOTS MODULE
-- ============================================================================

-- Add new columns to projects table
ALTER TABLE projects
ADD COLUMN land_purchase_price DECIMAL(15,2) COMMENT 'Total cost to acquire land',
ADD COLUMN total_operational_costs DECIMAL(15,2) DEFAULT 0 COMMENT 'Survey, legal, development costs',
ADD COLUMN total_investment DECIMAL(15,2) GENERATED ALWAYS AS (land_purchase_price + total_operational_costs) STORED,
ADD COLUMN cost_per_sqm DECIMAL(10,2) COMMENT 'Buying cost per square meter',
ADD COLUMN selling_price_per_sqm DECIMAL(10,2) COMMENT 'Selling price per square meter',
ADD COLUMN profit_margin_percentage DECIMAL(5,2) DEFAULT 0,
ADD COLUMN total_expected_revenue DECIMAL(15,2) DEFAULT 0,
ADD COLUMN total_actual_revenue DECIMAL(15,2) DEFAULT 0;

-- Table: project_costs (Track all operational costs)
CREATE TABLE project_costs (
    cost_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    project_id INT NOT NULL,
    
    -- Cost Details
    cost_category ENUM('land_purchase', 'survey', 'legal_fees', 'title_processing', 
                       'development', 'marketing', 'consultation', 'other') NOT NULL,
    cost_description TEXT NOT NULL,
    cost_amount DECIMAL(15,2) NOT NULL,
    cost_date DATE NOT NULL,
    
    -- Supporting Documents
    receipt_number VARCHAR(100),
    attachment_path VARCHAR(255),
    
    -- Approval
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    -- Notes
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    INDEX idx_company_project_cost (company_id, project_id),
    INDEX idx_category (cost_category)
) ENGINE=InnoDB COMMENT='Project operational costs tracking';

-- Table: plot_contracts (Sale agreements/contracts)
CREATE TABLE plot_contracts (
    contract_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Contract Details
    contract_number VARCHAR(50) UNIQUE NOT NULL,
    contract_date DATE NOT NULL,
    contract_type ENUM('sale', 'lease', 'installment') DEFAULT 'installment',
    
    -- Terms & Conditions
    contract_duration_months INT COMMENT 'For installment contracts',
    contract_terms TEXT COMMENT 'Full terms and conditions',
    special_conditions TEXT,
    
    -- Parties
    seller_name VARCHAR(200) NOT NULL,
    seller_id_number VARCHAR(50),
    buyer_name VARCHAR(200) NOT NULL,
    buyer_id_number VARCHAR(50),
    
    -- Witnesses
    witness1_name VARCHAR(200),
    witness1_id_number VARCHAR(50),
    witness1_signature_path VARCHAR(255),
    witness2_name VARCHAR(200),
    witness2_id_number VARCHAR(50),
    witness2_signature_path VARCHAR(255),
    
    -- Legal
    lawyer_name VARCHAR(200),
    notary_name VARCHAR(200),
    notary_stamp_number VARCHAR(100),
    
    -- Contract Document
    contract_template_path VARCHAR(255),
    signed_contract_path VARCHAR(255),
    
    -- Status
    status ENUM('draft', 'pending_signature', 'signed', 'completed', 'cancelled') DEFAULT 'draft',
    signed_date DATE,
    completion_date DATE,
    
    -- Cancellation
    cancelled_date DATE,
    cancellation_reason TEXT,
    cancelled_by INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    INDEX idx_company_contract (company_id, contract_number),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Sale contracts and agreements';

-- Table: payment_schedules (Auto-generated installment schedules)
CREATE TABLE payment_schedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Schedule Details
    installment_number INT NOT NULL COMMENT '1, 2, 3... up to total periods',
    due_date DATE NOT NULL,
    installment_amount DECIMAL(15,2) NOT NULL,
    
    -- Payment Status
    is_paid BOOLEAN DEFAULT FALSE,
    paid_amount DECIMAL(15,2) DEFAULT 0,
    payment_id INT COMMENT 'Links to actual payment',
    paid_date DATE,
    
    -- Late Payment
    is_overdue BOOLEAN DEFAULT FALSE,
    days_overdue INT DEFAULT 0,
    late_fee DECIMAL(15,2) DEFAULT 0,
    
    -- Notes
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
    UNIQUE KEY unique_schedule (reservation_id, installment_number),
    INDEX idx_due_date (due_date),
    INDEX idx_overdue (is_overdue)
) ENGINE=InnoDB COMMENT='Auto-generated payment schedules';

-- ============================================================================
-- SECTION 2: SERVICES MODULE (Land Services)
-- ============================================================================

-- Table: service_types
CREATE TABLE service_types (
    service_type_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Service Details
    service_code VARCHAR(20) UNIQUE,
    service_name VARCHAR(200) NOT NULL,
    service_category ENUM('land_evaluation', 'title_processing', 'consultation', 
                          'construction', 'survey', 'legal', 'other') NOT NULL,
    description TEXT,
    
    -- Pricing
    base_price DECIMAL(15,2),
    price_unit VARCHAR(50) COMMENT 'per sqm, per plot, flat fee',
    
    -- Duration
    estimated_duration_days INT,
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_service (company_id, service_category)
) ENGINE=InnoDB COMMENT='Service offerings catalog';

-- Table: service_requests
CREATE TABLE service_requests (
    service_request_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Request Details
    request_number VARCHAR(50) UNIQUE NOT NULL,
    request_date DATE NOT NULL,
    service_type_id INT NOT NULL,
    
    -- Customer/Plot
    customer_id INT,
    plot_id INT,
    project_id INT,
    
    -- Service Specifics
    service_description TEXT NOT NULL,
    plot_size DECIMAL(10,2) COMMENT 'If applicable',
    location_details TEXT,
    
    -- Pricing
    quoted_price DECIMAL(15,2),
    final_price DECIMAL(15,2),
    
    -- Schedule
    requested_start_date DATE,
    actual_start_date DATE,
    expected_completion_date DATE,
    actual_completion_date DATE,
    
    -- Assignment
    assigned_to INT COMMENT 'User/consultant assigned',
    
    -- Status
    status ENUM('pending', 'quoted', 'approved', 'in_progress', 'completed', 
                'cancelled', 'on_hold') DEFAULT 'pending',
    
    -- Documents
    quotation_path VARCHAR(255),
    completion_report_path VARCHAR(255),
    
    -- Payment
    payment_status ENUM('unpaid', 'partial', 'paid') DEFAULT 'unpaid',
    amount_paid DECIMAL(15,2) DEFAULT 0,
    
    -- Notes
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (service_type_id) REFERENCES service_types(service_type_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    FOREIGN KEY (project_id) REFERENCES projects(project_id),
    INDEX idx_company_request (company_id, request_date),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Service requests and orders';

-- ============================================================================
-- SECTION 3: ENHANCED CRM MODULE
-- ============================================================================

-- Table: leads
CREATE TABLE leads (
    lead_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Lead Information
    lead_source ENUM('website', 'referral', 'walk_in', 'phone', 'email', 
                     'social_media', 'advertisement', 'other') NOT NULL,
    lead_status ENUM('new', 'contacted', 'qualified', 'proposal', 
                     'negotiation', 'won', 'lost') DEFAULT 'new',
    
    -- Contact Details
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(150),
    phone VARCHAR(50) NOT NULL,
    alternative_phone VARCHAR(50),
    
    -- Interest
    interested_in ENUM('plot_purchase', 'land_services', 'consultation', 
                       'construction', 'other'),
    budget_range VARCHAR(100),
    preferred_location VARCHAR(200),
    preferred_plot_size VARCHAR(100),
    
    -- Assignment
    assigned_to INT COMMENT 'Sales person',
    
    -- Follow-up
    last_contact_date DATE,
    next_follow_up_date DATE,
    follow_up_notes TEXT,
    
    -- Conversion
    converted_to_customer_id INT,
    conversion_date DATE,
    
    -- Loss Reason
    lost_reason TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(user_id),
    FOREIGN KEY (converted_to_customer_id) REFERENCES customers(customer_id),
    INDEX idx_company_lead (company_id, lead_status),
    INDEX idx_assigned (assigned_to)
) ENGINE=InnoDB COMMENT='Sales leads management';

-- Table: sales_quotations (Enhanced quotations)
CREATE TABLE sales_quotations (
    quotation_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Quotation Details
    quotation_number VARCHAR(50) UNIQUE NOT NULL,
    quotation_date DATE NOT NULL,
    valid_until_date DATE NOT NULL,
    
    -- Customer
    customer_id INT,
    lead_id INT,
    
    -- Items/Services
    quotation_type ENUM('plot_sale', 'service', 'mixed') DEFAULT 'plot_sale',
    
    -- Plot Details (if plot sale)
    plot_id INT,
    
    -- Amounts
    subtotal DECIMAL(15,2) DEFAULT 0,
    discount_percentage DECIMAL(5,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Payment Terms
    payment_terms TEXT,
    down_payment_required DECIMAL(15,2),
    installment_months INT,
    
    -- Status
    status ENUM('draft', 'sent', 'viewed', 'accepted', 'rejected', 
                'expired', 'revised') DEFAULT 'draft',
    accepted_date DATE,
    rejection_reason TEXT,
    
    -- Conversion
    converted_to_reservation_id INT,
    
    -- Documents
    quotation_pdf_path VARCHAR(255),
    
    -- Notes
    terms_conditions TEXT,
    internal_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (lead_id) REFERENCES leads(lead_id),
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    FOREIGN KEY (converted_to_reservation_id) REFERENCES reservations(reservation_id),
    INDEX idx_company_quotation (company_id, quotation_date),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Sales quotations';

-- Table: quotation_items
CREATE TABLE quotation_items (
    quotation_item_id INT PRIMARY KEY AUTO_INCREMENT,
    quotation_id INT NOT NULL,
    
    -- Item Type
    item_type ENUM('plot', 'service', 'other') NOT NULL,
    item_description TEXT NOT NULL,
    
    -- References
    plot_id INT,
    service_type_id INT,
    
    -- Pricing
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (quotation_id) REFERENCES sales_quotations(quotation_id) ON DELETE CASCADE,
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    FOREIGN KEY (service_type_id) REFERENCES service_types(service_type_id),
    INDEX idx_quotation (quotation_id)
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 4: ENHANCED PAYMENTS, REFUNDS & RECONCILIATION
-- ============================================================================

-- Add new columns to payments table
ALTER TABLE payments
ADD COLUMN payment_type ENUM('down_payment', 'installment', 'full_payment', 
                             'service_payment', 'refund', 'other') DEFAULT 'installment',
ADD COLUMN voucher_number VARCHAR(50),
ADD COLUMN is_reconciled BOOLEAN DEFAULT FALSE,
ADD COLUMN reconciliation_date DATE,
ADD COLUMN reconciled_by INT;

-- Table: payment_vouchers
CREATE TABLE payment_vouchers (
    voucher_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Voucher Details
    voucher_number VARCHAR(50) UNIQUE NOT NULL,
    voucher_type ENUM('payment', 'receipt', 'refund', 'adjustment') NOT NULL,
    voucher_date DATE NOT NULL,
    
    -- Reference
    payment_id INT,
    reservation_id INT,
    customer_id INT,
    
    -- Amounts
    amount DECIMAL(15,2) NOT NULL,
    
    -- Bank Details
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    cheque_number VARCHAR(50),
    transaction_reference VARCHAR(100),
    
    -- Approval
    approved_by INT,
    approved_at TIMESTAMP NULL,
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    
    -- Document
    voucher_pdf_path VARCHAR(255),
    
    -- Notes
    description TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_company_voucher (company_id, voucher_date),
    INDEX idx_type (voucher_type)
) ENGINE=InnoDB COMMENT='Payment vouchers and receipts';

-- Table: refunds
CREATE TABLE refunds (
    refund_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Refund Details
    refund_number VARCHAR(50) UNIQUE NOT NULL,
    refund_date DATE NOT NULL,
    refund_reason ENUM('cancellation', 'overpayment', 'plot_unavailable', 
                       'customer_request', 'dispute', 'other') NOT NULL,
    
    -- Original Transaction
    reservation_id INT NOT NULL,
    customer_id INT NOT NULL,
    plot_id INT,
    
    -- Original Payment
    original_payment_id INT,
    original_amount DECIMAL(15,2) NOT NULL,
    
    -- Refund Amount
    refund_amount DECIMAL(15,2) NOT NULL,
    penalty_amount DECIMAL(15,2) DEFAULT 0 COMMENT 'Deduction if any',
    net_refund_amount DECIMAL(15,2) GENERATED ALWAYS AS (refund_amount - penalty_amount) STORED,
    
    -- Refund Method
    refund_method ENUM('bank_transfer', 'cheque', 'cash', 'mobile_money') NOT NULL,
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    cheque_number VARCHAR(50),
    transaction_reference VARCHAR(100),
    
    -- Processing
    status ENUM('pending', 'approved', 'processed', 'rejected') DEFAULT 'pending',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    processed_by INT,
    processed_at TIMESTAMP NULL,
    
    -- Rejection
    rejection_reason TEXT,
    
    -- Documents
    refund_voucher_path VARCHAR(255),
    supporting_documents_path VARCHAR(255),
    
    -- Notes
    detailed_reason TEXT,
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    FOREIGN KEY (original_payment_id) REFERENCES payments(payment_id),
    INDEX idx_company_refund (company_id, refund_date),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Payment refunds management';

-- Table: payment_recovery (For failed/cancelled payments)
CREATE TABLE payment_recovery (
    recovery_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Recovery Details
    recovery_number VARCHAR(50) UNIQUE NOT NULL,
    recovery_date DATE NOT NULL,
    
    -- Customer & Reservation
    customer_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Debt Details
    total_debt DECIMAL(15,2) NOT NULL,
    amount_recovered DECIMAL(15,2) DEFAULT 0,
    outstanding_balance DECIMAL(15,2) GENERATED ALWAYS AS (total_debt - amount_recovered) STORED,
    
    -- Recovery Method
    recovery_method ENUM('legal_action', 'negotiation', 'payment_plan', 
                        'asset_seizure', 'write_off') NOT NULL,
    
    -- Status
    status ENUM('initiated', 'in_progress', 'partially_recovered', 
                'fully_recovered', 'written_off') DEFAULT 'initiated',
    
    -- Assignment
    assigned_to INT COMMENT 'Recovery officer',
    
    -- Timeline
    follow_up_date DATE,
    resolution_date DATE,
    
    -- Documents
    legal_notice_path VARCHAR(255),
    agreement_path VARCHAR(255),
    
    -- Notes
    recovery_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    INDEX idx_company_recovery (company_id, status),
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB COMMENT='Payment recovery tracking';

-- ============================================================================
-- SECTION 5: BANK RECONCILIATION MODULE
-- ============================================================================

-- Table: bank_accounts
CREATE TABLE bank_accounts (
    bank_account_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Account Details
    account_name VARCHAR(200) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    bank_branch VARCHAR(100),
    swift_code VARCHAR(50),
    
    -- Account Type
    account_type ENUM('checking', 'savings', 'business', 'escrow') DEFAULT 'business',
    currency_code VARCHAR(3) DEFAULT 'TZS',
    
    -- Balances
    opening_balance DECIMAL(15,2) DEFAULT 0,
    current_balance DECIMAL(15,2) DEFAULT 0,
    
    -- Integration
    gl_account_id INT COMMENT 'Link to chart of accounts',
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (gl_account_id) REFERENCES chart_of_accounts(account_id),
    INDEX idx_company_account (company_id, account_number)
) ENGINE=InnoDB COMMENT='Company bank accounts';

-- Table: bank_statements
CREATE TABLE bank_statements (
    statement_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    bank_account_id INT NOT NULL,
    
    -- Statement Details
    statement_number VARCHAR(50),
    statement_date DATE NOT NULL,
    statement_period_start DATE NOT NULL,
    statement_period_end DATE NOT NULL,
    
    -- Balances
    opening_balance DECIMAL(15,2) NOT NULL,
    closing_balance DECIMAL(15,2) NOT NULL,
    total_credits DECIMAL(15,2) DEFAULT 0,
    total_debits DECIMAL(15,2) DEFAULT 0,
    
    -- Upload
    statement_file_path VARCHAR(255),
    
    -- Reconciliation
    is_reconciled BOOLEAN DEFAULT FALSE,
    reconciliation_date DATE,
    reconciled_by INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(bank_account_id),
    INDEX idx_company_statement (company_id, statement_date)
) ENGINE=InnoDB COMMENT='Bank statements';

-- Table: bank_transactions
CREATE TABLE bank_transactions (
    bank_transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    bank_account_id INT NOT NULL,
    statement_id INT,
    
    -- Transaction Details
    transaction_date DATE NOT NULL,
    value_date DATE,
    transaction_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    
    -- Description
    description TEXT,
    reference_number VARCHAR(100),
    cheque_number VARCHAR(50),
    
    -- Balance
    running_balance DECIMAL(15,2),
    
    -- Reconciliation
    is_reconciled BOOLEAN DEFAULT FALSE,
    reconciled_with_payment_id INT,
    reconciliation_date DATE,
    reconciliation_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(bank_account_id),
    FOREIGN KEY (statement_id) REFERENCES bank_statements(statement_id),
    FOREIGN KEY (reconciled_with_payment_id) REFERENCES payments(payment_id),
    INDEX idx_company_transaction (company_id, transaction_date),
    INDEX idx_reconciliation (is_reconciled)
) ENGINE=InnoDB COMMENT='Bank transaction entries';

-- ============================================================================
-- SECTION 6: ENHANCED TAX MANAGEMENT
-- ============================================================================

-- Table: tax_types
CREATE TABLE tax_types (
    tax_type_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Tax Details
    tax_code VARCHAR(20) UNIQUE NOT NULL,
    tax_name VARCHAR(100) NOT NULL,
    tax_description TEXT,
    
    -- Tax Rate
    tax_rate DECIMAL(5,2) NOT NULL COMMENT 'Percentage',
    
    -- Applicability
    applies_to ENUM('sales', 'purchases', 'services', 'payroll', 'all') DEFAULT 'all',
    
    -- Authority
    tax_authority VARCHAR(200) COMMENT 'e.g., TRA - Tanzania Revenue Authority',
    tax_account_number VARCHAR(100),
    
    -- GL Integration
    tax_payable_account_id INT,
    tax_expense_account_id INT,
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_tax (company_id, tax_code)
) ENGINE=InnoDB COMMENT='Tax types and rates';

-- Table: tax_transactions
CREATE TABLE tax_transactions (
    tax_transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Transaction Reference
    transaction_date DATE NOT NULL,
    transaction_type ENUM('sale', 'purchase', 'service', 'payroll') NOT NULL,
    reference_type VARCHAR(50) COMMENT 'invoice, payment, etc',
    reference_id INT,
    
    -- Tax Details
    tax_type_id INT NOT NULL,
    taxable_amount DECIMAL(15,2) NOT NULL,
    tax_rate DECIMAL(5,2) NOT NULL,
    tax_amount DECIMAL(15,2) NOT NULL,
    
    -- Customer/Supplier
    customer_id INT,
    supplier_id INT,
    
    -- Status
    is_filed BOOLEAN DEFAULT FALSE,
    filing_date DATE,
    filing_period VARCHAR(20) COMMENT 'e.g., 2025-Q1',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (tax_type_id) REFERENCES tax_types(tax_type_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    INDEX idx_company_tax_trans (company_id, transaction_date),
    INDEX idx_filing (is_filed, filing_period)
) ENGINE=InnoDB COMMENT='Tax transaction records';

-- ============================================================================
-- SECTION 7: CREDITORS & DEBTORS MANAGEMENT
-- ============================================================================

-- Table: creditors (Accounts Payable)
CREATE TABLE creditors (
    creditor_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Creditor Details
    creditor_type ENUM('supplier', 'contractor', 'consultant', 'employee', 'other') NOT NULL,
    supplier_id INT,
    employee_id INT,
    creditor_name VARCHAR(200) NOT NULL,
    
    -- Contact
    contact_person VARCHAR(200),
    phone VARCHAR(50),
    email VARCHAR(150),
    
    -- Financial
    total_amount_owed DECIMAL(15,2) DEFAULT 0,
    amount_paid DECIMAL(15,2) DEFAULT 0,
    outstanding_balance DECIMAL(15,2) GENERATED ALWAYS AS (total_amount_owed - amount_paid) STORED,
    
    -- Terms
    credit_days INT DEFAULT 30,
    
    -- Status
    status ENUM('active', 'settled', 'overdue', 'disputed') DEFAULT 'active',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    INDEX idx_company_creditor (company_id, status)
) ENGINE=InnoDB COMMENT='Creditors/Accounts Payable';

-- Table: creditor_invoices
CREATE TABLE creditor_invoices (
    creditor_invoice_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    creditor_id INT NOT NULL,
    
    -- Invoice Details
    invoice_number VARCHAR(50) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    
    -- Amounts
    invoice_amount DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) DEFAULT 0,
    balance_due DECIMAL(15,2) GENERATED ALWAYS AS (invoice_amount - amount_paid) STORED,
    
    -- Reference
    purchase_order_id INT,
    description TEXT,
    
    -- Status
    status ENUM('pending', 'approved', 'partially_paid', 'paid', 'overdue') DEFAULT 'pending',
    
    -- Payment
    payment_date DATE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (creditor_id) REFERENCES creditors(creditor_id),
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id),
    INDEX idx_company_invoice (company_id, due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Creditor invoices';

-- Table: debtors (Accounts Receivable)
CREATE TABLE debtors (
    debtor_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Debtor Details
    debtor_type ENUM('customer', 'plot_buyer', 'service_client', 'other') NOT NULL,
    customer_id INT,
    debtor_name VARCHAR(200) NOT NULL,
    
    -- Contact
    phone VARCHAR(50),
    email VARCHAR(150),
    
    -- Financial
    total_amount_due DECIMAL(15,2) DEFAULT 0,
    amount_received DECIMAL(15,2) DEFAULT 0,
    outstanding_balance DECIMAL(15,2) GENERATED ALWAYS AS (total_amount_due - amount_received) STORED,
    
    -- Aging
    current_due DECIMAL(15,2) DEFAULT 0,
    days_30 DECIMAL(15,2) DEFAULT 0,
    days_60 DECIMAL(15,2) DEFAULT 0,
    days_90_plus DECIMAL(15,2) DEFAULT 0,
    
    -- Status
    status ENUM('active', 'settled', 'overdue', 'legal_action') DEFAULT 'active',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_company_debtor (company_id, status)
) ENGINE=InnoDB COMMENT='Debtors/Accounts Receivable';

-- ============================================================================
-- SECTION 8: MANAGERIAL AUTHORIZATION & POLICIES
-- ============================================================================

-- Table: approval_workflows
CREATE TABLE approval_workflows (
    workflow_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Workflow Details
    workflow_name VARCHAR(200) NOT NULL,
    workflow_code VARCHAR(50) UNIQUE NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    
    -- Applicable To
    applies_to ENUM('payment', 'purchase_order', 'refund', 'contract', 
                    'service_request', 'budget', 'expense', 'all'),
    
    -- Amount Thresholds
    min_amount DECIMAL(15,2) DEFAULT 0,
    max_amount DECIMAL(15,2),
    
    -- Auto-approve conditions
    auto_approve_below DECIMAL(15,2),
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_workflow (company_id, workflow_code)
) ENGINE=InnoDB COMMENT='Approval workflow definitions';

-- Table: approval_levels
CREATE TABLE approval_levels (
    approval_level_id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    
    -- Level Details
    level_number INT NOT NULL,
    level_name VARCHAR(100) NOT NULL,
    
    -- Approvers (Role or User)
    approver_type ENUM('role', 'user', 'any_manager') NOT NULL,
    role_id INT,
    user_id INT,
    
    -- Requirements
    is_required BOOLEAN DEFAULT TRUE,
    can_skip BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES system_roles(role_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_workflow (workflow_id),
    UNIQUE KEY unique_workflow_level (workflow_id, level_number)
) ENGINE=InnoDB COMMENT='Approval workflow levels';

-- Table: approval_requests
CREATE TABLE approval_requests (
    approval_request_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    workflow_id INT NOT NULL,
    
    -- Request Details
    request_number VARCHAR(50) UNIQUE NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Reference
    reference_type VARCHAR(50) NOT NULL COMMENT 'payment, purchase_order, etc',
    reference_id INT NOT NULL,
    amount DECIMAL(15,2),
    
    -- Requester
    requested_by INT NOT NULL,
    
    -- Current Status
    current_level INT DEFAULT 1,
    overall_status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    
    -- Completion
    completed_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id),
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    INDEX idx_company_request (company_id, overall_status),
    INDEX idx_status (overall_status)
) ENGINE=InnoDB COMMENT='Approval requests tracking';

-- Table: approval_actions
CREATE TABLE approval_actions (
    approval_action_id INT PRIMARY KEY AUTO_INCREMENT,
    approval_request_id INT NOT NULL,
    approval_level_id INT NOT NULL,
    
    -- Action Details
    action ENUM('approved', 'rejected', 'returned', 'cancelled') NOT NULL,
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acted_by INT NOT NULL,
    
    -- Comments
    comments TEXT,
    
    -- Attachments
    attachment_path VARCHAR(255),
    
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(approval_request_id) ON DELETE CASCADE,
    FOREIGN KEY (approval_level_id) REFERENCES approval_levels(approval_level_id),
    FOREIGN KEY (acted_by) REFERENCES users(user_id),
    INDEX idx_request (approval_request_id)
) ENGINE=InnoDB COMMENT='Approval actions log';

-- ============================================================================
-- SECTION 9: ENHANCED COMMISSIONS MODULE
-- ============================================================================

-- Add commission tiers and structures
ALTER TABLE commissions
ADD COLUMN commission_tier VARCHAR(50) COMMENT 'Bronze, Silver, Gold, etc',
ADD COLUMN base_commission_rate DECIMAL(5,2),
ADD COLUMN bonus_commission_rate DECIMAL(5,2) DEFAULT 0,
ADD COLUMN total_commission_rate DECIMAL(5,2) GENERATED ALWAYS AS (base_commission_rate + bonus_commission_rate) STORED,
ADD COLUMN payment_method ENUM('cash', 'bank_transfer', 'cheque', 'mobile_money'),
ADD COLUMN payment_account_number VARCHAR(100);

-- Table: commission_structures
CREATE TABLE commission_structures (
    commission_structure_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Structure Details
    structure_name VARCHAR(100) NOT NULL,
    structure_code VARCHAR(20) UNIQUE,
    
    -- Applicable To
    commission_type ENUM('sales', 'referral', 'consultant', 'marketing', 
                        'collection', 'performance') NOT NULL,
    
    -- Rate Structure
    is_tiered BOOLEAN DEFAULT FALSE,
    base_rate DECIMAL(5,2) NOT NULL COMMENT 'Base percentage',
    
    -- Conditions
    min_sales_amount DECIMAL(15,2),
    target_amount DECIMAL(15,2),
    
    -- Payment Terms
    payment_frequency ENUM('immediate', 'monthly', 'quarterly', 'on_completion') DEFAULT 'monthly',
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_structure (company_id, commission_type)
) ENGINE=InnoDB COMMENT='Commission rate structures';

-- Table: commission_tiers
CREATE TABLE commission_tiers (
    commission_tier_id INT PRIMARY KEY AUTO_INCREMENT,
    commission_structure_id INT NOT NULL,
    
    -- Tier Details
    tier_name VARCHAR(50) NOT NULL COMMENT 'Bronze, Silver, Gold, Platinum',
    tier_level INT NOT NULL,
    
    -- Thresholds
    min_amount DECIMAL(15,2) NOT NULL,
    max_amount DECIMAL(15,2),
    
    -- Commission Rate
    commission_rate DECIMAL(5,2) NOT NULL,
    bonus_rate DECIMAL(5,2) DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (commission_structure_id) REFERENCES commission_structures(commission_structure_id) ON DELETE CASCADE,
    INDEX idx_structure (commission_structure_id),
    UNIQUE KEY unique_structure_tier (commission_structure_id, tier_level)
) ENGINE=InnoDB COMMENT='Tiered commission rates';

-- ============================================================================
-- SECTION 10: ENHANCED PROCUREMENT & STORE MANAGEMENT
-- ============================================================================

-- Table: store_locations
CREATE TABLE store_locations (
    store_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Store Details
    store_code VARCHAR(20) UNIQUE NOT NULL,
    store_name VARCHAR(100) NOT NULL,
    store_type ENUM('main', 'branch', 'warehouse', 'site') DEFAULT 'main',
    
    -- Location
    physical_location TEXT,
    region VARCHAR(100),
    
    -- Manager
    store_manager_id INT,
    
    -- Capacity
    storage_capacity VARCHAR(100),
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (store_manager_id) REFERENCES employees(employee_id),
    INDEX idx_company_store (company_id, store_code)
) ENGINE=InnoDB COMMENT='Store/warehouse locations';

-- Table: store_stock (Stock per location)
CREATE TABLE store_stock (
    store_stock_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    store_id INT NOT NULL,
    item_id INT NOT NULL,
    
    -- Stock Levels
    quantity_on_hand DECIMAL(10,2) DEFAULT 0,
    quantity_reserved DECIMAL(10,2) DEFAULT 0,
    quantity_available DECIMAL(10,2) GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED,
    
    -- Reorder Levels
    reorder_level DECIMAL(10,2),
    reorder_quantity DECIMAL(10,2),
    
    -- Location in Store
    bin_location VARCHAR(50),
    shelf_number VARCHAR(50),
    
    -- Last Movement
    last_movement_date DATE,
    
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES store_locations(store_id),
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    UNIQUE KEY unique_store_item (store_id, item_id),
    INDEX idx_company_store_stock (company_id, store_id)
) ENGINE=InnoDB COMMENT='Stock levels per store location';

-- ============================================================================
-- SECTION 11: PLOT CANCELLATION & RETURN TO MARKET
-- ============================================================================

-- Table: reservation_cancellations
CREATE TABLE reservation_cancellations (
    cancellation_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    reservation_id INT NOT NULL,
    
    -- Cancellation Details
    cancellation_number VARCHAR(50) UNIQUE NOT NULL,
    cancellation_date DATE NOT NULL,
    cancellation_reason ENUM('customer_request', 'payment_default', 
                            'mutual_agreement', 'breach_of_contract', 
                            'plot_unavailable', 'other') NOT NULL,
    detailed_reason TEXT,
    
    -- Financial Impact
    total_amount_paid DECIMAL(15,2) DEFAULT 0,
    refund_amount DECIMAL(15,2) DEFAULT 0,
    penalty_amount DECIMAL(15,2) DEFAULT 0,
    amount_forfeited DECIMAL(15,2) DEFAULT 0,
    
    -- Plot Status
    plot_id INT NOT NULL,
    plot_return_status ENUM('returned_to_market', 'reserved_for_other', 'blocked') DEFAULT 'returned_to_market',
    plot_returned_date DATE,
    
    -- Contract
    contract_id INT,
    contract_termination_date DATE,
    
    -- Approval
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    -- Documents
    cancellation_letter_path VARCHAR(255),
    termination_agreement_path VARCHAR(255),
    
    -- Notes
    internal_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id),
    FOREIGN KEY (contract_id) REFERENCES plot_contracts(contract_id),
    INDEX idx_company_cancellation (company_id, cancellation_date)
) ENGINE=InnoDB COMMENT='Reservation cancellations tracking';

-- Add trigger to update plot status on cancellation
DELIMITER //
CREATE TRIGGER after_cancellation_insert
AFTER INSERT ON reservation_cancellations
FOR EACH ROW
BEGIN
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
END//
DELIMITER ;

-- ============================================================================
-- SECTION 12: SYSTEM SETTINGS & CONFIGURATIONS
-- ============================================================================

-- Table: notification_templates
CREATE TABLE notification_templates (
    template_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    
    -- Template Details
    template_code VARCHAR(50) UNIQUE NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    template_type ENUM('email', 'sms', 'system', 'print') NOT NULL,
    
    -- Trigger
    trigger_event VARCHAR(100) COMMENT 'payment_received, contract_signed, etc',
    
    -- Content
    subject VARCHAR(200),
    message_body TEXT NOT NULL,
    
    -- Variables
    available_variables TEXT COMMENT 'JSON array of available placeholders',
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_company_template (company_id, template_type)
) ENGINE=InnoDB COMMENT='Notification message templates';

-- ============================================================================
-- INSERT DEFAULT DATA
-- ============================================================================

-- Insert default tax types for Tanzania
INSERT INTO tax_types (company_id, tax_code, tax_name, tax_rate, applies_to, tax_authority) VALUES
(1, 'VAT', 'Value Added Tax', 18.00, 'sales', 'Tanzania Revenue Authority (TRA)'),
(1, 'WHT', 'Withholding Tax', 5.00, 'services', 'Tanzania Revenue Authority (TRA)'),
(1, 'PAYE', 'Pay As You Earn', 0.00, 'payroll', 'Tanzania Revenue Authority (TRA)');

-- Insert default service types
INSERT INTO service_types (company_id, service_code, service_name, service_category, base_price, price_unit, estimated_duration_days) VALUES
(1, 'SRV001', 'Land Evaluation/Valuation', 'land_evaluation', 500000.00, 'per plot', 7),
(1, 'SRV002', 'Title Deed Processing', 'title_processing', 1000000.00, 'per plot', 30),
(1, 'SRV003', 'Land Consultation Services', 'consultation', 300000.00, 'per hour', 1),
(1, 'SRV004', 'Land Survey Services', 'survey', 800000.00, 'per plot', 14),
(1, 'SRV005', 'Construction Management', 'construction', 0.00, 'per sqm', 90);

-- Insert default approval workflow for payments
INSERT INTO approval_workflows (company_id, workflow_name, workflow_code, module_name, applies_to, auto_approve_below) VALUES
(1, 'Payment Approval - Standard', 'PAY_STD', 'finance', 'payment', 100000.00);

-- Get the workflow_id
SET @workflow_id = LAST_INSERT_ID();

-- Insert approval levels
INSERT INTO approval_levels (workflow_id, level_number, level_name, approver_type, role_id, is_required) VALUES
(@workflow_id, 1, 'Accountant Review', 'role', (SELECT role_id FROM system_roles WHERE role_code = 'ACCOUNTANT'), TRUE),
(@workflow_id, 2, 'Manager Approval', 'role', (SELECT role_id FROM system_roles WHERE role_code = 'MANAGER'), TRUE);

-- ============================================================================
-- END OF EXPANDED DATABASE SCHEMA
-- Total Tables: 70+ tables
-- ============================================================================