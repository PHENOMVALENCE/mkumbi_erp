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

// Get selected date (default to today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$display_date = date('F j, Y', strtotime($selected_date));

// Get selected month for statistics
$selected_month = $_GET['month'] ?? date('Y-m');
list($year, $month) = explode('-', $selected_month);

// Fetch statistics for selected month
$stats = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'on_leave' => 0,
    'total_hours' => 0
];

try {
    $stats_query = "
        SELECT 
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as on_leave,
            SUM(total_hours) as total_hours
        FROM attendance
        WHERE company_id = ? 
        AND YEAR(attendance_date) = ? 
        AND MONTH(attendance_date) = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id, $year, $month]);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats_result) {
        $stats = [
            'present' => (int)$stats_result['present'],
            'absent' => (int)$stats_result['absent'],
            'late' => (int)$stats_result['late'],
            'on_leave' => (int)$stats_result['on_leave'],
            'total_hours' => (float)$stats_result['total_hours']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching attendance stats: " . $e->getMessage());
}

// Build filter conditions
$where_conditions = ["a.company_id = ?", "a.attendance_date = ?"];
$params = [$company_id, $selected_date];

if (!empty($_GET['status'])) {
    $where_conditions[] = "a.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['department'])) {
    $where_conditions[] = "e.department_id = ?";
    $params[] = (int)$_GET['department'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(u.full_name LIKE ? OR e.employee_number LIKE ?)";
    $search = '%' . trim($_GET['search']) . '%';
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch attendance records
try {
    $query = "
        SELECT 
            a.*,
            e.employee_id,
            e.employee_number,
            u.full_name,
            u.profile_picture,
            d.department_name,
            p.position_title
        FROM attendance a
        INNER JOIN employees e ON a.employee_id = e.employee_id
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        WHERE " . $where_clause . "
        ORDER BY a.check_in_time ASC, u.full_name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching attendance: " . $e->getMessage());
    $attendance_records = [];
}

// Fetch departments for filter
$departments = [];
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

$page_title = 'Attendance';
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
}

.date-selector-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
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
    color: #495057;
    padding: 1rem 0.75rem;
    border-bottom: 2px solid #dee2e6;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.employee-photo {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.status-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.present {
    background: #d4edda;
    color: #155724;
}

.status-badge.absent {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.late {
    background: #fff3cd;
    color: #856404;
}

.status-badge.leave, .status-badge.holiday {
    background: #d1ecf1;
    color: #0c5460;
}

.time-display {
    font-family: 'SF Mono', monospace;
    font-weight: 600;
    color: #495057;
}

.hours-badge {
    background: #e7f3ff;
    color: #0066cc;
    padding: 0.3rem 0.7rem;
    border-radius: 6px;
    font-weight: 600;
}

.overtime-badge {
    background: #fff3cd;
    color: #856404;
    padding: 0.3rem 0.7rem;
    border-radius: 6px;
    font-weight: 600;
}

.quick-actions {
    display: flex;
    gap: 0.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-calendar-check text-primary me-2"></i>
                    Attendance
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Daily attendance tracking and management
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="mark-attendance.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> Mark Attendance
                    </a>
                    <a href="bulk-attendance.php" class="btn btn-success">
                        <i class="fas fa-users me-1"></i> Bulk Entry
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

        <!-- Month Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number"><?php echo number_format($stats['present']); ?></div>
                    <div class="stats-label">Present</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);">
                    <div class="stats-number"><?php echo number_format($stats['absent']); ?></div>
                    <div class="stats-label">Absent</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stats-number"><?php echo number_format($stats['late']); ?></div>
                    <div class="stats-label">Late</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number"><?php echo number_format($stats['on_leave']); ?></div>
                    <div class="stats-label">On Leave</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-8 col-sm-12">
                <div class="stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="stats-number"><?php echo number_format($stats['total_hours'], 1); ?></div>
                    <div class="stats-label">Total Hours (<?php echo date('F Y', strtotime($selected_month . '-01')); ?>)</div>
                </div>
            </div>
        </div>

        <!-- Date Selector -->
        <div class="date-selector-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-4">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-calendar me-1"></i> Select Date
                    </label>
                    <input type="date" name="date" class="form-control" 
                           value="<?php echo $selected_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label fw-semibold">Month (Stats)</label>
                    <input type="month" name="month" class="form-control" 
                           value="<?php echo $selected_month; ?>">
                </div>
                <div class="col-lg-2 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i> View
                    </button>
                </div>
                <div class="col-lg-2 col-md-3">
                    <a href="?" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-calendar-day me-1"></i> Today
                    </a>
                </div>
                <div class="col-lg-3 col-md-12">
                    <div class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Showing attendance for <strong><?php echo $display_date; ?></strong>
                    </div>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                <input type="hidden" name="month" value="<?php echo $selected_month; ?>">
                
                <div class="col-lg-4 col-md-6">
                    <label class="form-label fw-semibold small">Search Employee</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Name or employee number..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="present" <?php echo ($_GET['status'] ?? '') === 'present' ? 'selected' : ''; ?>>Present</option>
                        <option value="absent" <?php echo ($_GET['status'] ?? '') === 'absent' ? 'selected' : ''; ?>>Absent</option>
                        <option value="late" <?php echo ($_GET['status'] ?? '') === 'late' ? 'selected' : ''; ?>>Late</option>
                        <option value="leave" <?php echo ($_GET['status'] ?? '') === 'leave' ? 'selected' : ''; ?>>On Leave</option>
                        <option value="holiday" <?php echo ($_GET['status'] ?? '') === 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
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
                <div class="col-lg-1 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
                <div class="col-lg-2 col-md-3">
                    <?php if (!empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['department'])): ?>
                    <a href="?date=<?php echo $selected_date; ?>&month=<?php echo $selected_month; ?>" 
                       class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Attendance Table -->
        <?php if (empty($attendance_records)): ?>
        <div class="table-container">
            <div class="empty-state text-center py-5">
                <i class="fas fa-calendar-times fa-5x text-muted mb-3" style="opacity: 0.3;"></i>
                <h4 class="mb-3">No Attendance Records</h4>
                <p class="lead mb-4">
                    <?php if (!empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['department'])): ?>
                        No attendance records match your filters for <?php echo $display_date; ?>.
                    <?php else: ?>
                        No attendance has been marked for <?php echo $display_date; ?>.
                    <?php endif; ?>
                </p>
                <a href="mark-attendance.php?date=<?php echo $selected_date; ?>" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i> Mark Attendance for This Date
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="6%">Photo</th>
                            <th width="18%">Employee</th>
                            <th width="12%">Department</th>
                            <th width="10%">Check In</th>
                            <th width="10%">Check Out</th>
                            <th width="8%">Total Hours</th>
                            <th width="7%">Overtime</th>
                            <th width="10%">Status</th>
                            <th width="14%">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_num = 1;
                        foreach ($attendance_records as $record): 
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo $row_num++; ?></td>
                            <td>
                                <?php if (!empty($record['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($record['profile_picture']); ?>" 
                                     alt="Photo" class="employee-photo">
                                <?php else: ?>
                                <div class="employee-photo bg-secondary text-white d-flex align-items-center justify-content-center">
                                    <?php echo strtoupper(substr($record['full_name'], 0, 2)); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($record['full_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($record['employee_number']); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($record['department_name'] ?? '—'); ?></small>
                            </td>
                            <td>
                                <?php if ($record['check_in_time']): ?>
                                <span class="time-display">
                                    <i class="fas fa-sign-in-alt text-success me-1"></i>
                                    <?php echo date('g:i A', strtotime($record['check_in_time'])); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['check_out_time']): ?>
                                <span class="time-display">
                                    <i class="fas fa-sign-out-alt text-danger me-1"></i>
                                    <?php echo date('g:i A', strtotime($record['check_out_time'])); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['total_hours']): ?>
                                <span class="hours-badge">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo number_format($record['total_hours'], 1); ?>h
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['overtime_hours'] > 0): ?>
                                <span class="overtime-badge">
                                    <i class="fas fa-stopwatch me-1"></i>
                                    <?php echo number_format($record['overtime_hours'], 1); ?>h
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo strtolower($record['status']); ?>">
                                    <?php echo ucfirst($record['status']); ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo $record['remarks'] ? htmlspecialchars(substr($record['remarks'], 0, 50)) : '—'; ?>
                                    <?php if (strlen($record['remarks'] ?? '') > 50): ?>...<?php endif; ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="p-3 border-top bg-light">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Summary for <?php echo $display_date; ?>:</strong>
                        <?php echo count($attendance_records); ?> records
                    </div>
                    <div class="col-md-6 text-end">
                        <a href="attendance-report.php?date=<?php echo $selected_date; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-pdf me-1"></i> Generate Report
                        </a>
                        <a href="export-attendance.php?date=<?php echo $selected_date; ?>" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>