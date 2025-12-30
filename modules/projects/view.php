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

// Get project ID from URL
$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$project_id) {
    header('Location: index.php');
    exit;
}

// Fetch project details
try {
    $sql = "SELECT p.*, 
            u.full_name as created_by_name
            FROM projects p
            LEFT JOIN users u ON p.created_by = u.user_id
            WHERE p.project_id = ? AND p.company_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: index.php');
        exit;
    }

    // Fetch project seller/owner information
    $seller_sql = "SELECT * FROM project_sellers 
                   WHERE project_id = ? AND company_id = ?";
    $seller_stmt = $conn->prepare($seller_sql);
    $seller_stmt->execute([$project_id, $company_id]);
    $seller = $seller_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch plots statistics
    $plots_sql = "SELECT 
                  COALESCE(COUNT(*), 0) as total_plots,
                  COALESCE(SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END), 0) as available_plots,
                  COALESCE(SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END), 0) as reserved_plots,
                  COALESCE(SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END), 0) as sold_plots,
                  COALESCE(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END), 0) as blocked_plots,
                  COALESCE(SUM(area), 0) as total_used_area
                  FROM plots 
                  WHERE project_id = ? AND company_id = ?";
    $plots_stmt = $conn->prepare($plots_sql);
    $plots_stmt->execute([$project_id, $company_id]);
    $plots_stats = $plots_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch ALL project costs (both approved and pending)
    $costs_sql = "SELECT 
                  COALESCE(SUM(cost_amount), 0) as total_all_costs,
                  COALESCE(SUM(CASE WHEN approved_by IS NOT NULL THEN cost_amount ELSE 0 END), 0) as total_approved_costs,
                  COALESCE(SUM(CASE WHEN approved_by IS NULL THEN cost_amount ELSE 0 END), 0) as total_pending_costs,
                  COALESCE(COUNT(*), 0) as total_cost_items,
                  COALESCE(COUNT(CASE WHEN approved_by IS NOT NULL THEN 1 END), 0) as approved_count,
                  COALESCE(COUNT(CASE WHEN approved_by IS NULL THEN 1 END), 0) as pending_count
                  FROM project_costs 
                  WHERE project_id = ? AND company_id = ?";
    $costs_stmt = $conn->prepare($costs_sql);
    $costs_stmt->execute([$project_id, $company_id]);
    $costs_stats = $costs_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch detailed cost breakdown by category
    $cost_breakdown_sql = "SELECT 
                          cost_category,
                          COUNT(*) as item_count,
                          SUM(cost_amount) as category_total,
                          SUM(CASE WHEN approved_by IS NOT NULL THEN cost_amount ELSE 0 END) as approved_total,
                          SUM(CASE WHEN approved_by IS NULL THEN cost_amount ELSE 0 END) as pending_total
                          FROM project_costs
                          WHERE project_id = ? AND company_id = ?
                          GROUP BY cost_category
                          ORDER BY category_total DESC";
    $cost_breakdown_stmt = $conn->prepare($cost_breakdown_sql);
    $cost_breakdown_stmt->execute([$project_id, $company_id]);
    $cost_breakdown = $cost_breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch ACTUAL REVENUE from payments in this project
    $revenue_sql = "SELECT 
                    COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.amount ELSE 0 END), 0) as total_actual_revenue,
                    COALESCE(SUM(CASE WHEN p.status = 'pending_approval' THEN p.amount ELSE 0 END), 0) as pending_revenue,
                    COALESCE(COUNT(CASE WHEN p.status = 'approved' THEN 1 END), 0) as approved_payment_count,
                    COALESCE(COUNT(CASE WHEN p.status = 'pending_approval' THEN 1 END), 0) as pending_payment_count
                    FROM payments p
                    INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                    INNER JOIN plots pl ON r.plot_id = pl.plot_id
                    WHERE pl.project_id = ? AND p.company_id = ?";
    $revenue_stmt = $conn->prepare($revenue_sql);
    $revenue_stmt->execute([$project_id, $company_id]);
    $revenue_stats = $revenue_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch expected revenue from ALL PLOTS in the project (not just reservations)
    $expected_revenue_sql = "SELECT 
                            COALESCE(SUM(selling_price), 0) as total_expected_revenue,
                            COALESCE(COUNT(*), 0) as total_plots_count,
                            COALESCE(COUNT(CASE WHEN status = 'available' THEN 1 END), 0) as available_plots_count,
                            COALESCE(COUNT(CASE WHEN status = 'reserved' THEN 1 END), 0) as reserved_plots_count,
                            COALESCE(COUNT(CASE WHEN status = 'sold' THEN 1 END), 0) as sold_plots_count
                            FROM plots
                            WHERE project_id = ? AND company_id = ?";
    $expected_revenue_stmt = $conn->prepare($expected_revenue_sql);
    $expected_revenue_stmt->execute([$project_id, $company_id]);
    $expected_revenue_stats = $expected_revenue_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch reservation stats for additional context
    $reservation_stats_sql = "SELECT 
                            COALESCE(COUNT(*), 0) as total_reservations,
                            COALESCE(COUNT(CASE WHEN r.status = 'active' THEN 1 END), 0) as active_reservations,
                            COALESCE(COUNT(CASE WHEN r.status = 'completed' THEN 1 END), 0) as completed_reservations
                            FROM reservations r
                            INNER JOIN plots pl ON r.plot_id = pl.plot_id
                            WHERE pl.project_id = ? AND r.company_id = ?";
    $reservation_stats_stmt = $conn->prepare($reservation_stats_sql);
    $reservation_stats_stmt->execute([$project_id, $company_id]);
    $reservation_stats = $reservation_stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate comprehensive financial metrics
    $land_purchase_price = $project['land_purchase_price'] ?? 0;
    $total_all_costs = $costs_stats['total_all_costs'] ?? 0; // All operational costs (approved + pending)
    $total_approved_costs = $costs_stats['total_approved_costs'] ?? 0;
    
    // Total Investment = Land Purchase + ALL Operational Costs (both approved and pending)
    $total_investment = $land_purchase_price + $total_all_costs;
    $total_approved_investment = $land_purchase_price + $total_approved_costs; // Only approved costs
    
    $actual_revenue = $revenue_stats['total_actual_revenue'] ?? 0;
    $pending_revenue = $revenue_stats['pending_revenue'] ?? 0;
    
    // Expected Revenue = Sum of ALL plots selling prices in the project
    $expected_revenue = $expected_revenue_stats['total_expected_revenue'] ?? 0;
    
    // Actual Profit = Actual Revenue - Approved Investment
    $actual_profit = $actual_revenue - $total_approved_investment;
    
    // Expected Profit = Expected Revenue (all plots) - Total Investment (land + all costs)
    $expected_profit = $expected_revenue - $total_investment;
    
    $actual_profit_percentage = $total_approved_investment > 0 ? ($actual_profit / $total_approved_investment) * 100 : 0;
    $expected_profit_percentage = $total_investment > 0 ? ($expected_profit / $total_investment) * 100 : 0;
    
    $remaining_area = ($project['total_area'] ?? 0) - ($plots_stats['total_used_area'] ?? 0);

} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'View Project - ' . $project['project_name'];
require_once '../../includes/header.php';
?>

<style>
.info-card {
    background: #fff;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.info-card-header {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.info-card-header i {
    margin-right: 0.4rem;
    color: #007bff;
    font-size: 0.9rem;
}

.info-item {
    display: flex;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f8f9fa;
    font-size: 0.85rem;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #495057;
    min-width: 180px;
}

.info-value {
    color: #212529;
    flex: 1;
}

.status-badge {
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-planning { background: #fff3cd; color: #856404; }
.status-active { background: #d1ecf1; color: #0c5460; }
.status-completed { background: #d4edda; color: #155724; }
.status-suspended { background: #f8d7da; color: #721c24; }

.stat-card {
    background: #fff;
    color: #2c3e50;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stat-card.primary { border-left-color: #007bff; }
.stat-card.success { border-left-color: #28a745; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.info { border-left-color: #17a2b8; }
.stat-card.danger { border-left-color: #dc3545; }
.stat-card.purple { border-left-color: #6f42c1; }

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    font-weight: 600;
}

.financial-metric {
    background: #f8f9fa;
    border-left: 3px solid #007bff;
    padding: 0.75rem;
    border-radius: 4px;
    margin-bottom: 0.75rem;
}

.metric-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.15rem;
    text-transform: uppercase;
    font-weight: 600;
}

.metric-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #212529;
}

.metric-sublabel {
    font-size: 0.7rem;
    color: #6c757d;
    margin-top: 0.15rem;
}

.alert-costs {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    font-size: 0.85rem;
}

.cost-category-item {
    background: #f8f9fa;
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    border-left: 2px solid #007bff;
}

.cost-category-name {
    font-weight: 600;
    color: #2c3e50;
    text-transform: capitalize;
}

.cost-category-amount {
    font-weight: 700;
    color: #007bff;
}

.content-header h1 {
    font-size: 1.4rem;
}
</style>

<div class="content-header mb-3">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-eye text-primary"></i> <?php echo htmlspecialchars($project['project_name']); ?>
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($project['project_code']); ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="edit.php?id=<?php echo $project_id; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="costs.php?project_id=<?php echo $project_id; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-dollar-sign"></i> Costs
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-2 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($project['total_area'], 0); ?> m²</div>
                    <div class="stat-label">Total Area</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo number_format($plots_stats['total_plots']); ?></div>
                    <div class="stat-label">Total Plots</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card success">
                    <div class="stat-value"><?php echo number_format($plots_stats['available_plots']); ?></div>
                    <div class="stat-label">Available</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($plots_stats['sold_plots']); ?></div>
                    <div class="stat-label">Sold Plots</div>
                </div>
            </div>
        </div>

        <!-- Pending Costs Alert -->
        <?php if ($costs_stats['pending_count'] > 0): ?>
        <div class="alert-costs">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Pending Costs:</strong> <?php echo $costs_stats['pending_count']; ?> cost(s) totaling 
            TSH <?php echo number_format($costs_stats['total_pending_costs'], 0); ?> awaiting approval. 
            <a href="costs.php?project_id=<?php echo $project_id; ?>" class="alert-link">View costs</a>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column -->
            <div class="col-md-6">

                <!-- Basic Information -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-info-circle"></i>Basic Information</span>
                        <span class="status-badge status-<?php echo strtolower($project['status']); ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Project Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['project_name']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Project Code:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['project_code']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Description:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['description'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Acquisition Date:</div>
                        <div class="info-value">
                            <?php echo $project['acquisition_date'] ? date('d M Y', strtotime($project['acquisition_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Closing Date:</div>
                        <div class="info-value">
                            <?php echo $project['closing_date'] ? date('d M Y', strtotime($project['closing_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Created By:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['created_by_name'] ?? 'N/A'); ?></div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-map-marker-alt"></i>Location</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Region:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['region_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">District:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['district_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Ward:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['ward_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Village:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['village_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Physical Location:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['physical_location'] ?? 'N/A'); ?></div>
                    </div>
                </div>

                <!-- Project Owner -->
                <?php if ($seller): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-handshake"></i>Project Owner/Seller</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Owner Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_name']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_phone'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">NIDA:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_nida'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">TIN:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_tin'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Purchase Date:</div>
                        <div class="info-value">
                            <?php echo $seller['purchase_date'] ? date('d M Y', strtotime($seller['purchase_date'])) : 'N/A'; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Right Column -->
            <div class="col-md-6">

                <!-- Financial Summary -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-chart-line"></i>Financial Summary</span>
                    </div>

                    <div class="financial-metric" style="border-left-color: #dc3545;">
                        <div class="metric-label">Land Purchase Price</div>
                        <div class="metric-value">TSH <?php echo number_format($land_purchase_price, 0); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #ffc107;">
                        <div class="metric-label">Operational Costs (All)</div>
                        <div class="metric-value">TSH <?php echo number_format($total_all_costs, 0); ?></div>
                        <div class="metric-sublabel">
                            Approved: TSH <?php echo number_format($total_approved_costs, 0); ?>
                            <?php if ($costs_stats['pending_count'] > 0): ?>
                                | Pending: TSH <?php echo number_format($costs_stats['total_pending_costs'], 0); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #6f42c1;">
                        <div class="metric-label">Total Investment</div>
                        <div class="metric-value">TSH <?php echo number_format($total_investment, 0); ?></div>
                        <div class="metric-sublabel">Land + All Costs (<?php echo $costs_stats['total_cost_items']; ?> items)</div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #28a745;">
                        <div class="metric-label">Actual Revenue Received</div>
                        <div class="metric-value">TSH <?php echo number_format($actual_revenue, 0); ?></div>
                        <div class="metric-sublabel">
                            From <?php echo $revenue_stats['approved_payment_count']; ?> approved payment(s)
                            <?php if ($pending_revenue > 0): ?>
                                | Pending: TSH <?php echo number_format($pending_revenue, 0); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #17a2b8;">
                        <div class="metric-label">Expected Revenue (All Plots)</div>
                        <div class="metric-value">TSH <?php echo number_format($expected_revenue, 0); ?></div>
                        <div class="metric-sublabel">
                            Total from <?php echo $expected_revenue_stats['total_plots_count']; ?> plot(s) 
                            (<?php echo $expected_revenue_stats['available_plots_count']; ?> available, 
                            <?php echo $expected_revenue_stats['reserved_plots_count']; ?> reserved, 
                            <?php echo $expected_revenue_stats['sold_plots_count']; ?> sold)
                        </div>
                    </div>

                    <div class="financial-metric" style="border-left-color: <?php echo $actual_profit >= 0 ? '#28a745' : '#dc3545'; ?>;">
                        <div class="metric-label">Actual Profit/Loss (To Date)</div>
                        <div class="metric-value" style="color: <?php echo $actual_profit >= 0 ? '#28a745' : '#dc3545'; ?>">
                            TSH <?php echo number_format($actual_profit, 0); ?>
                        </div>
                        <div class="metric-sublabel">
                            Margin: <?php echo number_format($actual_profit_percentage, 2); ?>% 
                            (Revenue received vs approved investment)
                        </div>
                    </div>

                    <div class="financial-metric" style="border-left-color: <?php echo $expected_profit >= 0 ? '#007bff' : '#dc3545'; ?>;">
                        <div class="metric-label">Expected Profit (Full Project)</div>
                        <div class="metric-value" style="color: <?php echo $expected_profit >= 0 ? '#007bff' : '#dc3545'; ?>">
                            TSH <?php echo number_format($expected_profit, 0); ?>
                        </div>
                        <div class="metric-sublabel">
                            Expected Margin: <?php echo number_format($expected_profit_percentage, 2); ?>%
                            (All plots value vs total investment)
                        </div>
                    </div>
                </div>

                <!-- Cost Breakdown -->
                <?php if (!empty($cost_breakdown)): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-list"></i>Cost Breakdown by Category</span>
                    </div>
                    <?php foreach ($cost_breakdown as $category): ?>
                    <div class="cost-category-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="cost-category-name">
                                <?php echo ucwords(str_replace('_', ' ', $category['cost_category'])); ?>
                                (<?php echo $category['item_count']; ?> items)
                            </div>
                            <div class="cost-category-amount">
                                TSH <?php echo number_format($category['category_total'], 0); ?>
                            </div>
                        </div>
                        <?php if ($category['pending_total'] > 0): ?>
                        <div class="text-muted" style="font-size: 0.7rem; margin-top: 0.2rem;">
                            Approved: TSH <?php echo number_format($category['approved_total'], 0); ?> | 
                            Pending: TSH <?php echo number_format($category['pending_total'], 0); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Area Utilization -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-chart-pie"></i>Area Utilization</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Total Area:</div>
                        <div class="info-value"><?php echo number_format($project['total_area'], 2); ?> m²</div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Used Area:</div>
                        <div class="info-value"><?php echo number_format($plots_stats['total_used_area'], 2); ?> m²</div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Remaining Area:</div>
                        <div class="info-value"><?php echo number_format($remaining_area, 2); ?> m²</div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row">
            <div class="col-12">
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <a href="edit.php?id=<?php echo $project_id; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i> Edit Project
                            </a>
                            <a href="costs.php?project_id=<?php echo $project_id; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-dollar-sign"></i> Manage Costs
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete()">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this project? This will delete all associated plots and data.')) {
        window.location.href = 'delete.php?id=<?php echo $project_id; ?>';
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>