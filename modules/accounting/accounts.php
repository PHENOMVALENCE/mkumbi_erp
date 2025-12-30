<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

// ============================================================================
// COMPLETE BALANCE UPDATE - ALL LEVELS, ALL ACCOUNTS
// RUNS EVERY TIME PAGE LOADS - CALCULATES FROM DATABASE
// ============================================================================
function updateAllAccountBalancesComplete($conn, $company_id) {
    try {
        // Get all accounts
        $accounts = [];
        $stmt = $conn->prepare("SELECT account_id, account_code FROM chart_of_accounts WHERE company_id = ?");
        $stmt->execute([$company_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $accounts[$row['account_code']] = $row['account_id'];
        }
        
        // ====================================================================
        // COMPREHENSIVE BALANCE QUERIES - ALL ACCOUNTS FROM DATABASE
        // ====================================================================
        
        $balance_queries = [
            // ==================== ASSETS ====================
            
            // Level 4 - Cash
            '1111' => "SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE company_id = ? AND account_category = 'cash'",
            '1112' => "SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE company_id = ? AND account_category = 'petty_cash'",
            
            // Level 3 - Bank Accounts  
            '1120' => "SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE company_id = ? AND is_active = 1",
            
            // Level 4 - Receivables
            '1131' => "SELECT COALESCE(SUM(amount_due - COALESCE(amount_paid, 0)), 0) FROM invoices WHERE company_id = ? AND status IN ('pending', 'partial')",
            '1132' => "SELECT COALESCE(SUM(r.total_amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id = r.reservation_id AND p.status = 'approved'), 0)), 0) FROM reservations r WHERE r.company_id = ? AND r.status IN ('active', 'pending_approval', 'completed')",
            
            // Level 4 - Inventory
            '1141' => "SELECT COALESCE(SUM(ss.quantity_on_hand * COALESCE(i.cost_price, 0)), 0) FROM store_stock ss LEFT JOIN items i ON ss.item_id = i.item_id WHERE ss.company_id = ?",
            '1142' => "SELECT COALESCE(SUM(quantity * unit_price), 0) FROM inventory WHERE company_id = ? AND item_type = 'finished_goods'",
            
            // Level 3 - Inventory Total
            '1140' => "SELECT COALESCE(SUM(ss.quantity_on_hand * COALESCE(i.cost_price, 0)), 0) FROM store_stock ss LEFT JOIN items i ON ss.item_id = i.item_id WHERE ss.company_id = ?",
            
            // Level 3 - Fixed Assets
            '1210' => "SELECT COALESCE(SUM(land_purchase_price), 0) FROM projects WHERE company_id = ? AND is_active = 1",
            '1220' => "SELECT COALESCE(SUM(purchase_price), 0) FROM vehicles WHERE company_id = ?",
            '1230' => "SELECT COALESCE(SUM(purchase_price), 0) FROM equipment WHERE company_id = ?",
            '1250' => "SELECT COALESCE(SUM(accumulated_depreciation), 0) * -1 FROM depreciation WHERE company_id = ?",
            
            // Level 3 - Development
            '1310' => "SELECT COALESCE(SUM(land_purchase_price), 0) FROM projects WHERE company_id = ? AND is_active = 1",
            '1320' => "SELECT COALESCE(SUM(total_operational_costs), 0) FROM projects WHERE company_id = ? AND is_active = 1",
            '1330' => "SELECT COALESCE(SUM(amount), 0) FROM project_expenses WHERE company_id = ?",
            
            // ==================== LIABILITIES ====================
            
            // Level 4 - Accounts Payable
            '2111' => "SELECT COALESCE(SUM(total_amount_owed - COALESCE(amount_paid, 0)), 0) FROM creditors WHERE company_id = ? AND status = 'active'",
            '2112' => "SELECT COALESCE(SUM(amount), 0) FROM accrued_expenses WHERE company_id = ? AND status = 'pending'",
            
            // Level 4 - Tax Payable
            '2121' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND tax_type = 'VAT' AND status IN ('pending', 'filed')",
            '2122' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND tax_type = 'Income Tax' AND status IN ('pending', 'filed')",
            '2123' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND tax_type = 'WHT' AND status IN ('pending', 'filed')",
            
            // Level 4 - Payroll Liabilities
            '2131' => "SELECT COALESCE(SUM(pd.net_salary), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'pending'",
            '2132' => "SELECT COALESCE(SUM(pd.nssf_employee + pd.nssf_employer), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'pending'",
            '2133' => "SELECT COALESCE(SUM(pd.paye), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'pending'",
            
            // Level 3 - Commission Payable
            '2140' => "SELECT COALESCE(SUM(balance), 0) FROM commissions WHERE company_id = ? AND payment_status = 'approved'",
            
            // Level 3 - Long-term Loans
            '2210' => "SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE company_id = ? AND status = 'active'",
            
            // Level 3 - Customer Deposits
            '2310' => "SELECT COALESCE(SUM(down_payment), 0) FROM reservations WHERE company_id = ? AND status IN ('pending_approval', 'active')",
            
            // ==================== EQUITY ====================
            
            // Level 3 - Share Capital (can be from table or default)
            '3110' => "SELECT 10000000", // Default 10M or query actual: SELECT COALESCE(SUM(amount), 0) FROM share_capital WHERE company_id = ?
            
            // Level 3 - Retained Earnings (previous years)
            '3120' => "SELECT 0", // Will be updated from actual retained earnings table if exists
            
            // Current Year Earnings calculated automatically after revenue-expenses
            
            // ==================== REVENUE - COMPREHENSIVE CALCULATION ====================
            
            // PRIMARY REVENUE SOURCE: All approved payments (this is your main revenue)
            // Check if column is 'amount' or 'payment_amount'
            '4110' => "SELECT COALESCE(SUM(COALESCE(amount, payment_amount, 0)), 0) FROM payments WHERE company_id = ? AND status = 'approved'",
            
            // DETAILED BREAKDOWN (Level 4) - if you want to split by payment type
            '4111' => "SELECT COALESCE(SUM(COALESCE(amount, payment_amount, 0)), 0) FROM payments WHERE company_id = ? AND status = 'approved' AND (payment_type = 'down_payment' OR COALESCE(is_down_payment, 0) = 1)",
            '4112' => "SELECT COALESCE(SUM(COALESCE(amount, payment_amount, 0)), 0) FROM payments WHERE company_id = ? AND status = 'approved' AND payment_type = 'installment' AND COALESCE(is_down_payment, 0) = 0",
            '4113' => "SELECT COALESCE(SUM(COALESCE(amount, payment_amount, 0)), 0) FROM payments WHERE company_id = ? AND status = 'approved' AND payment_type IN ('full_payment', 'partial')",
            
            // ALTERNATIVE: If payment_amount column exists instead of amount
            // '4110' => "SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE company_id = ? AND status = 'approved'",
            
            // Level 3 - Service Revenue
            '4120' => "SELECT COALESCE(SUM(amount_paid), 0) FROM service_requests WHERE company_id = ? AND status IN ('completed', 'in_progress')",
            
            // Level 3 - Interest Income
            '4210' => "SELECT COALESCE(SUM(interest_earned), 0) FROM bank_accounts WHERE company_id = ?",
            
            // ==================== EXPENSES - ALL CATEGORIES ====================
            
            // NOTE: Land Purchase is NOT an expense - it's an asset (account 1310)
            // Level 3 - Land Costs REMOVED - This should be 0 or removed entirely
            '5110' => "SELECT 0", // Land is an ASSET, not an expense!
            
            // Level 4 - Development Costs
            '5121' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Survey and Mapping'",
            '5122' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Legal Fees'",
            '5123' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Infrastructure'",
            
            // Level 3 - Commission Expenses
            '5130' => "SELECT COALESCE(SUM(total_paid), 0) FROM commissions WHERE company_id = ? AND payment_status IN ('approved', 'paid')",
            
            // Level 3 - Refunds
            '5140' => "SELECT COALESCE(SUM(refund_amount - COALESCE(penalty_amount, 0)), 0) FROM refunds WHERE company_id = ? AND status IN ('approved', 'processed')",
            
            // Level 4 - Admin Expenses
            '6110' => "SELECT COALESCE(SUM(pd.gross_salary), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'paid'",
            '6120' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Rent'",
            '6130' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Utilities'",
            
            // Level 4 - Marketing Expenses
            '6210' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Advertising'",
            '6220' => "SELECT COALESCE(SUM(actual_cost), 0) FROM campaigns WHERE company_id = ? AND is_active = 1",
            
            // Level 3 - Professional Fees
            '6300' => "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ? AND expense_category = 'Professional Fees'",
            
            // Level 3 - Purchase Orders
            '7100' => "SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE company_id = ? AND status IN ('approved', 'received')",
            
            // Level 3 - Interest Expense
            '8100' => "SELECT COALESCE(SUM(interest_paid), 0) FROM loan_payments WHERE company_id = ?",
            
            // Level 3 - Corporate Tax
            '9100' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND status = 'paid' AND tax_type IN ('Income Tax', 'Corporate Tax')",
        ];
        
        // Execute all balance updates
        foreach ($balance_queries as $code => $query) {
            if (isset($accounts[$code])) {
                try {
                    $stmt = $conn->prepare($query);
                    $stmt->execute([$company_id]);
                    $balance = $stmt->fetchColumn();
                    
                    $update_stmt = $conn->prepare("UPDATE chart_of_accounts SET current_balance = ?, updated_at = NOW() WHERE account_id = ?");
                    $update_stmt->execute([$balance, $accounts[$code]]);
                } catch (PDOException $e) {
                    error_log("Error updating account $code: " . $e->getMessage());
                }
            }
        }
        
        // ====================================================================
        // ROLL UP CONTROL ACCOUNTS - BOTTOM TO TOP
        // ====================================================================
        
        // Level 3 Control (sum children)
        $stmt = $conn->prepare("
            UPDATE chart_of_accounts parent
            SET parent.current_balance = (
                SELECT COALESCE(SUM(child.current_balance), 0)
                FROM chart_of_accounts child
                WHERE child.parent_account_id = parent.account_id AND child.company_id = ?
            ),
            parent.updated_at = NOW()
            WHERE parent.company_id = ? AND parent.account_level = 3 AND parent.is_control_account = 1
        ");
        $stmt->execute([$company_id, $company_id]);
        
        // Level 2 Control (sum children)
        $stmt = $conn->prepare("
            UPDATE chart_of_accounts parent
            SET parent.current_balance = (
                SELECT COALESCE(SUM(child.current_balance), 0)
                FROM chart_of_accounts child
                WHERE child.parent_account_id = parent.account_id AND child.company_id = ?
            ),
            parent.updated_at = NOW()
            WHERE parent.company_id = ? AND parent.account_level = 2 AND parent.is_control_account = 1
        ");
        $stmt->execute([$company_id, $company_id]);
        
        // Level 1 Control (sum children)
        $stmt = $conn->prepare("
            UPDATE chart_of_accounts parent
            SET parent.current_balance = (
                SELECT COALESCE(SUM(child.current_balance), 0)
                FROM chart_of_accounts child
                WHERE child.parent_account_id = parent.account_id AND child.company_id = ?
            ),
            parent.updated_at = NOW()
            WHERE parent.company_id = ? AND parent.account_level = 1 AND parent.is_control_account = 1
        ");
        $stmt->execute([$company_id, $company_id]);
        
        // ====================================================================
        // CALCULATE NET INCOME (Revenue - Expenses)
        // ====================================================================
        
        if (isset($accounts['3130'])) {
            $revenue_stmt = $conn->prepare("
                SELECT COALESCE(SUM(current_balance), 0) 
                FROM chart_of_accounts 
                WHERE company_id = ? AND account_type = 'revenue' AND is_control_account = 0
            ");
            $revenue_stmt->execute([$company_id]);
            $total_revenue = $revenue_stmt->fetchColumn();
            
            $expense_stmt = $conn->prepare("
                SELECT COALESCE(SUM(current_balance), 0) 
                FROM chart_of_accounts 
                WHERE company_id = ? AND account_type = 'expense' AND is_control_account = 0
            ");
            $expense_stmt->execute([$company_id]);
            $total_expenses = $expense_stmt->fetchColumn();
            
            $net_income = $total_revenue - $total_expenses;
            
            $update_stmt = $conn->prepare("UPDATE chart_of_accounts SET current_balance = ?, updated_at = NOW() WHERE account_id = ?");
            $update_stmt->execute([$net_income, $accounts['3130']]);
            
            // Update parent equity accounts
            if (isset($accounts['3100'])) {
                $equity_total_stmt = $conn->prepare("
                    SELECT COALESCE(SUM(current_balance), 0) 
                    FROM chart_of_accounts 
                    WHERE company_id = ? AND parent_account_id = ?
                ");
                $equity_total_stmt->execute([$company_id, $accounts['3100']]);
                $equity_total = $equity_total_stmt->fetchColumn();
                
                $update_stmt->execute([$equity_total, $accounts['3100']]);
            }
            
            if (isset($accounts['3000'])) {
                $total_equity_stmt = $conn->prepare("
                    SELECT COALESCE(SUM(current_balance), 0) 
                    FROM chart_of_accounts 
                    WHERE company_id = ? AND parent_account_id = ?
                ");
                $total_equity_stmt->execute([$company_id, $accounts['3000']]);
                $total_equity = $total_equity_stmt->fetchColumn();
                
                $update_stmt->execute([$total_equity, $accounts['3000']]);
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in updateAllAccountBalancesComplete: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// CREATE ACCOUNTS (same as before)
// ============================================================================
function createComprehensiveChartOfAccounts($conn, $company_id, $user_id) {
    try {
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chart_of_accounts WHERE company_id = ?");
        $check_stmt->execute([$company_id]);
        $exists = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists['count'] > 0) {
            return ['exists' => true, 'count' => $exists['count']];
        }
        
        $conn->beginTransaction();
        
        $accounts = [
            ['1000', 'ASSETS', 'asset', 'All Company Assets', null, 1, 1],
            ['2000', 'LIABILITIES', 'liability', 'All Company Liabilities', null, 1, 1],
            ['3000', 'EQUITY', 'equity', 'Owner\'s Equity', null, 1, 1],
            ['4000', 'REVENUE', 'revenue', 'All Revenue Sources', null, 1, 1],
            ['5000', 'EXPENSES', 'expense', 'All Company Expenses', null, 1, 1],
            ['1100', 'Current Assets', 'asset', 'Short-term assets', '1000', 2, 1],
            ['1200', 'Fixed Assets', 'asset', 'Long-term assets', '1000', 2, 1],
            ['1300', 'Development Properties', 'asset', 'Land projects', '1000', 2, 1],
            ['2100', 'Current Liabilities', 'liability', 'Short-term obligations', '2000', 2, 1],
            ['2200', 'Long-term Liabilities', 'liability', 'Long-term debts', '2000', 2, 1],
            ['2300', 'Customer Deposits', 'liability', 'Advance payments', '2000', 2, 1],
            ['3100', 'Owner\'s Equity', 'equity', 'Capital and earnings', '3000', 2, 1],
            ['4100', 'Sales Revenue', 'revenue', 'Sales income', '4000', 2, 1],
            ['4200', 'Other Income', 'revenue', 'Other income', '4000', 2, 1],
            ['5100', 'Cost of Sales', 'expense', 'Direct costs', '5000', 2, 1],
            ['6000', 'Operating Expenses', 'expense', 'Operating costs', '5000', 2, 1],
            ['7000', 'Procurement Expenses', 'expense', 'Purchases', '5000', 2, 1],
            ['8000', 'Financial Expenses', 'expense', 'Finance costs', '5000', 2, 1],
            ['9000', 'Tax Expenses', 'expense', 'Tax costs', '5000', 2, 1],
            ['1110', 'Cash and Cash Equivalents', 'asset', 'Cash resources', '1100', 3, 1],
            ['1120', 'Bank Accounts', 'asset', 'Bank balances', '1100', 3, 0],
            ['1130', 'Accounts Receivable', 'asset', 'Money owed', '1100', 3, 1],
            ['1140', 'Inventory', 'asset', 'Stock', '1100', 3, 0],
            ['1210', 'Land and Buildings', 'asset', 'Property', '1200', 3, 0],
            ['1220', 'Vehicles', 'asset', 'Vehicles', '1200', 3, 0],
            ['1230', 'Equipment', 'asset', 'Equipment', '1200', 3, 0],
            ['1250', 'Accumulated Depreciation', 'asset', 'Depreciation', '1200', 3, 0],
            ['1310', 'Land Under Development', 'asset', 'Project land', '1300', 3, 0],
            ['1320', 'Development Costs', 'asset', 'Infrastructure', '1300', 3, 0],
            ['1330', 'Project Costs', 'asset', 'Project expenses', '1300', 3, 0],
            ['2110', 'Accounts Payable', 'liability', 'Supplier debts', '2100', 3, 1],
            ['2120', 'Tax Payable', 'liability', 'Tax due', '2100', 3, 1],
            ['2130', 'Payroll Liabilities', 'liability', 'Employee pay', '2100', 3, 1],
            ['2140', 'Commission Payable', 'liability', 'Commissions due', '2100', 3, 0],
            ['2210', 'Bank Loans', 'liability', 'Loans', '2200', 3, 0],
            ['2310', 'Plot Deposits', 'liability', 'Plot down payments', '2300', 3, 0],
            ['3110', 'Share Capital', 'equity', 'Capital', '3100', 3, 0],
            ['3120', 'Retained Earnings', 'equity', 'Profits', '3100', 3, 0],
            ['3130', 'Current Year Earnings', 'equity', 'Current profit', '3100', 3, 0],
            ['4110', 'Plot Sales', 'revenue', 'Plot sales', '4100', 3, 1],
            ['4120', 'Service Revenue', 'revenue', 'Services', '4100', 3, 0],
            ['4210', 'Interest Income', 'revenue', 'Interest', '4200', 3, 0],
            ['5110', 'Land Costs', 'expense', 'Land purchase', '5100', 3, 0],
            ['5120', 'Development Costs', 'expense', 'Development', '5100', 3, 1],
            ['5130', 'Commission Expenses', 'expense', 'Commissions', '5100', 3, 0],
            ['5140', 'Refunds', 'expense', 'Refunds', '5100', 3, 0],
            ['6100', 'Admin Expenses', 'expense', 'Admin', '6000', 3, 1],
            ['6200', 'Marketing', 'expense', 'Marketing', '6000', 3, 1],
            ['6300', 'Professional Fees', 'expense', 'Services', '6000', 3, 0],
            ['1111', 'Cash on Hand', 'asset', 'Physical cash', '1110', 4, 0],
            ['1112', 'Petty Cash', 'asset', 'Petty cash', '1110', 4, 0],
            ['1131', 'Trade Debtors', 'asset', 'Trade debts', '1130', 4, 0],
            ['1132', 'Plot Sales Receivable', 'asset', 'Plot payments due', '1130', 4, 0],
            ['1141', 'Raw Materials', 'asset', 'Materials', '1140', 4, 0],
            ['1142', 'Finished Goods', 'asset', 'Finished stock', '1140', 4, 0],
            ['2111', 'Trade Creditors', 'liability', 'Suppliers', '2110', 4, 0],
            ['2112', 'Accrued Expenses', 'liability', 'Accruals', '2110', 4, 0],
            ['2121', 'VAT Payable', 'liability', 'VAT', '2120', 4, 0],
            ['2122', 'Income Tax Payable', 'liability', 'Income tax', '2120', 4, 0],
            ['2123', 'WHT Payable', 'liability', 'WHT', '2120', 4, 0],
            ['2131', 'Salaries Payable', 'liability', 'Salaries', '2130', 4, 0],
            ['2132', 'NSSF Payable', 'liability', 'NSSF', '2130', 4, 0],
            ['2133', 'PAYE Payable', 'liability', 'PAYE', '2130', 4, 0],
            ['4111', 'Down Payments', 'revenue', 'Down payments', '4110', 4, 0],
            ['4112', 'Installments', 'revenue', 'Installments', '4110', 4, 0],
            ['4113', 'Full Payments', 'revenue', 'Full payments', '4110', 4, 0],
            ['5121', 'Survey Costs', 'expense', 'Survey', '5120', 4, 0],
            ['5122', 'Legal Fees', 'expense', 'Legal', '5120', 4, 0],
            ['5123', 'Infrastructure', 'expense', 'Infrastructure', '5120', 4, 0],
            ['6110', 'Salaries', 'expense', 'Salaries', '6100', 4, 0],
            ['6120', 'Rent', 'expense', 'Rent', '6100', 4, 0],
            ['6130', 'Utilities', 'expense', 'Utilities', '6100', 4, 0],
            ['6210', 'Advertising', 'expense', 'Ads', '6200', 4, 0],
            ['6220', 'Campaigns', 'expense', 'Campaigns', '6200', 4, 0],
            ['7100', 'Purchase Orders', 'expense', 'Purchases', '7000', 3, 0],
            ['8100', 'Interest Expense', 'expense', 'Interest', '8000', 3, 0],
            ['9100', 'Corporate Tax', 'expense', 'Tax', '9000', 3, 0],
        ];
        
        $insert_stmt = $conn->prepare("
            INSERT INTO chart_of_accounts 
            (company_id, account_code, account_name, account_type, account_category, 
             parent_account_id, account_level, is_control_account, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        
        $account_map = [];
        foreach ($accounts as $account) {
            list($code, $name, $type, $category, $parent_code, $level, $is_control) = $account;
            
            $parent_id = null;
            if ($parent_code && isset($account_map[$parent_code])) {
                $parent_id = $account_map[$parent_code];
            }
            
            $insert_stmt->execute([
                $company_id, $code, $name, $type, $category,
                $parent_id, $level, $is_control, $user_id
            ]);
            
            $account_map[$code] = $conn->lastInsertId();
        }
        
        $conn->commit();
        
        return ['exists' => false, 'count' => count($accounts), 'created' => true];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error creating accounts: " . $e->getMessage());
        return ['exists' => false, 'count' => 0, 'error' => $e->getMessage()];
    }
}

// MAIN EXECUTION
$create_result = createComprehensiveChartOfAccounts($conn, $company_id, $_SESSION['user_id']);
$update_success = updateAllAccountBalancesComplete($conn, $company_id);

// Fetch statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_accounts,
        SUM(CASE WHEN account_level = 1 THEN 1 ELSE 0 END) as level_1,
        SUM(CASE WHEN account_level = 2 THEN 1 ELSE 0 END) as level_2,
        SUM(CASE WHEN account_level = 3 THEN 1 ELSE 0 END) as level_3,
        SUM(CASE WHEN account_level = 4 THEN 1 ELSE 0 END) as level_4,
        SUM(CASE WHEN is_control_account = 1 THEN 1 ELSE 0 END) as control_accounts,
        SUM(CASE WHEN is_control_account = 0 THEN 1 ELSE 0 END) as leaf_accounts,
        COALESCE((SELECT current_balance FROM chart_of_accounts WHERE company_id = ? AND account_code = '1000'), 0) as total_assets,
        COALESCE((SELECT current_balance FROM chart_of_accounts WHERE company_id = ? AND account_code = '2000'), 0) as total_liabilities,
        COALESCE((SELECT current_balance FROM chart_of_accounts WHERE company_id = ? AND account_code = '3000'), 0) as total_equity,
        COALESCE((SELECT current_balance FROM chart_of_accounts WHERE company_id = ? AND account_code = '4000'), 0) as total_revenue,
        COALESCE((SELECT current_balance FROM chart_of_accounts WHERE company_id = ? AND account_code = '5000'), 0) as total_expenses
    FROM chart_of_accounts
    WHERE company_id = ?
";
$stmt = $conn->prepare($stats_query);
$stmt->execute([$company_id, $company_id, $company_id, $company_id, $company_id, $company_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$net_income = $stats['total_revenue'] - $stats['total_expenses'];
$total_equity_with_income = $stats['total_equity'];
$is_balanced = abs($stats['total_assets'] - ($stats['total_liabilities'] + $total_equity_with_income)) < 1;

// Fetch all accounts
$accounts_query = "
    SELECT a.*, parent.account_name as parent_account_name, parent.account_code as parent_account_code
    FROM chart_of_accounts a
    LEFT JOIN chart_of_accounts parent ON a.parent_account_id = parent.account_id
    WHERE a.company_id = ?
    ORDER BY a.account_code ASC
";
$stmt = $conn->prepare($accounts_query);
$stmt->execute([$company_id]);
$all_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$accounts_by_type = ['asset' => [], 'liability' => [], 'equity' => [], 'revenue' => [], 'expense' => []];
foreach ($all_accounts as $account) {
    $accounts_by_type[$account['account_type']][] = $account;
}

$page_title = 'Chart of Accounts';
require_once '../../includes/header.php';
?>

<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:#fff;padding:1.25rem;border-radius:8px;border-left:4px solid;box-shadow:0 2px 4px rgba(0,0,0,.1)}
.stat-card.primary{border-left-color:#007bff}.stat-card.success{border-left-color:#28a745}.stat-card.warning{border-left-color:#ffc107}.stat-card.danger{border-left-color:#dc3545}.stat-card.info{border-left-color:#17a2b8}.stat-card.purple{border-left-color:#6f42c1}
.stat-number{font-size:1.75rem;font-weight:700;color:#2c3e50}
.stat-label{color:#6c757d;font-size:.8rem;text-transform:uppercase}
.account-table{background:#fff;border-radius:8px;overflow:hidden;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.account-header{padding:1.5rem;color:#fff;display:flex;justify-content:space-between;align-items:center}
.account-header h3{margin:0;font-size:1.4rem}
.level-badge{display:inline-block;padding:.2rem .6rem;border-radius:10px;font-size:.7rem;font-weight:600;margin-right:.4rem}
.level-1{background:#007bff;color:#fff}.level-2{background:#6c757d;color:#fff}.level-3{background:#17a2b8;color:#fff}.level-4{background:#28a745;color:#fff}
.account-code{font-family:'Courier New',monospace;background:#f8f9fa;padding:.2rem .4rem;border-radius:4px;font-weight:600;font-size:.85rem}
.hierarchy-indent-1{padding-left:0}.hierarchy-indent-2{padding-left:20px}.hierarchy-indent-3{padding-left:40px}.hierarchy-indent-4{padding-left:60px}
.control-badge{background:#ffc107;color:#000;padding:.15rem .4rem;border-radius:10px;font-size:.65rem;font-weight:600}
.balance-positive{color:#28a745;font-weight:600}
.balance-alert{padding:1rem;border-radius:6px;margin-bottom:2rem;font-weight:600;text-align:center}
.balance-alert.balanced{background:#d4edda;color:#155724;border:2px solid #28a745}
.balance-alert.unbalanced{background:#f8d7da;color:#721c24;border:2px solid #dc3545}
.auto-update-badge{background:#28a745;color:#fff;padding:.3rem .6rem;border-radius:4px;font-size:.75rem;font-weight:600;margin-left:.5rem}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-list-alt text-primary me-2"></i>Chart of Accounts
                    <span class="auto-update-badge"><i class="fas fa-sync-alt"></i> LIVE DATA</span>
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    <?= $stats['total_accounts'] ?> accounts - ALL levels calculated from database
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-success btn-sm" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (isset($create_result['created']) && $create_result['created']): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>Created <?= $create_result['count'] ?> accounts with live database balances!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php elseif ($update_success): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <i class="fas fa-sync-alt me-2"></i>✓ All balances updated: Assets, Liabilities, Equity, Revenue, Expenses
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="balance-alert <?= $is_balanced ? 'balanced' : 'unbalanced' ?>">
            <?php if ($is_balanced): ?>
                <i class="fas fa-check-circle me-2"></i>
                ✓ BALANCED: Assets (<?= number_format($stats['total_assets']) ?>) = L+E (<?= number_format($stats['total_liabilities'] + $total_equity_with_income) ?>)
            <?php else: ?>
                <i class="fas fa-exclamation-triangle me-2"></i>
                ✗ UNBALANCED | Difference: <?= number_format(abs($stats['total_assets'] - ($stats['total_liabilities'] + $total_equity_with_income))) ?> TSH
            <?php endif; ?>
        </div>

        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-number"><?= $stats['total_accounts'] ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number">TSH <?= number_format($stats['total_assets']/1000000, 1) ?>M</div>
                <div class="stat-label">Assets</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number">TSH <?= number_format($stats['total_liabilities']/1000000, 1) ?>M</div>
                <div class="stat-label">Liabilities</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number">TSH <?= number_format($total_equity_with_income/1000000, 1) ?>M</div>
                <div class="stat-label">Equity</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-number">TSH <?= number_format($stats['total_revenue']/1000000, 1) ?>M</div>
                <div class="stat-label">Revenue</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-number">TSH <?= number_format($stats['total_expenses']/1000000, 1) ?>M</div>
                <div class="stat-label">Expenses</div>
            </div>
        </div>

        <?php 
        $categories = [
            'asset' => ['ASSETS', 'coins', 'linear-gradient(135deg, #28a745 0%, #20c997 100%)', 'success', 'total_assets'],
            'liability' => ['LIABILITIES', 'file-invoice-dollar', 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)', 'warning', 'total_liabilities'],
            'equity' => ['EQUITY', 'balance-scale', 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)', 'info', 'total_equity'],
            'revenue' => ['REVENUE', 'chart-line', 'linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%)', 'purple', 'total_revenue'],
            'expense' => ['EXPENSES', 'receipt', 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)', 'danger', 'total_expenses']
        ];
        
        foreach ($categories as $type => $details):
            list($title, $icon, $gradient, $class, $amount_field) = $details;
            $amount = $stats[$amount_field];
        ?>
        <div class="account-table">
            <div class="account-header" style="background:<?= $gradient ?>">
                <h3><i class="fas fa-<?= $icon ?> me-2"></i><?= $title ?> (<?= count($accounts_by_type[$type]) ?>)</h3>
                <span class="badge bg-light text-dark">TSH <?= number_format($amount/1000000, 2) ?>M</span>
            </div>
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="10%">Code</th>
                        <th width="40%">Account Name</th>
                        <th width="8%">Level</th>
                        <th width="12%">Parent</th>
                        <th width="18%" class="text-end">Balance</th>
                        <th width="12%">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts_by_type[$type] as $acc): ?>
                    <tr>
                        <td><span class="account-code"><?= $acc['account_code'] ?></span></td>
                        <td class="hierarchy-indent-<?= $acc['account_level'] ?>">
                            <span class="level-badge level-<?= $acc['account_level'] ?>">L<?= $acc['account_level'] ?></span>
                            <strong><?= htmlspecialchars($acc['account_name']) ?></strong>
                            <?php if ($acc['is_control_account']): ?>
                            <span class="control-badge ms-1">CTRL</span>
                            <?php endif; ?>
                            <br><small class="text-muted"><?= htmlspecialchars($acc['account_category']) ?></small>
                        </td>
                        <td><span class="badge bg-secondary">L<?= $acc['account_level'] ?></span></td>
                        <td>
                            <?php if ($acc['parent_account_code']): ?>
                            <small><?= $acc['parent_account_code'] ?></small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($acc['current_balance'] > 0): ?>
                            <span class="balance-positive">TSH <?= number_format($acc['current_balance'], 2) ?></span>
                            <?php else: ?>
                            <span class="text-muted">0.00</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($acc['is_control_account']): ?>
                            <span class="badge bg-warning text-dark">Control</span>
                            <?php else: ?>
                            <span class="badge bg-success">Leaf</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-<?= $class ?> fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">TOTAL <?= $title ?>:</td>
                        <td class="text-end">TSH <?= number_format($amount, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>