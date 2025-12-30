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

// Helper function to safely format numbers
function safe_format($number, $decimals = 0) {
    return number_format((float)$number ?: 0, $decimals);
}

// Calculate statistics
try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_reservations,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                    COALESCE(SUM(total_amount), 0) as total_revenue
                  FROM reservations
                  WHERE company_id = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute([$company_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    $stats = [
        'total_reservations' => 0,
        'active_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'total_revenue' => 0
    ];
}

// Fetch all reservations
try {
    $sql = "SELECT r.*,
                   c.full_name as customer_name,
                   c.phone as customer_phone,
                   p.plot_number,
                   p.block_number,
                   pr.project_name,
                   COALESCE((
                       SELECT SUM(amount) 
                       FROM payments 
                       WHERE reservation_id = r.reservation_id 
                         AND status = 'approved'
                   ), 0) as total_paid
            FROM reservations r
            LEFT JOIN customers c ON r.customer_id = c.customer_id
            LEFT JOIN plots p ON r.plot_id = p.plot_id
            LEFT JOIN projects pr ON p.project_id = pr.project_id
            WHERE r.company_id = ?
            ORDER BY r.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching reservations: " . $e->getMessage());
    $reservations = [];
}

$page_title = 'Reservations / Sales';
require_once '../../includes/header.php';
?>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-shopping-cart"></i> Reservations / Sales</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active">Reservations</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="icon fas fa-check"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="icon fas fa-ban"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Info boxes -->
        <div class="row">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-info elevation-1"><i class="fas fa-file-contract"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Reservations</span>
                        <span class="info-box-number"><?php echo safe_format($stats['total_reservations']); ?></span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-success elevation-1"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active</span>
                        <span class="info-box-number"><?php echo safe_format($stats['active_count']); ?></span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-clipboard-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo safe_format($stats['completed_count']); ?></span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <div class="info-box mb-3">
                    <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-coins"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Revenue</span>
                        <span class="info-box-number">
                            <?php echo safe_format($stats['total_revenue']); ?> <small>TZS</small>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main card -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">All Reservations</h3>
                <div class="card-tools">
                    <a href="create.php" class="btn btn-success btn-sm">
                        <i class="fas fa-plus-circle"></i> New Reservation
                    </a>
                </div>
            </div>
            <div class="card-body">
                <table id="reservationsTable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Reservation #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Plot</th>
                            <th>Project</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): 
                            $total_amount = (float)($reservation['total_amount'] ?? 0);
                            $total_paid   = (float)($reservation['total_paid'] ?? 0);
                            $balance      = $total_amount - $total_paid;
                            $progress     = $total_amount > 0 ? ($total_paid / $total_amount) * 100 : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($reservation['reservation_number'] ?? 'N/A'); ?></strong></td>
                                <td data-order="<?php echo strtotime($reservation['reservation_date'] ?? 'now'); ?>">
                                    <?php echo $reservation['reservation_date'] ? date('d M Y', strtotime($reservation['reservation_date'])) : 'N/A'; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($reservation['customer_name'] ?? 'Unknown'); ?>
                                    <?php if (!empty($reservation['customer_phone'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($reservation['customer_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>Plot <?php echo htmlspecialchars($reservation['plot_number'] ?? 'N/A'); ?></strong>
                                    <?php if (!empty($reservation['block_number'])): ?>
                                        <br><small class="text-muted">Block <?php echo htmlspecialchars($reservation['block_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($reservation['project_name'] ?? 'Unknown'); ?></td>
                                <td data-order="<?php echo $total_amount; ?>">
                                    TZS <?php echo safe_format($total_amount); ?>
                                </td>
                                <td data-order="<?php echo $total_paid; ?>" class="text-success">
                                    TZS <?php echo safe_format($total_paid); ?>
                                </td>
                                <td data-order="<?php echo $balance; ?>" class="text-danger">
                                    TZS <?php echo safe_format($balance); ?>
                                </td>
                                <td>
                                    <div class="progress progress-xs">
                                        <div class="progress-bar <?php echo $progress >= 100 ? 'bg-success' : 'bg-warning'; ?>" 
                                             style="width: <?php echo min(100, $progress); ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($progress, 1); ?>%</small>
                                </td>
                                <td>
                                    <?php
                                    $status = strtolower($reservation['status'] ?? 'draft');
                                    $badge = match($status) {
                                        'active'     => 'badge-success',
                                        'completed'  => 'badge-primary',
                                        'cancelled'  => 'badge-danger',
                                        'draft'      => 'badge-warning',
                                        default      => 'badge-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $badge; ?>">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="view.php?id=<?php echo $reservation['reservation_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $reservation['reservation_id']; ?>" 
                                           class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="../payments/create.php?reservation_id=<?php echo $reservation['reservation_id']; ?>" 
                                           class="btn btn-success btn-sm" title="Add Payment"><i class="fas fa-plus"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
$(function () {
    $("#reservationsTable").DataTable({
        "responsive": true,
        "lengthChange": true,
        "autoWidth": false,
        "buttons": ["copy", "csv", "excel", "pdf", "print"],
        "pageLength": 25,
        "order": [[1, "desc"]],
        "columnDefs": [{ "orderable": false, "targets": 10 }]
    }).buttons().container().appendTo('#reservationsTable_wrapper .col-md-6:eq(0)');
});
</script>

<?php require_once '../../includes/footer.php'; ?>