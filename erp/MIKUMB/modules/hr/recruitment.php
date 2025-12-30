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

// This page would track job openings and applications
// For now, we'll create a simplified version showing positions to recruit for

// Fetch recruitment statistics
$stats = [
    'open_positions' => 0,
    'total_applications' => 0,
    'interviews_scheduled' => 0,
    'offers_made' => 0
];

// Since we don't have a full recruitment table, we'll show positions that need filling
try {
    $positions_query = "
        SELECT 
            p.*,
            d.department_name,
            COUNT(e.employee_id) as current_count
        FROM positions p
        LEFT JOIN departments d ON p.department_id = d.department_id
        LEFT JOIN employees e ON e.position_id = p.position_id AND e.employment_status = 'active'
        WHERE p.company_id = ? AND p.is_active = 1
        GROUP BY p.position_id
        ORDER BY d.department_name, p.position_title
    ";
    
    $stmt = $conn->prepare($positions_query);
    $stmt->execute([$company_id]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count positions that might need recruitment
    $stats['open_positions'] = count($positions);
    
} catch (PDOException $e) {
    error_log("Error fetching positions: " . $e->getMessage());
    $positions = [];
}

$page_title = 'Recruitment';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
    transition: all 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-3px);
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
}

.stats-label {
    font-size: 0.85rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.position-card {
    background: white;
    border-radius: 12px;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 5px solid #007bff;
    transition: all 0.3s ease;
}

.position-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}

.position-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #212529;
    margin-bottom: 0.5rem;
}

.department-badge {
    background: #e3f2fd;
    color: #1976d2;
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.salary-range {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    padding: 0.75rem 1.25rem;
    border-radius: 10px;
    font-weight: 700;
    display: inline-block;
}

.requirement-list {
    list-style: none;
    padding: 0;
}

.requirement-list li {
    padding: 0.5rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.requirement-list li:last-child {
    border-bottom: none;
}

.requirement-list li:before {
    content: "✓";
    color: #28a745;
    font-weight: bold;
    margin-right: 0.75rem;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-recruit {
    padding: 0.75rem 1.5rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 5rem;
    opacity: 0.3;
    margin-bottom: 1.5rem;
}

.current-staff-badge {
    background: #fff3cd;
    color: #856404;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-tie text-primary me-2"></i>
                    Recruitment
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Job openings and candidate management
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="job-openings.php" class="btn btn-primary">
                        <i class="fas fa-briefcase me-1"></i> Post Job Opening
                    </a>
                    <a href="applications.php" class="btn btn-success">
                        <i class="fas fa-file-alt me-1"></i> View Applications
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['open_positions']); ?></div>
                    <div class="stats-label">Open Positions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number"><?php echo number_format($stats['total_applications']); ?></div>
                    <div class="stats-label">Total Applications</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="stats-number"><?php echo number_format($stats['interviews_scheduled']); ?></div>
                    <div class="stats-label">Interviews Scheduled</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number"><?php echo number_format($stats['offers_made']); ?></div>
                    <div class="stats-label">Offers Made</div>
                </div>
            </div>
        </div>

        <!-- Information Alert -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Recruitment Module:</strong> Below are the available positions in your organization. 
            You can post job openings, receive applications, and track candidates through the hiring process.
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5 col-md-6">
                    <label class="form-label fw-semibold small">Search Positions</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by position title or description..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold small">Department</label>
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php
                        $dept_list = array_unique(array_column($positions, 'department_name'));
                        sort($dept_list);
                        foreach ($dept_list as $dept): 
                            if ($dept):
                        ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"
                                <?php echo ($_GET['department'] ?? '') === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
                <div class="col-lg-2 col-md-6">
                    <?php if (!empty($_GET)): ?>
                    <a href="?" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Positions Grid -->
        <?php if (empty($positions)): ?>
        <div class="position-card">
            <div class="empty-state">
                <i class="fas fa-briefcase"></i>
                <h4 class="mb-3">No Positions Available</h4>
                <p class="lead mb-4">
                    Start by creating positions in the system to begin recruitment.
                </p>
                <a href="../settings/positions.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle me-2"></i> Create Positions
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($positions as $position): ?>
            <div class="col-lg-6 col-md-12">
                <div class="position-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="flex-grow-1">
                            <h5 class="position-title">
                                <?php echo htmlspecialchars($position['position_title']); ?>
                            </h5>
                            <span class="department-badge">
                                <i class="fas fa-building me-1"></i>
                                <?php echo htmlspecialchars($position['department_name'] ?? 'No Department'); ?>
                            </span>
                            <span class="current-staff-badge ms-2">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $position['current_count']; ?> current staff
                            </span>
                        </div>
                    </div>

                    <?php if ($position['job_description']): ?>
                    <div class="mb-3">
                        <p class="text-muted mb-0">
                            <?php echo nl2br(htmlspecialchars(substr($position['job_description'], 0, 200))); ?>
                            <?php if (strlen($position['job_description']) > 200): ?>...<?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="salary-range">
                                <small class="d-block text-uppercase" style="font-size: 0.7rem; opacity: 0.8;">Salary Range</small>
                                <div>
                                    <?php if ($position['min_salary'] && $position['max_salary']): ?>
                                    TSH <?php echo number_format($position['min_salary'], 0); ?> - 
                                    <?php echo number_format($position['max_salary'], 0); ?>
                                    <?php else: ?>
                                    Not specified
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <small class="text-muted d-block mb-1">Position Code</small>
                            <strong><?php echo htmlspecialchars($position['position_code'] ?? 'N/A'); ?></strong>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <small class="text-muted">
                            <i class="fas fa-calendar me-1"></i>
                            Created <?php echo date('M j, Y', strtotime($position['created_at'])); ?>
                        </small>
                        <div class="action-buttons">
                            <a href="post-job.php?position_id=<?php echo $position['position_id']; ?>" 
                               class="btn btn-sm btn-primary btn-recruit">
                                <i class="fas fa-bullhorn me-1"></i> Post Opening
                            </a>
                            <a href="view-position.php?id=<?php echo $position['position_id']; ?>" 
                               class="btn btn-sm btn-outline-secondary btn-recruit">
                                <i class="fas fa-eye me-1"></i> View
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Quick Links -->
        <div class="row g-4 mt-4">
            <div class="col-lg-4 col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center p-4">
                        <div class="mb-3">
                            <i class="fas fa-clipboard-list fa-3x text-primary"></i>
                        </div>
                        <h5 class="card-title">Job Applications</h5>
                        <p class="card-text text-muted">
                            Review and manage candidate applications for open positions
                        </p>
                        <a href="applications.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-right me-1"></i> View Applications
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center p-4">
                        <div class="mb-3">
                            <i class="fas fa-calendar-check fa-3x text-success"></i>
                        </div>
                        <h5 class="card-title">Interview Schedule</h5>
                        <p class="card-text text-muted">
                            Schedule and manage interviews with candidates
                        </p>
                        <a href="interviews.php" class="btn btn-outline-success">
                            <i class="fas fa-arrow-right me-1"></i> View Interviews
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center p-4">
                        <div class="mb-3">
                            <i class="fas fa-user-check fa-3x text-info"></i>
                        </div>
                        <h5 class="card-title">Candidate Pipeline</h5>
                        <p class="card-text text-muted">
                            Track candidates through the recruitment process
                        </p>
                        <a href="candidates.php" class="btn btn-outline-info">
                            <i class="fas fa-arrow-right me-1"></i> View Pipeline
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>