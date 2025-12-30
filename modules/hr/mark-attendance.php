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

// Get date from URL or use today
$attendance_date = $_GET['date'] ?? date('Y-m-d');
$display_date = date('F j, Y', strtotime($attendance_date));

// Fetch active employees
$employees = [];
try {
    $query = "
        SELECT 
            e.employee_id,
            e.employee_number,
            u.full_name,
            u.profile_picture,
            d.department_name,
            p.position_title,
            a.attendance_id,
            a.check_in_time,
            a.check_out_time,
            a.total_hours,
            a.overtime_hours,
            a.status,
            a.remarks
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN attendance a ON e.employee_id = a.employee_id 
            AND a.attendance_date = ?
        WHERE e.company_id = ? 
        AND e.employment_status = 'active'
        ORDER BY u.full_name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$attendance_date, $company_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching employees: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $success_count = 0;
    
    try {
        $conn->beginTransaction();
        
        foreach ($_POST['attendance'] as $employee_id => $data) {
            // Skip if status not selected
            if (empty($data['status'])) continue;
            
            $employee_id = (int)$employee_id;
            $status = $data['status'];
            $check_in = !empty($data['check_in']) ? $data['check_in'] : null;
            $check_out = !empty($data['check_out']) ? $data['check_out'] : null;
            $remarks = $data['remarks'] ?? null;
            
            // Calculate total hours if both check in and out are provided
            $total_hours = null;
            $overtime_hours = 0;
            
            if ($check_in && $check_out) {
                $check_in_time = strtotime($check_in);
                $check_out_time = strtotime($check_out);
                
                if ($check_out_time > $check_in_time) {
                    $total_hours = ($check_out_time - $check_in_time) / 3600; // Convert to hours
                    
                    // Calculate overtime (assuming 8 hours is standard)
                    if ($total_hours > 8) {
                        $overtime_hours = $total_hours - 8;
                    }
                }
            }
            
            // Check if attendance already exists
            $check_query = "
                SELECT attendance_id 
                FROM attendance 
                WHERE company_id = ? 
                AND employee_id = ? 
                AND attendance_date = ?
            ";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([$company_id, $employee_id, $attendance_date]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing record
                $update_query = "
                    UPDATE attendance SET
                        check_in_time = ?,
                        check_out_time = ?,
                        total_hours = ?,
                        overtime_hours = ?,
                        status = ?,
                        remarks = ?
                    WHERE attendance_id = ?
                ";
                $stmt = $conn->prepare($update_query);
                $stmt->execute([
                    $check_in,
                    $check_out,
                    $total_hours,
                    $overtime_hours,
                    $status,
                    $remarks,
                    $existing['attendance_id']
                ]);
            } else {
                // Insert new record
                $insert_query = "
                    INSERT INTO attendance (
                        company_id, employee_id, attendance_date,
                        check_in_time, check_out_time, total_hours,
                        overtime_hours, status, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt = $conn->prepare($insert_query);
                $stmt->execute([
                    $company_id,
                    $employee_id,
                    $attendance_date,
                    $check_in,
                    $check_out,
                    $total_hours,
                    $overtime_hours,
                    $status,
                    $remarks
                ]);
            }
            
            $success_count++;
        }
        
        $conn->commit();
        
        if ($success_count > 0) {
            $_SESSION['success_message'] = "Attendance marked for {$success_count} employee(s) successfully!";
            header("Location: attendance.php?date={$attendance_date}");
            exit;
        } else {
            $errors[] = "No attendance records were updated. Please select at least one employee.";
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error marking attendance: " . $e->getMessage());
        $errors[] = "Error saving attendance. Please try again.";
    }
}

$page_title = 'Mark Attendance';
require_once '../../includes/header.php';
?>

<style>
.attendance-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.date-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
}

.employee-row {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
    border-left: 4px solid #e9ecef;
    transition: all 0.2s ease;
}

.employee-row:hover {
    background: #e9ecef;
    border-left-color: #007bff;
}

.employee-row.marked {
    border-left-color: #28a745;
}

.employee-photo {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
}

.employee-name {
    font-weight: 600;
    color: #212529;
}

.quick-mark-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.quick-mark-btn {
    padding: 0.4rem 1rem;
    border-radius: 20px;
    border: 2px solid;
    background: white;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.quick-mark-btn.present {
    border-color: #28a745;
    color: #28a745;
}

.quick-mark-btn.present:hover,
.quick-mark-btn.present.active {
    background: #28a745;
    color: white;
}

.quick-mark-btn.absent {
    border-color: #dc3545;
    color: #dc3545;
}

.quick-mark-btn.absent:hover,
.quick-mark-btn.absent.active {
    background: #dc3545;
    color: white;
}

.quick-mark-btn.late {
    border-color: #ffc107;
    color: #856404;
}

.quick-mark-btn.late:hover,
.quick-mark-btn.late.active {
    background: #ffc107;
    color: #856404;
}

.quick-mark-btn.leave {
    border-color: #17a2b8;
    color: #17a2b8;
}

.quick-mark-btn.leave:hover,
.quick-mark-btn.leave.active {
    background: #17a2b8;
    color: white;
}

.bulk-actions {
    background: #fff3cd;
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border-left: 4px solid #ffc107;
}

.time-input-group {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-calendar-check text-primary me-2"></i>
                    Mark Attendance
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Record daily employee attendance
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="attendance.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Attendance
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Date Header -->
        <div class="date-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h3 class="mb-0">
                        <i class="fas fa-calendar-day me-2"></i>
                        <?php echo $display_date; ?>
                    </h3>
                    <p class="mb-0 mt-2 opacity-75">
                        Marking attendance for <?php echo count($employees); ?> active employee(s)
                    </p>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <form method="GET" class="d-inline-block">
                        <div class="input-group input-group-lg">
                            <input type="date" name="date" class="form-control" 
                                   value="<?php echo $attendance_date; ?>" 
                                   max="<?php echo date('Y-m-d'); ?>">
                            <button type="submit" class="btn btn-light">
                                <i class="fas fa-search"></i> Change Date
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (empty($employees)): ?>
        <div class="attendance-card">
            <div class="text-center py-5">
                <i class="fas fa-users fa-5x text-muted mb-3" style="opacity: 0.3;"></i>
                <h4>No Active Employees Found</h4>
                <p class="text-muted">Please add employees to the system first.</p>
                <a href="add-employee.php" class="btn btn-primary mt-3">
                    <i class="fas fa-user-plus me-2"></i> Add Employee
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <strong><i class="fas fa-magic me-2"></i>Quick Actions:</strong>
                    Mark all as:
                </div>
                <div class="col-md-6">
                    <div class="quick-mark-buttons justify-content-md-end">
                        <button type="button" class="quick-mark-btn present" onclick="markAllAs('present')">
                            <i class="fas fa-check me-1"></i> Present
                        </button>
                        <button type="button" class="quick-mark-btn absent" onclick="markAllAs('absent')">
                            <i class="fas fa-times me-1"></i> Absent
                        </button>
                        <button type="button" class="quick-mark-btn late" onclick="markAllAs('late')">
                            <i class="fas fa-clock me-1"></i> Late
                        </button>
                        <button type="button" class="quick-mark-btn leave" onclick="markAllAs('leave')">
                            <i class="fas fa-umbrella-beach me-1"></i> Leave
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="">
            <div class="attendance-card">
                
                <?php foreach ($employees as $index => $emp): ?>
                <div class="employee-row <?php echo $emp['attendance_id'] ? 'marked' : ''; ?>" 
                     data-employee-id="<?php echo $emp['employee_id']; ?>">
                    <div class="row align-items-center g-3">
                        
                        <!-- Employee Info -->
                        <div class="col-lg-3 col-md-4">
                            <div class="d-flex align-items-center">
                                <?php if (!empty($emp['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($emp['profile_picture']); ?>" 
                                     alt="Photo" class="employee-photo me-3">
                                <?php else: ?>
                                <div class="employee-photo bg-secondary text-white d-flex align-items-center justify-content-center me-3">
                                    <?php echo strtoupper(substr($emp['full_name'], 0, 2)); ?>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="employee-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($emp['employee_number']); ?></small>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($emp['department_name'] ?? 'No Dept'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- Status Selection -->
                        <div class="col-lg-2 col-md-3">
                            <label class="form-label small">Status</label>
                            <select name="attendance[<?php echo $emp['employee_id']; ?>][status]" 
                                    class="form-select form-select-sm status-select" required>
                                <option value="">Select...</option>
                                <option value="present" <?php echo ($emp['status'] ?? '') === 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo ($emp['status'] ?? '') === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="late" <?php echo ($emp['status'] ?? '') === 'late' ? 'selected' : ''; ?>>Late</option>
                                <option value="leave" <?php echo ($emp['status'] ?? '') === 'leave' ? 'selected' : ''; ?>>Leave</option>
                                <option value="holiday" <?php echo ($emp['status'] ?? '') === 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                            </select>
                        </div>

                        <!-- Check In -->
                        <div class="col-lg-2 col-md-2">
                            <label class="form-label small">Check In</label>
                            <input type="time" 
                                   name="attendance[<?php echo $emp['employee_id']; ?>][check_in]" 
                                   class="form-control form-control-sm"
                                   value="<?php echo $emp['check_in_time'] ?? ''; ?>">
                        </div>

                        <!-- Check Out -->
                        <div class="col-lg-2 col-md-2">
                            <label class="form-label small">Check Out</label>
                            <input type="time" 
                                   name="attendance[<?php echo $emp['employee_id']; ?>][check_out]" 
                                   class="form-control form-control-sm"
                                   value="<?php echo $emp['check_out_time'] ?? ''; ?>">
                        </div>

                        <!-- Remarks -->
                        <div class="col-lg-3 col-md-4">
                            <label class="form-label small">Remarks</label>
                            <input type="text" 
                                   name="attendance[<?php echo $emp['employee_id']; ?>][remarks]" 
                                   class="form-control form-control-sm"
                                   placeholder="Optional notes..."
                                   value="<?php echo htmlspecialchars($emp['remarks'] ?? ''); ?>">
                        </div>

                    </div>
                </div>
                <?php endforeach; ?>

            </div>

            <!-- Submit Buttons -->
            <div class="attendance-card">
                <div class="row">
                    <div class="col-md-12 text-center">
                        <button type="submit" class="btn btn-success btn-lg px-5">
                            <i class="fas fa-save me-2"></i> Save Attendance
                        </button>
                        <a href="attendance.php?date=<?php echo $attendance_date; ?>" 
                           class="btn btn-outline-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>

        </form>

        <?php endif; ?>

    </div>
</section>

<script>
// Mark all employees with a specific status
function markAllAs(status) {
    const statusSelects = document.querySelectorAll('.status-select');
    statusSelects.forEach(select => {
        select.value = status;
    });
    
    // If marking as present, set default times
    if (status === 'present') {
        document.querySelectorAll('input[type="time"]').forEach((input, index) => {
            if (index % 2 === 0) { // Check-in times
                if (!input.value) input.value = '08:00';
            } else { // Check-out times
                if (!input.value) input.value = '17:00';
            }
        });
    }
}

// Validate form before submission
document.querySelector('form').addEventListener('submit', function(e) {
    const statusSelects = document.querySelectorAll('.status-select');
    let hasSelection = false;
    
    statusSelects.forEach(select => {
        if (select.value) hasSelection = true;
    });
    
    if (!hasSelection) {
        e.preventDefault();
        alert('Please select status for at least one employee.');
        return false;
    }
});

// Auto-calculate hours when times are entered
document.querySelectorAll('input[type="time"]').forEach(input => {
    input.addEventListener('change', function() {
        const row = this.closest('.employee-row');
        const checkIn = row.querySelector('input[name*="[check_in]"]').value;
        const checkOut = row.querySelector('input[name*="[check_out]"]').value;
        
        if (checkIn && checkOut) {
            const start = new Date('2000-01-01 ' + checkIn);
            const end = new Date('2000-01-01 ' + checkOut);
            
            if (end > start) {
                const hours = (end - start) / (1000 * 60 * 60);
                console.log('Total hours: ' + hours.toFixed(2));
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>