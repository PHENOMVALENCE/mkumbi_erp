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

// Fetch accounts for dropdowns
try {
    $stmt = $conn->prepare("
        SELECT account_id, account_code, account_name, account_type
        FROM chart_of_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY account_code
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_journal') {
        if (empty($_POST['journal_date'])) {
            $errors[] = "Journal date is required";
        }
        if (empty($_POST['description'])) {
            $errors[] = "Description is required";
        }
        if (empty($_POST['lines']) || count($_POST['lines']) < 2) {
            $errors[] = "At least two journal lines are required";
        }
        
        // Validate balanced entry
        $total_debit = 0;
        $total_credit = 0;
        
        if (!empty($_POST['lines'])) {
            foreach ($_POST['lines'] as $line) {
                $total_debit += floatval($line['debit'] ?? 0);
                $total_credit += floatval($line['credit'] ?? 0);
            }
        }
        
        if (abs($total_debit - $total_credit) > 0.01) {
            $errors[] = "Journal entry must be balanced (Debits = Credits)";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                // Generate journal number
                $journal_number = 'JV-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Insert journal header
                $sql = "INSERT INTO journal_entries (
                    company_id, journal_number, journal_date, journal_type,
                    reference_number, description, total_debit, total_credit,
                    status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $journal_number,
                    $_POST['journal_date'],
                    $_POST['journal_type'] ?? 'general',
                    $_POST['reference_number'] ?? null,
                    $_POST['description'],
                    $total_debit,
                    $total_credit,
                    'draft',
                    $_SESSION['user_id']
                ]);
                
                $journal_id = $conn->lastInsertId();
                
                // Insert journal lines
                $line_number = 1;
                foreach ($_POST['lines'] as $line) {
                    if (empty($line['account_id'])) continue;
                    
                    $debit = floatval($line['debit'] ?? 0);
                    $credit = floatval($line['credit'] ?? 0);
                    
                    if ($debit == 0 && $credit == 0) continue;
                    
                    $sql = "INSERT INTO journal_entry_lines (
                        journal_id, line_number, account_id, description,
                        debit_amount, credit_amount
                    ) VALUES (?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $journal_id,
                        $line_number++,
                        $line['account_id'],
                        $line['description'] ?? null,
                        $debit,
                        $credit
                    ]);
                }
                
                $conn->commit();
                $success = "Journal entry created successfully! Journal Number: " . $journal_number;
                header("refresh:2;url=journal.php");
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error creating journal: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'post_journal') {
        try {
            $conn->beginTransaction();
            
            // Get journal details
            $stmt = $conn->prepare("SELECT * FROM journal_entries WHERE journal_id = ? AND company_id = ?");
            $stmt->execute([$_POST['journal_id'], $company_id]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($journal && $journal['status'] === 'draft') {
                // Get journal lines
                $stmt = $conn->prepare("
                    SELECT jel.*, coa.account_code, coa.account_name
                    FROM journal_entry_lines jel
                    INNER JOIN chart_of_accounts coa ON jel.account_id = coa.account_id
                    WHERE jel.journal_id = ?
                ");
                $stmt->execute([$_POST['journal_id']]);
                $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Update account balances
                foreach ($lines as $line) {
                    $balance_change = $line['debit_amount'] - $line['credit_amount'];
                    
                    $stmt = $conn->prepare("
                        UPDATE chart_of_accounts 
                        SET current_balance = current_balance + ?
                        WHERE account_id = ?
                    ");
                    $stmt->execute([$balance_change, $line['account_id']]);
                }
                
                // Update journal status
                $stmt = $conn->prepare("
                    UPDATE journal_entries 
                    SET status = 'posted',
                        posted_by = ?,
                        posted_at = NOW()
                    WHERE journal_id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $_POST['journal_id']]);
                
                $success = "Journal entry posted successfully!";
            }
            
            $conn->commit();
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error posting journal: " . $e->getMessage());
            $errors[] = "Error posting journal entry";
        }
    } elseif ($action === 'delete_journal') {
        try {
            $stmt = $conn->prepare("
                DELETE FROM journal_entries 
                WHERE journal_id = ? AND company_id = ? AND status = 'draft'
            ");
            $stmt->execute([$_POST['journal_id'], $company_id]);
            $success = "Journal entry deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting journal: " . $e->getMessage());
            $errors[] = "Error deleting journal entry";
        }
    }
}

// Build filter conditions
$where_conditions = ["je.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['journal_type'])) {
    $where_conditions[] = "je.journal_type = ?";
    $params[] = $_GET['journal_type'];
}

if (!empty($_GET['status'])) {
    $where_conditions[] = "je.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "je.journal_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "je.journal_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(je.journal_number LIKE ? OR je.description LIKE ? OR je.reference_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch journal entries
try {
    $stmt = $conn->prepare("
        SELECT je.*,
               u.full_name as created_by_name,
               pu.full_name as posted_by_name,
               (SELECT COUNT(*) FROM journal_entry_lines jel WHERE jel.journal_id = je.journal_id) as line_count
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.user_id
        LEFT JOIN users pu ON je.posted_by = pu.user_id
        WHERE $where_clause
        ORDER BY je.journal_date DESC, je.created_at DESC
    ");
    $stmt->execute($params);
    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching journals: " . $e->getMessage());
    $journals = [];
}

// Calculate statistics
$stats = [
    'total' => count($journals),
    'draft' => 0,
    'posted' => 0,
    'total_debits' => 0,
    'total_credits' => 0
];

foreach ($journals as $journal) {
    if ($journal['status'] === 'draft') {
        $stats['draft']++;
    } elseif ($journal['status'] === 'posted') {
        $stats['posted']++;
        $stats['total_debits'] += $journal['total_debit'];
        $stats['total_credits'] += $journal['total_credit'];
    }
}

$page_title = 'Journal Entries';
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
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.75rem;
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

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.journal-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    border-left: 4px solid #007bff;
}

.journal-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateX(4px);
}

.journal-card.posted {
    border-left-color: #28a745;
}

.journal-card.cancelled {
    border-left-color: #dc3545;
    opacity: 0.7;
}

.journal-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.draft {
    background: #fff3cd;
    color: #856404;
}

.status-badge.posted {
    background: #d4edda;
    color: #155724;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.journal-line-item {
    padding: 0.5rem;
    background: #f8f9fa;
    border-radius: 4px;
    margin-bottom: 0.5rem;
}

#journal-lines-container .row {
    margin-bottom: 0.75rem;
}

.remove-line-btn {
    position: absolute;
    right: -10px;
    top: 50%;
    transform: translateY(-50%);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i>Journal Entries
                </h1>
                <p class="text-muted small mb-0 mt-1">Record general journal transactions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="trial.php" class="btn btn-info me-2">
                        <i class="fas fa-balance-scale me-1"></i> Trial Balance
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#journalModal">
                        <i class="fas fa-plus-circle me-1"></i> New Journal Entry
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
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stats-label">Total Entries</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($stats['draft']); ?></div>
                    <div class="stats-label">Draft</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($stats['posted']); ?></div>
                    <div class="stats-label">Posted</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_debits'] / 1000000, 2); ?>M</div>
                    <div class="stats-label">Total Posted</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Journal #, description..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Type</label>
                    <select name="journal_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="general" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'general') ? 'selected' : ''; ?>>General</option>
                        <option value="sales" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'sales') ? 'selected' : ''; ?>>Sales</option>
                        <option value="purchase" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'purchase') ? 'selected' : ''; ?>>Purchase</option>
                        <option value="cash" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                        <option value="bank" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'bank') ? 'selected' : ''; ?>>Bank</option>
                        <option value="adjustment" <?php echo (isset($_GET['journal_type']) && $_GET['journal_type'] == 'adjustment') ? 'selected' : ''; ?>>Adjustment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="posted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'posted') ? 'selected' : ''; ?>>Posted</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $_GET['date_from'] ?? ''; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $_GET['date_to'] ?? ''; ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Journal Entries List -->
        <?php if (empty($journals)): ?>
        <div class="text-center py-5">
            <i class="fas fa-file-invoice fa-4x text-muted mb-3"></i>
            <h4>No Journal Entries</h4>
            <p class="text-muted">Create your first journal entry</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#journalModal">
                <i class="fas fa-plus-circle me-1"></i> New Journal Entry
            </button>
        </div>
        <?php else: ?>
            <?php foreach ($journals as $journal): ?>
            <div class="journal-card <?php echo $journal['status']; ?>">
                <div class="row align-items-start">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-2">
                            <span class="journal-number me-3"><?php echo htmlspecialchars($journal['journal_number']); ?></span>
                            <span class="status-badge <?php echo $journal['status']; ?>">
                                <?php echo ucfirst($journal['status']); ?>
                            </span>
                            <span class="badge bg-info ms-2"><?php echo ucfirst($journal['journal_type']); ?></span>
                        </div>
                        <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($journal['description']); ?></div>
                        <div class="text-muted small">
                            <i class="fas fa-calendar me-1"></i>
                            <?php echo date('M d, Y', strtotime($journal['journal_date'])); ?>
                            <?php if (!empty($journal['reference_number'])): ?>
                                | Ref: <?php echo htmlspecialchars($journal['reference_number']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small mt-1">
                            <?php echo $journal['line_count']; ?> line(s) | 
                            Created by <?php echo htmlspecialchars($journal['created_by_name']); ?>
                            <?php if ($journal['status'] === 'posted' && !empty($journal['posted_by_name'])): ?>
                                | Posted by <?php echo htmlspecialchars($journal['posted_by_name']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="mb-2">
                            <div class="text-muted small">Debit</div>
                            <div class="fw-bold text-danger">TSH <?php echo number_format($journal['total_debit'], 2); ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="text-muted small">Credit</div>
                            <div class="fw-bold text-success">TSH <?php echo number_format($journal['total_credit'], 2); ?></div>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <a href="journal_view.php?id=<?php echo $journal['journal_id']; ?>" 
                               class="btn btn-outline-info">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <?php if ($journal['status'] === 'draft'): ?>
                            <button type="button" 
                                    class="btn btn-outline-success"
                                    onclick="postJournal(<?php echo $journal['journal_id']; ?>)">
                                <i class="fas fa-check"></i> Post
                            </button>
                            <button type="button" 
                                    class="btn btn-outline-danger"
                                    onclick="deleteJournal(<?php echo $journal['journal_id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</section>

<!-- Create Journal Modal -->
<div class="modal fade" id="journalModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice me-2"></i>New Journal Entry
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="journalForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_journal">

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Journal Date <span class="text-danger">*</span></label>
                            <input type="date" name="journal_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Journal Type</label>
                            <select name="journal_type" class="form-select">
                                <option value="general">General</option>
                                <option value="sales">Sales</option>
                                <option value="purchase">Purchase</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="2" required></textarea>
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3">Journal Lines</h6>
                    
                    <div id="journal-lines-container">
                        <!-- Lines will be added here dynamically -->
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addJournalLine()">
                        <i class="fas fa-plus me-1"></i> Add Line
                    </button>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <strong>Total Debits:</strong> <span id="total_debits">0.00</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-success">
                                <strong>Total Credits:</strong> <span id="total_credits">0.00</span>
                            </div>
                        </div>
                    </div>
                    <div id="balance-warning" class="alert alert-warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Entry is not balanced! Debits must equal Credits.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Journal Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let lineCounter = 0;

function addJournalLine() {
    lineCounter++;
    const container = document.getElementById('journal-lines-container');
    const lineHtml = `
        <div class="row position-relative mb-3" id="line-${lineCounter}">
            <div class="col-md-4">
                <select name="lines[${lineCounter}][account_id]" class="form-select" required>
                    <option value="">Select Account</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?php echo $account['account_id']; ?>">
                            <?php echo htmlspecialchars($account['account_code']); ?> - 
                            <?php echo htmlspecialchars($account['account_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <input type="number" name="lines[${lineCounter}][debit]" class="form-control debit-input" 
                       step="0.01" placeholder="Debit" onchange="calculateTotals()">
            </div>
            <div class="col-md-3">
                <input type="number" name="lines[${lineCounter}][credit]" class="form-control credit-input" 
                       step="0.01" placeholder="Credit" onchange="calculateTotals()">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeLine(${lineCounter})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="col-md-12 mt-2">
                <input type="text" name="lines[${lineCounter}][description]" class="form-control" 
                       placeholder="Line description (optional)">
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', lineHtml);
    calculateTotals();
}

function removeLine(id) {
    document.getElementById('line-' + id).remove();
    calculateTotals();
}

function calculateTotals() {
    let totalDebits = 0;
    let totalCredits = 0;
    
    document.querySelectorAll('.debit-input').forEach(input => {
        totalDebits += parseFloat(input.value || 0);
    });
    
    document.querySelectorAll('.credit-input').forEach(input => {
        totalCredits += parseFloat(input.value || 0);
    });
    
    document.getElementById('total_debits').textContent = totalDebits.toFixed(2);
    document.getElementById('total_credits').textContent = totalCredits.toFixed(2);
    
    const warning = document.getElementById('balance-warning');
    if (Math.abs(totalDebits - totalCredits) > 0.01) {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

function postJournal(journalId) {
    if (confirm('Are you sure you want to post this journal entry? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="post_journal">
            <input type="hidden" name="journal_id" value="${journalId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteJournal(journalId) {
    if (confirm('Are you sure you want to delete this journal entry?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_journal">
            <input type="hidden" name="journal_id" value="${journalId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize with 2 lines
document.addEventListener('DOMContentLoaded', function() {
    addJournalLine();
    addJournalLine();
});

// Reset modal when closed
document.getElementById('journalModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('journalForm').reset();
    document.getElementById('journal-lines-container').innerHTML = '';
    lineCounter = 0;
    addJournalLine();
    addJournalLine();
});
</script>

<?php 
require_once '../../includes/footer.php';
?>