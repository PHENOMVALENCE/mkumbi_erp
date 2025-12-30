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

// Fetch statistics
$stats = [
    'total_employees' => 0,
    'active_employees' => 0,
    'terminated_employees' => 0,
    'on_leave' => 0
];

try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_employees,
            SUM(CASE WHEN employment_status = 'active' THEN 1 ELSE 0 END) as active_employees,
            SUM(CASE WHEN employment_status IN ('terminated', 'resigned') THEN 1 ELSE 0 END) as terminated_employees,
            SUM(CASE WHEN employment_status = 'suspended' THEN 1 ELSE 0 END) as on_leave
        FROM employees
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats_result) {
        $stats = [
            'total_employees' => (int)$stats_result['total_employees'],
            'active_employees' => (int)$stats_result['active_employees'],
            'terminated_employees' => (int)$stats_result['terminated_employees'],
            'on_leave' => (int)$stats_result['on_leave']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching employee stats: " . $e->getMessage());
}

// Build filter conditions
$where_conditions = ["e.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "e.employment_status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['department'])) {
    $where_conditions[] = "e.department_id = ?";
    $params[] = (int)$_GET['department'];
}

if (!empty($_GET['employment_type'])) {
    $where_conditions[] = "e.employment_type = ?";
    $params[] = $_GET['employment_type'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(u.full_name LIKE ? OR e.employee_number LIKE ? OR u.email LIKE ?)";
    $search = '%' . trim($_GET['search']) . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total records
try {
    $count_query = "
        SELECT COUNT(*) 
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        WHERE " . $where_clause;
    $stmt = $conn->prepare($count_query);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log("Error counting employees: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
}

// Fetch employees
try {
    $query = "
        SELECT 
            e.*,
            u.full_name,
            u.email,
            u.phone1,
            u.profile_picture,
            d.department_name,
            p.position_title,
            TIMESTAMPDIFF(YEAR, e.hire_date, CURDATE()) as years_of_service,
            TIMESTAMPDIFF(MONTH, e.hire_date, CURDATE()) as months_of_service
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        WHERE " . $where_clause . "
        ORDER BY e.employment_status = 'active' DESC, u.full_name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching employees: " . $e->getMessage());
    $employees = [];
}

// Fetch departments for filter
$departments = [];
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

$page_title = 'Employees';
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
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
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
    margin-top: 0.5rem;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table thead {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding: 1rem 0.75rem;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.employee-photo {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e9ecef;
}

.employee-name {
    font-weight: 600;
    color: #212529;
    font-size: 0.95rem;
}

.employee-number {
    font-family: 'SF Mono', monospace;
    background: #e3f2fd;
    padding: 0.3rem 0.7rem;
    border-radius: 6px;
    font-weight: 600;
    color: #1976d2;
    font-size: 0.8rem;
    display: inline-block;
}

.status-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-badge.terminated, .status-badge.resigned {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.status-badge.suspended {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.employment-type-badge {
    background: #e7f3ff;
    color: #0066cc;
    padding: 0.3rem 0.7rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
}

.salary-amount {
    font-weight: 700;
    color: #28a745;
}

.service-years {
    color: #6c757d;
    font-size: 0.85rem;
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

@media (max-width: 768px) {
    .stats-number { font-size: 1.5rem; }
    .employee-photo { width: 35px; height: 35px; }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-users text-primary me-2"></i>
                    Employees
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Manage employee records (<?php echo number_format($total_records); ?> total)
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="add-employee.php" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i> Add Employee
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
                    <div class="stats-number"><?php echo number_format($stats['total_employees']); ?></div>
                    <div class="stats-label">Total Employees</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number"><?php echo number_format($stats['active_employees']); ?></div>
                    <div class="stats-label">Active</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);">
                    <div class="stats-number"><?php echo number_format($stats['terminated_employees']); ?></div>
                    <div class="stats-label">Terminated/Resigned</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stats-number"><?php echo number_format($stats['on_leave']); ?></div>
                    <div class="stats-label">Suspended</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold small">Search Employees</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Name, number, email..." 
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="suspended" <?php echo ($_GET['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="terminated" <?php echo ($_GET['status'] ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                        <option value="resigned" <?php echo ($_GET['status'] ?? '') === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Department</label>
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" 
                                <?php echo ($_GET['department'] ?? '') == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Type</label>
                    <select name="employment_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="permanent" <?php echo ($_GET['employment_type'] ?? '') === 'permanent' ? 'selected' : ''; ?>>Permanent</option>
                        <option value="contract" <?php echo ($_GET['employment_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                        <option value="casual" <?php echo ($_GET['employment_type'] ?? '') === 'casual' ? 'selected' : ''; ?>>Casual</option>
                        <option value="intern" <?php echo ($_GET['employment_type'] ?? '') === 'intern' ? 'selected' : ''; ?>>Intern</option>
                    </select>
                </div>
                <div class="col-lg-1 col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i>
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

        <!-- Employees Table -->
        <?php if (empty($employees)): ?>
        <div class="table-container">
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h4 class="mb-3">No Employees Found</h4>
                <p class="lead mb-4">
                    <?php if (!empty($_GET)): ?>
                        No employees match your current filters.
                    <?php else: ?>
                        Start by adding your first employee to the system.
                    <?php endif; ?>
                </p>
                <?php if (empty($_GET)): ?>
                <a href="add-employee.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-user-plus me-2"></i> Add First Employee
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="8%">Photo</th>
                            <th width="15%">Employee</th>
                            <th width="12%">Number</th>
                            <th width="12%">Department</th>
                            <th width="10%">Position</th>
                            <th width="8%">Type</th>
                            <th width="10%">Salary</th>
                            <th width="10%">Service</th>
                            <th width="8%">Status</th>
                            <th width="2%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_num = $offset + 1;
                        foreach ($employees as $emp): 
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo $row_num++; ?></td>
                            <td>
                                <?php if (!empty($emp['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($emp['profile_picture']); ?>" 
                                     alt="Photo" class="employee-photo">
                                <?php else: ?>
                                <div class="employee-photo bg-secondary text-white d-flex align-items-center justify-content-center">
                                    <?php echo strtoupper(substr($emp['full_name'], 0, 2)); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="employee-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                                <small class="text-muted">
                                    <i class="fas fa-envelope me-1"></i>
                                    <?php echo htmlspecialchars($emp['email']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="employee-number">
                                    <?php echo htmlspecialchars($emp['employee_number']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($emp['department_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($emp['position_title'] ?? '—'); ?></td>
                            <td>
                                <span class="employment-type-badge">
                                    <?php echo ucfirst($emp['employment_type']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="salary-amount">
                                    TSH <?php echo number_format($emp['total_salary'] ?? 0, 0); ?>
                                </div>
                            </td>
                            <td>
                                <div class="service-years">
                                    <?php 
                                    $years = $emp['years_of_service'];
                                    $months = $emp['months_of_service'] % 12;
                                    if ($years > 0) {
                                        echo $years . ' yr' . ($years > 1 ? 's' : '');
                                        if ($months > 0) echo ' ' . $months . ' mo';
                                    } else {
                                        echo $months . ' month' . ($months != 1 ? 's' : '');
                                    }
                                    ?>
                                </div>
                                <small class="text-muted">
                                    Since <?php echo date('M Y', strtotime($emp['hire_date'])); ?>
                                </small>
                            </td>
                            <td>
                                <span class="status-badge <?php echo strtolower($emp['employment_status']); ?>">
                                    <?php echo ucfirst($emp['employment_status']); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="view-employee.php?id=<?php echo $emp['employee_id']; ?>" 
                                       class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit-employee.php?id=<?php echo $emp['employee_id']; ?>" 
                                       class="btn btn-outline-success" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="p-3 border-top">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="text-muted small">
                            Showing <?php echo number_format($offset + 1); ?> to 
                            <?php echo number_format(min($offset + $per_page, $total_records)); ?> 
                            of <?php echo number_format($total_records); ?> entries
                        </div>
                    </div>
                    <div class="col-md-6">
                        <nav>
                            <ul class="pagination pagination-sm justify-content-end mb-0">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo ($page - 1); ?><?php echo http_build_query(array_diff_key($_GET, ['page' => ''])) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo http_build_query(array_diff_key($_GET, ['page' => ''])) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo ($page + 1); ?><?php echo http_build_query(array_diff_key($_GET, ['page' => ''])) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>