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

// Set current user for triggers
$conn->exec("SET @current_user_id = " . $_SESSION['user_id']);

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_movement':
                $stmt = $conn->prepare("INSERT INTO plot_movements 
                    (company_id, plot_id, project_id, movement_type, movement_date,
                     previous_status, new_status, previous_customer_id, new_customer_id,
                     reason, remarks, initiated_by, approval_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $company_id,
                    $_POST['plot_id'],
                    $_POST['project_id'],
                    $_POST['movement_type'],
                    $_POST['movement_date'],
                    $_POST['previous_status'] ?? null,
                    $_POST['new_status'] ?? null,
                    $_POST['previous_customer_id'] ?? null,
                    $_POST['new_customer_id'] ?? null,
                    $_POST['reason'] ?? null,
                    $_POST['remarks'] ?? null,
                    $_SESSION['user_id'],
                    'approved'
                ]);
                
                // Update plot status if applicable
                if (!empty($_POST['new_status'])) {
                    $stmt = $conn->prepare("UPDATE plots SET status = ? WHERE plot_id = ? AND company_id = ?");
                    $stmt->execute([$_POST['new_status'], $_POST['plot_id'], $company_id]);
                }
                
                $_SESSION['success_message'] = "Movement recorded successfully!";
                header("Location: movements.php");
                exit();
                break;

            case 'add_hold':
                $stmt = $conn->prepare("INSERT INTO plot_holds 
                    (company_id, plot_id, project_id, hold_type, hold_reason,
                     hold_start_date, expected_release_date, customer_id, hold_fee,
                     auto_release, priority, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $company_id,
                    $_POST['plot_id'],
                    $_POST['project_id'],
                    $_POST['hold_type'],
                    $_POST['hold_reason'],
                    $_POST['hold_start_date'],
                    $_POST['expected_release_date'] ?? null,
                    $_POST['customer_id'] ?? null,
                    $_POST['hold_fee'] ?? 0,
                    isset($_POST['auto_release']) ? 1 : 0,
                    $_POST['priority'] ?? 'medium',
                    $_POST['notes'] ?? null,
                    $_SESSION['user_id']
                ]);
                
                // Update plot status to blocked
                $stmt = $conn->prepare("UPDATE plots SET status = 'blocked' WHERE plot_id = ? AND company_id = ?");
                $stmt->execute([$_POST['plot_id'], $company_id]);
                
                $_SESSION['success_message'] = "Plot hold created successfully!";
                header("Location: movements.php");
                exit();
                break;

            case 'release_hold':
                $stmt = $conn->prepare("UPDATE plot_holds 
                    SET status = 'released', release_date = NOW(), 
                        release_reason = ?, released_by = ?
                    WHERE hold_id = ? AND company_id = ?");
                $stmt->execute([
                    $_POST['release_reason'] ?? 'Manual release',
                    $_SESSION['user_id'],
                    $_POST['hold_id'],
                    $company_id
                ]);
                
                // Update plot status back to available
                if (!empty($_POST['plot_id'])) {
                    $stmt = $conn->prepare("UPDATE plots SET status = 'available' WHERE plot_id = ? AND company_id = ?");
                    $stmt->execute([$_POST['plot_id'], $company_id]);
                }
                
                $_SESSION['success_message'] = "Hold released successfully!";
                header("Location: movements.php");
                exit();
                break;

            case 'add_transfer':
                $stmt = $conn->prepare("INSERT INTO plot_transfers 
                    (company_id, plot_id, project_id, transfer_type, transfer_date,
                     from_customer_id, to_customer_id, transfer_fee, transfer_reason,
                     price_adjustment, approval_status, notes, initiated_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $company_id,
                    $_POST['plot_id'],
                    $_POST['project_id'],
                    $_POST['transfer_type'],
                    $_POST['transfer_date'],
                    $_POST['from_customer_id'] ?? null,
                    $_POST['to_customer_id'],
                    $_POST['transfer_fee'] ?? 0,
                    $_POST['transfer_reason'] ?? null,
                    $_POST['price_adjustment'] ?? 0,
                    'pending',
                    $_POST['notes'] ?? null,
                    $_SESSION['user_id']
                ]);
                $_SESSION['success_message'] = "Transfer request created successfully!";
                header("Location: movements.php");
                exit();
                break;

            case 'approve_transfer':
                $stmt = $conn->prepare("UPDATE plot_transfers 
                    SET approval_status = 'approved', approved_by = ?, approved_at = NOW()
                    WHERE transfer_id = ? AND company_id = ?");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $_POST['transfer_id'],
                    $company_id
                ]);
                $_SESSION['success_message'] = "Transfer approved successfully!";
                header("Location: movements.php");
                exit();
                break;

            case 'reject_transfer':
                $stmt = $conn->prepare("UPDATE plot_transfers 
                    SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(),
                        rejection_reason = ?
                    WHERE transfer_id = ? AND company_id = ?");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $_POST['rejection_reason'] ?? 'Not specified',
                    $_POST['transfer_id'],
                    $company_id
                ]);
                $_SESSION['success_message'] = "Transfer rejected!";
                header("Location: movements.php");
                exit();
                break;
        }
    } catch (Exception $e) {
        error_log("Movement operation error: " . $e->getMessage());
        $_SESSION['error_message'] = "Operation failed: " . $e->getMessage();
        header("Location: movements.php");
        exit();
    }
}

// ==================== COMPREHENSIVE STATISTICS ====================
try {
    // Basic movement statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_movements,
            COUNT(CASE WHEN movement_type = 'status_change' THEN 1 END) as status_changes,
            COUNT(CASE WHEN movement_type = 'reservation' THEN 1 END) as reservations,
            COUNT(CASE WHEN movement_type = 'sale' THEN 1 END) as sales,
            COUNT(CASE WHEN movement_type = 'transfer' THEN 1 END) as transfers,
            COUNT(CASE WHEN movement_type = 'cancellation' THEN 1 END) as cancellations,
            COUNT(CASE WHEN DATE(movement_date) = CURDATE() THEN 1 END) as today_movements,
            COUNT(CASE WHEN DATE(movement_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_movements,
            COUNT(CASE WHEN DATE(movement_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_movements
        FROM plot_movements 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Current plot status distribution
    $stmt = $conn->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(selling_price) as total_value
        FROM plots 
        WHERE company_id = ? AND is_active = 1
        GROUP BY status
    ");
    $stmt->execute([$company_id]);
    $status_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to associative array
    $current_status = [
        'available' => 0, 'reserved' => 0, 'sold' => 0, 'blocked' => 0,
        'available_value' => 0, 'reserved_value' => 0, 'sold_value' => 0
    ];
    foreach ($status_distribution as $row) {
        $current_status[$row['status']] = $row['count'];
        $current_status[$row['status'] . '_value'] = $row['total_value'];
    }
    
    // Status flow analysis (last 30 days)
    $stmt = $conn->prepare("
        SELECT 
            previous_status,
            new_status,
            COUNT(*) as flow_count
        FROM plot_movements 
        WHERE company_id = ? 
          AND movement_type = 'status_change'
          AND movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND previous_status IS NOT NULL
          AND new_status IS NOT NULL
        GROUP BY previous_status, new_status
        ORDER BY flow_count DESC
    ");
    $stmt->execute([$company_id]);
    $status_flows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate specific flows
    $flows = [
        'available_to_reserved' => 0,
        'available_to_sold' => 0,
        'reserved_to_sold' => 0,
        'reserved_to_available' => 0,
        'sold_to_available' => 0,
        'blocked_to_available' => 0
    ];
    
    foreach ($status_flows as $flow) {
        $key = strtolower($flow['previous_status']) . '_to_' . strtolower($flow['new_status']);
        if (isset($flows[$key])) {
            $flows[$key] = $flow['flow_count'];
        }
    }
    
    // Active holds count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM plot_holds WHERE company_id = ? AND status = 'active'");
    $stmt->execute([$company_id]);
    $stats['active_holds'] = $stmt->fetchColumn();
    
    // Pending transfers count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM plot_transfers WHERE company_id = ? AND approval_status = 'pending'");
    $stmt->execute([$company_id]);
    $stats['pending_transfers'] = $stmt->fetchColumn();
    
    // Average time in each status (last 90 days)
    $stmt = $conn->prepare("
        SELECT 
            new_status as status,
            AVG(TIMESTAMPDIFF(DAY, movement_date, 
                COALESCE(
                    (SELECT MIN(pm2.movement_date) 
                     FROM plot_movements pm2 
                     WHERE pm2.plot_id = pm.plot_id 
                     AND pm2.movement_date > pm.movement_date
                     AND pm2.company_id = pm.company_id),
                    CURDATE()
                )
            )) as avg_days
        FROM plot_movements pm
        WHERE company_id = ? 
          AND movement_type = 'status_change'
          AND new_status IS NOT NULL
          AND movement_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        GROUP BY new_status
    ");
    $stmt->execute([$company_id]);
    $avg_time_in_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Conversion rates
    $conversion_rates = [
        'reservation_to_sale' => 0,
        'available_to_sale_direct' => 0
    ];
    
    if ($flows['reserved_to_sold'] > 0 && $flows['available_to_reserved'] > 0) {
        $conversion_rates['reservation_to_sale'] = 
            round(($flows['reserved_to_sold'] / $flows['available_to_reserved']) * 100, 1);
    }
    
    if ($flows['available_to_sold'] > 0) {
        $conversion_rates['available_to_sale_direct'] = $flows['available_to_sold'];
    }
    
    // Cancellation rate
    $total_reservations = $flows['available_to_reserved'];
    $cancellations = $flows['reserved_to_available'];
    $cancellation_rate = $total_reservations > 0 ? 
        round(($cancellations / $total_reservations) * 100, 1) : 0;
    
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = [
        'total_movements'=>0, 'status_changes'=>0, 'reservations'=>0,
        'sales'=>0, 'transfers'=>0, 'cancellations'=>0,
        'today_movements'=>0, 'week_movements'=>0, 'month_movements'=>0,
        'active_holds'=>0, 'pending_transfers'=>0
    ];
    $current_status = ['available'=>0, 'reserved'=>0, 'sold'=>0, 'blocked'=>0];
    $flows = ['available_to_reserved'=>0, 'reserved_to_sold'=>0, 'reserved_to_available'=>0];
    $avg_time_in_status = [];
    $conversion_rates = ['reservation_to_sale'=>0, 'available_to_sale_direct'=>0];
    $cancellation_rate = 0;
}

// ==================== TIME PERIOD ANALYSIS ====================
$time_period = $_GET['period'] ?? '30days';
$date_condition = match($time_period) {
    'today' => "DATE(pm.movement_date) = CURDATE()",
    '7days' => "pm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    '30days' => "pm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    '90days' => "pm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
    'year' => "pm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)",
    default => "pm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
};

// ==================== PROJECTS FOR FILTER ====================
$projects = [];
try {
    $stmt = $conn->prepare("
        SELECT project_id, project_name, project_code
        FROM projects 
        WHERE company_id = ? AND is_active = 1 
        ORDER BY project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Projects error: " . $e->getMessage());
}

// ==================== GET PLOTS FOR DROPDOWN ====================
$all_plots = [];
try {
    $stmt = $conn->prepare("
        SELECT p.plot_id, p.plot_number, p.block_number, p.project_id, pr.project_name, p.status
        FROM plots p
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        WHERE p.company_id = ? AND p.is_active = 1
        ORDER BY pr.project_name, p.plot_number
    ");
    $stmt->execute([$company_id]);
    $all_plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Plots error: " . $e->getMessage());
}

// ==================== GET CUSTOMERS FOR DROPDOWN ====================
$customers = [];
try {
    $stmt = $conn->prepare("
        SELECT customer_id, 
               COALESCE(customer_name, name, full_name, company_name) as customer_name,
               phone, email
        FROM customers 
        WHERE company_id = ? AND status = 'active'
        ORDER BY customer_name
    ");
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Customers error: " . $e->getMessage());
}

// ==================== BUILD FILTERS ====================
$where = ['pm.company_id = ?', $date_condition];
$params = [$company_id];

$active_tab = $_GET['tab'] ?? 'movements';

if (!empty($_GET['project_id'])) {
    $where[] = 'pm.project_id = ?';
    $params[] = (int)$_GET['project_id'];
}
if (!empty($_GET['movement_type'])) {
    $where[] = 'pm.movement_type = ?';
    $params[] = $_GET['movement_type'];
}
if (!empty($_GET['plot_id'])) {
    $where[] = 'pm.plot_id = ?';
    $params[] = (int)$_GET['plot_id'];
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ==================== FETCH DATA BASED ON ACTIVE TAB ====================
$movements = [];
$holds = [];
$transfers = [];
$flow_details = [];

try {
    if ($active_tab === 'movements') {
        $query = "
            SELECT 
                pm.*,
                p.plot_number,
                p.block_number,
                pr.project_name,
                pr.project_code,
                u.full_name as initiated_by_name,
                c1.customer_name as previous_customer_name,
                c2.customer_name as new_customer_name,
                TIMESTAMPDIFF(DAY, pm.movement_date, 
                    COALESCE(
                        (SELECT MIN(pm2.movement_date) 
                         FROM plot_movements pm2 
                         WHERE pm2.plot_id = pm.plot_id 
                         AND pm2.movement_date > pm.movement_date
                         AND pm2.company_id = pm.company_id),
                        CURDATE()
                    )
                ) as days_in_status
            FROM plot_movements pm
            LEFT JOIN plots p ON pm.plot_id = p.plot_id
            LEFT JOIN projects pr ON pm.project_id = pr.project_id
            LEFT JOIN users u ON pm.initiated_by = u.user_id
            LEFT JOIN customers c1 ON pm.previous_customer_id = c1.customer_id
            LEFT JOIN customers c2 ON pm.new_customer_id = c2.customer_id
            $where_clause
            ORDER BY pm.movement_date DESC
            LIMIT 500
        ";
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    elseif ($active_tab === 'holds') {
        $stmt = $conn->prepare("
            SELECT 
                ph.*,
                p.plot_number,
                p.block_number,
                pr.project_name,
                pr.project_code,
                u.full_name as created_by_name,
                DATEDIFF(COALESCE(ph.release_date, CURDATE()), ph.hold_start_date) as actual_hold_days
            FROM plot_holds ph
            LEFT JOIN plots p ON ph.plot_id = p.plot_id
            LEFT JOIN projects pr ON ph.project_id = pr.project_id
            LEFT JOIN users u ON ph.created_by = u.user_id
            WHERE ph.company_id = ? AND ph.status = 'active'
            ORDER BY ph.created_at DESC
        ");
        $stmt->execute([$company_id]);
        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    elseif ($active_tab === 'transfers') {
        $stmt = $conn->prepare("
            SELECT 
                pt.*,
                p.plot_number,
                p.block_number,
                pr.project_name,
                pr.project_code,
                u.full_name as initiated_by_name,
                c1.customer_name as from_customer_name,
                c1.phone as from_customer_phone,
                c2.customer_name as to_customer_name,
                c2.phone as to_customer_phone
            FROM plot_transfers pt
            LEFT JOIN plots p ON pt.plot_id = p.plot_id
            LEFT JOIN projects pr ON pt.project_id = pr.project_id
            LEFT JOIN users u ON pt.initiated_by = u.user_id
            LEFT JOIN customers c1 ON pt.from_customer_id = c1.customer_id
            LEFT JOIN customers c2 ON pt.to_customer_id = c2.customer_id
            WHERE pt.company_id = ?
            ORDER BY pt.created_at DESC
            LIMIT 200
        ");
        $stmt->execute([$company_id]);
        $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    elseif ($active_tab === 'flow_analysis') {
        // Get detailed flow analysis with plot details
        $stmt = $conn->prepare("
            SELECT 
                pm.plot_id,
                p.plot_number,
                p.block_number,
                pr.project_name,
                pr.project_code,
                pm.previous_status,
                pm.new_status,
                pm.movement_date,
                TIMESTAMPDIFF(DAY, 
                    (SELECT MAX(pm2.movement_date) 
                     FROM plot_movements pm2 
                     WHERE pm2.plot_id = pm.plot_id 
                     AND pm2.movement_date < pm.movement_date
                     AND pm2.company_id = pm.company_id),
                    pm.movement_date
                ) as days_in_previous_status
            FROM plot_movements pm
            LEFT JOIN plots p ON pm.plot_id = p.plot_id
            LEFT JOIN projects pr ON pm.project_id = pr.project_id
            WHERE pm.company_id = ?
              AND pm.movement_type = 'status_change'
              AND pm.previous_status IS NOT NULL
              AND pm.new_status IS NOT NULL
              AND $date_condition
            ORDER BY pm.movement_date DESC
            LIMIT 200
        ");
        $stmt->execute([$company_id]);
        $flow_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
}

$page_title = 'Plot Movements & Analytics';
require_once '../../includes/header.php';
?>

<style>
/* Stats Cards */
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.purple { border-left-color: #6f42c1; }
.stats-card.teal { border-left-color: #20c997; }
.stats-card.orange { border-left-color: #fd7e14; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

/* Flow Cards */
.flow-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
    border: 2px solid #e9ecef;
    transition: all 0.3s;
}

.flow-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.flow-number {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.flow-label {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 600;
}

.flow-arrow {
    font-size: 1.5rem;
    margin: 0.5rem 0;
}

/* Movement Type Badges */
.movement-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.movement-badge.status_change { background: #cfe2ff; color: #084298; }
.movement-badge.reservation { background: #fff3cd; color: #664d03; }
.movement-badge.sale { background: #d1e7dd; color: #0f5132; }
.movement-badge.cancellation { background: #f8d7da; color: #842029; }
.movement-badge.transfer { background: #e7f3ff; color: #0066cc; }
.movement-badge.hold { background: #e2e3e5; color: #41464b; }

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.active { background: #d1ecf1; color: #0c5460; }
.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.approved { background: #d4edda; color: #155724; }
.status-badge.rejected { background: #f8d7da; color: #721c24; }
.status-badge.completed { background: #d1e7dd; color: #0f5132; }
.status-badge.released { background: #e2e3e5; color: #41464b; }
.status-badge.expired { background: #f8d7da; color: #721c24; }

.status-badge.available { background: #d4edda; color: #155724; }
.status-badge.reserved { background: #fff3cd; color: #856404; }
.status-badge.sold { background: #d1ecf1; color: #0c5460; }
.status-badge.blocked { background: #f8d7da; color: #721c24; }

/* Priority Badges */
.priority-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
}

.priority-badge.low { background: #d1ecf1; color: #0c5460; }
.priority-badge.medium { background: #fff3cd; color: #856404; }
.priority-badge.high { background: #f8d7da; color: #721c24; }
.priority-badge.critical { background: #842029; color: #fff; }

/* Table Styling */
.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.65rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

/* Plot Info */
.plot-info {
    line-height: 1.3;
}

.plot-number {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.85rem;
}

.plot-block {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
}

.project-code {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
}

/* Action Buttons */
.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    margin-bottom: 0.2rem;
    white-space: nowrap;
}

/* Tabs */
.nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 0.75rem 1.5rem;
}

.nav-tabs .nav-link.active {
    color: #007bff;
    border-bottom-color: #007bff;
    background: transparent;
}

.nav-tabs .nav-link:hover {
    border-bottom-color: #dee2e6;
}

/* Cards */
.filter-card, .main-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
}

.empty-state i {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 1rem;
}

/* Metric Cards */
.metric-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.metric-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.metric-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

/* Time Period Selector */
.period-selector {
    display: inline-flex;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.period-btn {
    padding: 0.5rem 1rem;
    border: none;
    background: #fff;
    color: #6c757d;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s;
    border-right: 1px solid #dee2e6;
}

.period-btn:last-child {
    border-right: none;
}

.period-btn.active {
    background: #007bff;
    color: white;
}

.period-btn:hover:not(.active) {
    background: #f8f9fa;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Plot Movements & Analytics</h1>
            </div>
            <div class="col-sm-6 text-end">
                <div class="period-selector me-2 d-inline-flex">
                    <a href="?tab=<?= $active_tab ?>&period=today" class="period-btn <?= $time_period === 'today' ? 'active' : '' ?>">Today</a>
                    <a href="?tab=<?= $active_tab ?>&period=7days" class="period-btn <?= $time_period === '7days' ? 'active' : '' ?>">7 Days</a>
                    <a href="?tab=<?= $active_tab ?>&period=30days" class="period-btn <?= $time_period === '30days' ? 'active' : '' ?>">30 Days</a>
                    <a href="?tab=<?= $active_tab ?>&period=90days" class="period-btn <?= $time_period === '90days' ? 'active' : '' ?>">90 Days</a>
                    <a href="?tab=<?= $active_tab ?>&period=year" class="period-btn <?= $time_period === 'year' ? 'active' : '' ?>">Year</a>
                </div>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-plus me-1"></i>New Activity
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addMovementModal">
                            <i class="fas fa-exchange-alt me-2"></i>Record Movement
                        </a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addHoldModal">
                            <i class="fas fa-hand-paper me-2"></i>Create Hold
                        </a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addTransferModal">
                            <i class="fas fa-user-friends me-2"></i>Request Transfer
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Current Status Overview -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($current_status['available']) ?></div>
                <div class="stats-label">Currently Available</div>
                <small class="text-muted">TZS <?= number_format($current_status['available_value']/1000000, 1) ?>M</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($current_status['reserved']) ?></div>
                <div class="stats-label">Currently Reserved</div>
                <small class="text-muted">TZS <?= number_format($current_status['reserved_value']/1000000, 1) ?>M</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($current_status['sold']) ?></div>
                <div class="stats-label">Currently Sold</div>
                <small class="text-muted">TZS <?= number_format($current_status['sold_value']/1000000, 1) ?>M</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($current_status['blocked']) ?></div>
                <div class="stats-label">Currently Blocked</div>
                <small class="text-muted"><?= $stats['active_holds'] ?> active holds</small>
            </div>
        </div>
    </div>

    <!-- Movement Statistics -->
    <div class="row g-2 mb-3">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($stats['total_movements']) ?></div>
                <div class="stats-label">Total Movements</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['sales']) ?></div>
                <div class="stats-label">Sales</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['reservations']) ?></div>
                <div class="stats-label">Reservations</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['transfers']) ?></div>
                <div class="stats-label">Transfers</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['cancellations']) ?></div>
                <div class="stats-label">Cancellations</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card purple">
                <div class="stats-number"><?= number_format($stats['today_movements']) ?></div>
                <div class="stats-label">Today</div>
            </div>
        </div>
    </div>

    <!-- Status Flow Analysis (Last 30 Days) -->
    <div class="card mb-3">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-exchange-alt me-2"></i>Status Flow Analysis
                <small class="text-muted">(Last 30 Days)</small>
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <!-- Available to Reserved -->
                <div class="col-md-4">
                    <div class="flow-card border-warning">
                        <div class="text-muted small">AVAILABLE</div>
                        <div class="flow-arrow text-warning">↓</div>
                        <div class="flow-number text-warning"><?= $flows['available_to_reserved'] ?></div>
                        <div class="flow-label">To Reserved</div>
                        <?php if(isset($avg_time_in_status['reserved'])): ?>
                            <small class="text-muted">Avg: <?= round($avg_time_in_status['reserved']) ?> days in reserved</small>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reserved to Sold -->
                <div class="col-md-4">
                    <div class="flow-card border-success">
                        <div class="text-muted small">RESERVED</div>
                        <div class="flow-arrow text-success">↓</div>
                        <div class="flow-number text-success"><?= $flows['reserved_to_sold'] ?></div>
                        <div class="flow-label">To Sold</div>
                        <div class="mt-2">
                            <span class="badge bg-success">
                                Conversion: <?= $conversion_rates['reservation_to_sale'] ?>%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Available to Sold (Direct) -->
                <div class="col-md-4">
                    <div class="flow-card border-info">
                        <div class="text-muted small">AVAILABLE</div>
                        <div class="flow-arrow text-info">⇒</div>
                        <div class="flow-number text-info"><?= $flows['available_to_sold'] ?></div>
                        <div class="flow-label">Direct to Sold</div>
                        <small class="text-muted">Skipped reservation</small>
                    </div>
                </div>

                <!-- Reserved to Available (Cancellations) -->
                <div class="col-md-4">
                    <div class="flow-card border-danger">
                        <div class="text-muted small">RESERVED</div>
                        <div class="flow-arrow text-danger">↑</div>
                        <div class="flow-number text-danger"><?= $flows['reserved_to_available'] ?></div>
                        <div class="flow-label">Back to Available</div>
                        <div class="mt-2">
                            <span class="badge bg-danger">
                                Cancellation Rate: <?= $cancellation_rate ?>%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sold to Available (Returns) -->
                <div class="col-md-4">
                    <div class="flow-card border-warning">
                        <div class="text-muted small">SOLD</div>
                        <div class="flow-arrow text-warning">↑</div>
                        <div class="flow-number text-warning"><?= $flows['sold_to_available'] ?></div>
                        <div class="flow-label">Returns/Cancellations</div>
                        <small class="text-muted">Post-sale cancellations</small>
                    </div>
                </div>

                <!-- Blocked to Available (Releases) -->
                <div class="col-md-4">
                    <div class="flow-card border-secondary">
                        <div class="text-muted small">BLOCKED</div>
                        <div class="flow-arrow text-secondary">↑</div>
                        <div class="flow-number text-secondary"><?= $flows['blocked_to_available'] ?></div>
                        <div class="flow-label">Hold Releases</div>
                        <small class="text-muted">Released from holds</small>
                    </div>
                </div>
            </div>

            <!-- Average Time in Status -->
            <?php if (!empty($avg_time_in_status)): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <h6 class="text-muted mb-3">Average Time in Each Status</h6>
                        <div class="row g-2">
                            <?php foreach ($avg_time_in_status as $status => $days): ?>
                                <div class="col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <div class="fw-bold text-uppercase small text-muted"><?= $status ?></div>
                                        <div class="h4 mb-0"><?= round($days) ?> <small class="text-muted">days</small></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'movements' ? 'active' : '' ?>" 
               href="?tab=movements&period=<?= $time_period ?>">
                <i class="fas fa-history me-1"></i>All Movements
                <span class="badge bg-primary ms-1"><?= $stats['total_movements'] ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'flow_analysis' ? 'active' : '' ?>" 
               href="?tab=flow_analysis&period=<?= $time_period ?>">
                <i class="fas fa-chart-line me-1"></i>Flow Details
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'holds' ? 'active' : '' ?>" 
               href="?tab=holds&period=<?= $time_period ?>">
                <i class="fas fa-hand-paper me-1"></i>Active Holds
                <?php if ($stats['active_holds'] > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $stats['active_holds'] ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'transfers' ? 'active' : '' ?>" 
               href="?tab=transfers&period=<?= $time_period ?>">
                <i class="fas fa-exchange-alt me-1"></i>Transfers
                <?php if ($stats['pending_transfers'] > 0): ?>
                    <span class="badge bg-warning ms-1"><?= $stats['pending_transfers'] ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'movements'): ?>
        <!-- Filter Card -->
        <div class="card filter-card mb-3">
            <div class="card-body">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="movements">
                    <input type="hidden" name="period" value="<?= $time_period ?>">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted mb-1">PROJECT</label>
                        <select name="project_id" class="form-select form-select-sm">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['project_id'] ?>" 
                                        <?= ($_GET['project_id'] ?? '') == $p['project_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold text-muted mb-1">MOVEMENT TYPE</label>
                        <select name="movement_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="status_change" <?= ($_GET['movement_type'] ?? '') === 'status_change' ? 'selected' : '' ?>>Status Change</option>
                            <option value="reservation" <?= ($_GET['movement_type'] ?? '') === 'reservation' ? 'selected' : '' ?>>Reservation</option>
                            <option value="sale" <?= ($_GET['movement_type'] ?? '') === 'sale' ? 'selected' : '' ?>>Sale</option>
                            <option value="transfer" <?= ($_GET['movement_type'] ?? '') === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                            <option value="cancellation" <?= ($_GET['movement_type'] ?? '') === 'cancellation' ? 'selected' : '' ?>>Cancellation</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Apply Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="?tab=movements&period=<?= $time_period ?>" class="btn btn-outline-secondary btn-sm w-100">
                            <i class="fas fa-redo me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Movements Table -->
        <div class="card main-card">
            <div class="card-body">
                <?php if (empty($movements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No movements found for the selected period</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-professional table-hover" id="movementsTable">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Plot</th>
                                    <th>Project</th>
                                    <th>Type</th>
                                    <th>From Status</th>
                                    <th>To Status</th>
                                    <th>Customer</th>
                                    <th>Time in Status</th>
                                    <th>Reason</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $mv): ?>
                                    <tr>
                                        <td><?= date('d M Y H:i', strtotime($mv['movement_date'])) ?></td>
                                        <td>
                                            <div class="plot-info">
                                                <span class="plot-number">Plot <?= htmlspecialchars($mv['plot_number']) ?></span>
                                                <?php if ($mv['block_number']): ?>
                                                    <span class="plot-block">Block <?= htmlspecialchars($mv['block_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="project-code"><?= htmlspecialchars($mv['project_code'] ?: '') ?></span>
                                            <?= htmlspecialchars($mv['project_name'] ?: 'N/A') ?>
                                        </td>
                                        <td>
                                            <span class="movement-badge <?= $mv['movement_type'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $mv['movement_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($mv['previous_status']): ?>
                                                <span class="status-badge <?= $mv['previous_status'] ?>">
                                                    <?= ucfirst($mv['previous_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mv['new_status']): ?>
                                                <span class="status-badge <?= $mv['new_status'] ?>">
                                                    <?= ucfirst($mv['new_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mv['new_customer_name']): ?>
                                                <span class="text-success fw-bold">→ <?= htmlspecialchars($mv['new_customer_name']) ?></span>
                                            <?php elseif ($mv['previous_customer_name']): ?>
                                                <span class="text-danger" style="text-decoration: line-through;">
                                                    <?= htmlspecialchars($mv['previous_customer_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mv['days_in_status'] !== null && $mv['days_in_status'] > 0): ?>
                                                <span class="badge bg-secondary">
                                                    <?= $mv['days_in_status'] ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Current</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(substr($mv['reason'] ?: '-', 0, 50)) ?></td>
                                        <td><?= htmlspecialchars($mv['initiated_by_name'] ?: 'System') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- DataTables -->
                    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                    <script>
                    $(document).ready(function() {
                        $('#movementsTable').DataTable({
                            pageLength: 25,
                            order: [[0, 'desc']],
                            columnDefs: [
                                { targets: [0], type: 'date' }
                            ]
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($active_tab === 'flow_analysis'): ?>
        <!-- Flow Analysis Details -->
        <div class="card main-card">
            <div class="card-body">
                <h5 class="mb-3">
                    <i class="fas fa-project-diagram me-2"></i>Detailed Flow Analysis
                    <small class="text-muted">(<?= ucfirst(str_replace('days', ' days', $time_period)) ?>)</small>
                </h5>
                
                <?php if (empty($flow_details)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <p>No status changes found for the selected period</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-professional table-hover" id="flowTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Plot</th>
                                    <th>Project</th>
                                    <th>From Status</th>
                                    <th>To Status</th>
                                    <th>Days in Previous</th>
                                    <th>Flow Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flow_details as $flow): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($flow['movement_date'])) ?></td>
                                        <td>
                                            <div class="plot-info">
                                                <span class="plot-number">Plot <?= htmlspecialchars($flow['plot_number']) ?></span>
                                                <?php if ($flow['block_number']): ?>
                                                    <span class="plot-block">Block <?= htmlspecialchars($flow['block_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="project-code"><?= htmlspecialchars($flow['project_code'] ?: '') ?></span>
                                            <?= htmlspecialchars($flow['project_name'] ?: 'N/A') ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $flow['previous_status'] ?>">
                                                <?= ucfirst($flow['previous_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $flow['new_status'] ?>">
                                                <?= ucfirst($flow['new_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($flow['days_in_previous_status']): ?>
                                                <span class="badge bg-info"><?= $flow['days_in_previous_status'] ?> days</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $prev = strtolower($flow['previous_status']);
                                            $new = strtolower($flow['new_status']);
                                            if ($prev === 'available' && $new === 'reserved') {
                                                echo '<span class="badge bg-warning">New Reservation</span>';
                                            } elseif ($prev === 'reserved' && $new === 'sold') {
                                                echo '<span class="badge bg-success">Successful Sale</span>';
                                            } elseif ($prev === 'available' && $new === 'sold') {
                                                echo '<span class="badge bg-info">Direct Sale</span>';
                                            } elseif ($prev === 'reserved' && $new === 'available') {
                                                echo '<span class="badge bg-danger">Cancellation</span>';
                                            } elseif ($prev === 'sold' && $new === 'available') {
                                                echo '<span class="badge bg-warning">Return</span>';
                                            } elseif ($new === 'blocked') {
                                                echo '<span class="badge bg-dark">Hold Applied</span>';
                                            } elseif ($prev === 'blocked') {
                                                echo '<span class="badge bg-secondary">Hold Released</span>';
                                            } else {
                                                echo '<span class="badge bg-light text-dark">Other</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                    $(document).ready(function() {
                        $('#flowTable').DataTable({
                            pageLength: 25,
                            order: [[0, 'desc']]
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($active_tab === 'holds'): ?>
        <!-- Active Holds Table -->
        <div class="card main-card">
            <div class="card-body">
                <?php if (empty($holds)): ?>
                    <div class="empty-state">
                        <i class="fas fa-hand-paper"></i>
                        <p>No active holds</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-professional table-hover" id="holdsTable">
                            <thead>
                                <tr>
                                    <th>Plot</th>
                                    <th>Project</th>
                                    <th>Hold Type</th>
                                    <th>Reason</th>
                                    <th>Start Date</th>
                                    <th>Expected Release</th>
                                    <th>Hold Duration</th>
                                    <th>Priority</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($holds as $hold): 
                                    $duration = $hold['expected_release_date'] ? 
                                        (strtotime($hold['expected_release_date']) - strtotime($hold['hold_start_date'])) / 86400 : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="plot-info">
                                                <span class="plot-number">Plot <?= htmlspecialchars($hold['plot_number']) ?></span>
                                                <?php if ($hold['block_number']): ?>
                                                    <span class="plot-block">Block <?= htmlspecialchars($hold['block_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="project-code"><?= htmlspecialchars($hold['project_code'] ?: '') ?></span>
                                            <?= htmlspecialchars($hold['project_name'] ?: 'N/A') ?>
                                        </td>
                                        <td><?= ucfirst(str_replace('_', ' ', $hold['hold_type'])) ?></td>
                                        <td><?= htmlspecialchars(substr($hold['hold_reason'], 0, 50)) ?></td>
                                        <td><?= date('d M Y', strtotime($hold['hold_start_date'])) ?></td>
                                        <td>
                                            <?= $hold['expected_release_date'] ? 
                                                date('d M Y', strtotime($hold['expected_release_date'])) : 
                                                '<span class="badge bg-secondary">TBD</span>' ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= $hold['actual_hold_days'] ?> days
                                            </span>
                                        </td>
                                        <td>
                                            <span class="priority-badge <?= $hold['priority'] ?>">
                                                <?= ucfirst($hold['priority']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-success action-btn"
                                                    onclick="releaseHold(<?= $hold['hold_id'] ?>, <?= $hold['plot_id'] ?>, '<?= htmlspecialchars(addslashes($hold['plot_number'])) ?>')"
                                                    title="Release Hold">
                                                <i class="fas fa-unlock"></i> Release
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                    $(document).ready(function() {
                        $('#holdsTable').DataTable({
                            pageLength: 25,
                            order: [[4, 'desc']]
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($active_tab === 'transfers'): ?>
        <!-- Transfers Table -->
        <div class="card main-card">
            <div class="card-body">
                <?php if (empty($transfers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <p>No transfer requests</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-professional table-hover" id="transfersTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Plot</th>
                                    <th>Project</th>
                                    <th>Type</th>
                                    <th>From Customer</th>
                                    <th>To Customer</th>
                                    <th class="text-end">Transfer Fee</th>
                                    <th>Status</th>
                                    <th>Initiated By</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $tr): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($tr['transfer_date'])) ?></td>
                                        <td>
                                            <div class="plot-info">
                                                <span class="plot-number">Plot <?= htmlspecialchars($tr['plot_number']) ?></span>
                                                <?php if ($tr['block_number']): ?>
                                                    <span class="plot-block">Block <?= htmlspecialchars($tr['block_number']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="project-code"><?= htmlspecialchars($tr['project_code'] ?: '') ?></span>
                                            <?= htmlspecialchars($tr['project_name'] ?: 'N/A') ?>
                                        </td>
                                        <td><?= ucfirst(str_replace('_', ' ', $tr['transfer_type'])) ?></td>
                                        <td>
                                            <?php if ($tr['from_customer_name']): ?>
                                                <div><?= htmlspecialchars($tr['from_customer_name']) ?></div>
                                                <?php if ($tr['from_customer_phone']): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($tr['from_customer_phone']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success"><?= htmlspecialchars($tr['to_customer_name']) ?></div>
                                            <?php if ($tr['to_customer_phone']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($tr['to_customer_phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= number_format($tr['transfer_fee'], 0) ?></td>
                                        <td>
                                            <span class="status-badge <?= $tr['approval_status'] ?>">
                                                <?= ucfirst($tr['approval_status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($tr['initiated_by_name']) ?></td>
                                        <td class="text-center">
                                            <?php if ($tr['approval_status'] === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success action-btn"
                                                        onclick="approveTransfer(<?= $tr['transfer_id'] ?>)"
                                                        title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger action-btn"
                                                        onclick="rejectTransfer(<?= $tr['transfer_id'] ?>)"
                                                        title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                    $(document).ready(function() {
                        $('#transfersTable').DataTable({
                            pageLength: 25,
                            order: [[0, 'desc']]
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modals (Add Movement, Add Hold, Add Transfer, Release Hold) -->
<!-- [Previous modal code remains the same - truncated for brevity] -->

<!-- Add Movement Modal -->
<div class="modal fade" id="addMovementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Movement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_movement">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plot <span class="text-danger">*</span></label>
                            <select name="plot_id" id="movement_plot_id" class="form-select" required onchange="updatePlotProject(this.value, 'movement')">
                                <option value="">Select Plot</option>
                                <?php foreach ($all_plots as $plot): ?>
                                    <option value="<?= $plot['plot_id'] ?>" data-project="<?= $plot['project_id'] ?? '' ?>">
                                        Plot <?= htmlspecialchars($plot['plot_number']) ?>
                                        <?= $plot['block_number'] ? ' - Block ' . htmlspecialchars($plot['block_number']) : '' ?>
                                        (<?= htmlspecialchars($plot['project_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Movement Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="movement_date" class="form-control" 
                                   value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <input type="hidden" name="project_id" id="movement_project_id">
                        <div class="col-md-6">
                            <label class="form-label">Movement Type <span class="text-danger">*</span></label>
                            <select name="movement_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="status_change">Status Change</option>
                                <option value="reservation">Reservation</option>
                                <option value="sale">Sale</option>
                                <option value="cancellation">Cancellation</option>
                                <option value="transfer">Transfer</option>
                                <option value="hold">Hold</option>
                                <option value="release">Release</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Previous Status</label>
                            <select name="previous_status" class="form-select">
                                <option value="">-</option>
                                <option value="available">Available</option>
                                <option value="reserved">Reserved</option>
                                <option value="sold">Sold</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">New Status</label>
                            <select name="new_status" class="form-select">
                                <option value="">-</option>
                                <option value="available">Available</option>
                                <option value="reserved">Reserved</option>
                                <option value="sold">Sold</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Previous Customer</label>
                            <select name="previous_customer_id" class="form-select">
                                <option value="">-</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Customer</label>
                            <select name="new_customer_id" class="form-select">
                                <option value="">-</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Additional Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Record Movement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Hold Modal -->
<div class="modal fade" id="addHoldModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Plot Hold</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_hold">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plot <span class="text-danger">*</span></label>
                            <select name="plot_id" class="form-select" required onchange="updatePlotProject(this.value, 'hold')">
                                <option value="">Select Plot</option>
                                <?php foreach ($all_plots as $plot): ?>
                                    <?php if ($plot['status'] === 'available'): ?>
                                        <option value="<?= $plot['plot_id'] ?>" data-project="<?= $plot['project_id'] ?? '' ?>">
                                            Plot <?= htmlspecialchars($plot['plot_number']) ?>
                                            <?= $plot['block_number'] ? ' - Block ' . htmlspecialchars($plot['block_number']) : '' ?>
                                            (<?= htmlspecialchars($plot['project_name']) ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hold Type <span class="text-danger">*</span></label>
                            <select name="hold_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="customer_interest">Customer Interest</option>
                                <option value="internal_review">Internal Review</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="legal_issue">Legal Issue</option>
                                <option value="dispute">Dispute</option>
                                <option value="management_hold">Management Hold</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <input type="hidden" name="project_id" id="hold_project_id">
                        <div class="col-md-6">
                            <label class="form-label">Hold Start Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="hold_start_date" class="form-control" 
                                   value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected Release Date</label>
                            <input type="date" name="expected_release_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer (if applicable)</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Hold Fee (TZS)</label>
                            <input type="number" name="hold_fee" class="form-control" value="0" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Priority <span class="text-danger">*</span></label>
                            <select name="priority" class="form-select" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Hold Reason <span class="text-danger">*</span></label>
                            <textarea name="hold_reason" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_release" id="auto_release" checked>
                                <label class="form-check-label" for="auto_release">
                                    Auto-release when expected date passes
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create Hold
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Transfer Modal -->
<div class="modal fade" id="addTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Plot Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_transfer">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plot <span class="text-danger">*</span></label>
                            <select name="plot_id" class="form-select" required onchange="updatePlotProject(this.value, 'transfer')">
                                <option value="">Select Plot</option>
                                <?php foreach ($all_plots as $plot): ?>
                                    <option value="<?= $plot['plot_id'] ?>" data-project="<?= $plot['project_id'] ?? '' ?>">
                                        Plot <?= htmlspecialchars($plot['plot_number']) ?>
                                        <?= $plot['block_number'] ? ' - Block ' . htmlspecialchars($plot['block_number']) : '' ?>
                                        (<?= htmlspecialchars($plot['project_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transfer Type <span class="text-danger">*</span></label>
                            <select name="transfer_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="customer_change">Customer Change</option>
                                <option value="ownership_transfer">Ownership Transfer</option>
                                <option value="reassignment">Reassignment</option>
                                <option value="swap">Plot Swap</option>
                                <option value="upgrade">Upgrade</option>
                                <option value="downgrade">Downgrade</option>
                            </select>
                        </div>
                        <input type="hidden" name="project_id" id="transfer_project_id">
                        <div class="col-md-6">
                            <label class="form-label">Transfer Date <span class="text-danger">*</span></label>
                            <input type="date" name="transfer_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transfer Fee (TZS)</label>
                            <input type="number" name="transfer_fee" class="form-control" value="0" step="0.01" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From Customer</label>
                            <select name="from_customer_id" class="form-select">
                                <option value="">-</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Customer <span class="text-danger">*</span></label>
                            <select name="to_customer_id" class="form-select" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Price Adjustment (TZS)</label>
                            <input type="number" name="price_adjustment" class="form-control" value="0" step="0.01">
                            <small class="text-muted">Positive for increase, negative for decrease</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Transfer Reason</label>
                            <textarea name="transfer_reason" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Request Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Release Hold Modal -->
<div class="modal fade" id="releaseHoldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Release Plot Hold</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="release_hold">
                <input type="hidden" name="hold_id" id="release_hold_id">
                <input type="hidden" name="plot_id" id="release_plot_id">
                <div class="modal-body">
                    <p>Release hold on <strong id="release_plot_number"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label">Release Reason</label>
                        <textarea name="release_reason" class="form-control" rows="3" 
                                  placeholder="Optional reason for releasing the hold"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-unlock me-1"></i>Release Hold
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updatePlotProject(plotId, prefix) {
    const select = document.querySelector(`select[name="plot_id"][onchange*="${prefix}"]`);
    const selectedOption = select.options[select.selectedIndex];
    const projectId = selectedOption.getAttribute('data-project');
    document.getElementById(`${prefix}_project_id`).value = projectId || '';
}

function releaseHold(holdId, plotId, plotNumber) {
    document.getElementById('release_hold_id').value = holdId;
    document.getElementById('release_plot_id').value = plotId;
    document.getElementById('release_plot_number').textContent = 'Plot ' + plotNumber;
    
    new bootstrap.Modal(document.getElementById('releaseHoldModal')).show();
}

function approveTransfer(transferId) {
    if (confirm('Approve this transfer request?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve_transfer';
        form.appendChild(actionInput);
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'transfer_id';
        idInput.value = transferId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectTransfer(transferId) {
    const reason = prompt('Rejection reason:');
    if (reason !== null && reason.trim() !== '') {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'reject_transfer';
        form.appendChild(actionInput);
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'transfer_id';
        idInput.value = transferId;
        form.appendChild(idInput);
        
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'rejection_reason';
        reasonInput.value = reason;
        form.appendChild(reasonInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>