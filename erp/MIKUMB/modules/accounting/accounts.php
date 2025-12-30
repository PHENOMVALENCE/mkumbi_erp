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

// Comprehensive Chart of Accounts Creation
function createComprehensiveChartOfAccounts($conn, $company_id, $user_id) {
    try {
        // Check if accounts already exist
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM chart_of_accounts WHERE company_id = ?");
        $check_stmt->execute([$company_id]);
        $exists = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists['count'] > 0) {
            updateAccountBalances($conn, $company_id);
            return ['success' => true, 'message' => 'Chart of Accounts already exists. Balances updated.', 'count' => $exists['count']];
        }
        
        $conn->beginTransaction();
        
        // Complete Chart of Accounts - 90+ Accounts
        $accounts = [
            // ============================================================
            // LEVEL 1: MAIN CATEGORIES (5 accounts)
            // ============================================================
            ['1000', 'ASSETS', 'asset', 'All Company Assets', null, 1, 1],
            ['2000', 'LIABILITIES', 'liability', 'All Company Liabilities', null, 1, 1],
            ['3000', 'EQUITY', 'equity', 'Owner\'s Equity', null, 1, 1],
            ['4000', 'REVENUE', 'revenue', 'All Revenue Sources', null, 1, 1],
            ['5000', 'EXPENSES', 'expense', 'All Company Expenses', null, 1, 1],
            
            // ============================================================
            // LEVEL 2: MAJOR ACCOUNT GROUPS (15 accounts)
            // ============================================================
            
            // ASSETS - Level 2
            ['1100', 'Current Assets', 'asset', 'Short-term assets', '1000', 2, 1],
            ['1200', 'Fixed Assets', 'asset', 'Long-term tangible assets', '1000', 2, 1],
            ['1300', 'Development Properties', 'asset', 'Land development projects', '1000', 2, 1],
            
            // LIABILITIES - Level 2
            ['2100', 'Current Liabilities', 'liability', 'Short-term obligations', '2000', 2, 1],
            ['2200', 'Long-term Liabilities', 'liability', 'Long-term debts', '2000', 2, 1],
            ['2300', 'Customer Deposits', 'liability', 'Advance customer payments', '2000', 2, 1],
            
            // EQUITY - Level 2
            ['3100', 'Owner\'s Equity', 'equity', 'Owner capital and earnings', '3000', 2, 1],
            
            // REVENUE - Level 2
            ['4100', 'Sales Revenue', 'revenue', 'Primary revenue from sales', '4000', 2, 1],
            ['4200', 'Other Income', 'revenue', 'Non-operating income', '4000', 2, 1],
            
            // EXPENSES - Level 2
            ['5100', 'Cost of Sales', 'expense', 'Direct costs of goods sold', '5000', 2, 1],
            ['6000', 'Operating Expenses', 'expense', 'Day-to-day operating costs', '5000', 2, 1],
            ['7000', 'Procurement Expenses', 'expense', 'Purchase-related costs', '5000', 2, 1],
            ['8000', 'Financial Expenses', 'expense', 'Interest and finance costs', '5000', 2, 1],
            ['9000', 'Tax Expenses', 'expense', 'Tax obligations', '5000', 2, 1],
            ['9500', 'Other Expenses', 'expense', 'Miscellaneous expenses', '5000', 2, 1],
            
            // ============================================================
            // LEVEL 3: SUB-GROUPS (35 accounts)
            // ============================================================
            
            // CURRENT ASSETS - Level 3
            ['1110', 'Cash and Cash Equivalents', 'asset', 'Liquid cash resources', '1100', 3, 1],
            ['1120', 'Bank Accounts', 'asset', 'Funds in bank accounts', '1100', 3, 1],
            ['1130', 'Accounts Receivable', 'asset', 'Money owed to company', '1100', 3, 1],
            ['1140', 'Inventory', 'asset', 'Stock and materials', '1100', 3, 1],
            ['1150', 'Prepaid Expenses', 'asset', 'Advance payments', '1100', 3, 0],
            ['1160', 'Tax Receivable', 'asset', 'Tax refunds due', '1100', 3, 0],
            
            // FIXED ASSETS - Level 3
            ['1210', 'Land and Buildings', 'asset', 'Property owned', '1200', 3, 0],
            ['1220', 'Vehicles', 'asset', 'Company vehicles', '1200', 3, 0],
            ['1230', 'Furniture and Fixtures', 'asset', 'Office furniture', '1200', 3, 0],
            ['1240', 'Computer Equipment', 'asset', 'IT hardware', '1200', 3, 0],
            ['1250', 'Accumulated Depreciation', 'asset', 'Asset depreciation', '1200', 3, 0],
            
            // DEVELOPMENT PROPERTIES - Level 3
            ['1310', 'Land Under Development', 'asset', 'Project land holdings', '1300', 3, 0],
            ['1320', 'Development Costs', 'asset', 'Infrastructure costs', '1300', 3, 0],
            ['1330', 'Project Operational Costs', 'asset', 'Ongoing project expenses', '1300', 3, 0],
            
            // CURRENT LIABILITIES - Level 3
            ['2110', 'Accounts Payable', 'liability', 'Supplier payments due', '2100', 3, 1],
            ['2120', 'Tax Payable', 'liability', 'Tax obligations', '2100', 3, 1],
            ['2130', 'Payroll Liabilities', 'liability', 'Employee payment obligations', '2100', 3, 1],
            ['2140', 'Commission Payable', 'liability', 'Unpaid commissions', '2100', 3, 0],
            
            // LONG-TERM LIABILITIES - Level 3
            ['2210', 'Bank Loans', 'liability', 'Long-term bank financing', '2200', 3, 0],
            ['2220', 'Mortgages Payable', 'liability', 'Property mortgages', '2200', 3, 0],
            
            // CUSTOMER DEPOSITS - Level 3
            ['2310', 'Plot Reservation Deposits', 'liability', 'Plot down payments', '2300', 3, 0],
            ['2320', 'Service Deposits', 'liability', 'Service advance payments', '2300', 3, 0],
            
            // OWNER'S EQUITY - Level 3
            ['3110', 'Share Capital', 'equity', 'Invested capital', '3100', 3, 0],
            ['3120', 'Retained Earnings', 'equity', 'Accumulated profits', '3100', 3, 0],
            ['3130', 'Current Year Earnings', 'equity', 'Current period profit/loss', '3100', 3, 0],
            ['3140', 'Drawings', 'equity', 'Owner withdrawals', '3100', 3, 0],
            
            // SALES REVENUE - Level 3
            ['4110', 'Plot Sales', 'revenue', 'Revenue from plot sales', '4100', 3, 1],
            ['4120', 'Service Revenue', 'revenue', 'Revenue from services', '4100', 3, 1],
            ['4130', 'Commission Income', 'revenue', 'Commission earned', '4100', 3, 0],
            
            // OTHER INCOME - Level 3
            ['4210', 'Interest Income', 'revenue', 'Interest earned', '4200', 3, 0],
            ['4220', 'Rental Income', 'revenue', 'Property rental income', '4200', 3, 0],
            ['4230', 'Miscellaneous Income', 'revenue', 'Other income sources', '4200', 3, 0],
            
            // COST OF SALES - Level 3
            ['5110', 'Land Purchase Costs', 'expense', 'Land acquisition costs', '5100', 3, 0],
            ['5120', 'Development Costs', 'expense', 'Project development', '5100', 3, 1],
            ['5130', 'Commission Expenses', 'expense', 'Sales commissions paid', '5100', 3, 0],
            ['5140', 'Refunds', 'expense', 'Customer refunds', '5100', 3, 0],
            
            // ============================================================
            // LEVEL 4: DETAIL ACCOUNTS (35+ accounts)
            // ============================================================
            
            // CASH - Level 4
            ['1111', 'Cash on Hand', 'asset', 'Physical cash', '1110', 4, 0],
            ['1112', 'Petty Cash', 'asset', 'Small cash fund', '1110', 4, 0],
            
            // ACCOUNTS RECEIVABLE - Level 4
            ['1131', 'Trade Debtors', 'asset', 'Customer receivables', '1130', 4, 0],
            ['1132', 'Plot Sales Receivable', 'asset', 'Outstanding plot payments', '1130', 4, 0],
            ['1133', 'Service Revenue Receivable', 'asset', 'Unbilled services', '1130', 4, 0],
            
            // INVENTORY - Level 4
            ['1141', 'Raw Materials', 'asset', 'Construction materials', '1140', 4, 0],
            ['1142', 'Finished Goods', 'asset', 'Completed inventory', '1140', 4, 0],
            
            // ACCOUNTS PAYABLE - Level 4
            ['2111', 'Trade Creditors', 'liability', 'Supplier debts', '2110', 4, 0],
            ['2112', 'Accrued Expenses', 'liability', 'Unpaid expenses', '2110', 4, 0],
            
            // TAX PAYABLE - Level 4
            ['2121', 'VAT Payable', 'liability', 'Value Added Tax', '2120', 4, 0],
            ['2122', 'Income Tax Payable', 'liability', 'Corporate tax', '2120', 4, 0],
            ['2123', 'Withholding Tax Payable', 'liability', 'WHT obligations', '2120', 4, 0],
            
            // PAYROLL LIABILITIES - Level 4
            ['2131', 'Salaries Payable', 'liability', 'Unpaid salaries', '2130', 4, 0],
            ['2132', 'NSSF Payable', 'liability', 'Social security', '2130', 4, 0],
            ['2133', 'NHIF Payable', 'liability', 'Health insurance', '2130', 4, 0],
            ['2134', 'PAYE Payable', 'liability', 'Employee tax', '2130', 4, 0],
            
            // PLOT SALES REVENUE - Level 4
            ['4111', 'Plot Down Payments', 'revenue', 'Initial plot payments', '4110', 4, 0],
            ['4112', 'Plot Installments', 'revenue', 'Installment payments', '4110', 4, 0],
            ['4113', 'Plot Full Payments', 'revenue', 'Complete plot payments', '4110', 4, 0],
            
            // SERVICE REVENUE - Level 4
            ['4121', 'Survey Services', 'revenue', 'Survey income', '4120', 4, 0],
            ['4122', 'Legal Services', 'revenue', 'Legal service income', '4120', 4, 0],
            ['4123', 'Consultation Services', 'revenue', 'Consulting income', '4120', 4, 0],
            ['4124', 'Other Services', 'revenue', 'Miscellaneous services', '4120', 4, 0],
            
            // DEVELOPMENT COSTS - Level 4
            ['5121', 'Survey and Mapping', 'expense', 'Land survey costs', '5120', 4, 0],
            ['5122', 'Legal and Title Processing', 'expense', 'Documentation costs', '5120', 4, 0],
            ['5123', 'Infrastructure Development', 'expense', 'Roads and utilities', '5120', 4, 0],
            ['5124', 'Land Clearing', 'expense', 'Site preparation', '5120', 4, 0],
            ['5125', 'Utilities Installation', 'expense', 'Power and water', '5120', 4, 0],
            ['5126', 'Security and Fencing', 'expense', 'Security infrastructure', '5120', 4, 0],
            ['5127', 'Environmental Compliance', 'expense', 'Environmental assessments', '5120', 4, 0],
            ['5128', 'Landscaping', 'expense', 'Gardens and beautification', '5120', 4, 0],
            
            // OPERATING EXPENSES - Level 3
            ['6100', 'Administrative Expenses', 'expense', 'Admin costs', '6000', 3, 1],
            ['6200', 'Marketing Expenses', 'expense', 'Marketing costs', '6000', 3, 1],
            ['6300', 'Professional Fees', 'expense', 'External services', '6000', 3, 1],
            ['6400', 'Transportation', 'expense', 'Travel costs', '6000', 3, 1],
            ['6500', 'Insurance', 'expense', 'Insurance premiums', '6000', 3, 0],
            ['6600', 'Bank Charges', 'expense', 'Bank fees', '6000', 3, 0],
            ['6700', 'Depreciation', 'expense', 'Asset depreciation', '6000', 3, 0],
            ['6800', 'Repairs and Maintenance', 'expense', 'General repairs', '6000', 3, 0],
            
            // ADMINISTRATIVE EXPENSES - Level 4
            ['6110', 'Salaries and Wages', 'expense', 'Employee compensation', '6100', 4, 0],
            ['6120', 'Office Rent', 'expense', 'Office rental', '6100', 4, 0],
            ['6130', 'Utilities', 'expense', 'Office utilities', '6100', 4, 0],
            ['6140', 'Office Supplies', 'expense', 'Stationery', '6100', 4, 0],
            ['6150', 'Telephone and Communication', 'expense', 'Phone and internet', '6100', 4, 0],
            ['6160', 'Postage and Courier', 'expense', 'Mailing services', '6100', 4, 0],
            
            // MARKETING EXPENSES - Level 4
            ['6210', 'Advertising', 'expense', 'Promotional campaigns', '6200', 4, 0],
            ['6220', 'Marketing Materials', 'expense', 'Brochures and flyers', '6200', 4, 0],
            ['6230', 'Website and Digital Marketing', 'expense', 'Online marketing', '6200', 4, 0],
            ['6240', 'Campaign Costs', 'expense', 'Campaign expenses', '6200', 4, 0],
            
            // PROFESSIONAL FEES - Level 4
            ['6310', 'Legal Fees', 'expense', 'Legal services', '6300', 4, 0],
            ['6320', 'Accounting Fees', 'expense', 'Accounting services', '6300', 4, 0],
            ['6330', 'Consulting Fees', 'expense', 'Consultant fees', '6300', 4, 0],
            
            // TRANSPORTATION - Level 4
            ['6410', 'Vehicle Fuel', 'expense', 'Fuel costs', '6400', 4, 0],
            ['6420', 'Vehicle Maintenance', 'expense', 'Vehicle repairs', '6400', 4, 0],
            ['6430', 'Travel Expenses', 'expense', 'Business travel', '6400', 4, 0],
            
            // PROCUREMENT EXPENSES - Level 3
            ['7100', 'Purchase Orders', 'expense', 'Goods purchased', '7000', 3, 0],
            ['7200', 'Purchase Requisitions', 'expense', 'Requisitioned items', '7000', 3, 0],
            
            // FINANCIAL EXPENSES - Level 3
            ['8100', 'Interest Expense', 'expense', 'Loan interest', '8000', 3, 0],
            ['8200', 'Bank Charges', 'expense', 'Banking fees', '8000', 3, 0],
            
            // TAX EXPENSES - Level 3
            ['9100', 'Corporate Tax', 'expense', 'Income tax expense', '9000', 3, 0],
            ['9200', 'VAT Expense', 'expense', 'Non-recoverable VAT', '9000', 3, 0],
            ['9300', 'Withholding Tax', 'expense', 'WHT expense', '9000', 3, 0],
            
            // OTHER EXPENSES - Level 3
            ['9510', 'Donations', 'expense', 'Charitable giving', '9500', 3, 0],
            ['9520', 'Penalties and Fines', 'expense', 'Regulatory penalties', '9500', 3, 0],
            ['9530', 'Miscellaneous Expenses', 'expense', 'Other costs', '9500', 3, 0],
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
        updateAccountBalances($conn, $company_id);
        
        return [
            'success' => true, 
            'message' => 'Successfully created ' . count($accounts) . ' accounts with real data!', 
            'count' => count($accounts)
        ];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Update balances function (same as before)
function updateAccountBalances($conn, $company_id) {
    try {
        $accounts = [];
        $stmt = $conn->prepare("SELECT account_id, account_code FROM chart_of_accounts WHERE company_id = ?");
        $stmt->execute([$company_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $accounts[$row['account_code']] = $row['account_id'];
        }
        
        // Update with real data from system tables
        $updates = [
            '1120' => "SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE company_id = ? AND is_active = 1",
            '1132' => "SELECT COALESCE(SUM(total_amount - down_payment), 0) FROM reservations WHERE company_id = ? AND status IN ('active', 'draft')",
            '1140' => "SELECT COALESCE(SUM(ss.quantity_on_hand * i.cost_price), 0) FROM store_stock ss JOIN items i ON ss.item_id = i.item_id WHERE ss.company_id = ?",
            '1310' => "SELECT COALESCE(SUM(land_purchase_price), 0) FROM projects WHERE company_id = ? AND is_active = 1",
            '1320' => "SELECT COALESCE(SUM(total_operational_costs), 0) FROM projects WHERE company_id = ? AND is_active = 1",
            '2111' => "SELECT COALESCE(SUM(total_amount_owed - amount_paid), 0) FROM creditors WHERE company_id = ? AND status = 'active'",
            '2121' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND status IN ('pending', 'filed')",
            '2131' => "SELECT COALESCE(SUM(pd.net_salary), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'pending'",
            '2140' => "SELECT COALESCE(SUM(commission_amount), 0) FROM commissions WHERE company_id = ? AND payment_status = 'pending'",
            '2310' => "SELECT COALESCE(SUM(down_payment), 0) FROM reservations WHERE company_id = ? AND status IN ('active', 'draft')",
            '4110' => "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE company_id = ? AND status = 'approved' AND payment_type IN ('down_payment', 'installment', 'full_payment')",
            '4120' => "SELECT COALESCE(SUM(amount_paid), 0) FROM service_requests WHERE company_id = ? AND status IN ('completed', 'in_progress')",
            '5130' => "SELECT COALESCE(SUM(commission_amount), 0) FROM commissions WHERE company_id = ? AND payment_status = 'paid'",
            '5140' => "SELECT COALESCE(SUM(net_refund_amount), 0) FROM refunds WHERE company_id = ? AND status = 'processed'",
            '6110' => "SELECT COALESCE(SUM(pd.net_salary), 0) FROM payroll_details pd JOIN payroll p ON pd.payroll_id = p.payroll_id WHERE p.company_id = ? AND pd.payment_status = 'paid'",
            '6240' => "SELECT COALESCE(SUM(actual_cost), 0) FROM campaigns WHERE company_id = ? AND is_active = 1",
            '7100' => "SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE company_id = ? AND status IN ('approved', 'received')",
            '9200' => "SELECT COALESCE(SUM(tax_amount), 0) FROM tax_transactions WHERE company_id = ? AND status = 'paid'"
        ];
        
        foreach ($updates as $code => $query) {
            if (isset($accounts[$code])) {
                $stmt = $conn->prepare($query);
                $stmt->execute([$company_id]);
                $balance = $stmt->fetchColumn();
                
                if ($balance > 0) {
                    $update_stmt = $conn->prepare("UPDATE chart_of_accounts SET current_balance = ? WHERE account_id = ?");
                    $update_stmt->execute([$balance, $accounts[$code]]);
                }
            }
        }
        
        // Roll up parent balances
        for ($level = 4; $level >= 1; $level--) {
            $stmt = $conn->prepare("
                UPDATE chart_of_accounts parent
                SET parent.current_balance = (
                    SELECT COALESCE(SUM(child.current_balance), 0)
                    FROM chart_of_accounts child
                    WHERE child.parent_account_id = parent.account_id AND child.company_id = ?
                )
                WHERE parent.company_id = ? AND parent.account_level = ? AND parent.is_control_account = 1
            ");
            $stmt->execute([$company_id, $company_id, $level]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating balances: " . $e->getMessage());
        return false;
    }
}

// Auto-create accounts
$auto_result = createComprehensiveChartOfAccounts($conn, $company_id, $_SESSION['user_id']);

// Fetch statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_accounts,
        SUM(CASE WHEN account_type = 'asset' THEN 1 ELSE 0 END) as asset_accounts,
        SUM(CASE WHEN account_type = 'liability' THEN 1 ELSE 0 END) as liability_accounts,
        SUM(CASE WHEN account_type = 'equity' THEN 1 ELSE 0 END) as equity_accounts,
        SUM(CASE WHEN account_type = 'revenue' THEN 1 ELSE 0 END) as revenue_accounts,
        SUM(CASE WHEN account_type = 'expense' THEN 1 ELSE 0 END) as expense_accounts,
        SUM(CASE WHEN account_level = 1 THEN 1 ELSE 0 END) as level_1,
        SUM(CASE WHEN account_level = 2 THEN 1 ELSE 0 END) as level_2,
        SUM(CASE WHEN account_level = 3 THEN 1 ELSE 0 END) as level_3,
        SUM(CASE WHEN account_level = 4 THEN 1 ELSE 0 END) as level_4,
        COALESCE(SUM(CASE WHEN account_type = 'asset' THEN current_balance ELSE 0 END), 0) as total_assets,
        COALESCE(SUM(CASE WHEN account_type = 'liability' THEN current_balance ELSE 0 END), 0) as total_liabilities,
        COALESCE(SUM(CASE WHEN account_type = 'equity' THEN current_balance ELSE 0 END), 0) as total_equity,
        COALESCE(SUM(CASE WHEN account_type = 'revenue' THEN current_balance ELSE 0 END), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN account_type = 'expense' THEN current_balance ELSE 0 END), 0) as total_expenses
    FROM chart_of_accounts
    WHERE company_id = ?
";
$stmt = $conn->prepare($stats_query);
$stmt->execute([$company_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all accounts grouped by level and type
$accounts_query = "
    SELECT 
        a.*,
        parent.account_name as parent_account_name,
        parent.account_code as parent_account_code
    FROM chart_of_accounts a
    LEFT JOIN chart_of_accounts parent ON a.parent_account_id = parent.account_id
    WHERE a.company_id = ?
    ORDER BY a.account_code ASC
";
$stmt = $conn->prepare($accounts_query);
$stmt->execute([$company_id]);
$all_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group accounts by type and level
$accounts_by_type = [
    'asset' => [],
    'liability' => [],
    'equity' => [],
    'revenue' => [],
    'expense' => []
];

foreach ($all_accounts as $account) {
    $accounts_by_type[$account['account_type']][] = $account;
}

$page_title = 'Comprehensive Chart of Accounts';
require_once '../../includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-card.primary { border-left-color: #007bff; }
.stat-card.success { border-left-color: #28a745; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.danger { border-left-color: #dc3545; }
.stat-card.info { border-left-color: #17a2b8; }
.stat-card.purple { border-left-color: #6f42c1; }

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stat-label {
    color: #6c757d;
    font-size: 0.875rem;
    text-transform: uppercase;
}

.account-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.account-header {
    padding: 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.account-header h3 {
    margin: 0;
    font-size: 1.5rem;
}

.level-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-right: 0.5rem;
}

.level-1 { background: #007bff; color: white; }
.level-2 { background: #6c757d; color: white; }
.level-3 { background: #17a2b8; color: white; }
.level-4 { background: #28a745; color: white; }

.account-code {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
}

.hierarchy-indent-1 { padding-left: 0; }
.hierarchy-indent-2 { padding-left: 20px; }
.hierarchy-indent-3 { padding-left: 40px; }
.hierarchy-indent-4 { padding-left: 60px; }

.control-badge {
    background: #ffc107;
    color: #000;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}

.balance-positive { color: #28a745; font-weight: 600; }
.balance-negative { color: #dc3545; font-weight: 600; }

.category-section {
    margin-bottom: 3rem;
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-list-alt text-primary me-2"></i>Chart of Accounts
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    <?php echo $stats['total_accounts']; ?> accounts across 4 levels
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-info" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
        
        <?php if ($auto_result['success']): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $auto_result['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-number"><?php echo $stats['total_accounts']; ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number"><?php echo $stats['level_1']; ?></div>
                <div class="stat-label">Level 1 (Main)</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?php echo $stats['level_2']; ?></div>
                <div class="stat-label">Level 2 (Groups)</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $stats['level_3']; ?></div>
                <div class="stat-label">Level 3 (Sub-groups)</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-number"><?php echo $stats['level_4']; ?></div>
                <div class="stat-label">Level 4 (Details)</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-number">TSH <?php echo number_format($stats['total_assets']/1000000, 1); ?>M</div>
                <div class="stat-label">Total Assets</div>
            </div>
        </div>

        <!-- ASSETS -->
        <div class="category-section">
            <div class="account-table">
                <div class="account-header" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <h3><i class="fas fa-coins me-2"></i>ASSETS (<?php echo count($accounts_by_type['asset']); ?> accounts)</h3>
                    <span class="badge bg-light text-dark">TSH <?php echo number_format($stats['total_assets']/1000000, 2); ?>M</span>
                </div>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">Code</th>
                            <th width="40%">Account Name</th>
                            <th width="8%">Level</th>
                            <th width="15%">Parent</th>
                            <th width="15%" class="text-end">Balance</th>
                            <th width="12%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts_by_type['asset'] as $acc): ?>
                        <tr>
                            <td><span class="account-code"><?php echo $acc['account_code']; ?></span></td>
                            <td class="hierarchy-indent-<?php echo $acc['account_level']; ?>">
                                <span class="level-badge level-<?php echo $acc['account_level']; ?>">L<?php echo $acc['account_level']; ?></span>
                                <strong><?php echo htmlspecialchars($acc['account_name']); ?></strong>
                                <?php if ($acc['is_control_account']): ?>
                                <span class="control-badge ms-1">CTRL</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($acc['account_category']); ?></small>
                            </td>
                            <td><span class="badge bg-secondary">L<?php echo $acc['account_level']; ?></span></td>
                            <td>
                                <?php if ($acc['parent_account_code']): ?>
                                <small><?php echo $acc['parent_account_code']; ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($acc['current_balance'] > 0): ?>
                                <span class="balance-positive">TSH <?php echo number_format($acc['current_balance'], 2); ?></span>
                                <?php else: ?>
                                <span class="text-muted">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acc['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-success fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">TOTAL ASSETS:</td>
                            <td class="text-end">TSH <?php echo number_format($stats['total_assets'], 2); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- LIABILITIES -->
        <div class="category-section">
            <div class="account-table">
                <div class="account-header" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);">
                    <h3><i class="fas fa-file-invoice-dollar me-2"></i>LIABILITIES (<?php echo count($accounts_by_type['liability']); ?> accounts)</h3>
                    <span class="badge bg-light text-dark">TSH <?php echo number_format($stats['total_liabilities']/1000000, 2); ?>M</span>
                </div>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">Code</th>
                            <th width="40%">Account Name</th>
                            <th width="8%">Level</th>
                            <th width="15%">Parent</th>
                            <th width="15%" class="text-end">Balance</th>
                            <th width="12%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts_by_type['liability'] as $acc): ?>
                        <tr>
                            <td><span class="account-code"><?php echo $acc['account_code']; ?></span></td>
                            <td class="hierarchy-indent-<?php echo $acc['account_level']; ?>">
                                <span class="level-badge level-<?php echo $acc['account_level']; ?>">L<?php echo $acc['account_level']; ?></span>
                                <strong><?php echo htmlspecialchars($acc['account_name']); ?></strong>
                                <?php if ($acc['is_control_account']): ?>
                                <span class="control-badge ms-1">CTRL</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($acc['account_category']); ?></small>
                            </td>
                            <td><span class="badge bg-secondary">L<?php echo $acc['account_level']; ?></span></td>
                            <td>
                                <?php if ($acc['parent_account_code']): ?>
                                <small><?php echo $acc['parent_account_code']; ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($acc['current_balance'] > 0): ?>
                                <span class="text-warning fw-bold">TSH <?php echo number_format($acc['current_balance'], 2); ?></span>
                                <?php else: ?>
                                <span class="text-muted">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acc['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-warning fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">TOTAL LIABILITIES:</td>
                            <td class="text-end">TSH <?php echo number_format($stats['total_liabilities'], 2); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- EQUITY, REVENUE, EXPENSES tables follow same pattern -->
        
        <?php 
        $categories = [
            'equity' => ['EQUITY', 'balance-scale', '#17a2b8', 'info'],
            'revenue' => ['REVENUE', 'chart-line', '#28a745', 'success'],
            'expense' => ['EXPENSES', 'receipt', '#dc3545', 'danger']
        ];
        
        foreach ($categories as $type => $details):
            list($title, $icon, $color, $class) = $details;
            $amount_field = 'total_' . ($type == 'expense' ? 'expenses' : $type);
        ?>
        <div class="category-section">
            <div class="account-table">
                <div class="account-header" style="background: linear-gradient(135deg, <?php echo $color; ?> 0%, <?php echo $color; ?>dd 100%);">
                    <h3><i class="fas fa-<?php echo $icon; ?> me-2"></i><?php echo $title; ?> (<?php echo count($accounts_by_type[$type]); ?> accounts)</h3>
                    <span class="badge bg-light text-dark">TSH <?php echo number_format($stats[$amount_field]/1000000, 2); ?>M</span>
                </div>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">Code</th>
                            <th width="40%">Account Name</th>
                            <th width="8%">Level</th>
                            <th width="15%">Parent</th>
                            <th width="15%" class="text-end">Balance</th>
                            <th width="12%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts_by_type[$type] as $acc): ?>
                        <tr>
                            <td><span class="account-code"><?php echo $acc['account_code']; ?></span></td>
                            <td class="hierarchy-indent-<?php echo $acc['account_level']; ?>">
                                <span class="level-badge level-<?php echo $acc['account_level']; ?>">L<?php echo $acc['account_level']; ?></span>
                                <strong><?php echo htmlspecialchars($acc['account_name']); ?></strong>
                                <?php if ($acc['is_control_account']): ?>
                                <span class="control-badge ms-1">CTRL</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($acc['account_category']); ?></small>
                            </td>
                            <td><span class="badge bg-secondary">L<?php echo $acc['account_level']; ?></span></td>
                            <td>
                                <?php if ($acc['parent_account_code']): ?>
                                <small><?php echo $acc['parent_account_code']; ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($acc['current_balance'] > 0): ?>
                                <span class="text-<?php echo $class; ?> fw-bold">TSH <?php echo number_format($acc['current_balance'], 2); ?></span>
                                <?php else: ?>
                                <span class="text-muted">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acc['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-<?php echo $class; ?> fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">TOTAL <?php echo strtoupper($title); ?>:</td>
                            <td class="text-end">TSH <?php echo number_format($stats[$amount_field], 2); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>