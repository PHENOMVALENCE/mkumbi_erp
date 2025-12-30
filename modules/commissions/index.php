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
$user_id = $_SESSION['user_id'];

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            $conn->beginTransaction();
            
            // CREATE NEW COMMISSION
            if ($action === 'create_commission') {
                
                // Generate commission number
                $year = date('Y');
                $count_sql = "SELECT COUNT(*) FROM commissions WHERE company_id = ? AND YEAR(commission_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $year]);
                $count = $count_stmt->fetchColumn() + 1;
                $commission_number = 'COM-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                
                // Get reservation and plot details
                $reservation_id = $_POST['reservation_id'];
                $res_sql = "SELECT r.*, p.selling_price, p.discount_amount 
                           FROM reservations r
                           JOIN plots p ON r.plot_id = p.plot_id
                           WHERE r.reservation_id = ? AND r.company_id = ?";
                $res_stmt = $conn->prepare($res_sql);
                $res_stmt->execute([$reservation_id, $company_id]);
                $reservation = $res_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$reservation) {
                    throw new Exception("Reservation not found");
                }
                
                // Calculate amounts
                $base_amount = $reservation['total_amount'];
                $plot_size_sqm = $reservation['plot_size'] ?? 0; // Get plot size from reservation query
                $commission_percentage = floatval($_POST['commission_percentage']);
                $commission_amount = ($base_amount * $commission_percentage) / 100;
                
                // Withholding tax (default 5%)
                $withholding_tax_rate = floatval($_POST['withholding_tax_rate'] ?? 5.00);
                $withholding_tax_amount = ($commission_amount * $withholding_tax_rate) / 100;
                $entitled_amount = $commission_amount - $withholding_tax_amount;
                
                // Prepare recipient data based on type
                $recipient_type = $_POST['recipient_type'] ?? 'user';
                $user_id_value = null;
                $recipient_name = '';
                $recipient_phone = '';
                
                if ($recipient_type === 'user') {
                    $user_id_value = $_POST['user_id'];
                    // Get user details
                    $user_sql = "SELECT full_name, phone1 FROM users WHERE user_id = ?";
                    $user_stmt = $conn->prepare($user_sql);
                    $user_stmt->execute([$user_id_value]);
                    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                    $recipient_name = $user_data['full_name'] ?? '';
                    $recipient_phone = $user_data['phone1'] ?? '';
                } else {
                    // External or consultant
                    $recipient_name = $_POST['recipient_name'];
                    $recipient_phone = $_POST['recipient_phone'] ?? '';
                }
                
                // Insert commission
                $insert_sql = "INSERT INTO commissions (
                    company_id, commission_number, commission_date, commission_type,
                    reservation_id, plot_size_sqm, recipient_type, user_id, recipient_name, recipient_phone,
                    base_amount, commission_percentage, commission_amount,
                    withholding_tax_rate, withholding_tax_amount, entitled_amount, balance,
                    payment_status, notes, created_by, submitted_by, submitted_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())";
                
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([
                    $company_id,
                    $commission_number,
                    $_POST['commission_date'],
                    $_POST['commission_type'],
                    $reservation_id,
                    $plot_size_sqm,
                    $recipient_type,
                    $user_id_value,
                    $recipient_name,
                    $recipient_phone,
                    $base_amount,
                    $commission_percentage,
                    $commission_amount,
                    $withholding_tax_rate,
                    $withholding_tax_amount,
                    $entitled_amount,
                    $entitled_amount, // Initial balance = entitled amount
                    $_POST['notes'] ?? null,
                    $user_id,
                    $user_id
                ]);
                
                $commission_id = $conn->lastInsertId();
                
                // Record tax transaction
                $tax_transaction_number = 'TAX-WHT-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                $tax_sql = "INSERT INTO tax_transactions (
                    company_id, transaction_number, transaction_date, transaction_type,
                    tax_type_id, taxable_amount, tax_amount, total_amount,
                    description, status, created_by, created_at
                ) VALUES (?, ?, ?, 'withholding',
                    (SELECT tax_type_id FROM tax_types WHERE tax_code = 'WHT' AND company_id = ? LIMIT 1),
                    ?, ?, ?, ?, 'pending', ?, NOW())";
                
                $tax_stmt = $conn->prepare($tax_sql);
                $tax_stmt->execute([
                    $company_id,
                    $tax_transaction_number,
                    $_POST['commission_date'],
                    $company_id,
                    $commission_amount,
                    $withholding_tax_amount,
                    $commission_amount + $withholding_tax_amount,
                    "Withholding tax for commission {$commission_number}",
                    $user_id
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Commission {$commission_number} created successfully and submitted for approval!";
                header("Location: index.php");
                exit;
            }
            
            // EDIT COMMISSION
            elseif ($action === 'edit_commission') {
                
                $commission_id = $_POST['commission_id'];
                
                // Check if commission is still pending
                $check_sql = "SELECT payment_status FROM commissions WHERE commission_id = ? AND company_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->execute([$commission_id, $company_id]);
                $status = $check_stmt->fetchColumn();
                
                if ($status !== 'pending') {
                    throw new Exception("Can only edit pending commissions");
                }
                
                // Recalculate amounts
                $reservation_id = $_POST['reservation_id'];
                $res_sql = "SELECT r.total_amount, p.area_sqm as plot_size 
                           FROM reservations r
                           JOIN plots p ON r.plot_id = p.plot_id
                           WHERE r.reservation_id = ? AND r.company_id = ?";
                $res_stmt = $conn->prepare($res_sql);
                $res_stmt->execute([$reservation_id, $company_id]);
                $reservation = $res_stmt->fetch(PDO::FETCH_ASSOC);
                
                $base_amount = $reservation['total_amount'];
                $plot_size_sqm = $reservation['plot_size'] ?? 0;
                
                $commission_percentage = floatval($_POST['commission_percentage']);
                $commission_amount = ($base_amount * $commission_percentage) / 100;
                
                $withholding_tax_rate = floatval($_POST['withholding_tax_rate'] ?? 5.00);
                $withholding_tax_amount = ($commission_amount * $withholding_tax_rate) / 100;
                $entitled_amount = $commission_amount - $withholding_tax_amount;
                
                // Update commission
                $update_sql = "UPDATE commissions SET
                    commission_date = ?, commission_type = ?, user_id = ?, plot_size_sqm = ?,
                    commission_percentage = ?, commission_amount = ?,
                    withholding_tax_rate = ?, withholding_tax_amount = ?,
                    entitled_amount = ?, balance = ?, notes = ?, updated_at = NOW()
                    WHERE commission_id = ? AND company_id = ?";
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->execute([
                    $_POST['commission_date'],
                    $_POST['commission_type'],
                    $_POST['user_id'],
                    $plot_size_sqm,
                    $commission_percentage,
                    $commission_amount,
                    $withholding_tax_rate,
                    $withholding_tax_amount,
                    $entitled_amount,
                    $entitled_amount,
                    $_POST['notes'] ?? null,
                    $commission_id,
                    $company_id
                ]);
                
                // Update tax transaction (find by description pattern)
                $commission_number_query = "SELECT commission_number FROM commissions WHERE commission_id = ? AND company_id = ?";
                $cn_stmt = $conn->prepare($commission_number_query);
                $cn_stmt->execute([$commission_id, $company_id]);
                $commission_number = $cn_stmt->fetchColumn();
                
                $update_tax_sql = "UPDATE tax_transactions SET
                    taxable_amount = ?, tax_amount = ?, transaction_date = ?, total_amount = ?
                    WHERE description LIKE ? AND company_id = ? AND transaction_type = 'withholding'";
                
                $update_tax_stmt = $conn->prepare($update_tax_sql);
                $update_tax_stmt->execute([
                    $commission_amount,
                    $withholding_tax_amount,
                    $_POST['commission_date'],
                    $commission_amount + $withholding_tax_amount,
                    "%{$commission_number}%",
                    $company_id
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Commission updated successfully!";
                header("Location: index.php");
                exit;
            }
            
            // DELETE COMMISSION
            elseif ($action === 'delete_commission') {
                
                $commission_id = $_POST['commission_id'];
                
                // Check if can delete
                $check_sql = "SELECT payment_status, total_paid FROM commissions 
                             WHERE commission_id = ? AND company_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->execute([$commission_id, $company_id]);
                $comm = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($comm['payment_status'] === 'paid' || $comm['total_paid'] > 0) {
                    throw new Exception("Cannot delete commission with payments");
                }
                
                // Get commission number for tax transaction lookup
                $cn_query = "SELECT commission_number FROM commissions WHERE commission_id = ? AND company_id = ?";
                $cn_stmt = $conn->prepare($cn_query);
                $cn_stmt->execute([$commission_id, $company_id]);
                $commission_number = $cn_stmt->fetchColumn();
                
                // Delete tax transaction (find by description pattern)
                $del_tax_sql = "DELETE FROM tax_transactions 
                               WHERE description LIKE ? AND company_id = ? AND transaction_type = 'withholding'";
                $del_tax_stmt = $conn->prepare($del_tax_sql);
                $del_tax_stmt->execute(["%{$commission_number}%", $company_id]);
                
                // Delete commission
                $del_sql = "DELETE FROM commissions WHERE commission_id = ? AND company_id = ?";
                $del_stmt = $conn->prepare($del_sql);
                $del_stmt->execute([$commission_id, $company_id]);
                
                $conn->commit();
                $_SESSION['success'] = "Commission deleted successfully!";
                header("Location: index.php");
                exit;
            }
            
            // RECORD PAYMENT
            elseif ($action === 'record_payment') {
                
                $commission_id = $_POST['commission_id'];
                
                // Check if commission is approved
                $check_sql = "SELECT payment_status, COALESCE(balance, 0) as balance, 
                             commission_number, entitled_amount, COALESCE(total_paid, 0) as total_paid 
                             FROM commissions WHERE commission_id = ? AND company_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->execute([$commission_id, $company_id]);
                $comm = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($comm['payment_status'] !== 'approved') {
                    throw new Exception("Can only record payments for approved commissions");
                }
                
                $payment_amount = floatval($_POST['payment_amount']);
                
                if ($payment_amount <= 0) {
                    throw new Exception("Payment amount must be greater than zero");
                }
                
                if ($payment_amount > $comm['balance']) {
                    throw new Exception("Payment amount cannot exceed balance: TZS " . number_format($comm['balance'], 2));
                }
                
                // Generate payment number
                $year = date('Y');
                $count_sql = "SELECT COUNT(*) FROM commission_payments WHERE company_id = ? AND YEAR(payment_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $year]);
                $count = $count_stmt->fetchColumn() + 1;
                $payment_number = 'PAY-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                
                // Insert payment
                $insert_payment_sql = "INSERT INTO commission_payments (
                    commission_id, company_id, payment_number, payment_date, payment_amount,
                    payment_method, reference_number, bank_account_id, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $insert_payment_stmt = $conn->prepare($insert_payment_sql);
                $insert_payment_stmt->execute([
                    $commission_id,
                    $company_id,
                    $payment_number,
                    $_POST['payment_date'],
                    $payment_amount,
                    $_POST['payment_method'],
                    $_POST['reference_number'] ?? null,
                    !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
                    $_POST['payment_notes'] ?? null,
                    $user_id
                ]);
                
                // Update commission totals
                $new_total_paid = $comm['total_paid'] + $payment_amount;
                $new_balance = $comm['balance'] - $payment_amount;
                $new_status = ($new_balance <= 0.01) ? 'paid' : 'approved';
                
                $update_comm_sql = "UPDATE commissions SET
                    total_paid = ?, balance = ?, payment_status = ?,
                    paid_by = ?, paid_at = NOW(), updated_at = NOW()
                    WHERE commission_id = ? AND company_id = ?";
                
                $update_comm_stmt = $conn->prepare($update_comm_sql);
                $update_comm_stmt->execute([
                    $new_total_paid,
                    $new_balance,
                    $new_status,
                    $user_id,
                    $commission_id,
                    $company_id
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Payment of TZS " . number_format($payment_amount, 2) . " recorded successfully!";
                header("Location: index.php");
                exit;
            }
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
            error_log("Commission error: " . $e->getMessage());
        }
    }
}

// ==================== FETCH COMMISSIONS ====================
$where = ['c.company_id = ?'];
$params = [$company_id];

// Apply filters
if (!empty($_GET['status'])) {
    $where[] = 'c.payment_status = ?';
    $params[] = $_GET['status'];
}

if (!empty($_GET['user_id'])) {
    $where[] = 'c.user_id = ?';
    $params[] = $_GET['user_id'];
}

if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where[] = '(c.commission_number LIKE ? OR cust.full_name LIKE ? OR r.reservation_number LIKE ?)';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

try {
    $commissions_sql = "SELECT c.*,
                              r.reservation_number,
                              r.total_amount as reservation_total,
                              COALESCE(u.full_name, c.recipient_name) as recipient_name_display,
                              u.full_name as user_full_name,
                              u.email as recipient_email,
                              u.phone1 as user_phone,
                              c.recipient_name,
                              c.recipient_phone,
                              c.recipient_type,
                              cust.full_name as customer_name,
                              pl.plot_number,
                              pr.project_name,
                              sb.full_name as submitted_by_name,
                              ab.full_name as approved_by_name,
                              pb.full_name as paid_by_name
                       FROM commissions c
                       JOIN reservations r ON c.reservation_id = r.reservation_id
                       JOIN customers cust ON r.customer_id = cust.customer_id
                       JOIN plots pl ON r.plot_id = pl.plot_id
                       JOIN projects pr ON pl.project_id = pr.project_id
                       LEFT JOIN users u ON c.user_id = u.user_id
                       LEFT JOIN users sb ON c.submitted_by = sb.user_id
                       LEFT JOIN users ab ON c.approved_by = ab.user_id
                       LEFT JOIN users pb ON c.paid_by = pb.user_id
                       {$where_clause}
                       ORDER BY c.created_at DESC
                       LIMIT 1000";
    
    $stmt = $conn->prepare($commissions_sql);
    $stmt->execute($params);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $commissions = [];
    error_log("Error fetching commissions: " . $e->getMessage());
}

// ==================== STATISTICS ====================
try {
    $stats_sql = "SELECT 
                    COALESCE(COUNT(*), 0) as total_commissions,
                    COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count,
                    COALESCE(SUM(CASE WHEN payment_status = 'approved' THEN 1 ELSE 0 END), 0) as approved_count,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count,
                    COALESCE(SUM(commission_amount), 0) as total_commission_amount,
                    COALESCE(SUM(withholding_tax_amount), 0) as total_tax,
                    COALESCE(SUM(entitled_amount), 0) as total_entitled,
                    COALESCE(SUM(total_paid), 0) as total_paid_out,
                    COALESCE(SUM(balance), 0) as total_outstanding
                  FROM commissions
                  WHERE company_id = ?";
    
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute([$company_id]);
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all values are numeric, not null
    $stats = [
        'total_commissions' => intval($stats_result['total_commissions'] ?? 0),
        'pending_count' => intval($stats_result['pending_count'] ?? 0),
        'approved_count' => intval($stats_result['approved_count'] ?? 0),
        'paid_count' => intval($stats_result['paid_count'] ?? 0),
        'total_commission_amount' => floatval($stats_result['total_commission_amount'] ?? 0),
        'total_tax' => floatval($stats_result['total_tax'] ?? 0),
        'total_entitled' => floatval($stats_result['total_entitled'] ?? 0),
        'total_paid_out' => floatval($stats_result['total_paid_out'] ?? 0),
        'total_outstanding' => floatval($stats_result['total_outstanding'] ?? 0)
    ];
    
} catch (PDOException $e) {
    $stats = [
        'total_commissions' => 0,
        'pending_count' => 0,
        'approved_count' => 0,
        'paid_count' => 0,
        'total_commission_amount' => 0,
        'total_tax' => 0,
        'total_entitled' => 0,
        'total_paid_out' => 0,
        'total_outstanding' => 0
    ];
}

// ==================== FETCH USERS (SALES TEAM) ====================
try {
    $users_sql = "SELECT user_id, full_name, email FROM users 
                  WHERE company_id = ? AND is_active = 1 
                  ORDER BY full_name";
    $users_stmt = $conn->prepare($users_sql);
    $users_stmt->execute([$company_id]);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// ==================== FETCH RESERVATIONS FOR DROPDOWN ====================
try {
    $reservations_sql = "SELECT r.reservation_id, r.reservation_number, r.total_amount,
                               c.full_name as customer_name, pl.plot_number, pr.project_name
                        FROM reservations r
                        JOIN customers c ON r.customer_id = c.customer_id
                        JOIN plots pl ON r.plot_id = pl.plot_id
                        JOIN projects pr ON pl.project_id = pr.project_id
                        WHERE r.company_id = ? AND r.status = 'active'
                        ORDER BY r.created_at DESC
                        LIMIT 500";
    $res_stmt = $conn->prepare($reservations_sql);
    $res_stmt->execute([$company_id]);
    $reservations = $res_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reservations = [];
}

$page_title = 'Commission Management';
require_once '../../includes/header.php';
?>

<style>
/* Stats Cards */
.stats-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: all 0.3s;
}

.stats-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #3b82f6; }
.stats-card.warning { border-left-color: #f59e0b; }
.stats-card.success { border-left-color: #10b981; }
.stats-card.info { border-left-color: #06b6d4; }
.stats-card.purple { border-left-color: #8b5cf6; }
.stats-card.danger { border-left-color: #ef4444; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.25rem;
}

.stats-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280;
    font-weight: 600;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.approved {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge.paid {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.cancelled {
    background: #f3f4f6;
    color: #374151;
}

/* Table Styling */
.table-professional {
    font-size: 0.875rem;
}

.table-professional thead th {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
    color: #374151;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    padding: 0.75rem 0.5rem;
}

.table-professional tbody td {
    padding: 0.75rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f3f4f6;
}

.table-professional tbody tr:hover {
    background-color: #fafafa;
}

/* Cards */
.main-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}

.filter-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}

/* Modal Enhancements */
.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom: none;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.form-label {
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.required {
    color: #ef4444;
}

/* Action Buttons */
.btn-action {
    padding: 0.375rem 0.75rem;
    font-size: 0.8rem;
    border-radius: 4px;
    margin-right: 0.25rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state i {
    font-size: 4rem;
    color: #e5e7eb;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #6b7280;
    font-size: 1.125rem;
}

/* Info Box */
.info-box {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.info-box strong {
    color: #1e40af;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-percent"></i> Commission Management</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCommissionModal">
                    <i class="fas fa-plus me-1"></i>Create Commission
                </button>
                <a href="structures.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-cog me-1"></i>Structures
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="info-box">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Commission Workflow:</strong> 
        All new commissions are created with <strong>Pending</strong> status and must be approved in the 
        <a href="../approvals/pending.php" class="text-primary"><u>Approvals</u></a> module before payment can be processed.
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($stats['total_commissions'] ?? 0) ?></div>
                <div class="stats-label">Total Commissions</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['pending_count'] ?? 0) ?></div>
                <div class="stats-label">Pending Approval</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['paid_count'] ?? 0) ?></div>
                <div class="stats-label">Paid</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['total_outstanding'] ?? 0, 0) ?></div>
                <div class="stats-label">Outstanding (TSH)</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['total_commission_amount'] ?? 0, 0) ?></div>
                <div class="stats-label">Total Commission (TSH)</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card purple">
                <div class="stats-number"><?= number_format($stats['total_tax'] ?? 0, 0) ?></div>
                <div class="stats-label">Withholding Tax (TSH)</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['total_entitled'] ?? 0, 0) ?></div>
                <div class="stats-label">Net Entitled (TSH)</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['total_paid_out'] ?? 0, 0) ?></div>
                <div class="stats-label">Total Paid (TSH)</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card mb-3">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted mb-1">SEARCH</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Commission number, customer..." 
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">RECIPIENT</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">All Recipients</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" 
                                    <?= ($_GET['user_id'] ?? '') == $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">STATUS</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= ($_GET['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="paid" <?= ($_GET['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="rejected" <?= ($_GET['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card main-card">
        <div class="card-body">
            <?php if (empty($commissions)): ?>
                <div class="empty-state">
                    <i class="fas fa-percent"></i>
                    <p>No commissions found</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCommissionModal">
                        <i class="fas fa-plus me-1"></i>Create First Commission
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="commissionsTable">
                        <thead>
                            <tr>
                                <th>Commission #</th>
                                <th>Date</th>
                                <th>Recipient</th>
                                <th>Customer</th>
                                <th>Reservation</th>
                                <th class="text-end">Base Amount</th>
                                <th class="text-center">Rate %</th>
                                <th class="text-end">Commission</th>
                                <th class="text-end">WHT (5%)</th>
                                <th class="text-end">Net Entitled</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($c['commission_number']) ?></strong>
                                        <br><small class="text-muted"><?= ucfirst($c['commission_type']) ?></small>
                                    </td>
                                    <td><?= date('d M Y', strtotime($c['commission_date'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['recipient_name_display']) ?></strong>
                                        <?php if ($c['recipient_type'] !== 'user'): ?>
                                        <br><small class="badge bg-info"><?= ucfirst($c['recipient_type']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($c['recipient_phone']): ?>
                                        <br><small class="text-muted"><i class="fas fa-phone"></i> <?= htmlspecialchars($c['recipient_phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($c['customer_name']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($c['reservation_number']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($c['project_name']) ?></small>
                                    </td>
                                    <td class="text-end"><?= number_format($c['base_amount'], 0) ?></td>
                                    <td class="text-center"><strong><?= $c['commission_percentage'] ?>%</strong></td>
                                    <td class="text-end"><strong><?= number_format($c['commission_amount'], 0) ?></strong></td>
                                    <td class="text-end text-danger"><?= number_format($c['withholding_tax_amount'], 0) ?></td>
                                    <td class="text-end"><strong><?= number_format($c['entitled_amount'], 0) ?></strong></td>
                                    <td class="text-end">
                                        <?php if ($c['balance'] > 0): ?>
                                            <span class="text-danger fw-bold"><?= number_format($c['balance'], 0) ?></span>
                                        <?php else: ?>
                                            <span class="text-success">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-badge <?= $c['payment_status'] ?>"><?= ucfirst($c['payment_status']) ?></span></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-info btn-action" 
                                                onclick='viewCommission(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($c['payment_status'] === 'approved' && $c['balance'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-success btn-action" 
                                                onclick='recordPayment(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                title="Record Payment">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($c['payment_status'], ['approved', 'paid'])): ?>
                                        <a href="statement.php?commission_id=<?= $c['commission_id'] ?>" 
                                           class="btn btn-sm btn-outline-primary btn-action"
                                           title="View Statement">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($c['payment_status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-warning btn-action" 
                                                onclick='editCommission(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger btn-action" 
                                                onclick="deleteCommission(<?= $c['commission_id'] ?>, '<?= htmlspecialchars(addslashes($c['commission_number'])) ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CREATE COMMISSION MODAL -->
<div class="modal fade" id="createCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Create New Commission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_commission">
                <div class="modal-body">
                    <div class="row g-3">
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Date <span class="required">*</span></label>
                            <input type="date" name="commission_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Type <span class="required">*</span></label>
                            <select name="commission_type" class="form-select" required>
                                <option value="sales">Sales Commission</option>
                                <option value="referral">Referral Commission</option>
                                <option value="milestone">Milestone Bonus</option>
                                <option value="bonus">Performance Bonus</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Reservation <span class="required">*</span></label>
                            <select name="reservation_id" id="create_reservation_id" class="form-select" required onchange="loadReservationDetails(this.value, 'create')">
                                <option value="">-- Select Reservation --</option>
                                <?php foreach ($reservations as $r): ?>
                                    <option value="<?= $r['reservation_id'] ?>" 
                                            data-amount="<?= $r['total_amount'] ?>"
                                            data-customer="<?= htmlspecialchars($r['customer_name']) ?>"
                                            data-plot="<?= htmlspecialchars($r['plot_number']) ?>"
                                            data-project="<?= htmlspecialchars($r['project_name']) ?>">
                                        <?= htmlspecialchars($r['reservation_number']) ?> - 
                                        <?= htmlspecialchars($r['customer_name']) ?> - 
                                        <?= htmlspecialchars($r['project_name']) ?> - 
                                        TZS <?= number_format($r['total_amount'], 0) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <div id="create_reservation_details" class="alert alert-info" style="display:none;">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Recipient Type <span class="required">*</span></label>
                            <select name="recipient_type" id="create_recipient_type" class="form-select" required onchange="toggleRecipientFields('create')">
                                <option value="user">System User (Employee)</option>
                                <option value="external">External Agent</option>
                                <option value="consultant">Consultant</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Recipient (Sales Person) <span class="required">*</span></label>
                            <select name="user_id" id="create_user_id" class="form-select">
                                <option value="">-- Select Recipient --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['user_id'] ?>" 
                                            data-name="<?= htmlspecialchars($u['full_name']) ?>"
                                            data-phone="<?= htmlspecialchars($u['phone1'] ?? '') ?>">
                                        <?= htmlspecialchars($u['full_name']) ?> - <?= htmlspecialchars($u['email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6" id="create_recipient_name_div" style="display:none;">
                            <label class="form-label">Recipient Name <span class="required">*</span></label>
                            <input type="text" name="recipient_name" id="create_recipient_name" class="form-control" placeholder="Enter recipient name">
                        </div>
                        
                        <div class="col-md-6" id="create_recipient_phone_div" style="display:none;">
                            <label class="form-label">Recipient Phone</label>
                            <input type="text" name="recipient_phone" id="create_recipient_phone" class="form-control" placeholder="Enter phone number">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Percentage (%) <span class="required">*</span></label>
                            <input type="number" name="commission_percentage" id="create_commission_percentage" 
                                   class="form-control" step="0.01" min="0" max="100" value="2.50" 
                                   required onchange="calculateCommission('create')">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Withholding Tax Rate (%) <span class="required">*</span></label>
                            <input type="number" name="withholding_tax_rate" id="create_withholding_tax_rate" 
                                   class="form-control" step="0.01" min="0" max="100" value="5.00" 
                                   required onchange="calculateCommission('create')">
                        </div>
                        
                        <div class="col-md-12">
                            <div id="create_commission_calculation" class="alert alert-success" style="display:none;">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Additional notes about this commission..."></textarea>
                        </div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create & Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT COMMISSION MODAL -->
<div class="modal fade" id="editCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Commission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_commission">
                <input type="hidden" name="commission_id" id="edit_commission_id">
                <input type="hidden" name="reservation_id" id="edit_reservation_id">
                <div class="modal-body">
                    <div class="row g-3">
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Date <span class="required">*</span></label>
                            <input type="date" name="commission_date" id="edit_commission_date" 
                                   class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Type <span class="required">*</span></label>
                            <select name="commission_type" id="edit_commission_type" class="form-select" required>
                                <option value="sales">Sales Commission</option>
                                <option value="referral">Referral Commission</option>
                                <option value="milestone">Milestone Bonus</option>
                                <option value="bonus">Performance Bonus</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <div id="edit_reservation_details" class="alert alert-info">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Recipient (Sales Person) <span class="required">*</span></label>
                            <select name="user_id" id="edit_user_id" class="form-select" required>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['user_id'] ?>">
                                        <?= htmlspecialchars($u['full_name']) ?> - <?= htmlspecialchars($u['email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Commission Percentage (%) <span class="required">*</span></label>
                            <input type="number" name="commission_percentage" id="edit_commission_percentage" 
                                   class="form-control" step="0.01" min="0" max="100" 
                                   required onchange="calculateCommission('edit')">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Withholding Tax Rate (%) <span class="required">*</span></label>
                            <input type="number" name="withholding_tax_rate" id="edit_withholding_tax_rate" 
                                   class="form-control" step="0.01" min="0" max="100" 
                                   required onchange="calculateCommission('edit')">
                        </div>
                        
                        <div class="col-md-12">
                            <div id="edit_commission_calculation" class="alert alert-success">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                        </div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-white">
                        <i class="fas fa-save me-1"></i>Update Commission
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW COMMISSION MODAL -->
<div class="modal fade" id="viewCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Commission Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewCommissionContent">
                <!-- Populated by JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal fade" id="deleteCommissionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Commission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_commission">
                <input type="hidden" name="commission_id" id="delete_commission_id">
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Are you sure you want to delete commission <strong id="delete_commission_number"></strong>?
                    </div>
                    <p>This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete Commission
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RECORD PAYMENT MODAL -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Record Commission Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="commission_id" id="payment_commission_id">
                <div class="modal-body">
                    <div class="row g-3">
                        
                        <div class="col-12">
                            <div class="alert alert-info">
                                <strong>Commission:</strong> <span id="payment_commission_number"></span><br>
                                <strong>Recipient:</strong> <span id="payment_recipient_name"></span><br>
                                <strong>Entitled Amount:</strong> TZS <span id="payment_entitled_amount"></span><br>
                                <strong>Already Paid:</strong> TZS <span id="payment_total_paid"></span><br>
                                <strong>Outstanding Balance:</strong> <strong class="text-danger">TZS <span id="payment_balance"></span></strong>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Amount (TZS) <span class="required">*</span></label>
                            <input type="number" name="payment_amount" id="payment_amount" class="form-control" 
                                   step="0.01" min="0.01" required>
                            <small class="text-muted">Maximum: <span id="payment_max_amount"></span></small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="required">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" 
                                   placeholder="Transaction reference / cheque number">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Payment Notes</label>
                            <textarea name="payment_notes" class="form-control" rows="2" 
                                      placeholder="Additional notes about this payment..."></textarea>
                        </div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
// Initialize DataTable
$(document).ready(function() {
    $('#commissionsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        columnDefs: [
            { targets: [5, 7, 8, 9, 10], className: 'text-end' },
            { targets: 6, className: 'text-center' },
            { targets: 12, orderable: false, className: 'text-center' }
        ]
    });
});

// Load reservation details when selected
function loadReservationDetails(reservationId, mode) {
    const select = document.getElementById(mode + '_reservation_id');
    const option = select.options[select.selectedIndex];
    const detailsDiv = document.getElementById(mode + '_reservation_details');
    
    if (!reservationId) {
        detailsDiv.style.display = 'none';
        return;
    }
    
    const amount = parseFloat(option.dataset.amount);
    const customer = option.dataset.customer;
    const plot = option.dataset.plot;
    const project = option.dataset.project;
    
    detailsDiv.innerHTML = `
        <strong>Reservation Details:</strong><br>
        Customer: ${customer}<br>
        Plot: ${plot} - ${project}<br>
        Total Amount: <strong>TZS ${amount.toLocaleString()}</strong>
    `;
    detailsDiv.style.display = 'block';
    
    // Store amount for calculation
    window[mode + '_base_amount'] = amount;
    
    // Trigger calculation
    calculateCommission(mode);
}

// Calculate commission amounts
function calculateCommission(mode) {
    const baseAmount = window[mode + '_base_amount'] || 0;
    const percentage = parseFloat(document.getElementById(mode + '_commission_percentage').value) || 0;
    const taxRate = parseFloat(document.getElementById(mode + '_withholding_tax_rate').value) || 0;
    
    const commissionAmount = (baseAmount * percentage) / 100;
    const taxAmount = (commissionAmount * taxRate) / 100;
    const entitledAmount = commissionAmount - taxAmount;
    
    const calcDiv = document.getElementById(mode + '_commission_calculation');
    
    if (baseAmount > 0) {
        calcDiv.innerHTML = `
            <strong>Commission Calculation:</strong><br>
            Base Amount: TZS ${baseAmount.toLocaleString()}<br>
            Commission (${percentage}%): <strong>TZS ${commissionAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong><br>
            Withholding Tax (${taxRate}%): <span class="text-danger">TZS ${taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span><br>
            <hr>
            Net Entitled Amount: <strong class="text-success">TZS ${entitledAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
        `;
        calcDiv.style.display = 'block';
    } else {
        calcDiv.style.display = 'none';
    }
}

// Toggle recipient fields based on recipient type
function toggleRecipientFields(mode) {
    const recipientType = document.getElementById(mode + '_recipient_type').value;
    const userIdSelect = document.getElementById(mode + '_user_id');
    const recipientNameDiv = document.getElementById(mode + '_recipient_name_div');
    const recipientPhoneDiv = document.getElementById(mode + '_recipient_phone_div');
    const recipientNameInput = document.getElementById(mode + '_recipient_name');
    const recipientPhoneInput = document.getElementById(mode + '_recipient_phone');
    
    if (recipientType === 'user') {
        // System user - show user dropdown, hide manual fields
        userIdSelect.style.display = 'block';
        userIdSelect.required = true;
        recipientNameDiv.style.display = 'none';
        recipientPhoneDiv.style.display = 'none';
        recipientNameInput.required = false;
        
        // Auto-fill name and phone from selected user
        userIdSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                recipientNameInput.value = selectedOption.dataset.name || '';
                recipientPhoneInput.value = selectedOption.dataset.phone || '';
            }
        });
        
    } else {
        // External or consultant - hide user dropdown, show manual fields
        userIdSelect.style.display = 'none';
        userIdSelect.required = false;
        userIdSelect.value = '';
        recipientNameDiv.style.display = 'block';
        recipientPhoneDiv.style.display = 'block';
        recipientNameInput.required = true;
    }
}

// View commission details
function viewCommission(data) {
    const content = `
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="border-bottom pb-2">Commission Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>Commission Number:</strong></td><td>${data.commission_number}</td></tr>
                    <tr><td><strong>Date:</strong></td><td>${new Date(data.commission_date).toLocaleDateString()}</td></tr>
                    <tr><td><strong>Type:</strong></td><td>${data.commission_type.charAt(0).toUpperCase() + data.commission_type.slice(1)}</td></tr>
                    <tr><td><strong>Status:</strong></td><td><span class="status-badge ${data.payment_status}">${data.payment_status.toUpperCase()}</span></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="border-bottom pb-2">Recipient Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>Type:</strong></td><td><span class="badge bg-info">${data.recipient_type.charAt(0).toUpperCase() + data.recipient_type.slice(1)}</span></td></tr>
                    <tr><td><strong>Name:</strong></td><td>${data.recipient_name_display}</td></tr>
                    ${data.recipient_email ? `<tr><td><strong>Email:</strong></td><td>${data.recipient_email}</td></tr>` : ''}
                    ${data.recipient_phone || data.user_phone ? `<tr><td><strong>Phone:</strong></td><td>${data.recipient_phone || data.user_phone || '-'}</td></tr>` : ''}
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="border-bottom pb-2">Reservation Details</h6>
                <table class="table table-sm">
                    <tr><td><strong>Reservation:</strong></td><td>${data.reservation_number}</td></tr>
                    <tr><td><strong>Customer:</strong></td><td>${data.customer_name}</td></tr>
                    <tr><td><strong>Plot:</strong></td><td>${data.plot_number}</td></tr>
                    <tr><td><strong>Project:</strong></td><td>${data.project_name}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="border-bottom pb-2">Financial Details</h6>
                <table class="table table-sm">
                    <tr><td><strong>Base Amount:</strong></td><td>TZS ${parseFloat(data.base_amount).toLocaleString()}</td></tr>
                    <tr><td><strong>Commission Rate:</strong></td><td>${data.commission_percentage}%</td></tr>
                    <tr><td><strong>Commission Amount:</strong></td><td>TZS ${parseFloat(data.commission_amount).toLocaleString()}</td></tr>
                    <tr><td><strong>Withholding Tax (${data.withholding_tax_rate}%):</strong></td><td class="text-danger">TZS ${parseFloat(data.withholding_tax_amount).toLocaleString()}</td></tr>
                    <tr><td><strong>Net Entitled:</strong></td><td class="text-success"><strong>TZS ${parseFloat(data.entitled_amount).toLocaleString()}</strong></td></tr>
                    <tr><td><strong>Total Paid:</strong></td><td>TZS ${parseFloat(data.total_paid).toLocaleString()}</td></tr>
                    <tr><td><strong>Balance:</strong></td><td class="text-danger"><strong>TZS ${parseFloat(data.balance).toLocaleString()}</strong></td></tr>
                </table>
            </div>
            ${data.notes ? `
            <div class="col-md-12">
                <h6 class="border-bottom pb-2">Notes</h6>
                <p>${data.notes}</p>
            </div>
            ` : ''}
            <div class="col-md-12">
                <h6 class="border-bottom pb-2">Audit Trail</h6>
                <table class="table table-sm">
                    ${data.submitted_by_name ? `<tr><td><strong>Submitted By:</strong></td><td>${data.submitted_by_name} on ${new Date(data.submitted_at).toLocaleString()}</td></tr>` : ''}
                    ${data.approved_by_name ? `<tr><td><strong>Approved By:</strong></td><td>${data.approved_by_name} on ${new Date(data.approved_at).toLocaleString()}</td></tr>` : ''}
                    ${data.paid_by_name ? `<tr><td><strong>Paid By:</strong></td><td>${data.paid_by_name} on ${new Date(data.paid_at).toLocaleString()}</td></tr>` : ''}
                </table>
            </div>
        </div>
    `;
    
    document.getElementById('viewCommissionContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewCommissionModal')).show();
}

// Edit commission
function editCommission(data) {
    document.getElementById('edit_commission_id').value = data.commission_id;
    document.getElementById('edit_reservation_id').value = data.reservation_id;
    document.getElementById('edit_commission_date').value = data.commission_date;
    document.getElementById('edit_commission_type').value = data.commission_type;
    document.getElementById('edit_user_id').value = data.user_id;
    document.getElementById('edit_commission_percentage').value = data.commission_percentage;
    document.getElementById('edit_withholding_tax_rate').value = data.withholding_tax_rate;
    document.getElementById('edit_notes').value = data.notes || '';
    
    // Set reservation details
    const detailsDiv = document.getElementById('edit_reservation_details');
    detailsDiv.innerHTML = `
        <strong>Reservation Details:</strong><br>
        Reservation: ${data.reservation_number}<br>
        Customer: ${data.customer_name}<br>
        Plot: ${data.plot_number} - ${data.project_name}<br>
        Total Amount: <strong>TZS ${parseFloat(data.base_amount).toLocaleString()}</strong>
    `;
    
    // Store base amount and calculate
    window.edit_base_amount = parseFloat(data.base_amount);
    calculateCommission('edit');
    
    new bootstrap.Modal(document.getElementById('editCommissionModal')).show();
}

// Delete commission
function deleteCommission(commissionId, commissionNumber) {
    document.getElementById('delete_commission_id').value = commissionId;
    document.getElementById('delete_commission_number').textContent = commissionNumber;
    new bootstrap.Modal(document.getElementById('deleteCommissionModal')).show();
}

// Record payment
function recordPayment(data) {
    document.getElementById('payment_commission_id').value = data.commission_id;
    document.getElementById('payment_commission_number').textContent = data.commission_number;
    document.getElementById('payment_recipient_name').textContent = data.recipient_name_display;
    document.getElementById('payment_entitled_amount').textContent = parseFloat(data.entitled_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_total_paid').textContent = parseFloat(data.total_paid || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_balance').textContent = parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_max_amount').textContent = 'TZS ' + parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Set max amount for payment
    document.getElementById('payment_amount').max = data.balance;
    document.getElementById('payment_amount').value = data.balance; // Default to full balance
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

// Auto-dismiss alerts
setTimeout(function() {
    $('.alert-success, .alert-danger').not('.info-box').fadeOut();
}, 5000);
</script>

<?php require_once '../../includes/footer.php'; ?>