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

$errors = [];
$success = '';

// Tanzanian Banks
$tz_banks = [
    'CRDB Bank',
    'NMB Bank',
    'NBC Bank',
    'Exim Bank',
    'DTB Bank',
    'Stanbic Bank',
    'Standard Chartered Bank',
    'Absa Bank',
    'Access Bank',
    'Bank of Africa',
    'Bank of Baroda',
    'Bank of India',
    'Citibank',
    'Ecobank',
    'Equity Bank',
    'I&M Bank',
    'KCB Bank',
    'Letshego Bank',
    'Maendeleo Bank',
    'Mwalimu Commercial Bank',
    'PBZ Bank',
    'Azania Bank',
    'TPB Bank',
    'UBA Bank',
    'Amana Bank'
];

// Tanzanian Mobile Money Providers
$tz_mobile_money = [
    'M-Pesa (Vodacom)',
    'Tigo Pesa',
    'Airtel Money',
    'Halopesa (Halotel)',
    'T-Pesa (TTCL)',
    'Ezy Pesa'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        // Validation
        $account_category = $_POST['account_category'] ?? '';
        
        if (empty($_POST['account_name'])) {
            $errors[] = "Account name is required";
        }
        if (empty($account_category)) {
            $errors[] = "Account category is required";
        }
        
        if ($account_category === 'bank') {
            if (empty($_POST['bank_name'])) {
                $errors[] = "Bank name is required";
            }
            if (empty($_POST['account_number'])) {
                $errors[] = "Account number is required";
            }
        } elseif ($account_category === 'mobile_money') {
            if (empty($_POST['mobile_provider'])) {
                $errors[] = "Mobile money provider is required";
            }
            if (empty($_POST['mobile_number'])) {
                $errors[] = "Mobile money number is required";
            }
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $sql = "INSERT INTO bank_accounts (
                        company_id, account_category, account_name, account_number, 
                        bank_name, mobile_provider, mobile_number, mobile_account_name,
                        branch_name, swift_code, account_type,
                        currency, currency_code, opening_balance, current_balance,
                        is_active, is_default, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $opening_balance = floatval($_POST['opening_balance'] ?? 0);
                    
                    $stmt->execute([
                        $company_id,
                        $account_category,
                        $_POST['account_name'],
                        $_POST['account_number'] ?? null,
                        $_POST['bank_name'] ?? null,
                        $_POST['mobile_provider'] ?? null,
                        $_POST['mobile_number'] ?? null,
                        $_POST['mobile_account_name'] ?? null,
                        $_POST['branch_name'] ?? null,
                        $_POST['swift_code'] ?? null,
                        $_POST['account_type'] ?? 'business',
                        $_POST['currency'] ?? 'TSH',
                        $_POST['currency_code'] ?? 'TZS',
                        $opening_balance,
                        $opening_balance,
                        isset($_POST['is_active']) ? 1 : 0,
                        isset($_POST['is_default']) ? 1 : 0,
                        $_SESSION['user_id']
                    ]);
                    
                    $new_account_id = $conn->lastInsertId();
                    
                    // If set as default, unset other defaults
                    if (isset($_POST['is_default'])) {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET is_default = 0 WHERE company_id = ? AND bank_account_id != ?");
                        $stmt->execute([$company_id, $new_account_id]);
                    }
                    
                    $success = ucfirst($account_category) . " account created successfully!";
                } else {
                    $sql = "UPDATE bank_accounts SET 
                        account_category = ?, account_name = ?, account_number = ?,
                        bank_name = ?, mobile_provider = ?, mobile_number = ?, mobile_account_name = ?,
                        branch_name = ?, swift_code = ?,
                        account_type = ?, currency = ?, currency_code = ?,
                        is_active = ?, is_default = ?, updated_at = NOW()
                        WHERE bank_account_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $account_category,
                        $_POST['account_name'],
                        $_POST['account_number'] ?? null,
                        $_POST['bank_name'] ?? null,
                        $_POST['mobile_provider'] ?? null,
                        $_POST['mobile_number'] ?? null,
                        $_POST['mobile_account_name'] ?? null,
                        $_POST['branch_name'] ?? null,
                        $_POST['swift_code'] ?? null,
                        $_POST['account_type'] ?? 'business',
                        $_POST['currency'] ?? 'TSH',
                        $_POST['currency_code'] ?? 'TZS',
                        isset($_POST['is_active']) ? 1 : 0,
                        isset($_POST['is_default']) ? 1 : 0,
                        $_POST['bank_account_id'],
                        $company_id
                    ]);
                    
                    // If set as default, unset other defaults
                    if (isset($_POST['is_default'])) {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET is_default = 0 WHERE company_id = ? AND bank_account_id != ?");
                        $stmt->execute([$company_id, $_POST['bank_account_id']]);
                    }
                    
                    $success = "Account updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving account: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $conn->prepare("UPDATE bank_accounts SET is_active = 0 WHERE bank_account_id = ? AND company_id = ?");
            $stmt->execute([$_POST['bank_account_id'], $company_id]);
            $success = "Account deactivated successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting account: " . $e->getMessage());
            $errors[] = "Error deactivating account";
        }
    }
}

// Fetch accounts with statistics
try {
    $stmt = $conn->prepare("
        SELECT ba.*,
               (SELECT COUNT(*) FROM bank_transactions bt 
                WHERE bt.bank_account_id = ba.bank_account_id) as transaction_count,
               (SELECT COUNT(*) FROM bank_statements bs 
                WHERE bs.bank_account_id = ba.bank_account_id) as statement_count
        FROM bank_accounts ba
        WHERE ba.company_id = ?
        ORDER BY ba.is_default DESC, ba.account_category, ba.account_name
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}

// Calculate statistics
$total_balance = 0;
$active_accounts = 0;
$total_accounts = count($accounts);
$bank_accounts = 0;
$mobile_accounts = 0;

foreach ($accounts as $account) {
    if ($account['is_active']) {
        $active_accounts++;
        $total_balance += $account['current_balance'];
    }
    if ($account['account_category'] === 'bank') {
        $bank_accounts++;
    } else {
        $mobile_accounts++;
    }
}

$page_title = 'Accounts Management';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.account-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    border-left: 4px solid #007bff;
}

.account-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateX(4px);
}

.account-card.inactive {
    opacity: 0.6;
    border-left-color: #6c757d;
}

.account-card.default {
    border-left-color: #28a745;
    background: linear-gradient(135deg, #f8fff9 0%, #ffffff 100%);
}

.account-card.mobile-money {
    border-left-color: #17a2b8;
}

.account-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.account-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.account-subtitle {
    color: #6c757d;
    font-size: 0.95rem;
    margin-top: 0.25rem;
}

.account-number {
    font-family: 'Courier New', monospace;
    color: #6c757d;
    font-size: 0.95rem;
}

.account-balance {
    font-size: 1.5rem;
    font-weight: 700;
    color: #28a745;
}

.account-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e9ecef;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.detail-value {
    font-size: 0.95rem;
    color: #2c3e50;
    font-weight: 500;
}

.badge-default {
    background: #28a745;
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-inactive {
    background: #6c757d;
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-category {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-mobile {
    background: #17a2b8;
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.form-section {
    margin-bottom: 1.5rem;
}

.form-section-title {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.account-icon {
    font-size: 1.5rem;
    margin-right: 0.5rem;
}

.icon-bank {
    color: #007bff;
}

.icon-mobile {
    color: #17a2b8;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-wallet text-primary me-2"></i>Accounts Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage bank accounts and mobile money accounts</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accountModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Account
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6"><div class="stats-card primary"><div class="stats-number"><?php echo number_format($total_accounts); ?></div><div class="stats-label">Total Accounts</div></div></div>
            <div class="col-lg-3 col-md-6"><div class="stats-card info"><div class="stats-number"><?php echo number_format($bank_accounts); ?> / <?php echo number_format($mobile_accounts); ?></div><div class="stats-label">Banks / Mobile Money</div></div></div>
            <div class="col-lg-3 col-md-6"><div class="stats-card warning"><div class="stats-number">TSH <?php echo number_format($total_balance / 1000000, 2); ?>M</div><div class="stats-label">Total Balance</div></div></div>
            <div class="col-lg-3 col-md-6"><div class="stats-card success"><div class="stats-number"><?php echo number_format($active_accounts); ?></div><div class="stats-label">Active Accounts</div></div></div>
        </div>

        <div class="row">
            <?php if (empty($accounts)): ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="fas fa-wallet fa-4x text-muted mb-3"></i>
                    <h4>No Accounts Found</h4>
                    <p class="text-muted">Start by adding your first bank or mobile money account</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accountModal"><i class="fas fa-plus-circle me-1"></i> Add Account</button>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($accounts as $account): ?>
                <div class="col-lg-6">
                    <div class="account-card <?php echo $account['is_active'] ? ($account['is_default'] ? 'default' : '') : 'inactive'; ?> <?php echo $account['account_category'] === 'mobile_money' ? 'mobile-money' : ''; ?>">
                        <div class="account-header">
                            <div style="flex: 1;">
                                <div class="account-name">
                                    <?php if ($account['account_category'] === 'bank'): ?>
                                        <i class="fas fa-university account-icon icon-bank"></i><?php echo htmlspecialchars($account['bank_name']); ?>
                                    <?php else: ?>
                                        <i class="fas fa-mobile-alt account-icon icon-mobile"></i><?php echo htmlspecialchars($account['mobile_provider']); ?>
                                    <?php endif; ?>
                                    <?php if ($account['is_default']): ?><span class="badge-default ms-2">Default</span><?php endif; ?>
                                    <?php if (!$account['is_active']): ?><span class="badge-inactive ms-2">Inactive</span><?php endif; ?>
                                    <?php if ($account['account_category'] === 'mobile_money'): ?><span class="badge-mobile ms-2">Mobile Money</span><?php else: ?><span class="badge-category ms-2">Bank</span><?php endif; ?>
                                </div>
                                <div class="account-subtitle"><?php echo htmlspecialchars($account['account_name']); ?></div>
                                <div class="account-number">
                                    <?php if ($account['account_category'] === 'bank'): ?>
                                        A/C: <?php echo htmlspecialchars($account['account_number']); ?>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($account['mobile_number']); ?>
                                        <?php if ($account['mobile_account_name']): ?>| <?php echo htmlspecialchars($account['mobile_account_name']); ?><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="account-balance"><?php echo htmlspecialchars($account['currency']); ?> <?php echo number_format($account['current_balance'], 2); ?></div>
                        </div>
                        <div class="account-details">
                            <?php if ($account['account_category'] === 'bank' && !empty($account['branch_name'])): ?>
                            <div class="detail-item"><div class="detail-label">Branch</div><div class="detail-value"><?php echo htmlspecialchars($account['branch_name']); ?></div></div>
                            <?php endif; ?>
                            <div class="detail-item"><div class="detail-label">Account Type</div><div class="detail-value"><?php echo ucfirst(htmlspecialchars($account['account_type'])); ?></div></div>
                            <div class="detail-item"><div class="detail-label">Opening Balance</div><div class="detail-value"><?php echo number_format($account['opening_balance'], 2); ?></div></div>
                            <div class="detail-item"><div class="detail-label">Transactions</div><div class="detail-value"><i class="fas fa-exchange-alt me-1 text-info"></i><?php echo number_format($account['transaction_count']); ?></div></div>
                        </div>
                        <div class="action-buttons mt-3">
                            <button type="button" class="btn btn-sm btn-primary" onclick="editAccount(<?php echo htmlspecialchars(json_encode($account)); ?>)"><i class="fas fa-edit me-1"></i> Edit</button>
                            <a href="account_statement.php?account_id=<?php echo $account['bank_account_id']; ?>" class="btn btn-sm btn-warning"><i class="fas fa-file-invoice-dollar me-1"></i> Statement</a>
                            <a href="bank_transactions.php?account_id=<?php echo $account['bank_account_id']; ?>" class="btn btn-sm btn-success"><i class="fas fa-list me-1"></i> Transactions</a>
                            <?php if ($account['account_category'] === 'bank'): ?>
                            <a href="bank_reconciliation.php?account_id=<?php echo $account['bank_account_id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-check-double me-1"></i> Reconcile</a>
                            <?php endif; ?>
                            <?php if ($account['is_active']): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deactivateAccount(<?php echo $account['bank_account_id']; ?>, '<?php echo htmlspecialchars($account['account_name']); ?>')"><i class="fas fa-ban me-1"></i> Deactivate</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Add/Edit Account Modal -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-wallet me-2"></i>Add Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="accountForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="bank_account_id" id="bank_account_id">

                    <div class="form-section">
                        <div class="form-section-title">Account Category</div>
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="account_category" id="category_bank" value="bank" checked>
                                    <label class="btn btn-outline-primary" for="category_bank">
                                        <i class="fas fa-university me-2"></i>Bank Account
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="account_category" id="category_mobile" value="mobile_money">
                                    <label class="btn btn-outline-info" for="category_mobile">
                                        <i class="fas fa-mobile-alt me-2"></i>Mobile Money
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Account Fields -->
                    <div id="bank_fields" class="form-section">
                        <div class="form-section-title">Bank Account Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Bank Name</label>
                                <select name="bank_name" id="bank_name" class="form-select">
                                    <option value="">-- Select Bank --</option>
                                    <?php foreach ($tz_banks as $bank): ?>
                                        <option value="<?php echo htmlspecialchars($bank); ?>"><?php echo htmlspecialchars($bank); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Account Number</label>
                                <input type="text" name="account_number" id="account_number" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Account Name</label>
                                <input type="text" name="account_name" id="account_name" class="form-control" placeholder="e.g., Company Operations Account">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Branch Name</label>
                                <input type="text" name="branch_name" id="branch_name" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">SWIFT Code</label>
                                <input type="text" name="swift_code" id="swift_code" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Account Type</label>
                                <select name="account_type" id="account_type" class="form-select">
                                    <option value="checking">Checking</option>
                                    <option value="savings">Savings</option>
                                    <option value="business" selected>Business</option>
                                    <option value="escrow">Escrow</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Money Fields -->
                    <div id="mobile_fields" class="form-section" style="display: none;">
                        <div class="form-section-title">Mobile Money Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Provider</label>
                                <select name="mobile_provider" id="mobile_provider" class="form-select">
                                    <option value="">-- Select Provider --</option>
                                    <?php foreach ($tz_mobile_money as $provider): ?>
                                        <option value="<?php echo htmlspecialchars($provider); ?>"><?php echo htmlspecialchars($provider); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Mobile Number</label>
                                <input type="text" name="mobile_number" id="mobile_number" class="form-control" placeholder="e.g., 0755123456">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Account Name</label>
                                <input type="text" name="account_name_mobile" id="account_name_mobile" class="form-control" placeholder="e.g., Company M-Pesa">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Registered Name / Lipa Number</label>
                                <input type="text" name="mobile_account_name" id="mobile_account_name" class="form-control" placeholder="e.g., COMPANY LTD or Lipa 123456">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Currency & Balance</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Currency</label>
                                <input type="text" name="currency" id="currency" class="form-control" value="TSH" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Currency Code</label>
                                <input type="text" name="currency_code" id="currency_code" class="form-control" value="TZS" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Opening Balance</label>
                                <input type="number" name="opening_balance" id="opening_balance" class="form-control" step="0.01" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Settings</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                    <label class="form-check-label" for="is_active">Active Account</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="is_default">
                                    <label class="form-check-label" for="is_default">Set as Default</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle between bank and mobile money fields
document.querySelectorAll('input[name="account_category"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const bankFields = document.getElementById('bank_fields');
        const mobileFields = document.getElementById('mobile_fields');
        
        if (this.value === 'bank') {
            bankFields.style.display = 'block';
            mobileFields.style.display = 'none';
            // Make bank fields required
            document.getElementById('bank_name').required = true;
            document.getElementById('account_number').required = true;
            document.getElementById('account_name').required = true;
            // Remove mobile money requirements
            document.getElementById('mobile_provider').required = false;
            document.getElementById('mobile_number').required = false;
            document.getElementById('account_name_mobile').required = false;
        } else {
            bankFields.style.display = 'none';
            mobileFields.style.display = 'block';
            // Make mobile fields required
            document.getElementById('mobile_provider').required = true;
            document.getElementById('mobile_number').required = true;
            document.getElementById('account_name_mobile').required = true;
            // Remove bank requirements
            document.getElementById('bank_name').required = false;
            document.getElementById('account_number').required = false;
            document.getElementById('account_name').required = false;
        }
    });
});

// Copy mobile account name to main account name field on submit
document.getElementById('accountForm').addEventListener('submit', function(e) {
    const category = document.querySelector('input[name="account_category"]:checked').value;
    if (category === 'mobile_money') {
        document.getElementById('account_name').value = document.getElementById('account_name_mobile').value;
    }
});

function editAccount(account) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Account';
    document.getElementById('formAction').value = 'update';
    document.getElementById('bank_account_id').value = account.bank_account_id;
    
    // Set category
    if (account.account_category === 'bank') {
        document.getElementById('category_bank').checked = true;
        document.getElementById('bank_fields').style.display = 'block';
        document.getElementById('mobile_fields').style.display = 'none';
        document.getElementById('bank_name').value = account.bank_name || '';
        document.getElementById('account_number').value = account.account_number || '';
        document.getElementById('account_name').value = account.account_name || '';
        document.getElementById('branch_name').value = account.branch_name || '';
        document.getElementById('swift_code').value = account.swift_code || '';
    } else {
        document.getElementById('category_mobile').checked = true;
        document.getElementById('bank_fields').style.display = 'none';
        document.getElementById('mobile_fields').style.display = 'block';
        document.getElementById('mobile_provider').value = account.mobile_provider || '';
        document.getElementById('mobile_number').value = account.mobile_number || '';
        document.getElementById('account_name_mobile').value = account.account_name || '';
        document.getElementById('mobile_account_name').value = account.mobile_account_name || '';
    }
    
    document.getElementById('account_type').value = account.account_type;
    document.getElementById('currency').value = account.currency;
    document.getElementById('currency_code').value = account.currency_code;
    document.getElementById('opening_balance').value = account.opening_balance;
    document.getElementById('is_active').checked = account.is_active == 1;
    document.getElementById('is_default').checked = account.is_default == 1;
    
    const modal = new bootstrap.Modal(document.getElementById('accountModal'));
    modal.show();
}

function deactivateAccount(accountId, accountName) {
    if (confirm(`Are you sure you want to deactivate "${accountName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="bank_account_id" value="${accountId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('accountModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('accountForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-wallet me-2"></i>Add Account';
    document.getElementById('formAction').value = 'create';
    document.getElementById('bank_account_id').value = '';
    document.getElementById('category_bank').checked = true;
    document.getElementById('bank_fields').style.display = 'block';
    document.getElementById('mobile_fields').style.display = 'none';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>